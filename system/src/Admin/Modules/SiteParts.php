<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\SiteParts as CastiWebu;
use Kaleta\Builder\Publisher;

/**
 * Části webu v builderu: záhlaví, patička a obálky detailu novinky, výpisu a stránky 404. Bez publikované stavby
 * kreslí část šablona; „Vrátit na šablonu“ stavbu vypne (zůstane ve verzích).
 */
final class SiteParts extends Module
{
    use BuilderActions {
        akceStavitel as protected openBuilder;
    }

    public const string IDENT = 'casti';
    public const string NAME = 'Části webu';
    public const string GROUP = 'Vzhled';
    public const string ICON = 'casti';
    public const bool ADMIN_ONLY = true;

    protected function akceVypis(): Response
    {
        $siteSettings = $this->app->settings();
        $languages = array_merge([''], Language::additional($siteSettings));
        $rows = [];
        $variants = [];
        foreach ($this->db->all('SELECT typ, jazyk, varianta, nazev, stranky, stavba IS NOT NULL AS publikovana, stavba_koncept IS NOT NULL AND (stavba IS NULL OR stavba_koncept <> stavba) AS zmeny, zmeneno FROM {casti} ORDER BY nazev') as $r) {
            if ($r['varianta'] === '') {
                $rows[$r['typ'] . ':' . $r['jazyk']] = $r;
            } else {
                $variants[$r['typ'] . ':' . $r['jazyk']][] = $r;
            }
        }

        return $this->view('vypis', 'Části webu', [
            'typy' => CastiWebu::TYPES, 'jazyky' => $languages, 'radky' => $rows, 'varianty' => $variants,
            'nazvyStranek' => $this->db->pairs('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL ORDER BY poradi, titulek'),
            'nazvyJazyku' => array_combine($languages, array_map(fn (string $j): string => Language::AVAILABLE[Language::ofContent($siteSettings, $j)][0], $languages)),
        ]);
    }

    /** Editor; část, která ještě není, se založí s konceptem podle toho, co dosud kreslila šablona. */
    protected function akceStavitel(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if ($type !== null && $variant === '' && CastiWebu::row($this->db, $type, $language) === null) {
            $this->db->insert('casti', ['typ' => $type, 'jazyk' => $language, 'stavba_koncept' => CastiWebu::initialDraft($this->db, $type, $language, $this->contentLanguage($language)), 'zmeneno' => date('Y-m-d H:i:s')]);
        }

        return $this->openBuilder();
    }

