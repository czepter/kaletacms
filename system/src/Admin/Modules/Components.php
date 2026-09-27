<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\Components as KomponentyStavby;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;

/**
 * Komponenty – znovupoužitelné bloky (karta služby, blok s kontaktem, výzva…). Vznikají v builderu tlačítkem
 * „Uložit jako komponentu“ nebo tady; upravují se v builderu a změna se projeví všude, kde jsou použité.
 */
final class Components extends Module
{
    use BuilderActions {
        akceStavitel as protected openBuilder;
    }

    public const string IDENT = 'komponenty';
    public const string NAME = 'Komponenty';
    public const string GROUP = 'Vzhled';
    public const string ICON = 'komponenta';
    public const bool ADMIN_ONLY = true;

    protected function akceVypis(): Response
    {
        $components = KomponentyStavby::all($this->db);
        foreach ($components as &$k) {
            $k['mista'] = $this->usages((int) $k['idm']);
            $k['pouziti'] = count($k['mista']);
        }
        unset($k);

        return $this->view('vypis', 'Komponenty', ['komponenty' => $components]);
    }

    protected function akceNovy(): Response
    {
        return $this->view('formular', 'Nová komponenta', ['k' => ['idm' => 0, 'nazev' => '', 'vlastnosti' => []]]);
    }

    protected function akceEdit(): Response
    {
        $k = KomponentyStavby::byId($this->db, $this->request->getInt('id'));

        return $k === null ? $this->error('Komponenta neexistuje.', 404) : $this->view('formular', $k['nazev'], ['k' => $k]);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('idm');
        $name = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if ($name === '') {
            return $this->back('Komponenta musí mít název.', $id > 0 ? 'edit' : 'novy', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        $data = ['nazev' => $name, 'vlastnosti' => (string) json_encode(KomponentyStavby::sanitizeProperties(is_array($_POST['vlastnosti'] ?? null) ? $_POST['vlastnosti'] : []), JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')];
        if ($id > 0 && KomponentyStavby::byId($this->db, $id) !== null) {
            $this->db->update('komponenty', $data, ['idm' => $id]);
        } else {
            $id = $this->db->insert('komponenty', $data + ['stavba_koncept' => Build::toJson(['v' => Build::VERSION, 'deti' => [Build::fresh('sekce')]])]);
        }
        \Kaleta\Front\Cache::clear();

        return $this->back('Komponenta byla uložena.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('komponenty', ['idm' => $this->request->postInt('idm')]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('Komponenta byla smazána. Místa, kde byla použitá, zůstanou prázdná.');
    }

    /** Z builderu: vybraný prvek se stane komponentou (JSON). Editor ho pak nahradí jejím použitím. */
    protected function akceZPrvku(): Response
    {
        $element = $this->request->isPost() ? json_decode((string) ($_POST['prvek'] ?? ''), true) : null;
        $name = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if (!is_array($element) || $name === '') {
            return Response::json(['ok' => false, 'chyba' => t('Chybí název nebo prvek.')], 400);
        }
        [$build] = Build::sanitize(['v' => Build::VERSION, 'deti' => [$element]], true);
        if ($build['deti'] === []) {
            return Response::json(['ok' => false, 'chyba' => t('Z tohoto prvku komponenta vzniknout nemůže.')], 400);
        }
        $id = $this->db->insert('komponenty', ['nazev' => $name, 'vlastnosti' => '[]', 'stavba' => Build::toJson($build), 'zmeneno' => date('Y-m-d H:i:s')]);

        return Response::json(['ok' => true, 'id' => $id, 'komponenty' => self::listForEditor($this->db)]);
    }

    /** @return list<array{id: int, nazev: string, vlastnosti: list<array<string, string>>}> */
    public static function listForEditor(\Kaleta\Core\Db $db): array
    {
        return array_map(fn (array $k): array => ['id' => (int) $k['idm'], 'nazev' => $k['nazev'], 'vlastnosti' => $k['vlastnosti']], KomponentyStavby::all($db));
    }

    /* ---------- úprava v builderu ---------- */

    protected function akceStavitel(): Response
    {
        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        $k = KomponentyStavby::byId($this->db, $this->request->getInt('id'));

        return $k === null ? null : [
            'radek' => $k, 'stavba' => $k['stavba'], 'koncept' => $k['stavba_koncept'], 'jazyk' => Language::defaults($this->app->settings()),
            'titulek' => t('Komponenta: %s', $k['nazev']), 'revize' => ['cast' => 'komponenta:' . (int) $k['idm']], 'parametry' => ['id' => (int) $k['idm']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('komponenty', ['stavba_koncept' => $draft], ['idm' => $target['radek']['idm']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::component($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $k = $target['radek'];
        $url = $this->app->url('_komponenta/' . (int) $k['idm']);

        return [
            'adresa' => $url . '?stavba=koncept', 'nahled' => $url . '?stavba=koncept&editor=1', 'zobrazena' => true, 'casti' => false,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Komponenty')], 'nastaveni' => $this->url('edit', ['id' => (int) $k['idm']]),
            // nápověda {{vlastností}} v editoru (stejná jako u kolekce, jen bez vestavěných hodnot)
            'kolekce' => ['seo_link' => '', 'nazev' => $k['nazev'], 'pole' => $k['vlastnosti'], 'detail' => false, 'vestavene' => false],
        ];
    }

    /**
     * Kde je komponenta použitá (stránky, části webu, kolekce, jiné komponenty) – názvy pro výpis a potvrzení smazání.
     *
     * @return list<string>
     */
    private function usages(int $idm): array
    {
        $pattern = '%"typ":"komponenta"%"komponenta":"' . $idm . '"%';
        $whereParts = ' WHERE (stavba LIKE ? OR stavba_koncept LIKE ?)';
        $usages = [];
        foreach ($this->db->all('SELECT titulek, smazano IS NOT NULL AS kos FROM {stranky}' . $whereParts . ' ORDER BY smazano IS NOT NULL, titulek', [$pattern, $pattern]) as $r) {
            $usages[] = t('stránka „%s“', $r['titulek']) . ($r['kos'] ? ' (' . t('v koši') . ')' : '');
        }
        foreach ($this->db->all('SELECT typ, jazyk, nazev FROM {casti}' . $whereParts . ' ORDER BY typ, jazyk, varianta', [$pattern, $pattern]) as $r) {
            $usages[] = mb_strtolower(t(\Kaleta\Builder\SiteParts::TYPES[$r['typ']][0] ?? $r['typ'])) . ($r['nazev'] !== '' ? ' „' . $r['nazev'] . '“' : '') . ($r['jazyk'] !== '' ? ' (' . $r['jazyk'] . ')' : '');
        }
        foreach ($this->db->all('SELECT nazev FROM {kolekce}' . $whereParts . ' ORDER BY nazev', [$pattern, $pattern]) as $r) {
            $usages[] = t('detail kolekce „%s“', $r['nazev']);
        }
        // komponenta sama v sobě se nepočítá (a na webu se ani nevykreslí)
        foreach ($this->db->all('SELECT nazev FROM {komponenty}' . $whereParts . ' AND idm <> ? ORDER BY nazev', [$pattern, $pattern, $idm]) as $r) {
            $usages[] = t('komponenta „%s“', $r['nazev']);
        }

        return $usages;
    }
}
