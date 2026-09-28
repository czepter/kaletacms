<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Novinky a blog firmy (v databázi tabulka ka_novinky, kategorie = ka_kategorie).
 *
 * Pravidla:
 *  - autor vidí a upravuje jen své novinky a nesmí vydávat,
 *  - editor a správce vidí všechny novinky a vydávají je,
 *  - vydanou novinku smí měnit jen ten, kdo smí vydávat.
 */
final class News extends Module
{
    public const string IDENT = 'news';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Novinky';
    public const string GROUP = 'Obsah';
    public const string ICON = 'novinky';

    private const int PER_PAGE = 20;

    /**
     * Koncept autora novinek (úroveň 0, sám nevydává) čeká, až ho vydá editor nebo správce. Jiný stav „odesláno ke schválení“
     * Kaleta nemá – autor umí uložit jen koncept a hláška mu říká, že ho vydá editor.
     */
    public const string AWAITING_PUBLICATION = 'c.visible = 0 AND c.autor IN (SELECT idu FROM {uzivatele} WHERE admin = 0)';

    /** Kolik novinek od autorů čeká na vydání (pro editory a správce; autorům 0). */
    public static function countAwaitingPublication(\Kaleta\Core\App $app): int
    {
        return $app->auth()->canPublish() ? (int) $app->db()->value('SELECT COUNT(*) FROM {novinky} c WHERE c.smazano IS NULL AND ' . self::AWAITING_PUBLICATION) : 0;
    }

    protected function actionList(): Response
    {
        $auth = $this->app->auth();
        $where = ['1 = 1'];
        $params = [];
        if (($authors = $auth->managedAuthors()) !== null) {
            $where[] = 'c.autor IN (' . implode(',', $authors) . ')';
        }
        if (($colorScheme = $this->request->getInt('tema')) > 0) {
            $where[] = 'c.tema = ?';
            $params[] = $colorScheme;
        }
        // jazyková verze: výchozí jazyk webu je v sloupci uložený jako ''
        $s = $this->app->settings();
        $siteLanguages = ($additional = \Kaleta\Core\Language::additional($s)) === [] ? [] : [\Kaleta\Core\Language::defaults($s), ...$additional];
        $language = in_array($this->request->get('jazyk'), $siteLanguages, true) ? $this->request->get('jazyk') : '';
        if ($language !== '') {
            $where[] = 'c.jazyk = ?';
            $params[] = \Kaleta\Core\Language::column($s, $language);
        }
        if (($search = $this->request->get('hledat')) !== '') {
            $where[] = 'c.titulek LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $state = $this->request->get('stav');
        $inTrash = $state === 'kos';
        $where[] = $inTrash ? 'c.smazano IS NOT NULL' : 'c.smazano IS NULL';
        $statusConditions = [
            'vydane' => 'c.visible = 1 AND c.datum <= NOW()',
            'plan' => 'c.visible = 1 AND c.datum > NOW()',
            'koncepty' => 'c.visible = 0',
            'ke_vydani' => self::AWAITING_PUBLICATION,
        ];
        if (isset($statusConditions[$state])) {
            $where[] = $statusConditions[$state];
        }
        $cond = implode(' AND ', $where);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM {novinky} c WHERE {$cond}", $params);
        $pageNumber = max(1, $this->request->getInt('strana', 1));
        $news = $this->db->all(
            "SELECT c.idc, c.seo_link, c.titulek, c.datum, c.visible, c.visit, c.smazano,
                    t.nazev AS tema_jm, u.jmeno AS autor_jm, u.user AS autor_login, u.admin AS autor_uroven
             FROM {novinky} c
             JOIN {kategorie} t ON t.idt = c.tema
             LEFT JOIN {uzivatele} u ON u.idu = c.autor
             WHERE {$cond}
             ORDER BY " . ($inTrash ? 'c.smazano DESC' : 'c.datum DESC') . ", c.idc DESC
             LIMIT ? OFFSET ?",
            [...$params, self::PER_PAGE, ($pageNumber - 1) * self::PER_PAGE],
        );

        return $this->view('list', 'Novinky', [
            'news' => $news,
            'total' => $total,
            'pageNumber' => $pageNumber,
            'pageCount' => max(1, (int) ceil($total / self::PER_PAGE)),
            'category' => Categories::listAll($this->db),
            'filter' => ['tema' => $colorScheme, 'jazyk' => $language, 'hledat' => $search, 'stav' => isset($statusConditions[$state]) || $inTrash ? $state : ''],
            'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {novinky} c WHERE c.smazano IS NOT NULL' . $auth->articleScope('c.')),
            'toPublish' => self::countAwaitingPublication($this->app),
            'siteLanguages' => $siteLanguages,
        ]);
    }