    /** Část se vrátí na šablonu (varianta se smaže): publikovaná stavba jde do verzí, na webu se kreslí část z layoutu. */
    protected function akceSablona(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        $row = $this->request->isPost() && $type !== null ? CastiWebu::row($this->db, $type, $language, $variant) : null;
        if ($row !== null) {
            Publisher::version($this->app, ['cast' => CastiWebu::versionKey($type, $language, $variant)], $row['stavba'], null, $row['zmeneno']);
            $this->db->delete('casti', ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back($variant !== '' ? 'Varianta byla smazána – vybrané stránky mají zase výchozí podobu.' : 'Část webu se vrátila na výchozí podobu. Předchozí podobu najdete ve verzích, když ji znovu otevřete v builderu.');
    }

    /** Formulář varianty záhlaví nebo patičky: název a stránky, na kterých platí. */
    protected function akceVarianta(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if ($type === null || !in_array($type, CastiWebu::WITH_VARIANTS, true)) {
            return $this->error('Varianty mají jen záhlaví a patička.', 404);
        }
        $row = $variant !== '' ? CastiWebu::row($this->db, $type, $language, $variant) : null;

        return $this->view('varianta', t('Varianta: %s', t(CastiWebu::TYPES[$type][0])), [
            'typ' => $type, 'jazyk' => $language, 'varianta' => $row['varianta'] ?? '', 'nazev' => $row['nazev'] ?? '',
            'vybrane' => array_map('intval', json_decode((string) ($row['stranky'] ?? '[]'), true) ?: []),
            'stranky' => $this->db->all('SELECT ids, titulek FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$language]),
        ]);
    }

    /** Uložení varianty; nová začíná kopií výchozí podoby (nebo podoby ze šablony) jako koncept. */
    protected function akceUlozVariantu(): Response
    {
        [$type, $language] = $this->readPartParams();
        if (!$this->request->isPost() || $type === null || !in_array($type, CastiWebu::WITH_VARIANTS, true)) {
            return $this->back();
        }
        $name = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if ($name === '') {
            return $this->back('Varianta musí mít název.', 'varianta', ['typ' => $type, 'jazyk' => $language], 'chyba');
        }
        $variant = CastiWebu::saveVariant($this->db, $type, $language, $this->request->post('varianta'), $name, array_map('intval', $this->request->postList('stranky')), $this->contentLanguage($language));
        \Kaleta\Front\Cache::clear();

        return \Kaleta\Core\Response::redirect($this->url('stavitel', ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant]));
    }

    protected function loadBuildTarget(): ?array
    {
        [$type, $language, $variant] = $this->readPartParams();
        $row = $type === null ? null : CastiWebu::row($this->db, $type, $language, $variant);

        return $row === null ? null : [
            'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => $this->contentLanguage($language),
            'titulek' => t(CastiWebu::TYPES[$type][0]) . ($variant !== '' ? ' – ' . $row['nazev'] : ''),
            'revize' => ['cast' => CastiWebu::versionKey($type, $language, $variant)], 'parametry' => ['typ' => $type, 'jazyk' => $language] + ($variant !== '' ? ['varianta' => $variant] : []),
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('casti', ['stavba_koncept' => $draft], ['typ' => $target['radek']['typ'], 'jazyk' => $target['radek']['jazyk'], 'varianta' => $target['radek']['varianta']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::part($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $type = $target['radek']['typ'];
        $language = $target['radek']['jazyk'];
        // náhled: stránka, na které se část ukáže (obálka novinky na nejnovější novince, 404 na neexistující adrese)
        // varianta se ukazuje na první stránce, pro kterou platí
        $page = $target['radek']['varianta'] !== '' ? (json_decode((string) $target['radek']['stranky'], true) ?: [])[0] ?? null : null;
        $path = $page !== null ? (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [(int) $page]) : match ($type) {
            'novinka' => ($seo = $this->db->value('SELECT seo_link FROM {novinky} WHERE visible = 1 AND smazano IS NULL AND datum <= NOW() AND jazyk = ? ORDER BY datum DESC LIMIT 1', [$language])) !== null ? 'novinky/' . $seo : 'novinky',
            'vypis' => 'novinky',
            'nenalezeno' => 'tahle-stranka-neexistuje',
            default => '',
        };
        $url = $this->app->url(($language !== '' ? $language . '/' : '') . $path);

        return [
            'adresa' => $url, 'nahled' => $url . '?cast=' . $type . '&stavba=koncept&editor=1' . ($target['radek']['varianta'] !== '' ? '&varianta=' . rawurlencode($target['radek']['varianta']) : ''),
            'zobrazena' => true, 'casti' => true,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Části webu')], 'nastaveni' => null, 'podpis' => 'cast:' . $type . ':' . $language . ($target['radek']['varianta'] !== '' ? ':' . $target['radek']['varianta'] : ''),
        ];
    }

    /** @return array{0: ?string, 1: string, 2: string} typ, jazyk a varianta části z adresy požadavku */
    private function readPartParams(): array
    {
        $type = $this->request->get('typ');
        $language = $this->request->get('jazyk');
        $variant = $this->request->get('varianta');

        return [isset(CastiWebu::TYPES[$type]) ? $type : null, in_array($language, Language::additional($this->app->settings()), true) ? $language : '',
            in_array($type, CastiWebu::WITH_VARIANTS, true) && preg_match(CastiWebu::VARIANT_PATTERN, $variant) ? $variant : ''];
    }
}
