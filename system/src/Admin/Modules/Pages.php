<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;

/**
 * Stránky webu: úvod, O nás, Služby, Kontakt, Zásady ochrany soukromí… Úvodní stránku určuje Nastavení → Základní.
 * Stránka má adresu /<seo_link>. Obsah je buď text z editoru, nebo stavba z builderu (sloupec stavba, rozpracovaná stavba_koncept).
 */
final class Pages extends Module
{
    use \Kaleta\Admin\BuilderActions {
        akceStavitel as protected openBuilder;
    }

    public const string IDENT = 'stranky';
    public const string NAME = 'Stránky';
    public const string GROUP = 'Obsah';
    public const string ICON = 'stranky';

    /** Adresy, které patří systému a stránka je mít nemůže. */
    public const array RESERVED_SLUGS = ['novinky', 'hledani', 'news', 'search', 'mcp', 'api', 'admin', 'install', 'media', 'image', 'layout', 'system', 'storage', 'tools', 'docs', 'dist', 'rss', 'sitemap', 'robots', 'llms', 'feed', 'stav', 'ulohy', 'souhlas', 'formular', 'popup'];

    /** Stránky v koši vydrží tolik dní, pak se smažou natrvalo (jako novinky). */
    public const int TRASH_DAYS = 30;

    protected function akceVypis(): Response
    {
        $trash = $this->request->get('stav') === 'kos';
        $search = mb_substr(trim($this->request->get('hledat')), 0, 100);
        [$siteLanguages, $language, $column] = $this->readLanguageFilter();
        $where = [$trash ? 'smazano IS NOT NULL' : 'smazano IS NULL'];
        $params = [];
        if ($column !== null) {
            $where[] = 'jazyk = ?';
            $params[] = $column;
        }
        if ($search !== '') {
            $where[] = '(titulek LIKE ? OR seo_link LIKE ?)';
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $pattern, $pattern);
        }

