<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Language;
use Kaleta\Core\Preview;
use Kaleta\Core\Response;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\Style;

/**
 * Akce builderu společné pro stránky (Moduly\Stranky) a části webu (Moduly\Casti): editor, průběžné ukládání konceptu,
 * publikování, zahození změn, verze, sekce z knihovny a sdílené třídy. Modul dodá, co je „cíl“ stavby a jak ho uložit.
 *
 * Cíl: ['radek' => řádek z databáze, 'stavba' => ?string, 'koncept' => ?string, 'jazyk' => kód obsahu, 'titulek' => string,
 *       'revize' => ['ids' => …] | ['cast' => …], 'parametry' => parametry adres akcí (id nebo typ a jazyk)]
 */
trait BuilderActions
{
    /** @return array<string, mixed>|null cíl z parametrů požadavku */
    abstract protected function loadBuildTarget(): ?array;

    /** Uloží rozpracovaný koncept (null = zahodit). */
    abstract protected function saveDraft(array $target, ?string $draft): void;

    abstract protected function publishTarget(array $target): void;

    /**
     * Údaje pro editor: titulek, adresa (veřejná), nahled (plátno), zobrazena, casti (nabízet prvky částí), zpet [adresa, text],
     * nastaveni (adresa nastavení cíle, nebo null), podpis (cíl podepsaného náhledu, např. „stranka:12“ – Core\Nahled).
     *
     * @return array<string, mixed>
     */
    abstract protected function describeTarget(array $target): array;

