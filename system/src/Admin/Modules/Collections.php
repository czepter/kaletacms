<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\Collections as KolekceObsahu;
use Kaleta\Builder\Publisher;

/**
 * Kolekce – vlastní typy obsahu (reference, tým, produkty, pobočky…). Definici polí a šablonu detailu mění správce,
 * položky každý, kdo má k modulu přístup. Na web je dostane prvek Výpis kolekce v builderu.
 */
final class Collections extends Module
{
    use BuilderActions {
        akceStavitel as protected openBuilder;
    }

    public const string IDENT = 'kolekce';
    public const string NAME = 'Kolekce';
    public const string GROUP = 'Obsah';
    public const string ICON = 'kolekce';

    protected function akceVypis(): Response
    {
        return $this->view('list', 'Kolekce', [
            'collection' => $this->db->all('SELECT k.idk, k.nazev, k.seo_link, k.detail, (SELECT COUNT(*) FROM {kolekce_polozky} p WHERE p.idk = k.idk) AS pocet FROM {kolekce} k ORDER BY k.nazev'),
        ]);
    }

    /* ---------- definice kolekce (správce) ---------- */

    protected function akceNovy(): Response
    {
        return $this->admin() ?? $this->view('form', 'Nová kolekce', ['k' => ['idk' => 0, 'nazev' => '', 'seo_link' => '', 'detail' => 0, 'pole' => [
            ['klic' => '', 'popisek' => t('Popis'), 'typ' => 'radky'], ['klic' => '', 'popisek' => t('Obrázek'), 'typ' => 'obrazek'],
        ]]]);
    }