        $pages = $this->db->all('SELECT * FROM {stranky} WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . ($trash ? 'smazano DESC' : 'jazyk, poradi, titulek'), $params);

        // sloupec „V navigaci“: s vlastním menu podle položek menu (stránka nebo odkaz na její adresu), jinak příznak v_menu
        foreach ($pages as &$s) {
            $s['v_menu'] = \Kaleta\Core\Menu::hasPage($this->db, (int) $s['ids'], (string) $s['jazyk'], (string) $s['seo_link']) ?? (bool) $s['v_menu'];
        }
        unset($s);

        return $this->view('list', 'Stránky', [
            'pages' => $trash || $search !== '' ? $pages : self::sortAsTree($pages),
            'trash' => $trash, 'search' => $search, 'siteLanguages' => $siteLanguages, 'language' => $language,
            'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NOT NULL'),
        ]);
    }

    /**
     * Stránky seřazené jako strom: podstránka hned za nadřazenou, s hloubkou (klíč „uroven“) pro odsazení ve výpisu.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private static function sortAsTree(array $pages): array
    {
        $byParent = [];
        foreach ($pages as $s) {
            $byParent[(int) ($s['nadrazena'] ?? 0)][] = $s;
        }
        $ids = array_column($pages, 'ids');
        $result = [];
        $add = function (int $parent, int $level) use (&$add, &$result, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $s) {
                $result[] = $s + ['uroven' => $level];
                if ($level < 4) {
                    $add((int) $s['ids'], $level + 1);
                }
            }
        };
        $add(0, 0);
        // stránky, jejichž nadřazená je v koši nebo neexistuje, se ukážou na nejvyšší úrovni
        foreach ($byParent as $parent => $children) {
            if ($parent !== 0 && !in_array($parent, array_map('intval', $ids), true)) {
                foreach ($children as $s) {
                    $result[] = $s + ['uroven' => 0];
                }
            }
        }

        return $result;
    }

    protected function akceNovy(): Response
    {
        return $this->form(['ids' => 0, 'seo_link' => '', 'titulek' => '', 'popis' => '', 'seo_titulek' => '', 'obrazek' => '', 'noindex' => 0, 'text' => '', 'zobrazit' => 1, 'v_menu' => 1, 'poradi' => 100, 'stavba' => null, 'stavba_koncept' => null,
            'nadrazena' => $this->request->getInt('nadrazena') ?: null, 'zverejnit_od' => null]);
    }

    protected function akceEdit(): Response
    {
        $page = $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$this->request->getInt('id')]);

        return $page === null ? $this->error('Stránka neexistuje.', 404) : $this->form($page);
    }

    /** Uložení z úpravy „přímo na webu“ (views/front/upravit.php): jen název a text stránky. */
    protected function akceUlozText(): Response
    {
        $r = $this->request;
        $page = $r->isPost() ? $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$r->postInt('id')]) : null;
        if ($page === null || (!$this->app->auth()->canPublish() && $page['zobrazit'])) {
            return $this->redirectToSite($r->post('zpet'));
        }
        $title = mb_substr($r->post('titulek'), 0, 200);
        if ($title === '') {
            return $this->redirectToSite($r->post('zpet'), '?upravit=text&chyba=1');
        }
        $text = \Kaleta\Core\Html::forUser($r->post('text'), $this->app->auth());
        if ($page['titulek'] !== $title || (string) $page['text'] !== $text) {
            $this->saveVersion((int) $page['ids'], $page['titulek'], (string) $page['text']); // úprava přímo na webu jde do historie jako v administraci
        }
        $this->db->update('stranky', ['titulek' => $title, 'text' => $text, 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $page['ids']]);
        \Kaleta\Admin\ChangeLog::write($this->app, 'stranky', 'úprava přímo na webu', mb_substr($title, 0, 80));

        return $this->redirectToSite($r->post('zpet'));
    }

    /**
     * Role na úrovni autora (i vlastní role bez práva vydávat) smí stránky připravovat, ale ne zveřejnit, měnit zveřejněné ani mazat.
     * Vrací odpověď s odmítnutím, nebo null, když smí.
     */
    private function requirePublishPermission(?array $page = null): ?Response
    {
        if ($this->app->auth()->canPublish() || ($page !== null && !$page['zobrazit'])) {
            return null;
        }

        return $this->back('Zveřejněné stránky upravuje, zveřejňuje a maže jen editor nebo správce. Můžete připravit novou skrytou stránku.', '', [], 'chyba');
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('ids');
        if ($id > 0 && ($refusal = $this->requirePublishPermission($this->db->one('SELECT zobrazit FROM {stranky} WHERE ids = ?', [$id]) ?? ['zobrazit' => 1])) !== null) {
            return $refusal;
        }
        $language = \Kaleta\Core\Language::column($this->app->settings(), $r->post('jazyk'));
        // nadřazená stránka: stejný jazyk, ne ona sama ani její podstránka (jinak by vznikl kruh)
        $custom = $id > 0 ? (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$id]) : '';
        $parent = $r->postInt('nadrazena') > 0 ? $this->db->one('SELECT ids, seo_link FROM {stranky} WHERE ids = ? AND ids <> ? AND jazyk = ? AND smazano IS NULL', [$r->postInt('nadrazena'), $id, $language]) : null;
        if ($parent !== null && $custom !== '' && str_starts_with($parent['seo_link'] . '/', $custom . '/')) {
            $parent = null;
        }
        $prefix = $parent !== null ? $parent['seo_link'] . '/' : '';
        $slug = slugify($r->post('seo_link') !== '' ? basename(str_replace('\\', '/', $r->post('seo_link'))) : $r->post('titulek'), max(20, 118 - strlen($prefix)));
        $data = [
            'titulek' => mb_substr($r->post('titulek'), 0, 200),
            'seo_link' => $prefix . $slug,
            'nadrazena' => $parent !== null ? (int) $parent['ids'] : null,
            'popis' => mb_substr($r->post('popis'), 0, 300),
            'seo_titulek' => mb_substr(trim($r->post('seo_titulek')), 0, 200),
            'obrazek' => mb_substr(trim($r->post('obrazek')), 0, 255),
            'noindex' => (int) $r->postBool('noindex'),
            'text' => \Kaleta\Core\Html::forUser($r->post('text'), $this->app->auth()),
            'zobrazit' => (int) $r->postBool('zobrazit'),
            'v_menu' => (int) $r->postBool('v_menu'),
            'poradi' => max(0, min(65535, $r->postInt('poradi', 100))),
            'zmeneno' => date('Y-m-d H:i:s'),
            'jazyk' => $language,
        ];
        // plánované zveřejnění: jen u skryté stránky s budoucím časem; prošlý čas stránku rovnou zveřejní
        $from = strtotime(str_replace('T', ' ', $r->post('zverejnit_od'))) ?: null;
        $data['zverejnit_od'] = !$data['zobrazit'] && $from !== null && $from > time() ? date('Y-m-d H:i:s', $from) : null;
        if (!$data['zobrazit'] && $from !== null && $from <= time()) {
            $data['zobrazit'] = 1;
        }
        if (!$this->app->auth()->canPublish()) {
            $data['zobrazit'] = 0; // bez práva vydávat zůstává stránka skrytá, zveřejní ji editor
            $data['zverejnit_od'] = null;
        }
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = '' AND ids <> ?", [$r->postInt('preklad_z'), $id]) ?: null);
        $errors = [];
        if ($data['titulek'] === '') {
            $errors['titulek'] = 'Vyplňte název stránky.';
        }
        if ($r->post('seo_link') === '') {
            // adresa z názvu: obsazená dostane číslo (o-nas-2), jako u novinek
            $data['seo_link'] = $this->availableSlug($data['seo_link'], $id);
        }
        if ($parent === null && (in_array($data['seo_link'], self::RESERVED_SLUGS, true) || isset(\Kaleta\Core\Language::AVAILABLE[$data['seo_link']]))) {
            $errors['seo_link'] = 'Tuto adresu používá systém, zvolte jinou.';
        } elseif (($other = $this->db->one('SELECT ids, smazano FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$data['seo_link'], $id])) !== null) {
            $errors['seo_link'] = $other['smazano'] !== null ? 'Tuto adresu má stránka v koši – obnovte ji, nebo ji smažte natrvalo.' : 'Stránka s touto adresou už existuje.';
        }
        if ($id > 0 && $id === $this->app->settings()->int('titulni_stranka') && !$data['zobrazit']) {
            $errors['zobrazit'] = 'Úvodní stránku nejde skrýt. Nejdřív v Nastavení → Základní vyberte jinou úvodní stránku.';
        }
        if ($errors !== []) {
            $previous = $id > 0 ? $this->db->one('SELECT stavba, stavba_koncept FROM {stranky} WHERE ids = ?', [$id]) : null;

            return $this->form(['ids' => $id] + $data + ($previous ?? ['stavba' => null, 'stavba_koncept' => null]), $errors);
        }
        if ($id > 0) {
            $previous = $this->db->one('SELECT seo_link, zobrazit, titulek, text FROM {stranky} WHERE ids = ?', [$id]);
            if ($previous !== null && ($previous['text'] !== $data['text'] || $previous['titulek'] !== $data['titulek'])) {
                $this->saveVersion($id, $previous['titulek'], (string) $previous['text']);
            }
            $this->db->update('stranky', $data, ['ids' => $id]);
            if ($previous !== null && $previous['seo_link'] !== $data['seo_link']) {
                $this->moveSubpages($previous['seo_link'], $data['seo_link'], (bool) $previous['zobrazit']);
            }
        } else {
            $id = $this->db->insert('stranky', $data);
            $template = \Kaleta\Builder\Library::PAGE_TEMPLATES[$r->post('sablona')] ?? null;
            if ($template !== null && $template[1] !== []) {
                // nová stránka podle šablony: sekce z knihovny jako koncept a rovnou do builderu
                $build = \Kaleta\Builder\Library::page($this->db, $template[1], $data['titulek'], $this->contentLanguage($language));
                $this->db->update('stranky', ['stavba_koncept' => Build::toJson($build)], ['ids' => $id]);
                \Kaleta\Core\Menu::setPage($this->db, $id, $language, (bool) $data['v_menu']);

                return \Kaleta\Core\Response::redirect($this->url('stavitel', ['id' => $id]));
            }
            if ($template !== null && $data['text'] === '') {
                $this->db->update('stranky', ['text' => \Kaleta\Builder\Library::privacyPolicyText()], ['ids' => $id]);

                return $this->back('Stránka je založená s kostrou zásad – doplňte údaje v hranatých závorkách.', 'edit', ['id' => $id]);
            }
        }
        // sestavené menu (Vzhled → Menu): zaškrtávátko „v navigaci“ stránku do menu přidá nebo z něj odebere
        \Kaleta\Core\Menu::setPage($this->db, $id, $data['jazyk'], (bool) $data['v_menu']);
        if ($r->post('po_ulozeni') === 'stavitel') {
            return \Kaleta\Core\Response::redirect($this->url('stavitel', ['id' => $id]));
        }

        return $this->back('Stránka byla uložena.');
    }

    /* ---------- builder (akce v Admin\StavitelAkce) ---------- */

    /** Editor; textová stránka se při prvním otevření převede na stavbu (úzká sekce s nadpisem a textem, text zůstane). */
    protected function akceStavitel(): Response
    {
        $page = $this->loadPage($this->request->getInt('id'));
        if ($page !== null && $page['stavba'] === null && $page['stavba_koncept'] === null) {
            $this->db->update('stranky', ['stavba_koncept' => Build::toJson(Build::fromText($page['titulek'], (string) $page['text']))], ['ids' => $page['ids']]);
        }

        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        $page = $this->loadPage($this->request->getInt('id'));

        return $page === null ? null : [
            'radek' => $page, 'stavba' => $page['stavba'], 'koncept' => $page['stavba_koncept'], 'jazyk' => $this->contentLanguage($page['jazyk']),
            'titulek' => $page['titulek'], 'revize' => ['ids' => (int) $page['ids']], 'parametry' => ['id' => (int) $page['ids']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('stranky', ['stavba_koncept' => $draft], ['ids' => $target['radek']['ids']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::page($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $page = $target['radek'];
        $home = $this->app->settings()->int('titulni_stranka') === (int) $page['ids'];
        $url = $this->app->url(($page['jazyk'] !== '' ? $page['jazyk'] . '/' : '') . ($home ? '' : $page['seo_link']));

        return [
            'adresa' => $url, 'nahled' => $url . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $page['zobrazit'], 'casti' => false, 'nadpisy' => true,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Stránky')], 'nastaveni' => $this->url('edit', ['id' => (int) $page['ids']]),
            'podpis' => 'stranka:' . (int) $page['ids'],
        ];
    }

    /** Publikuje koncept stránky (i z MCP). */
    public static function publish(\Kaleta\Core\App $app, array $page): void
    {
        Publisher::page($app, $page);
    }

    /** Stránka se vrátí k textu z editoru (stavba zůstane ve verzích). */
    protected function akceStavbaText(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->request->postInt('ids')) : null;
        if ($page !== null && $page['stavba'] !== null) {
            $this->db->insert('stavba_revize', ['ids' => $page['ids'], 'datum' => date('Y-m-d H:i:s'), 'kdo' => $this->app->auth()->id(), 'stavba' => $page['stavba']]);
            $this->db->update('stranky', ['stavba' => null, 'stavba_koncept' => null], ['ids' => $page['ids']]);
        }

        return $this->back('Stránka zobrazuje text z editoru (obsah stavby bez rozložení). Stavbu najdete ve verzích, když otevřete builder.', 'edit', ['id' => (int) ($page['ids'] ?? 0)]);
    }

    /** @return array<string, mixed>|null */
    private function loadPage(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]);
    }

    /** Předchozí podoba textu stránky do historie (posledních 30 verzí). */
    private function saveVersion(int $ids, string $title, string $text): void
    {
        self::version($this->db, $ids, $this->app->auth()->id(), $title, $text);
    }

    /** Totéž pro MCP a jiné vstupy mimo modul. */
    public static function version(\Kaleta\Core\Db $db, int $ids, int $who, string $title, string $text): void
    {
        $db->insert('stranky_revize', ['ids' => $ids, 'datum' => date('Y-m-d H:i:s'), 'kdo' => $who, 'titulek' => $title, 'text' => $text]);
        $db->run('DELETE FROM {stranky_revize} WHERE ids = ? AND idr NOT IN (SELECT idr FROM (SELECT idr FROM {stranky_revize} WHERE ids = ? ORDER BY idr DESC LIMIT 30) t)', [$ids, $ids]);
    }

    /** Stránka změnila adresu: podstránky se posunou s ní a staré adresy zobrazených stránek se přesměrují. */
    private function moveSubpages(string $old, string $newVersion, bool $visible): void
    {
        self::move($this->db, $old, $newVersion, $visible);
    }

    public static function move(\Kaleta\Core\Db $db, string $old, string $newVersion, bool $visible): void
    {
        if ($visible) {
            Redirects::add($db, $old, $newVersion);
        }
        foreach ($db->all('SELECT ids, seo_link, zobrazit FROM {stranky} WHERE seo_link LIKE ?', [addcslashes($old, '%_\\') . '/%']) as $p) {
            $target = $newVersion . substr($p['seo_link'], strlen($old));
            $db->update('stranky', ['seo_link' => $target], ['ids' => $p['ids']]);
            if ($p['zobrazit']) {
                Redirects::add($db, $p['seo_link'], $target);
            }
        }
    }

    /** Obnovení starší verze textu stránky (současná podoba jde do historie). */
    protected function akceObnovVerzi(): Response
    {
        $version = $this->request->isPost() ? $this->db->one('SELECT * FROM {stranky_revize} WHERE idr = ?', [$this->request->postInt('idr')]) : null;
        $page = $version !== null ? $this->loadPage((int) $version['ids']) : null;
        if ($page === null) {
            return $this->back();
        }
        if (($refusal = $this->requirePublishPermission($page)) !== null) {
            return $refusal;
        }
        $this->saveVersion((int) $page['ids'], $page['titulek'], (string) $page['text']);
        $this->db->update('stranky', ['titulek' => $version['titulek'], 'text' => $version['text'], 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $page['ids']]);

        return $this->back(t('Obnovena verze z %s.', format_date($version['datum'], true)), 'edit', ['id' => (int) $page['ids']]);
    }

    /** Stránka jako soubor JSON (název, popis a stavba) – pro přenos na jiný web s Kaletou. */
    protected function akceExport(): Response
    {
        $s = $this->loadPage($this->request->getInt('id'));
        if ($s === null) {
            return $this->error('Stránka neexistuje.', 404);
        }
        $json = (string) json_encode(['format' => 'kaleta-stranka', 'verze' => 1, 'titulek' => $s['titulek'], 'popis' => $s['popis'], 'text' => $s['text'],
            'stavba' => Build::fromJson($s['stavba_koncept'] ?? $s['stavba'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="stranka-' . basename(str_replace('/', '-', $s['seo_link'])) . '.json"']);
    }

    /** Import stránky z JSON exportu: vznikne skrytá stránka, stavba projde validátorem jako každá jiná. */
    protected function akceImport(): Response
    {
        $file = $_FILES['soubor']['tmp_name'] ?? '';
        $data = $this->request->isPost() && is_uploaded_file($file) && filesize($file) < 5_000_000 ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || ($data['format'] ?? '') !== 'kaleta-stranka' || trim((string) ($data['titulek'] ?? '')) === '') {
            return $this->back('Soubor není export stránky.', '', [], 'chyba');
        }
        $title = mb_substr(trim((string) $data['titulek']), 0, 200);
        $record = ['titulek' => $title, 'seo_link' => $this->availableSlug(slugify($title, 110), 0), 'popis' => mb_substr((string) ($data['popis'] ?? ''), 0, 300),
            'text' => \Kaleta\Core\WpContent::safeHtml((string) ($data['text'] ?? '')), 'zobrazit' => 0, 'v_menu' => 0, 'zmeneno' => date('Y-m-d H:i:s')];
        if (is_array($data['stavba'] ?? null)) {
            [$build, $errors] = Build::sanitize($data['stavba'], $this->app->auth()->isAdmin());
            $record['stavba_koncept'] = Build::toJson($build);
        }
        $id = $this->db->insert('stranky', $record);

        return $this->back('Stránka je importovaná jako skrytá – zkontrolujte ji a zveřejněte.', 'edit', ['id' => $id]);
    }

    /** Volná adresa odvozená z $zaklad: o-nas, o-nas-2, o-nas-3… */
    private function availableSlug(string $base, int $id): string
    {
        return \Kaleta\Core\Slug::makeUnique($base, fn (string $a): bool => $this->db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$a, $id]) !== null, 120);
    }

    /** Smazání = přesun do koše: stránka zmizí z webu, adresa zůstane rezervovaná a jde ji obnovit. */
    protected function akceSmaz(): Response
    {
        $ids = $this->request->postInt('ids');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (($refusal = $this->requirePublishPermission()) !== null) {
            return $refusal;
        }
        if ($ids === $this->app->settings()->int('titulni_stranka')) {
            return $this->back('Úvodní stránku nejde smazat. Nejdřív v Nastavení → Základní vyberte jinou úvodní stránku.', '', [], 'chyba');
        }
        $this->db->run('UPDATE {stranky} SET smazano = NOW(), zobrazit = 0 WHERE ids = ? AND smazano IS NULL', [$ids]);

        return $this->back(t('Stránka je v koši. Obnovit ji můžete %d dní.', self::TRASH_DAYS));
    }

    /** Obnovení z koše: stránka se vrátí skrytá, zveřejní ji až uživatel. */
    protected function akceObnov(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('UPDATE {stranky} SET smazano = NULL WHERE ids = ?', [$this->request->postInt('ids')]);
        }

        return $this->back('Stránka je obnovená jako skrytá – zveřejníte ji v jejím nastavení.');
    }

    protected function akceSmazNatrvalo(): Response
    {
        if (($refusal = $this->requirePublishPermission()) !== null) {
            return $refusal;
        }
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {stranky} WHERE ids = ? AND smazano IS NOT NULL', [$this->request->postInt('ids')]);
        }

        return $this->back('Stránka byla smazána natrvalo.', '', ['stav' => 'kos']);
    }

    /** Stránky v koši déle než DNY_V_KOSI se smažou natrvalo (volá Admin\Kernel). */
    public static function emptyTrash(\Kaleta\Core\Db $db): int
    {
        return $db->run('DELETE FROM {stranky} WHERE smazano < NOW() - INTERVAL ' . self::TRASH_DAYS . ' DAY')->rowCount();
    }

    /** Kopie stránky i se stavbou a rozpracovaným konceptem – skrytá, s volnou adresou. */
    protected function akceDuplikuj(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->request->postInt('ids')) : null;
        if ($page === null) {
            return $this->back();
        }
        $copy = array_diff_key($page, ['ids' => 0, 'smazano' => 0]);
        $copy['titulek'] = mb_substr(t('%s (kopie)', $page['titulek']), 0, 200);
        $copy['seo_link'] = $this->availableSlug(mb_substr($page['seo_link'] . '-kopie', 0, 110), 0);
        $copy['zobrazit'] = 0;
        $copy['v_menu'] = 0; // kopie se do navigace nedostane, dokud ji tam někdo nezařadí
        $copy['preklad_z'] = null;
        $copy['zmeneno'] = date('Y-m-d H:i:s');
        $id = $this->db->insert('stranky', $copy);

        return $this->back('Kopie stránky je skrytá – upravte ji a zveřejněte.', 'edit', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, string> $errors
     */
    private function form(array $page, array $errors = []): Response
    {
        $language = (string) ($page['jazyk'] ?? '');
        $custom = (string) ($page['seo_link'] ?? '');

        return $this->view('form', $page['ids'] ? 'Úprava stránky' : 'Nová stránka', [
            'page' => $page, 'errors' => $errors,
            // možné nadřazené stránky: stejný jazyk, ne ona sama ani její podstránky
            'parents' => array_values(array_filter($this->db->all('SELECT ids, titulek, seo_link FROM {stranky} WHERE jazyk = ? AND smazano IS NULL AND ids <> ? ORDER BY seo_link', [$language, (int) $page['ids']]),
                fn (array $s): bool => $custom === '' || !str_starts_with($s['seo_link'] . '/', $custom . '/'))),
            'versions' => $page['ids'] ? $this->db->all('SELECT r.idr, r.datum, r.titulek, IF(u.jmeno = \'\', u.user, u.jmeno) AS kdo FROM {stranky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.ids = ? ORDER BY r.idr DESC LIMIT 30', [(int) $page['ids']]) : [],
            'home' => $page['ids'] > 0 && (int) $page['ids'] === $this->app->settings()->int('titulni_stranka'),
            'inMenu' => $page['ids'] > 0 ? \Kaleta\Core\Menu::hasPage($this->db, (int) $page['ids'], (string) ($page['jazyk'] ?? '')) : null,
            'customMenu' => \Kaleta\Core\Menu::load($this->db, 'hlavni', (string) ($page['jazyk'] ?? '')) !== null,
        ]);
    }
}