    protected function actionNew(): Response
    {
        // bez kategorie by novinka nešla uložit: založí se výchozí a editor se otevře rovnou (žádná slepá ulička)
        if (Categories::createDefault($this->db, $this->app->settings()) !== null) {
            $this->app->session->flash('ok', t('Novinky potřebují kategorii, proto vznikla kategorie „%s“. Přejmenovat ji nebo přidat další můžete v Novinky → Kategorie.', (string) (Categories::listAll($this->db)[0]['nazev'] ?? '')));
        }

        return $this->form($this->defaults());
    }

    /** Hodnoty nové novinky; doplňují se jimi i pole, která při neúspěšné validaci ve formuláři chybí. */
    private function defaults(): array
    {
        return [
            'idc' => 0, 'seo_link' => '', 'titulek' => '', 'uvod' => '', 'text' => '', 'obrazek' => '', 'obrazek_popis' => '', 'obrazek_autor' => '',
            'tema' => (int) (Categories::listAll($this->db)[0]['idt'] ?? 0), 'autor' => $this->app->auth()->id(), 'datum' => date('Y-m-d H:i:s'),
            'visible' => 0, 't_slova' => '', 'seo_titulek' => '', 'seo_popis' => '', 'noindex' => 0, 'preklad_z' => null, 'faq' => '', 'jazyk' => '',
        ];
    }

    /** Kopie novinky jako koncept (i se štítky) – rychlý začátek podobné novinky. */
    protected function actionDuplicate(): Response
    {
        $newsItem = $this->request->isPost() ? $this->load($this->request->postInt('idc')) : null;
        if ($newsItem === null) {
            return $this->back();
        }
        $copy = array_intersect_key($newsItem, array_flip(['uvod', 'text', 'obrazek', 'obrazek_popis', 'obrazek_autor', 'tema', 't_slova', 'seo_popis', 'noindex', 'faq', 'jazyk']));
        $seo = \Kaleta\Core\Slug::makeUnique($newsItem['seo_link'] . '-kopie', fn (string $a): bool => $this->db->value('SELECT 1 FROM {novinky} WHERE seo_link = ?', [$a]) !== null);
        $id = $this->db->insert('novinky', $copy + ['titulek' => mb_substr(t('%s (kopie)', $newsItem['titulek']), 0, 255), 'seo_link' => $seo, 'visible' => 0,
            'datum' => date('Y-m-d H:i:s'), 'autor' => $this->app->auth()->id(), 'zmeneno' => date('Y-m-d H:i:s')]);
        $this->db->run('INSERT INTO {novinky_stitky} (idc, ids) SELECT ?, ids FROM {novinky_stitky} WHERE idc = ?', [$id, $newsItem['idc']]);
        \Kaleta\Core\Search::index($this->db, $id);

        return $this->back('Kopie novinky je uložená jako koncept.', 'edit', ['id' => $id]);
    }

    protected function actionEdit(): Response
    {
        $newsItem = $this->load($this->request->getInt('id'));

        return $newsItem === null ? $this->error('Novinka neexistuje nebo k ní nemáte přístup.', 404) : $this->form($newsItem);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $auth = $this->app->auth();
        $r = $this->request;
        $id = $r->postInt('idc');

        $previous = null;
        if ($id > 0) {
            $previous = $this->load($id);
            if ($previous === null) {
                return $this->error('Novinka neexistuje nebo k ní nemáte přístup.', 404);
            }
            if ($previous['visible'] && !$auth->canPublish()) {
                return $this->error('Vydanou novinku může upravit jen editor nebo správce.', 403);
            }
        }

        $data = [
            'titulek' => $r->post('titulek'),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('titulek'), 150),
            'uvod' => \Kaleta\Core\Html::forUser($r->post('uvod'), $this->app->auth()),
            'text' => \Kaleta\Core\Html::forUser($r->post('text'), $this->app->auth()),
            'obrazek' => $r->post('obrazek'),
            'obrazek_popis' => mb_substr(trim($r->post('obrazek_popis')), 0, 300),
            'obrazek_autor' => mb_substr(trim($r->post('obrazek_autor')), 0, 120),
            'tema' => $r->postInt('tema'),
            'autor' => $r->postInt('autor'),
            'datum' => self::parseFormDate($r->post('datum')) ?? date('Y-m-d H:i:s'),
            'visible' => (int) ($r->post('stav') === 'vydany' && $auth->canPublish()),
            't_slova' => $r->post('t_slova'),
            'seo_titulek' => mb_substr($r->post('seo_titulek'), 0, 255),
            'seo_popis' => mb_substr($r->post('seo_popis'), 0, 320),
            'noindex' => (int) $r->postBool('noindex'),
            'faq' => $r->post('faq'),
            'zmeneno' => date('Y-m-d H:i:s'),
        ];