    protected function akceEdit(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));

        return $this->admin() ?? ($k === null ? $this->error('Kolekce neexistuje.', 404) : $this->view('form', $k['nazev'], ['k' => $k]));
    }

    protected function akceUloz(): Response
    {
        if (($refusal = $this->admin()) !== null || !$this->request->isPost()) {
            return $refusal ?? $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('idk');
        $previous = $id > 0 ? KolekceObsahu::byId($this->db, $id) : null;
        $name = mb_substr(trim($r->post('nazev')), 0, 100);
        if ($name === '') {
            return $this->back('Kolekce musí mít název.', $id > 0 ? 'edit' : 'novy', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        $seo = slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $name, 110);
        if (in_array($seo, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$seo]) || $this->db->value('SELECT idk FROM {kolekce} WHERE seo_link = ? AND idk <> ?', [$seo, $id]) !== null) {
            return $this->back(t('Adresu „%s“ už používá systém nebo jiná kolekce.', $seo), $id > 0 ? 'edit' : 'novy', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        // klíč existujícího pole se nemění (jsou pod ním uložené hodnoty položek); nová pole ho dostanou z popisku
        $field = KolekceObsahu::sanitizeFields(is_array($_POST['pole'] ?? null) ? array_values($_POST['pole']) : []);
        $data = ['nazev' => $name, 'seo_link' => $seo, 'detail' => $r->postBool('detail') ? 1 : 0, 'pole' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')];
        if ($previous !== null) {
            $this->db->update('kolekce', $data, ['idk' => $id]);
        } else {
            $id = $this->db->insert('kolekce', $data);
        }
        \Kaleta\Front\Cache::clear();

        return $this->back('Kolekce byla uložena.', 'polozky', ['id' => $id]);
    }

    protected function akceSmaz(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        if ($this->request->isPost()) {
            $this->db->delete('kolekce', ['idk' => $this->request->postInt('idk')]);
        }

        return $this->back('Kolekce i s položkami byla smazána.');
    }

    /* ---------- položky ---------- */

    protected function akcePolozky(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->error('Kolekce neexistuje.', 404);
        }

        [$siteLanguages, $language, $column] = $this->readLanguageFilter();

        return $this->view('items', $k['nazev'], ['k' => $k, 'languages' => Language::additional($this->app->settings()), 'siteLanguages' => $siteLanguages, 'language' => $language,
            'items' => $this->db->all('SELECT idp, nazev, seo_link, poradi, zobrazit, jazyk, datum FROM {kolekce_polozky} WHERE idk = ?' . ($column !== null ? ' AND jazyk = ?' : '') . ' ORDER BY jazyk, poradi, nazev',
                $column !== null ? [$k['idk'], $column] : [$k['idk']])]);
    }

    protected function akcePolozka(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->error('Kolekce neexistuje.', 404);
        }
        $idp = $this->request->getInt('polozka');
        $p = $idp > 0 ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$idp, $k['idk']]) : null;
        if ($idp > 0 && $p === null) {
            return $this->error('Položka neexistuje.', 404);
        }
        $p ??= ['idp' => 0, 'nazev' => '', 'seo_link' => '', 'data' => '{}', 'poradi' => 100, 'zobrazit' => 1, 'jazyk' => '', 'datum' => date('Y-m-d H:i:s')];
        $p['data'] = json_decode((string) $p['data'], true) ?: [];

        return $this->view('item', $p['nazev'] !== '' ? $p['nazev'] : t('Nová položka'), ['k' => $k, 'p' => $p]);
    }

    protected function akceUlozPolozku(): Response
    {
        $r = $this->request;
        $k = $r->isPost() ? KolekceObsahu::byId($this->db, $r->postInt('idk')) : null;
        if ($k === null) {
            return $this->back();
        }
        $idp = $r->postInt('idp');
        $name = mb_substr(trim($r->post('nazev')), 0, 200);
        if ($name === '') {
            return $this->back('Položka musí mít název.', 'polozka', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }
        $errors = [];
        $data = KolekceObsahu::sanitizeData($k['pole'], is_array($_POST['data'] ?? null) ? $_POST['data'] : [], $errors);
        $seo = slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $name, 150);
        // adresa je jedinečná v jazyce: překlad položky smí mít stejnou (/compare/wordpress, /de/compare/wordpress)
        $language = Language::column($this->app->settings(), $r->post('jazyk'));
        $seo = \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => $this->db->value('SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ? AND idp <> ?', [$k['idk'], $language, $a, $idp]) !== null);
        $row = ['idk' => $k['idk'], 'nazev' => $name, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'poradi' => max(-9999, min(9999, $r->postInt('poradi'))), 'zobrazit' => $r->postBool('zobrazit') ? 1 : 0,
            'jazyk' => $language, 'zmeneno' => date('Y-m-d H:i:s')];
        if ($idp > 0 && $this->db->value('SELECT idp FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$idp, $k['idk']]) !== null) {
            $this->db->update('kolekce_polozky', $row, ['idp' => $idp]);
        } else {
            $idp = $this->db->insert('kolekce_polozky', $row + ['datum' => date('Y-m-d H:i:s')]);
        }
        \Kaleta\Front\Cache::clear();
        if ($errors !== []) {
            return $this->back(t('Položka je uložená, ale tato pole měla neplatnou hodnotu a zůstala prázdná: %s', implode(', ', $errors)), 'polozka', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }

        return $this->back('Položka byla uložena.', 'polozky', ['id' => $k['idk']]);
    }

    /** Kopie položky (skrytá, s volnou adresou) – rychlý začátek podobné reference, člena týmu, produktu. */
    protected function akceDuplikujPolozku(): Response
    {
        $idk = $this->request->postInt('idk');
        $p = $this->request->isPost() ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$this->request->postInt('idp'), $idk]) : null;
        if ($p === null) {
            return $this->back('', 'polozky', ['id' => $idk]);
        }
        $seo = \Kaleta\Core\Slug::makeUnique($p['seo_link'] . '-kopie', fn (string $a): bool => $this->db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ?', [$idk, $p['jazyk'], $a]) !== null);
        $id = $this->db->insert('kolekce_polozky', ['idk' => $idk, 'nazev' => mb_substr(t('%s (kopie)', $p['nazev']), 0, 200), 'seo_link' => $seo, 'data' => $p['data'],
            'poradi' => $p['poradi'], 'zobrazit' => 0, 'jazyk' => $p['jazyk'], 'datum' => date('Y-m-d H:i:s')]);

        return $this->back('Kopie položky je skrytá – upravte ji a zveřejněte.', 'polozka', ['id' => $idk, 'polozka' => $id]);
    }

    protected function akceSmazPolozku(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$this->request->postInt('idp'), $idk]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('Položka byla smazána.', 'polozky', ['id' => $idk]);
    }

    /* ---------- šablona detailu v builderu (správce) ---------- */

    protected function akceStavitel(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        $k = $this->template();
        if ($k !== null && $k['stavba'] === null && $k['stavba_koncept'] === null) {
            KolekceObsahu::writeTemplate($this->db, $k, ['stavba_koncept' => KolekceObsahu::initialTemplateDraft($this->db, $k), 'zmeneno' => date('Y-m-d H:i:s')]);
        }

        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        if (!$this->app->auth()->isAdmin()) {
            return null;
        }
        $k = $this->template();
        if ($k === null) {
            return null;
        }
        $language = $k['sablona_jazyk'];

        return [
            'radek' => $k, 'stavba' => $k['stavba'], 'koncept' => $k['stavba_koncept'], 'jazyk' => Language::ofContent($this->app->settings(), $language),
            'titulek' => t('Detail: %s', $k['nazev']) . ($language !== '' ? ' (' . strtoupper($language) . ')' : ''), 'revize' => ['cast' => KolekceObsahu::templateKey($k)],
            'parametry' => ['id' => (int) $k['idk']] + ($language !== '' ? ['jazyk' => $language] : []),
        ];
    }

    /** Kolekce se šablonou jazyka z adresy (?jazyk=de; bez něj nebo s jazykem, který web nemá, výchozí jazyk). */
    private function template(): ?array
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        $language = $this->request->get('jazyk');

        return $k === null ? null : KolekceObsahu::inLanguage($this->db, $k, in_array($language, Language::additional($this->app->settings()), true) ? $language : '');
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        KolekceObsahu::writeTemplate($this->db, $target['radek'], ['stavba_koncept' => $draft]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::collection($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $k = $target['radek'];
        $language = $k['sablona_jazyk'];
        // náhled na první položce v jazyce šablony (další jazyk má adresy /<jazyk>/…)
        $seo = $this->db->value('SELECT seo_link FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND zobrazit = 1 ORDER BY poradi, nazev LIMIT 1', [$k['idk'], $language]);
        $url = $this->app->url(($language !== '' ? $language . '/' : '') . $k['seo_link'] . '/' . ($seo ?? '_ukazka'));

        return [
            'adresa' => $url, 'nahled' => $url . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $k['detail'], 'casti' => false,
            'zpet' => ['adresa' => $this->url('polozky', ['id' => (int) $k['idk']]), 'text' => $k['nazev']], 'nastaveni' => $this->url('edit', ['id' => (int) $k['idk']]), 'textNastaveni' => t('Pole a nastavení kolekce'),
            'kolekce' => ['seo_link' => $k['seo_link'], 'nazev' => $k['nazev'], 'pole' => $k['pole'], 'detail' => (bool) $k['detail']],
            'podpis' => KolekceObsahu::templateKey($k),
        ];
    }

    private function admin(): ?Response
    {
        return $this->app->auth()->isAdmin() ? null : $this->error('Definici kolekce a šablonu detailu mění jen správce webu.', 403);
    }
}