    /** Editor stavby na celou obrazovku: plátno se skutečnou stránkou webu, strom, vlastnosti. */
    protected function akceStavitel(): Response
    {
        $target = $this->loadBuildTarget();
        if ($target === null) {
            return $this->error('Stránka neexistuje.', 404);
        }
        $app = $this->app;
        $e = $this->describeTarget($target);
        $collection = array_map(fn (array $k): array => ['seo_link' => $k['seo_link'], 'nazev' => $k['nazev'], 'pole' => $k['pole'], 'detail' => (bool) $k['detail']], Collections::all($this->db));
        $extensions = \Kaleta\Core\Extensions::enabled($app->settings());
        $schema = Build::schema($app->auth()->isAdmin(), $target['jazyk'], $e['casti'], $extensions);
        $components = \Kaleta\Admin\Modules\Components::listForEditor($this->db);
        foreach ($schema['prvky'] as &$element) {
            if ($element['typ'] === 'komponenta') {
                $element['vlastnosti']['komponenta'] = ['typ' => 'vyber', 'popisek' => 'Komponenta', 'vychozi' => '',
                    'moznosti' => ['' => '—'] + array_column(array_map(fn (array $k): array => ['id' => (string) $k['id'], 'nazev' => $k['nazev']], $components), 'nazev', 'id')];
            }
            if ($element['typ'] === 'kolekce') {
                // v editoru výběr z kolekcí webu (validátor bere adresu kolekce jako text)
                $element['vlastnosti']['kolekce'] = ['typ' => 'vyber', 'popisek' => 'Kolekce', 'vychozi' => $collection[0]['seo_link'] ?? '',
                    'moznosti' => ['' => '—'] + array_column($collection, 'nazev', 'seo_link')];
            }
        }
        unset($element);
        $schema = self::translateSchema($schema);
        Library::createClasses($this->db, ['karta']); // vzor karty ve Výpisu kolekce
        $data = [
            'stranka' => ['titulek' => $target['titulek'], 'adresa' => $e['adresa'], 'zobrazena' => $e['zobrazena'], 'publikovana' => $target['stavba'] !== null, 'smiPublikovat' => $app->auth()->canPublish(),
                'nadpisy' => (bool) ($e['nadpisy'] ?? false)], // kontrola před publikováním: stránka má mít jeden h1 a nepřeskakovat úrovně
            'stavba' => Build::fromJson($target['koncept'] ?? $target['stavba']),
            'zmeny' => $target['koncept'] !== null && $target['koncept'] !== $target['stavba'],
            'verze' => self::computeBuildVersion($target['koncept'] ?? $target['stavba']),
            'schema' => $schema,
            'kolekce' => $collection,
            'komponenty' => $components,
            'ai' => (new \Kaleta\Core\Assistant($app->settings()))->isReady(),
            'kolekceDetailu' => $e['kolekce'] ?? null,
            'knihovna' => Library::listAll($extensions),
            'kategorieKnihovny' => array_map(fn (string $k): string => t($k), Library::CATEGORIES),
            'tridy' => $this->loadBuilderClasses(),
            'mojeSekce' => self::listMySections($this->db),
            'barvy' => DesignSystem::load($app->settings())['barvy'],
            // nabídka pro pole odkazu: stránky webu (s jazykovou předponou) a novinky; kotvy na stránce doplní editor
            'odkazy' => [...array_map(fn (array $s): array => ['/' . ($s['jazyk'] !== '' ? $s['jazyk'] . '/' : '') . ((int) $s['ids'] === $app->settings()->int('titulni_stranka') ? '' : $s['seo_link']), $s['titulek'] . ($s['zobrazit'] ? '' : ' (' . t('skrytá') . ')')],
                $this->db->all('SELECT ids, titulek, seo_link, jazyk, zobrazit FROM {stranky} WHERE smazano IS NULL ORDER BY jazyk, poradi, titulek LIMIT 300')), ['/' . \Kaleta\Core\Routes::publicPath('novinky', \Kaleta\Core\Language::defaults($app->settings()), $this->db), t('Novinky')]],
            'nahled' => $e['nahled'],
            'textNastaveni' => $e['textNastaveni'] ?? null, // popisek odkazu na nastavení cíle (jinak „Nastavení stránky“)
            'zpet' => $e['zpet'],
            'adresy' => array_map(fn (string $action): string => $this->url($action, $target['parametry']), [
                'uloz' => 'stavba_uloz', 'publikuj' => 'stavba_publikuj', 'zahod' => 'stavba_zahod', 'sekce' => 'stavba_sekce', 'trida' => 'stavba_trida',
                'revize' => 'stavba_revize', 'obnov' => 'stavba_obnov', 'aiSekce' => 'stavba_ai_sekce', 'aiText' => 'stavba_ai_text', 'ulozSekci' => 'stavba_uloz_sekci',
                'sdilet' => 'stavba_sdilet',
            ]) + ['smazSekci' => $app->auth()->isAdmin() ? $this->url('stavba_smaz_sekci', $target['parametry']) : null] + ['admin' => $app->url('admin.php'), 'nastaveni' => $e['nastaveni'],
                'komponenta' => $app->auth()->isAdmin() ? $app->url('admin.php?modul=komponenty&akce=z_prvku') : null,
                'nahledSekce' => $app->url('_sekce/')],
        ];

        return Response::html($app->view->render('admin/stranky/stavitel', ['app' => $app, 'data' => $data, 'titulek' => $target['titulek']]));
    }