        $errors = [];
        if ($data['titulek'] === '') {
            $errors['titulek'] = 'Vyplňte titulek.';
        }
        if ($this->db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$data['tema']]) === null) {
            $errors['tema'] = 'Vyberte kategorii.';
        }
        $allowedAuthors = $auth->managedAuthors();
        if ($allowedAuthors !== null && !in_array($data['autor'], $allowedAuthors, true)) {
            $data['autor'] = $auth->id();
        }
        if ($this->db->value('SELECT idu FROM {uzivatele} WHERE idu = ?', [$data['autor']]) === null) {
            $errors['autor'] = 'Vyberte autora.';
        }
        if ($errors !== []) {
            return $this->form(['idc' => $id] + $data + ($previous ?? $this->defaults()), $errors);
        }
        // jazyková verze se přebírá z kategorie; překlad se propojuje s novinkou ve výchozím jazyce (adresa nebo číslo)
        $data['jazyk'] = (string) $this->db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$data['tema']]);
        $original = trim($r->post('preklad_z'));
        $data['preklad_z'] = $original === '' || $data['jazyk'] === '' ? null
            : ($this->db->value("SELECT idc FROM {novinky} WHERE (idc = ? OR seo_link = ?) AND jazyk = '' AND idc <> ?", [(int) $original, basename((string) parse_url($original, PHP_URL_PATH)), $id]) ?: null);

        if ($r->postBool('oznacit_aktualizaci') && $data['visible']) {
            $data['aktualizovano'] = date('Y-m-d H:i:s');
        }
        $data['seo_link'] = $this->findFreeSlug($data['seo_link'], $id);
        if ($id > 0) {
            if ([$previous['titulek'], $previous['uvod'], $previous['text']] !== [$data['titulek'], $data['uvod'], $data['text']]) {
                self::version($this->db, $previous, $this->app->auth()->id());
            }
            $this->db->update('novinky', $data, ['idc' => $id]);
            if ($previous['seo_link'] !== $data['seo_link'] && $previous['visible']) {
                // vydaná novinka změnila adresu: stará se přesměruje, aby odkazy a vyhledávače nepřišly o stránku
                Redirects::add($this->db, 'novinky/' . $previous['seo_link'], 'novinky/' . $data['seo_link']);
            }
        } else {
            $id = $this->db->insert('novinky', $data);
        }

        Media::recordUsage($this->db, $id, $data['obrazek'], $data['uvod'], $data['text']);
        \Kaleta\Core\Search::index($this->db, $id);
        // uložená novinka ruší rozepsaný stav na serveru (u nové je veden pod číslem 0)
        $this->db->run('DELETE FROM {novinky_koncepty} WHERE kdo = ? AND idc IN (0, ?)', [$auth->id(), $id]);
        self::tags($this->db, $id, $r->post('stitky'));
        // nově vydaná novinka se oznámí (webhook, IndexNow); naplánovaná počká na svůj čas - viz Core\Oznameni
        \Kaleta\Core\Notifications::process($this->app);
        if ($data['visible'] && !empty($previous['visible']) && !$data['noindex'] && strtotime($data['datum']) <= time()) {
            (new \Kaleta\Front\Seo($this->app))->indexNow($this->app->newsItemUrl($data['seo_link'], $data['jazyk']));
        }

        $message = $auth->canPublish() ? 'Novinka byla uložena.' : 'Novinka byla uložena. Na webu se objeví, až ji vydá editor.';

        return $r->post('po_ulozeni') === 'zustat' ? $this->back($message, 'edit', ['id' => $id]) : $this->back($message);
    }

    /**
     * Uložení z úpravy „přímo na webu“ (views/front/upravit.php): jen titulek, perex a text. Platí stejná pravidla jako
     * u běžného uložení - oprávnění přes nacti(), vydaná novinka jen s právem vydávat, revize, hledání, použití obrázků.
     */
    protected function actionSaveText(): Response
    {
        $r = $this->request;
        $newsItem = $r->isPost() ? $this->load($r->postInt('id')) : null;
        if ($newsItem === null || ($newsItem['visible'] && !$this->app->auth()->canPublish())) {
            return $this->redirectToSite($r->post('zpet'));
        }
        $data = ['titulek' => mb_substr($r->post('titulek'), 0, 255), 'uvod' => \Kaleta\Core\Html::forUser($r->post('uvod'), $this->app->auth()), 'text' => \Kaleta\Core\Html::forUser($r->post('text'), $this->app->auth())];
        // nevydaná novinka je na webu vidět jen v náhledu
        $preview = $newsItem['visible'] && strtotime((string) $newsItem['datum']) <= time() ? '' : 'nahled=1';
        if ($data['titulek'] === '') {
            return $this->redirectToSite($r->post('zpet'), '?' . ($preview !== '' ? $preview . '&' : '') . 'upravit=text&chyba=1');
        }
        if ([$newsItem['titulek'], $newsItem['uvod'], $newsItem['text']] !== array_values($data)) {
            self::version($this->db, $newsItem, $this->app->auth()->id());
        }
        $this->db->update('novinky', $data + ['zmeneno' => date('Y-m-d H:i:s')], ['idc' => $newsItem['idc']]);
        Media::recordUsage($this->db, (int) $newsItem['idc'], (string) $newsItem['obrazek'], $data['uvod'], $data['text']);
        \Kaleta\Core\Search::index($this->db, (int) $newsItem['idc']);
        \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'úprava přímo na webu', mb_substr($data['titulek'], 0, 80));
        if ($newsItem['visible'] && !$newsItem['noindex'] && strtotime((string) $newsItem['datum']) <= time()) {
            (new \Kaleta\Front\Seo($this->app))->indexNow($this->app->newsItemUrl($newsItem['seo_link'], $newsItem['jazyk']));
        }

        return $this->redirectToSite($r->post('zpet'), $preview !== '' ? '?' . $preview : '');
    }

    /**
     * Průběžné ukládání rozepsané novinky na server (image/editor.js). Neukládá novinku - jen stav formuláře
     * přihlášeného uživatele, aby v psaní mohl pokračovat jinde. POST bez pole "pole" rozepsaný stav smaže.
     */
    protected function actionDraft(): Response
    {
        if (!$this->request->isPost()) {
            return Response::json(['ok' => false], 405);
        }
        $idc = $this->request->postInt('idc');
        if ($idc > 0 && $this->load($idc) === null) {
            return Response::json(['ok' => false], 404);
        }
        $me = $this->app->auth()->id();
        $data = (string) ($_POST['pole'] ?? '');
        if ($data === '' || strlen($data) > 3_000_000 || !is_array(json_decode($data, true))) {
            $this->db->delete('novinky_koncepty', ['kdo' => $me, 'idc' => $idc]);

            return Response::json(['ok' => true, 'smazano' => true]);
        }
        $this->db->run('INSERT INTO {novinky_koncepty} (kdo, idc, cas, data) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE cas = NOW(), data = VALUES(data)', [$me, $idc, $data]);
        if (random_int(1, 40) === 1) {
            $this->db->run('DELETE FROM {novinky_koncepty} WHERE cas < NOW() - INTERVAL 30 DAY');
        }

        return Response::json(['ok' => true]);
    }

    /** Hledání novinek podle titulku pro dialog odkazu v editoru a pro paletu příkazů (?uprava=1). */
    protected function actionSearchJson(): Response
    {
        $q = mb_substr(trim($this->request->get('q')), 0, 80);
        if (mb_strlen($q) < 2) {
            return Response::json(['clanky' => []]);
        }
        $editMode = $this->request->get('uprava') === '1';
        $news = $this->db->all(
            'SELECT idc, titulek, seo_link, jazyk, visible AND datum <= NOW() AS vydany FROM {novinky} WHERE smazano IS NULL AND titulek LIKE ?'
                . ($editMode ? $this->app->auth()->articleScope() : '') . ' ORDER BY datum DESC LIMIT 8',
            ['%' . addcslashes($q, '%_\\') . '%'],
        );

        return Response::json(['clanky' => array_map(fn (array $c): array => [
            'titulek' => $c['titulek'], 'vydany' => (bool) $c['vydany'],
            'url' => $editMode ? $this->url('edit', ['id' => $c['idc']]) : $this->app->url(($c['jazyk'] !== '' ? $c['jazyk'] . '/' : '') . 'novinky/' . $c['seo_link']),
        ], $news)]);
    }

    /**
     * AI asistent: návrh k rozepsané novince (titulky, perex, SEO popis, štítky, korektura, popis obrázku).
     * Pracuje s textem z formuláře, nic neukládá - o použití návrhu rozhoduje člověk.
     */
    protected function actionAssistant(): Response
    {
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if (!$this->request->isPost() || !$assistant->isReady()) {
            return Response::json(['chyba' => t('AI asistent není zapnutý nebo chybí klíč (nabídka Rozšíření).')], 400);
        }
        // pojistka proti nechtěné útratě: nejvýš 60 dotazů za hodinu na uživatele
        if ($this->hasTooManyRequests()) {
            return Response::json(['chyba' => t('Za poslední hodinu jste asistenta použili 60×. Zkuste to prosím později.')], 429);
        }
        $task = $this->request->post('ukol');
        $image = null;
        if ($task === 'alt') {
            // jen soubory z media/: cesta se skládá z ověřených částí adresy
            $image = preg_match('#media/(\d{4}/\d{2}/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif))$#', (string) parse_url($this->request->post('obrazek'), PHP_URL_PATH), $m) ? KALETA_ROOT . '/media/' . $m[1] : null;
            $smaller = $image === null ? null : preg_replace('/\.(\w+)$/', '-1200.$1', $image);
            $image = $smaller !== null && is_file($smaller) ? $smaller : $image;
        }
        try {
            $result = $assistant->suggest($task, [
                'titulek' => $this->request->post('titulek'),
                'uvod' => $this->request->post('uvod'),
                'text' => $this->request->post('text'),
                'stitky_webu' => $task === 'stitky' ? array_column($this->db->all('SELECT nazev FROM {stitky} ORDER BY nazev LIMIT 300'), 'nazev') : [],
            ], $image);
        } catch (\RuntimeException $e) {
            return Response::json(['chyba' => t($e->getMessage())], 502);
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'asistent', $task, mb_substr($this->request->post('titulek'), 0, 80));

        return Response::json($result);
    }

    /**
     * „Přeložit asistentem“: z uložené verze novinky ve výchozím jazyce založí koncept v kategorii cílového jazyka,
     * propojený s originálem. Překlad vždy čeká na přečtení člověkem – nikdy se nevydává sám.
     */
    protected function actionTranslate(): Response
    {
        $newsItem = $this->request->isPost() ? $this->load($this->request->postInt('idc')) : null;
        if ($newsItem === null) {
            return $this->back('Novinku nejdřív uložte, pak ji půjde přeložit.', type: 'chyba');
        }
        $backToNewsItem = fn (string $message): Response => $this->back($message, 'edit', ['id' => $newsItem['idc']], type: 'chyba');
        $language = $this->request->post('prelozit_do');
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if (!$assistant->isReady()) {
            return $backToNewsItem('AI asistent není zapnutý nebo chybí klíč (nabídka Rozšíření).');
        }
        if ($newsItem['jazyk'] !== '' || !in_array($language, \Kaleta\Core\Language::additional($this->app->settings()), true)) {
            return $backToNewsItem('Přeložit jde jen novinka ve výchozím jazyce, a to do některé z dalších jazykových verzí webu.');
        }
        if (($existing = $this->db->value('SELECT idc FROM {novinky} WHERE preklad_z = ? AND jazyk = ?', [$newsItem['idc'], $language])) !== null) {
            return $this->back('Překlad do tohoto jazyka už existuje – tady je.', 'edit', ['id' => (int) $existing]);
        }
        // cílová kategorie: protějšek kategorie originálu, jinak první kategorie daného jazyka
        $category = $this->db->value('SELECT idt FROM {kategorie} WHERE jazyk = ? ORDER BY (preklad_z <=> ?) DESC, hodnost DESC, idt LIMIT 1', [$language, $newsItem['tema']]);
        if ($category === null) {
            return $backToNewsItem('V cílovém jazyce zatím není žádná kategorie. Založte ji v Novinky → Kategorie (pole Jazyková verze).');
        }
        if ($this->hasTooManyRequests()) {
            return $backToNewsItem('Za poslední hodinu jste asistenta použili 60×. Zkuste to prosím později.');
        }

        set_time_limit(600); // dlouhý text se překládá po dávkách
        $plainFields = ['titulek', 'seo_titulek', 'seo_popis', 'faq', 't_slova'];
        try {
            $translation = $assistant->translate(array_map(strval(...), array_intersect_key($newsItem, array_flip([...$plainFields, 'uvod', 'text']))), $language, $plainFields);
        } catch (\RuntimeException $e) {
            return $backToNewsItem(t($e->getMessage()));
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'asistent', 'preklad-' . $language, mb_substr($newsItem['titulek'], 0, 80));

        // koncept přebírá z originálu vše, co se nepřekládá (obrázek, autora…); počitadla ne
        $data = array_intersect_key($newsItem, array_flip(['obrazek', 'autor', 'noindex'])) + [
            'tema' => (int) $category, 'jazyk' => $language, 'preklad_z' => $newsItem['idc'], 'visible' => 0, 'datum' => date('Y-m-d H:i:s'),
        ];
        foreach ($translation as $field => $value) {
            $data[$field] = $field === 'titulek' ? mb_substr($value, 0, 255) : $value;
        }
        $data['seo_link'] = $this->findFreeSlug(slugify($data['titulek'], 100), 0);
        $id = $this->db->insert('novinky', $data);
        Media::recordUsage($this->db, $id, (string) $data['obrazek'], $data['uvod'], $data['text']);
        $this->db->run('INSERT INTO {novinky_stitky} (idc, ids) SELECT ?, ids FROM {novinky_stitky} WHERE idc = ?', [$id, $newsItem['idc']]);
        \Kaleta\Core\Search::index($this->db, $id);

        return $this->back('Překlad je založený jako koncept. Než ho vydáte, přečtěte ho – asistent může chybovat ve jménech, číslech a odborných výrazech.', 'edit', ['id' => $id]);
    }

    private function hasTooManyRequests(): bool
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM {protokol} WHERE kdo = ? AND modul = 'asistent' AND cas > NOW() - INTERVAL 1 HOUR", [$this->app->auth()->id()]) >= 60;
    }

    /** Načte do editoru starší verzi novinky; uloží se až odesláním formuláře. */
    protected function actionVersions(): Response
    {
        $newsItem = $this->load($this->request->getInt('id'));
        $version = $newsItem === null ? null : $this->db->one('SELECT * FROM {novinky_revize} WHERE idr = ? AND idc = ?', [$this->request->getInt('idr'), $newsItem['idc']]);
        if ($version === null) {
            return $this->error('Verze novinky neexistuje.', 404);
        }
        $this->app->session->flash('info', t('V editoru je verze z %s. Platit začne, až novinku uložíte.', format_date($version['datum'], true)));

        return $this->form(['titulek' => $version['titulek'], 'uvod' => $version['uvod'], 'text' => $version['text']] + $newsItem);
    }

    /** Co se od uložené verze změnilo: porovnání starší verze se současným zněním. */
    protected function actionCompare(): Response
    {
        $newsItem = $this->load($this->request->getInt('id'));
        $version = $newsItem === null ? null : $this->db->one(
            "SELECT r.*, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo_jm FROM {novinky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.idr = ? AND r.idc = ?",
            [$this->request->getInt('idr'), $newsItem['idc'] ?? 0],
        );
        if ($version === null) {
            return $this->error('Verze novinky neexistuje.', 404);
        }

        return $this->view('compare', 'Porovnání verzí', [
            'newsItem' => $newsItem,
            'versions' => $version,
            'title' => \Kaleta\Core\Diff::html((string) $version['titulek'], (string) $newsItem['titulek']),
            'home' => \Kaleta\Core\Diff::html((string) $version['uvod'], (string) $newsItem['uvod']),
            'text' => \Kaleta\Core\Diff::html((string) $version['text'], (string) $newsItem['text']),
        ]);
    }

    /** Nefunkční odkazy nalezené kontrolou na pozadí (Core\Odkazy). */
    protected function actionLinks(): Response
    {
        if ($this->request->isPost()) {
            // "zkontrolovat znovu": novinka se zařadí na začátek fronty
            $this->db->update('novinky', ['odkazy_cas' => null], ['idc' => $this->request->postInt('idc')]);
            $this->db->delete('odkazy_vadne', ['idc' => $this->request->postInt('idc')]);

            return $this->back('Novinka se zkontroluje znovu během několika minut.', 'links');
        }

        return $this->view('links', 'Nefunkční odkazy', [
            'links' => $this->db->all('SELECT o.*, c.titulek FROM {odkazy_vadne} o JOIN {novinky} c ON c.idc = o.idc WHERE 1 = 1' . $this->app->auth()->articleScope('c.') . ' ORDER BY o.cas DESC LIMIT 300'),
            'checked' => (int) $this->db->value('SELECT COUNT(*) FROM {novinky} WHERE odkazy_cas IS NOT NULL'),
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW()'),
            'isEnabled' => $this->app->settings()->bool('link_check'),
        ]);
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $moved = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $newsItem = $this->load((int) $id);
            if ($newsItem === null || ($newsItem['visible'] && !$this->app->auth()->canPublish())) {
                continue;
            }
            // koš: novinka zmizí z webu i z výpisů, ale 30 dní ji jde obnovit; vrátí se jako koncept, nikdy sama nevyjde
            $moved += $this->db->update('novinky', ['smazano' => date('Y-m-d H:i:s'), 'visible' => 0], ['idc' => $newsItem['idc']]);
            \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'do koše', mb_substr($newsItem['titulek'], 0, 80));
        }

        return $this->back(t('Do koše přesunuto novinek: %d. Obnovit je jde 30 dní (Novinky → Koš).', $moved), type: $moved > 0 ? 'ok' : 'chyba');
    }

    /** Obnovení z koše: novinka se vrátí jako koncept (ne vydaná). */
    protected function actionRestore(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $restored = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $newsItem = $this->load((int) $id, true);
            if ($newsItem === null) {
                continue;
            }
            $restored += $this->db->update('novinky', ['smazano' => null], ['idc' => $newsItem['idc']]);
            \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'obnovení z koše', mb_substr($newsItem['titulek'], 0, 80));
        }

        return $this->back(t('Obnoveno novinek: %d. Vrátily se jako koncepty.', $restored), '', ['stav' => 'kos'], $restored > 0 ? 'ok' : 'chyba');
    }

    /** Smazání natrvalo z koše (jen ten, kdo smí vydávat). */
    protected function actionDeletePermanently(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->canPublish()) {
            return $this->back();
        }
        $deleted = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $newsItem = $this->load((int) $id, true);
            if ($newsItem !== null) {
                $deleted += $this->db->delete('novinky', ['idc' => $newsItem['idc']]);
                \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'smazání natrvalo', mb_substr($newsItem['titulek'], 0, 80));
            }
        }

        return $this->back(t('Natrvalo smazáno novinek: %d.', $deleted), '', ['stav' => 'kos'], $deleted > 0 ? 'ok' : 'chyba');
    }

    /** Koš se vysypává sám: novinky starší 30 dní se smažou natrvalo (volá Admin\Kernel při vstupu do administrace). */
    public static function emptyTrash(\Kaleta\Core\Db $db): int
    {
        return $db->run('DELETE FROM {novinky} WHERE smazano < NOW() - INTERVAL 30 DAY')->rowCount();
    }

    /**
     * @param array<string, mixed> $newsItem
     * @param array<string, string> $errors
     */
    private function form(array $newsItem, array $errors = []): Response
    {
        $auth = $this->app->auth();
        $allowedIds = $auth->managedAuthors();
        $authors = $allowedIds === null
            ? $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 ORDER BY 2")
            : $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE idu IN (" . implode(',', $allowedIds) . ') ORDER BY 2');

        return $this->view('form', $newsItem['idc'] ? 'Úprava novinky' : 'Nová novinka', [
            'newsItem' => $newsItem,
            'errors' => $errors,
            'category' => Categories::listAll($this->db),
            'authors' => $authors,
            'canPublish' => $auth->canPublish(),
            'draftOnServer' => $this->request->isPost() ? null : $this->db->one('SELECT cas, data FROM {novinky_koncepty} WHERE kdo = ? AND idc = ?', [$auth->id(), (int) $newsItem['idc']]),
            'siteLanguages' => \Kaleta\Core\Language::additional($this->app->settings()) !== [],
            // u novinky ve výchozím jazyce: do kterých jazyků jde přeložit a které překlady už existují (jazyk => číslo)
            'translationLanguages' => $newsItem['idc'] && ($newsItem['jazyk'] ?? '') === '' ? \Kaleta\Core\Language::additional($this->app->settings()) : [],
            'translations' => $newsItem['idc'] ? array_map(intval(...), $this->db->pairs("SELECT jazyk, idc FROM {novinky} WHERE preklad_z = ? AND jazyk <> ''", [(int) $newsItem['idc']])) : [],
            'original' => empty($newsItem['preklad_z']) ? '' : (string) $this->db->value('SELECT seo_link FROM {novinky} WHERE idc = ?', [$newsItem['preklad_z']]),
            'assistant' => (new \Kaleta\Core\Assistant($this->app->settings()))->isReady(),
            'tags' => $this->request->isPost() ? $this->request->post('stitky') : implode(', ', array_column(
                $this->db->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY s.nazev', [(int) $newsItem['idc']]),
                'nazev',
            )),
            'allTags' => array_column($this->db->all('SELECT nazev FROM {stitky} ORDER BY nazev LIMIT 500'), 'nazev'),
            'versions' => $this->db->all(
                "SELECT r.idr, r.datum, r.titulek, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo_jm
                 FROM {novinky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.idc = ? ORDER BY r.idr DESC",
                [(int) $newsItem['idc']],
            ),
        ]);
    }

    /** Uloží předchozí podobu novinky; drží se posledních 20 verzí. */
    /** Předchozí podoba novinky do historie (posledních 20 verzí) – administrace i MCP. */
    public static function version(\Kaleta\Core\Db $db, array $previous, ?int $who): void
    {
        $db->insert('novinky_revize', [
            'idc' => $previous['idc'], 'datum' => $previous['zmeneno'] ?? $previous['datum'], 'kdo' => $who,
            'titulek' => $previous['titulek'], 'uvod' => $previous['uvod'], 'text' => $previous['text'],
        ]);
        $boundary = $db->value('SELECT idr FROM {novinky_revize} WHERE idc = ? ORDER BY idr DESC LIMIT 1 OFFSET 20', [$previous['idc']]);
        if ($boundary !== null) {
            $db->run('DELETE FROM {novinky_revize} WHERE idc = ? AND idr <= ?', [$previous['idc'], $boundary]);
        }
    }

    /** Štítky zapsané čárkami (nejvýš 20); neznámé se založí – administrace i MCP. */
    public static function tags(\Kaleta\Core\Db $db, int $idc, string $input): void
    {
        $db->delete('novinky_stitky', ['idc' => $idc]);
        $names = array_unique(array_filter(array_map(fn (string $n): string => mb_substr(trim($n), 0, 80), explode(',', $input))));
        foreach (array_slice($names, 0, 20) as $name) {
            $seo = slugify($name, 90);
            $ids = $db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]);
            $ids = $ids !== null ? (int) $ids : $db->insert('stitky', ['nazev' => $name, 'seo_link' => $seo]);
            $db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) VALUES (?, ?)', [$idc, $ids]);
        }
    }

    /** Načte novinku, jen pokud ji přihlášený smí spravovat. Novinku v koši jen s $zKose (obnovení, smazání natrvalo). */
    private function load(int $id, bool $fromTrash = false): ?array
    {
        $newsItem = $this->db->one('SELECT * FROM {novinky} WHERE idc = ? AND smazano IS ' . ($fromTrash ? 'NOT NULL' : 'NULL'), [$id]);
        $authors = $this->app->auth()->managedAuthors();

        return $newsItem === null || ($authors !== null && !in_array((int) $newsItem['autor'], $authors, true)) ? null : $newsItem;
    }

    private function findFreeSlug(string $seo, int $idc): string
    {
        return \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => $this->db->value('SELECT idc FROM {novinky} WHERE seo_link = ? AND idc <> ?', [$a, $idc]) !== null);
    }

    /** Hodnota z <input type="datetime-local"> -> DATETIME; prázdné nebo neplatné = null. */
    private static function parseFormDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($value, 0, 16));

        return $dt === false ? null : $dt->format('Y-m-d H:i:00');
    }
}