    /** Průběžné ukládání konceptu z editoru (JSON). Vrací vyčištěnou stavbu a chyby, které editor ukáže. */
    protected function akceStavbaUloz(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'chyba' => t('Stránka neexistuje.')], 404);
        }
        $input = json_decode((string) ($_POST['stavba'] ?? ''), true);
        if (!is_array($input)) {
            return Response::json(['ok' => false, 'chyba' => t('Stavba nemá platný tvar JSON.')], 400);
        }
        if (($conflict = $this->checkVersionConflict($target)) !== null) {
            return $conflict;
        }
        [$build, $errors] = Build::sanitize($input, $this->app->auth()->isAdmin(), Build::fromJson($target['koncept'] ?? $target['stavba']));
        $json = Build::toJson($build);
        $this->saveDraft($target, $json);

        return Response::json(['ok' => true, 'stavba' => $build, 'chyby' => $errors, 'zmeny' => $json !== $target['stavba'], 'verze' => self::computeBuildVersion($json)]);
    }

    /** Publikování: koncept se stane stavbou; předchozí publikovaná verze jde do historie. */
    protected function akceStavbaPublikuj(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null || ($target['koncept'] ?? $target['stavba']) === null) {
            return Response::json(['ok' => false, 'chyba' => t('Není co publikovat.')], 400);
        }
        if (!$this->app->auth()->canPublish()) {
            return Response::json(['ok' => false, 'chyba' => t('Publikovat smí jen editor nebo správce. Změny zůstávají uložené jako koncept.')], 403);
        }
        // publikuje se jen to, co editor naposledy uložil – ne starší koncept, ani cizí rozpracované změny
        if (($conflict = $this->checkVersionConflict($target)) !== null) {
            return $conflict;
        }
        $this->publishTarget($target);
        ChangeLog::write($this->app, static::IDENT, 'publikování stavby', mb_substr($target['titulek'], 0, 80));

        return Response::json(['ok' => true]);
    }

    /**
     * Odkaz na náhled konceptu pro kolegu nebo klienta: otevře ho kdokoli bez přihlášení, platí jen pro tenhle cíl a zadaný
     * počet dní (1–7). Ukazuje koncept v okamžiku otevření, ne stav při vytvoření odkazu; vyhledávače ho neindexují.
     */
    protected function akceStavbaSdilet(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'chyba' => t('Stránka neexistuje.')], 404);
        }
        $e = $this->describeTarget($target);
        $days = max(1, min(7, $this->request->postInt('dni', 7)));
        $key = Preview::key($this->db, $this->app->settings(), $e['podpis'], $days * 24 * 60);
        $url = str_replace('&editor=1', '', $e['nahled']);
        ChangeLog::write($this->app, static::IDENT, 'sdílení náhledu', mb_substr($target['titulek'], 0, 80) . ' (' . $days . ' d)');

        return Response::json(['ok' => true, 'odkaz' => $this->request->origin() . $url . '&nahled_klic=' . $key, 'plati_do' => time() + $days * 86400]);
    }

    /** Zahodí rozpracované změny: editor se vrátí k publikované stavbě. */
    protected function akceStavbaZahod(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null || $target['stavba'] === null) {
            return Response::json(['ok' => false, 'chyba' => t('Zatím není publikovaná verze – není k čemu se vrátit.')], 400);
        }
        $this->saveDraft($target, null);

        return Response::json(['ok' => true, 'stavba' => Build::fromJson($target['stavba']), 'verze' => self::computeBuildVersion($target['stavba'])]);
    }

    /** Sekce z knihovny jako nové prvky (JSON) v jazyce cíle; chybějící třídy, které používá, se založí. */
    protected function akceStavbaSekce(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $section = $target !== null ? Library::section($this->request->get('klic'), $target['jazyk']) : null;
        if ($section === null) {
            return Response::json(['ok' => false, 'chyba' => t('Sekce v knihovně není.')], 404);
        }
        Library::createClasses($this->db, $section['tridy']);

        return Response::json(['ok' => true, 'prvek' => $section['prvek'], 'tridy' => $this->loadBuilderClasses()]);
    }

    /** @return list<array{id:int, nazev:string, prvek:array<string, mixed>}> vlastní sekce webu (panel Přidat → Moje sekce) */
    public static function listMySections(\Kaleta\Core\Db $db): array
    {
        return array_values(array_filter(array_map(fn (array $r): ?array => is_array($p = json_decode((string) $r['prvek'], true)) ? ['id' => (int) $r['idx'], 'nazev' => $r['nazev'], 'prvek' => $p] : null,
            $db->all('SELECT idx, nazev, prvek FROM {sekce} ORDER BY nazev LIMIT 200'))));
    }

    /** Uloží vybraný prvek do vlastní knihovny sekcí (projde validátorem jako každá stavba). */
    protected function akceStavbaUlozSekci(): Response
    {
        $name = mb_substr(trim($this->request->post('nazev')), 0, 100);
        $element = json_decode((string) ($_POST['prvek'] ?? ''), true);
        if (!$this->request->isPost() || $name === '' || !is_array($element)) {
            return Response::json(['ok' => false, 'chyba' => t('Sekce potřebuje název.')], 400);
        }
        [$build] = Build::sanitize(['deti' => [$element]], $this->app->auth()->isAdmin());
        if (($build['deti'][0] ?? null) === null) {
            return Response::json(['ok' => false, 'chyba' => t('Prvek se nepodařilo uložit.')], 400);
        }
        $this->db->insert('sekce', ['nazev' => $name, 'prvek' => (string) json_encode($build['deti'][0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'zmeneno' => date('Y-m-d H:i:s')]);
        ChangeLog::write($this->app, static::IDENT, 'uložení sekce do knihovny', $name);

        return Response::json(['ok' => true, 'sekce' => self::listMySections($this->db)]);
    }

    protected function akceStavbaSmazSekci(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return Response::json(['ok' => false, 'chyba' => t('Sekce smí odebrat jen správce.')], 403);
        }
        $this->db->delete('sekce', ['idx' => $this->request->postInt('idx')]);

        return Response::json(['ok' => true, 'sekce' => self::listMySections($this->db)]);
    }

    /** Uložení nebo smazání sdílené třídy (JSON). */
    protected function akceStavbaTrida(): Response
    {
        if (!$this->request->isPost()) {
            return Response::json(['ok' => false], 405);
        }
        $name = $this->request->post('nazev');
        if (!preg_match(Build::CLASS_PATTERN, $name)) {
            return Response::json(['ok' => false, 'chyba' => t('Název třídy: malá písmena bez diakritiky, číslice a pomlčky (např. karta, karta--zvyraznena).')], 400);
        }
        if ($this->request->post('pouziti') === '1') {
            return Response::json(['ok' => true, 'pouziti' => $this->findClassUsages($name)]);
        }
        if (!$this->app->auth()->isAdmin()) {
            // sdílená třída mění vzhled na všech stránkách okamžitě (bez konceptu) – proto ji upravuje, přejmenovává i maže jen správce
            return Response::json(['ok' => false, 'chyba' => t('Sdílenou třídu upravuje jen správce – změna se hned projeví na celém webu. Vzhled jednoho prvku nastavíte v jeho stylu.')], 403);
        }
        if (($new = $this->request->post('novy_nazev')) !== '') {
            // přejmenování: řádek třídy i všechny stavby, které ji používají (stránky, části, šablony kolekcí, komponenty, moje sekce)
            if (!preg_match(Build::CLASS_PATTERN, $new) || $this->db->value('SELECT 1 FROM {tridy} WHERE nazev = ?', [$new]) !== null) {
                return Response::json(['ok' => false, 'chyba' => t('Nový název musí být volný a psaný malými písmeny bez diakritiky (např. karta-velka).')], 400);
            }
            $this->db->update('tridy', ['nazev' => $new, 'zmeneno' => date('Y-m-d H:i:s')], ['nazev' => $name]);
            foreach (self::BUILD_SOURCES as $table => [$key, $columns]) {
                foreach ($this->db->all('SELECT ' . $key . ', ' . implode(', ', $columns) . ' FROM {' . $table . '} WHERE ' . implode(' OR ', array_map(fn (string $s): string => $s . ' LIKE ?', $columns)), array_fill(0, count($columns), '%"' . $name . '"%')) as $r) {
                    $change = [];
                    foreach ($columns as $s) {
                        if ($r[$s] !== null && str_contains($r[$s], '"' . $name . '"')) {
                            $change[$s] = self::renameClass((string) $r[$s], $name, $new);
                        }
                    }
                    $this->db->update($table, $change, [$key => $r[$key]]);
                }
            }
            \Kaleta\Front\Cache::clear();

            return Response::json(['ok' => true, 'tridy' => $this->loadBuilderClasses(), 'nazev' => $new]);
        }
        if ($this->request->post('smazat') === '1') {
            $this->db->delete('tridy', ['nazev' => $name]);
        } else {
            $errors = [];
            $discarded = [];
            $style = Style::sanitize(json_decode((string) ($_POST['styl'] ?? ''), true), $name, $errors);
            $css = Style::customCss($this->request->post('css'), $discarded);
            $this->db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE styl = VALUES(styl), css = VALUES(css), zmeneno = NOW()',
                [$name, (string) json_encode($style ?: new \stdClass(), JSON_UNESCAPED_UNICODE), $css]);
            if ($errors !== [] || $discarded !== []) {
                \Kaleta\Front\Cache::clear(); // platná část třídy se uložila – web ji musí vidět
                return Response::json(['ok' => true, 'tridy' => $this->loadBuilderClasses(), 'chyby' => $errors + array_map(fn (string $d): string => t('Nepovolená deklarace: %s', $d), $discarded)]);
            }
        }
        \Kaleta\Front\Cache::clear();

        return Response::json(['ok' => true, 'tridy' => $this->loadBuilderClasses()]);
    }

    /** Tabulky se stavbami: tabulka => [klíč, sloupce se stavbou JSON]. */
    private const array BUILD_SOURCES = [
        'stranky' => ['ids', ['stavba', 'stavba_koncept']], 'casti' => ['typ', ['stavba', 'stavba_koncept']], 'kolekce' => ['idk', ['stavba', 'stavba_koncept']],
        'komponenty' => ['idm', ['stavba', 'stavba_koncept']], 'sekce' => ['idx', ['prvek']],
    ];

    /** Přejmenuje třídu v poli „tridy“ všech prvků stavby (JSON) – jiné výskyty textu zůstanou. */
    private static function renameClass(string $json, string $old, string $new): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $walk = function (array $x) use (&$walk, $old, $new): array {
            if (isset($x['tridy']) && is_array($x['tridy'])) {
                $x['tridy'] = array_map(fn (mixed $t): mixed => $t === $old ? $new : $t, $x['tridy']);
            }
            foreach ($x as $k => $v) {
                if (is_array($v)) {
                    $x[$k] = $walk($v);
                }
            }

            return $x;
        };

        return (string) json_encode($walk($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> kde je třída použitá (názvy stránek, částí, kolekcí, komponent, mých sekcí) */
    private function findClassUsages(string $name): array
    {
        $pattern = '%"tridy":[%"' . addcslashes($name, '%_\\') . '"%';
        $whereParts = [];
        foreach ($this->db->all('SELECT titulek FROM {stranky} WHERE smazano IS NULL AND (stavba LIKE ? OR stavba_koncept LIKE ?)', [$pattern, $pattern]) as $r) {
            $whereParts[] = t('stránka') . ' ' . $r['titulek'];
        }
        foreach ($this->db->all('SELECT typ, nazev FROM {casti} WHERE stavba LIKE ? OR stavba_koncept LIKE ?', [$pattern, $pattern]) as $r) {
            $whereParts[] = t('část webu') . ' ' . ($r['nazev'] !== '' ? $r['nazev'] : $r['typ']);
        }
        foreach ([['kolekce', 'nazev', 'kolekce'], ['komponenty', 'nazev', 'komponenta']] as [$table, $column, $kind]) {
            foreach ($this->db->all('SELECT ' . $column . ' AS n FROM {' . $table . '} WHERE stavba LIKE ? OR stavba_koncept LIKE ?', [$pattern, $pattern]) as $r) {
                $whereParts[] = t($kind) . ' ' . $r['n'];
            }
        }

        return array_values(array_unique($whereParts));
    }

    /** Publikované verze (JSON pro dialog Verze). */
    protected function akceStavbaRevize(): Response
    {
        $target = $this->loadBuildTarget();

        // datum ve formátu administrace (datum()), ne prohlížeče – anglicky jinak vycházelo americké 9/25/2026, 9:43:45 AM
        return Response::json(['revize' => $target === null ? [] : array_map(fn (array $r): array => $r + ['kdy' => format_date($r['datum'], true)], Publisher::listAll($this->db, $target['revize']))]);
    }

    /** Starší verze se načte do konceptu; publikuje se až tlačítkem Publikovat. */
    protected function akceStavbaObnov(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $build = $target !== null ? Publisher::load($this->db, $target['revize'], $this->request->postInt('idr')) : null;
        if ($build === null) {
            return Response::json(['ok' => false, 'chyba' => t('Verze neexistuje.')], 404);
        }
        $this->saveDraft($target, $build);

        return Response::json(['ok' => true, 'stavba' => Build::fromJson($build), 'verze' => self::computeBuildVersion($build)]);
    }

    /** Otisk obsahu, který editor naposledy viděl na serveru (koncept, jinak publikovaná stavba). */
    private static function computeBuildVersion(?string $json): string
    {
        return substr(md5((string) $json), 0, 16);
    }

    /**
     * Ochrana před přepsáním cizích změn: editor posílá otisk verze, ze které vychází. Když se koncept mezitím změnil
     * (jiný editor, Claude přes MCP, druhá záložka), odmítne se a editor nabídne načíst novější, nebo přepsat.
     */
    private function checkVersionConflict(array $target): ?Response
    {
        $version = $this->request->post('verze');
        $current = self::computeBuildVersion($target['koncept'] ?? $target['stavba']);
        if ($version === '' || $this->request->post('prepsat') === '1' || hash_equals($current, $version)) {
            return null;
        }

        return Response::json(['ok' => false, 'konflikt' => true, 'verze' => $current, 'stavba' => Build::fromJson($target['koncept'] ?? $target['stavba']),
            'chyba' => t('Stránku mezitím upravil někdo jiný (nebo jste ji otevřeli v jiném okně).')], 409);
    }

    /**
     * Popisky schématu (názvy prvků, polí, vlastností stylu a jejich voleb) do jazyka administrace. Výchozí obsah prvků
     * se nepřekládá – je v jazyce stránky (Stavba::schema).
     */
    private static function translateSchema(array $schema): array
    {
        $field = function (array $properties) use (&$field): array {
            foreach ($properties as $key => $d) {
                $properties[$key]['popisek'] = t((string) ($d['popisek'] ?? ''));
                if (isset($d['moznosti'])) {
                    $properties[$key]['moznosti'] = array_map(fn (string $m): string => t($m), $d['moznosti']);
                }
                if (isset($d['pole'])) {
                    $properties[$key]['pole'] = $field($d['pole']);
                }
            }

            return $properties;
        };
        foreach ($schema['prvky'] as $i => $p) {
            $schema['prvky'][$i] = ['nazev' => t($p['nazev']), 'popis' => t($p['popis']), 'skupina' => t($p['skupina']), 'vlastnosti' => $field($p['vlastnosti'])] + $p;
        }
        $schema['styl'] = $field($schema['styl']);
        $schema['skupiny_stylu'] = array_map(fn (string $s): string => t($s), $schema['skupiny_stylu']);

        return $schema;
    }

    /** AI asistent: nová sekce podle popisu (JSON s prvky k vložení). */
    protected function akceStavbaAiSekce(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if ($target === null || !$assistant->isReady()) {
            return Response::json(['ok' => false, 'chyba' => t('AI asistent není zapnutý (Rozšíření).')], 400);
        }
        try {
            $html = \Kaleta\Core\Language::runWith($target['jazyk'], fn (): string => $assistant->suggestSection($this->request->post('zadani'), $target['jazyk'], $target['titulek']));
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'chyba' => t($e->getMessage())], 502);
        }
        ['stavba' => $build, 'hlaseni' => $messages] = \Kaleta\Builder\HtmlConverter::saveToSite($this->db, $html, false);
        [$clean] = Build::sanitize($build, $this->app->auth()->isAdmin());
        if ($clean['deti'] === []) {
            return Response::json(['ok' => false, 'chyba' => t('Asistent nevrátil použitelnou sekci. Zkuste popis upřesnit.')], 502);
        }

        return Response::json(['ok' => true, 'prvky' => $clean['deti'], 'tridy' => $this->loadBuilderClasses(), 'hlaseni' => $messages]);
    }

    /** AI asistent: přepis textu prvku (kratší, delší, formálněji…). Nic neukládá – editor text vloží jako běžnou změnu. */
    protected function akceStavbaAiText(): Response
    {
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if (!$this->request->isPost() || !$assistant->isReady()) {
            return Response::json(['ok' => false, 'chyba' => t('AI asistent není zapnutý (Rozšíření).')], 400);
        }
        try {
            $text = $assistant->rewrite((string) ($_POST['text'] ?? ''), $this->request->post('pokyn'), $this->request->post('html') === '1');
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'chyba' => t($e->getMessage())], 502);
        }

        return Response::json(['ok' => true, 'text' => $text]);
    }

    /** @return array<string, array{styl: array<string, mixed>|\stdClass, css: string}> */
    protected function loadBuilderClasses(): array
    {
        $classes = [];
        foreach ($this->db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev') as $r) {
            $classes[$r['nazev']] = ['styl' => json_decode((string) $r['styl'], true) ?: new \stdClass(), 'css' => (string) $r['css']];
        }

        return $classes;
    }

    protected function contentLanguage(string $column): string
    {
        return Language::ofContent($this->app->settings(), $column);
    }
}
