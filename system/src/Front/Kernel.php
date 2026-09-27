<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\View;

/**
 * Veřejná část webu.
 *
 *   /                           úvodní stránka (Nastavení → Základní), bez ní výpis novinek
 *   /novinky                    výpis novinek
 *   /novinky/<seo-link>         novinka (+ .md pro jazykové modely)
 *   /novinky/kategorie/<seo>    novinky v kategorii
 *   /novinky/stitek/<seo>       novinky se štítkem
 *   /hledani?q=...              vyhledávání
 *   /rss.xml                    RSS kanál novinek
 *   /<adresa>                   stránka
 *   robots.txt, sitemap.xml, llms.txt, feed.json... viz Seo
 */
final class Kernel
{
    private readonly View $view;
    private readonly NewsRepository $news;

    /** Kategorie nebo stránka, kterou požadavek zobrazuje - přepínač jazyků podle ní najde protějšek v jiné verzi. */
    private ?array $counterpart = null;

    /** Zobrazená položka kolekce [idk, adresa kolekce, adresa položky]: protějšky v dalších jazycích mají stejnou adresu. */
    private ?array $collectionItem = null;

    /** Zobrazená stránka je úvodní: v každé jazykové verzi má adresu kořene (/, /en/), ne svou adresu (ta přesměrovává). */
    private bool $isHome = false;

    /** Sdílený stav builderu pro celou stránku (stavba stránky, záhlaví, patička, obálka) – jedno CSS bez opakování. */
    private ?\Kaleta\Builder\Context $context = null;

    /** Složka šablony (layoutu), kterou web právě používá. */
    private string $layout = Layouts::DEFAULTS;

    /** Systémová adresa v cizí podobě (/novinky na anglickém webu) – přesměrování na platnou (Core\Cesty). */
    private ?Response $redirect = null;

    /** Požadovaná stránka výpisu je až za jeho koncem - odpoví se 404. */
    private bool $pastEnd = false;

    /** Odkaz „Upravit zde“ pro právě zobrazenou stránku nebo novinku; vypíše ho stranka() přihlášenému, který na to má právo. */
    private string $editHereUrl = '';

    /** Kolekce zobrazené stránky položky (pravidla pop-up oken „jen v kolekci“). */
    private ?string $pageCollection = null;

    /** Pop-up okno, jehož koncept ukazuje podepsaný náhled /_popup/<id> (otevře se hned). */
    private int $previewPopup = 0;

    public function __construct(private readonly App $app)
    {
        $app->request->setOrigin($app->settings()->get('adresa_webu'));
        $app->applyTimezone();
        // po aktualizaci systému (i automatické) se databáze upraví hned při první návštěvě, ne až po přihlášení administrátora
        if ($app->settings()->int('verze_db') < KALETA_VERZE_DB) {
            // nepovedená migrace nesmí shodit celý web: zapíše se a web běží dál (změny databáze jsou jen přidávající);
            // správce ji uvidí v administraci a může nainstalovat opravu
            \Kaleta\Core\Migration::safe($app->db(), $app->settings());
        }
        // jazyková verze: /en/novinky/x -> jazyk "en", cesta "/novinky/x"; adresy z $app->url() pak dostávají předponu samy
        $language = Language::defaults($app->settings());
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $app->request->path(), $m) && in_array($m[1], Language::additional($app->settings()), true)) {
            $language = $m[1];
            $app->languagePrefix = $m[1];
            $app->request->setPath($m[2] ?? '/');
        }
        Language::setSite($app->settings(), $language);
        // systémové adresy v jazyce verze (/news ↔ /novinky): dál se pracuje s vnitřní podobou, cizí podoba přesměruje
        [$internal, $canonicalUrl] = \Kaleta\Core\Routes::internalPath($app->request->path(), $language, $app->db());
        if ($canonicalUrl !== $app->request->path() && !$app->request->isPost()) {
            $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            $this->redirect = Response::redirect($app->url(ltrim($internal, '/')) . ($query !== '' ? '?' . $query : ''), 301);
        }
        $app->request->setPath($internal);
        $layout = $app->settings()->get('layout');
        // náhled jiné šablony (?sablona=slozka) - jen přihlášenému administrátorovi, např. při tvorbě šablony přes Claude
        $preview = $app->request->get('sablona');
        if ($preview !== '' && preg_match('/^[a-z0-9_-]+$/i', $preview) && is_file(KALETA_ROOT . '/layout/' . $preview . '/base.php') && $app->auth()->isAdmin()) {
            $layout = $preview;
        }
        // nastavená šablona chybí (smazaná složka) - web se vykreslí výchozí
        if (!preg_match('/^[a-z0-9_-]+$/i', $layout) || !is_file(KALETA_ROOT . '/layout/' . $layout . '/base.php')) {
            $layout = Layouts::DEFAULTS;
        }
        $this->layout = $layout;
        // šablona se hledá nejdřív v layoutu webu, potom mezi systémovými - layout tak může přepsat cokoli
        $this->view = new View([KALETA_ROOT . '/layout/' . $layout, KALETA_SYSTEM . '/views/front']);
        $this->news = new NewsRepository($app->db(), $app->settings(), $app->request->basePath());
    }

    public function handle(): Response
    {
        $request = $this->app->request;
        if ($this->redirect !== null) {
            return $this->redirect;
        }
        // OAuth pro konektor Claude (metadata, registrace, tokeny) – běží i v režimu údržby, stejně jako /mcp
        if (($oauth = (new OAuth($this->app))->handle($request->path())) !== null) {
            return $oauth;
        }
        if ($this->app->settings()->bool('udrzba') && $request->path() !== '/mcp' && $this->app->auth()->user() === null) {
            return new Response('<!doctype html><html lang="' . e(Language::code()) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($this->app->settings()->get('nazev_webu')) . '</title>'
                . '<body style="font:18px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;padding:24px;text-align:center"><div><h1 style="font-size:28px">' . e($this->app->settings()->get('nazev_webu'))
                . '</h1><p>' . e($this->app->settings()->get('udrzba_text')) . '</p></div>', 503, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '3600']);
        }
        // stará číselná adresa WordPressu /?p=123 (po importu): její cesta je hlavní stránka, která existuje vždy, takže by se na
        // přesměrování při chybě 404 nikdy nedostalo – hledá se proto podle parametru, ještě před cache
        if ($request->getInt('p') > 0 && $request->path() === '/' && Extensions::isEnabled($this->app->settings(), 'presmerovani')) {
            $target = $this->app->db()->one('SELECT idp, na_adresu FROM {presmerovani} WHERE z_adresy = ?', ['?p=' . $request->getInt('p')]);
            if ($target !== null) {
                $this->app->db()->run('UPDATE {presmerovani} SET pocet = pocet + 1 WHERE idp = ?', [$target['idp']]);

                return Response::redirect(preg_match('#^https?://#i', $target['na_adresu']) ? $target['na_adresu'] : $this->app->url($target['na_adresu']), 301);
            }
        }
        if (($cached = Cache::load($this->app)) !== null) {
            return $cached;
        }
        $path = $request->path();
        if ($path === '/' || $path === '/index.php') {
            return $this->home();
        }
        $withNews = Extensions::isEnabled($this->app->settings(), 'novinky');
        if (!$withNews && ($path === '/novinky' || str_starts_with($path, '/novinky/') || $path === '/rss.xml' || $path === '/feed.json')) {
            return $this->notFound(); // rozšíření Novinky je vypnuté: data zůstávají, na webu nejsou
        }
        if ($path === '/novinky') {
            return $this->showNewsList();
        }
        if (preg_match('#^/novinky/kategorie/([a-z0-9-]+)$#', $path, $m)) {
            return $this->category($m[1]);
        }
        if (preg_match('#^/novinky/stitek/([a-z0-9-]+)$#', $path, $m)) {
            return $this->tag($m[1]);
        }
        if (preg_match('#^/novinky/([a-z0-9-]+)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_clanky')) {
            $newsItem = $this->news->bySlug($m[1]);

            return $newsItem === null
                ? $this->notFound()
                : new Response((new Seo($this->app))->newsItemMarkdown($newsItem), 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
        }
        if (preg_match('#^/novinky/([a-z0-9-]+)$#', $path, $m)) {
            return $this->newsItem($m[1]);
        }
        if ($path === '/hledani') {
            return $this->search();
        }
        if ($path === '/rss.xml') {
            return $this->rss();
        }
        $seo = new Seo($this->app);
        if ($path === '/manifest.webmanifest') {
            return new Response(SiteIdentity::manifest($this->app->settings(), $this->app->request->basePath()), 200, ['Content-Type' => 'application/manifest+json; charset=utf-8']);
        }
        if ($path === '/favicon.ico') {
            // prohlížeče se ptají samy; místo celé stránky 404 odkaz na ikonu webu, nebo prázdná odpověď
            $icon = is_file(KALETA_ROOT . '/media/ikona-32.png') ? $this->app->url('media/ikona-32.png') : null;

            return $icon !== null ? Response::redirect($icon, 301) : new Response('', 204, ['Cache-Control' => 'public, max-age=86400']);
        }
        if ($path === '/robots.txt') {
            return new Response($seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/sitemap.xml') {
            return new Response(Cache::text($this->app, 'sitemap', $seo->sitemapXml(...)), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
        }
        if ($path === '/feed.json') {
            $json = Cache::text($this->app, 'feed.json|' . Language::siteColumn(), fn (): string => (string) json_encode($seo->jsonFeed($this->news->listPublished(1, 20, true)[0]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return new Response($json, 200, ['Content-Type' => 'application/feed+json; charset=utf-8']);
        }
        if ($path === '/llms.txt' && $this->app->settings()->bool('llms_txt')) {
            return new Response(Cache::text($this->app, 'llms|' . Language::siteColumn(), $seo->llmsTxt(...)), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $indexNowKey = $this->app->settings()->get('indexnow_klic');
        if ($indexNowKey !== '' && $path === '/' . $indexNowKey . '.txt') {
            return new Response($indexNowKey, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/souhlas' && $request->isPost()) {
            // evidence souhlasu s cookies: bez IP adresy, jen náhodný identifikátor z cookie návštěvníka
            $category = implode(',', array_intersect(explode(',', $request->post('kategorie')), ['analytika', 'marketing'])) ?: 'nic';
            $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
            if ($this->app->settings()->bool('cookies_evidence') && preg_match('/^[a-f0-9]{32}$/', $request->post('id')) && $antispam->count($request->ip(), 'souhlas', 0, 60) < 20) {
                $antispam->write($request->ip(), 'souhlas', 0);
                $this->app->db()->insert('souhlasy', ['id_souhlasu' => $request->post('id'), 'cas' => date('Y-m-d H:i:s'), 'kategorie' => $category]);
            }

            return new Response('', 204);
        }
        if ($path === '/popup' && $request->isPost()) {
            // počitadla pop-up oken: zobrazení, zavření, konverze – bez cookies a bez údajů o návštěvníkovi
            $column = ['zobrazeni' => 'zobrazeni', 'zavreni' => 'zavreni', 'konverze' => 'konverze'][$request->post('udalost')] ?? null;
            $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
            if ($column !== null && $request->postInt('id') > 0 && $antispam->count($request->ip(), 'popup', 0, 60) < 60) {
                $antispam->write($request->ip(), 'popup', 0);
                $this->app->db()->run('UPDATE {popupy} SET ' . $column . ' = ' . $column . ' + 1 WHERE idpp = ? AND aktivni = 1', [$request->postInt('id')]);
            }

            return new Response('', 204);
        }
        if (str_starts_with($path, '/api/') && Extensions::isEnabled($this->app->settings(), 'api')) {
            return (new Api($this->app, $this->news))->handle($path);
        }
        if ($path === '/mcp') {
            return (new \Kaleta\Mcp\Server($this->app))->handle();
        }
        // odber: přihlášení z prvku (jen se zapnutým Newsletterem); potvrzení a odhlášení odkazem z e-mailu fungují vždy –
        // i po vypnutí rozšíření musí jít odhlásit z už rozeslaných e-mailů
        $subscriptionLink = $request->get('potvrdit') !== '' || $request->get('odhlasit') !== '';
        if ($path === '/odber' && ($subscriptionLink || Extensions::isEnabled($this->app->settings(), 'newsletter'))) {
            $subscription = new Subscription($this->app);
            if ($request->isPost() && !$subscriptionLink) {
                $back = $request->post('zpet');
                $back = preg_match('~^/[^\s\\\\?#]*$~', $back) && !str_starts_with($back, '//') ? $back : $this->app->url('');
                $anchor = preg_match('/^[a-z0-9-]{1,60}$/', $request->post('kotva')) ? '#' . $request->post('kotva') : '';

                return Response::redirect($back . '?odber=' . $subscription->subscribe() . $anchor, 303);
            }
            [$heading, $content] = $subscription->link();

            return $this->page($heading, '<header class="vypis-hlavicka"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Zpět na úvod')) . '</a></p>', ['noindex' => true]);
        }
        if ($path === '/formular' && Extensions::isEnabled($this->app->settings(), 'poptavky')) {
            return (new Forms($this->app))->process();
        }
        if ($path === '/ulohy') {
            // úlohy na pozadí pro cron: weby s malou návštěvností tak vydají naplánovanou novinku a odešlou poštu včas
            $token = $this->app->settings()->get('ulohy_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return new Response(t('Neplatný token.') . "\n", 403, ['Content-Type' => 'text/plain; charset=utf-8']);
            }
            $done = [];
            try {
                \Kaleta\Core\Notifications::process($this->app);
                $done[] = 'oznameni';
                \Kaleta\Core\Notifications::purgePersonalData($this->app, true);
                $done[] = 'uklid';
                \Kaleta\Core\Backup::createAutomatic($this->app->db(), $this->app->settings());
                $done[] = 'zalohy';
                $done[] = 'posta:' . \Kaleta\Core\Mail::processQueue($this->app->settings(), 30);
            } catch (\Throwable $e) {
                $done[] = 'chyba: ' . $e->getMessage();
            }

            return new Response('OK ' . date('c') . ' ' . implode(', ', $done) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        if ($path === '/stav.json') {
            $token = $this->app->settings()->get('stav_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return Response::json(['chyba' => 'Neplatný token.'], 403);
            }
            // monitoring dostává texty vždy česky - nesmí se měnit podle jazyka zobrazené verze webu
            $checks = Language::runWith('cs', fn (): array => \Kaleta\Core\Health::checks($this->app));

            return Response::json(['stav' => \Kaleta\Core\Health::summary($checks), 'verze' => KALETA_VERSION, 'cas' => date('c'), 'kontroly' => $checks]);
        }

        // skrytou stránku vidí jen náhled builderu (kdo smí upravovat stránky) a podepsaný odkaz na náhled (?nahled_klic=…, Core\Nahled)
        $showHidden = $request->get('stavba') === 'koncept' && ($this->app->auth()->hasModule('stranky') || $request->get('nahled_klic') !== '');
        $page = $this->app->db()->one('SELECT * FROM {stranky} WHERE seo_link = ? AND jazyk = ? AND smazano IS NULL' . ($showHidden ? '' : ' AND zobrazit = 1'), [ltrim($path, '/'), Language::siteColumn()]);
        if ($page !== null && !$page['zobrazit'] && !$this->canSeeDraft('stranka:' . (int) $page['ids'])) {
            $page = null;
        }
        if ($page !== null) {
            if ((int) $page['ids'] === $this->homePageId() && !$showHidden) {
                return Response::redirect($this->app->url(''), 301); // úvodní stránka má jen jednu adresu – kořen webu
            }

            return $this->showPage($page, ltrim($path, '/'));
        }
        if (preg_match('#^/_sekce/([a-z0-9-]{1,40})$#', $path, $m) && $this->app->auth()->hasModule('stranky')) {
            return $this->previewSection($m[1]);
        }
        if (preg_match('#^/_popup/(\d+)$#', $path, $m)) {
            return $this->previewPopup((int) $m[1]);
        }
        if (preg_match('#^/_komponenta/(\d+)$#', $path, $m) && $this->app->auth()->isAdmin()) {
            return $this->previewComponent((int) $m[1]);
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#', $path, $m) && $m[1] !== 'novinky') {
            return $this->showCollectionItem($m[1], $m[2]);
        }

        return $this->notFound();
    }

    /**
     * Náhled hotové sekce knihovny pro panel builderu: jen sekce ve vzhledu webu, bez záhlaví a patičky.
     * Třídy knihovny se jen vykreslí z jejich výchozího stylu – do webu se nic nezapisuje.
     */
    private function previewSection(string $key): Response
    {
        $section = \Kaleta\Builder\Library::section($key, Language::code());
        if ($section === null) {
            return $this->notFound();
        }
        $k = new \Kaleta\Builder\Context($this->app);
        $html = \Kaleta\Builder\Build::html(['deti' => [$section['prvek']]], $k);
        $classes = '';
        foreach ($section['tridy'] as $t) {
            $classes .= \Kaleta\Builder\Style::css('.' . $t, \Kaleta\Builder\Library::CLASSES[$t] ?? []);
        }
        $k->classes = []; // styl tříd výše z knihovny, ne z databáze webu (na webu třída ještě nemusí být)
        $siteSettings = $this->app->settings();
        $css = \Kaleta\Builder\DesignSystem::css(\Kaleta\Builder\DesignSystem::load($siteSettings), $this->app->request->basePath()) . \Kaleta\Builder\Build::css($this->app->db(), $k) . '@layer tridy {' . $classes . '}';
        $layout = $this->app->url('layout/' . $this->layout . '/style.css');

        return new Response('<!doctype html><html lang="' . e(Language::code()) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<link rel="stylesheet" href="' . e($layout) . '"><link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '"><style>' . $css . 'body{margin:0}</style></head>'
            . '<body><main class="stavba">' . $html . '</main></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
    }

    /** Plátno editoru komponenty (jen správce): rozpracovaná komponenta s výchozími hodnotami vlastností. */
    private function previewComponent(int $idm): Response
    {
        $component = \Kaleta\Builder\Components::byId($this->app->db(), $idm);
        if ($component === null) {
            return $this->notFound();
        }
        $k = $this->context();
        $k->item = \Kaleta\Builder\Components::values($component, []);
        $k->editor = $this->app->request->get('editor') === '1';
        $html = \Kaleta\Builder\Build::html(\Kaleta\Builder\Build::fromJson($component['stavba_koncept'] ?? $component['stavba']) ?? ['deti' => []], $k);
        [$k->item, $k->editor] = [null, false];

        return $this->page($component['nazev'], $this->view->render('stranka', ['stranka' => ['titulek' => ''], 'uvod' => false, 'stavba' => $html]), ['stavba' => true, 'noindex' => true]);
    }

    /**
     * Stránka položky kolekce (/<kolekce>/<položka>) podle šablony detailu z builderu. Správce vidí v editoru koncept
     * šablony (?stavba=koncept&editor=1), a když kolekce ještě nemá položky, ukázku s popisky polí (/<kolekce>/_ukazka).
     */
    private function showCollectionItem(string $collectionSlug, string $seo): Response
    {
        $db = $this->app->db();
        $r = $this->app->request;
        $collection = \Kaleta\Builder\Collections::bySlug($db, $collectionSlug);
        // šablona detailu v jazyce zobrazené verze webu; jazyk bez vlastní šablony použije šablonu výchozího jazyka
        $template = $collection !== null ? \Kaleta\Builder\Collections::inLanguage($db, $collection, Language::siteColumn()) : null;
        // koncept šablony: správce, nebo podepsaný náhled právě této šablony (Core\Nahled, cíl kolekce:<idk>[:<jazyk>])
        $draft = $template !== null && $r->get('stavba') === 'koncept' && ($this->app->auth()->isAdmin() || $this->canSeeDraft(\Kaleta\Builder\Collections::templateKey($template)));
        if ($collection === null || (!$collection['detail'] && !$draft)) {
            return $this->notFound();
        }
        $item = $db->one('SELECT * FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND jazyk = ?' . ($draft ? '' : ' AND zobrazit = 1'), [$collection['idk'], $seo, Language::siteColumn()]);
        if ($item === null && !($draft && $seo === '_ukazka')) {
            return $this->notFound();
        }
        if ($item !== null) {
            $item['data'] = json_decode((string) $item['data'], true) ?: [];
        }
        $build = \Kaleta\Builder\Build::fromJson($draft ? ($template['stavba_koncept'] ?? $template['stavba']) : $template['stavba'])
            ?? \Kaleta\Builder\Build::fromJson($draft ? ($collection['stavba_koncept'] ?? $collection['stavba']) : $collection['stavba'])
            ?? \Kaleta\Builder\Collections::defaultTemplate($collection);
        // úroveň kolekce odkazuje na stránku se stejnou adresou (např. /navod nad /navod/<článek>), když na webu je – s jejím
        // titulkem; v další jazykové verzi na její překlad (adresy stránek jsou jedinečné napříč jazyky: /de/vergleich)
        $parentPage = null;
        $main = $db->one('SELECT ids, preklad_z, jazyk, seo_link, titulek, zobrazit FROM {stranky} WHERE seo_link = ? AND smazano IS NULL', [$collection['seo_link']]);
        if ($main !== null && $main['jazyk'] === Language::siteColumn()) {
            $parentPage = $main['zobrazit'] ? $main : null;
        } elseif ($main !== null) {
            $original = (int) ($main['preklad_z'] ?: $main['ids']);
            $parentPage = $db->one('SELECT seo_link, titulek FROM {stranky} WHERE (ids = ? OR preklad_z = ?) AND jazyk = ? AND zobrazit = 1 AND smazano IS NULL LIMIT 1', [$original, $original, Language::siteColumn()]);
        }
        $this->breadcrumbs([$parentPage !== null && $parentPage['titulek'] !== '' ? (string) $parentPage['titulek'] : $collection['nazev'], $parentPage !== null ? $this->app->url((string) $parentPage['seo_link']) : ''],
            [$item['nazev'] ?? t('Ukázková položka'), '']);
        $this->collectionItem = $item !== null ? [(int) $collection['idk'], (string) $collection['seo_link'], (string) $item['seo_link']] : null;
        $k = $this->context();
        $k->item = $item !== null ? \Kaleta\Builder\Collections::values($collection, $item, $this->app->url(...)) : \Kaleta\Builder\Collections::sample($collection);
        $k->editor = $draft && $r->get('editor') === '1';
        $k->source = 'kolekce:' . (int) $collection['idk'];
        $this->pageCollection = (string) $collection['seo_link'];
        $html = \Kaleta\Builder\Build::html($build, $k);
        [$k->item, $k->editor] = [null, false];

        // popis a obrázek pro vyhledávače a sdílení: první delší text a první obrázek položky
        $description = '';
        $image = '';
        foreach ($collection['pole'] as $field) {
            $h = (string) ($item['data'][$field['klic']] ?? '');
            if ($description === '' && in_array($field['typ'], ['radky', 'html'], true) && $h !== '') {
                $description = mb_strimwidth(trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)), 0, 300, '…');
            }
            if ($image === '' && $field['typ'] === 'obrazek' && $h !== '') {
                $image = preg_match('#^https?://#', $h) ? $h : $this->app->request->origin() . $this->app->url(ltrim($h, '/'));
            }
        }

        return $this->page((string) ($item['nazev'] ?? $collection['nazev']), $this->view->render('stranka', ['stranka' => ['titulek' => ''], 'uvod' => false, 'stavba' => $html]), [
            'popis' => $description, 'obrazek' => $image, 'stavba' => true, 'noindex' => $draft,
        ]);
    }

    /** Číslo úvodní stránky v jazyce zobrazené verze webu (protějšek stránky z Nastavení); 0 = úvodem je výpis novinek. */
    private function homePageId(): int
    {
        $id = $this->app->settings()->int('titulni_stranka');
        if ($id === 0 || Language::siteColumn() === '') {
            return $id;
        }

        return (int) $this->app->db()->value('SELECT ids FROM {stranky} WHERE preklad_z = ? AND jazyk = ?', [$id, Language::siteColumn()]);
    }

    private function home(): Response
    {
        $page = ($id = $this->homePageId()) > 0 ? $this->app->db()->one('SELECT * FROM {stranky} WHERE ids = ? AND zobrazit = 1', [$id]) : null;

        if ($page === null && !Extensions::isEnabled($this->app->settings(), 'novinky')) {
            // bez úvodní stránky i bez novinek: první zveřejněná stránka webu
            $page = $this->app->db()->one('SELECT * FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND jazyk = ? ORDER BY poradi, ids LIMIT 1', [Language::siteColumn()]);
        }

        return $page !== null ? $this->showPage($page, '', true) : (Extensions::isEnabled($this->app->settings(), 'novinky') ? $this->showNewsList(true) : $this->notFound());
    }

    /** @param array<string, mixed> $page */
    private function showPage(array $page, string $path, bool $home = false): Response
    {
        $this->counterpart = ['stranky', 'ids', $page, ''];
        $this->isHome = $home;
        if (!$home) {
            // podstránka: v drobečcích i nadřazené stránky (podle adresy sluzby/kuchyne → sluzby)
            $levels = [];
            $segments = explode('/', (string) $page['seo_link']);
            for ($i = 1; $i < count($segments); $i++) {
                $parent = $this->app->db()->one('SELECT titulek, seo_link FROM {stranky} WHERE seo_link = ? AND jazyk = ? AND zobrazit = 1 AND smazano IS NULL', [implode('/', array_slice($segments, 0, $i)), $page['jazyk']]);
                if ($parent !== null) {
                    $levels[] = [$parent['titulek'], $this->app->url($parent['seo_link'])];
                }
            }
            $this->breadcrumbs(...[...$levels, [$page['titulek'], '']]);
        }
        // titulek a údaje pro vyhledávače a sdílení (vlastní titulek, obrázek, noindex – jako u novinek)
        $title = $page['seo_titulek'] !== '' ? $page['seo_titulek'] : ($home ? '' : $page['titulek']);
        $meta = [
            'popis' => $page['popis'] !== '' ? $page['popis'] : ($home ? $this->app->settings()->get('popis_webu') : ''),
            'hlavni' => $home, 'obrazek' => $page['obrazek'], 'noindex' => (bool) $page['noindex'],
        ];
        // náhled rozpracované stavby pro editor: ?stavba=koncept (jen kdo smí upravovat stránky), &editor=1 přidá značky pro výběr prvků
        $draft = $this->app->request->get('stavba') === 'koncept' && $this->canSeeDraft('stranka:' . (int) $page['ids']);
        $build = \Kaleta\Builder\Build::fromJson($draft ? ($page['stavba_koncept'] ?? $page['stavba']) : $page['stavba']);
        if ($build !== null) {
            $k = $this->context();
            $k->editor = $draft && $this->app->request->get('editor') === '1' && $this->app->request->get('cast') === '';
            $k->source = 'stranka:' . (int) $page['ids'];
            $html = \Kaleta\Builder\Build::html($build, $k);
            $k->editor = false;
            if (!$draft && $this->app->auth()->hasModule('stranky')) {
                $this->editHereUrl = $this->app->url('admin.php?modul=stranky&akce=stavitel&id=' . (int) $page['ids']);
            }

            return $this->page($title, $this->view->render('stranka', ['stranka' => $page, 'uvod' => $home, 'stavba' => $html]), [
                'stavba' => true, 'noindex' => $draft || $meta['noindex'],
            ] + $meta);
        }
        if (($form = $this->editInPlace('stranka', $page, $path)) !== null) {
            return $this->page($page['titulek'], $form, ['noindex' => true]);
        }

        return $this->page($title, $this->view->render('stranka', ['stranka' => $page, 'uvod' => $home, 'stavba' => null]), $meta);
    }

    private function showNewsList(bool $home = false): Response
    {
        $pageNumber = max(1, $this->app->request->getInt('strana', 1));
        [$news, $total] = $this->news->listPublished($pageNumber);
        if (!$home) {
            $this->breadcrumbs([t('Novinky'), '']);
        }

        return $this->page($home ? '' : t('Novinky'), $this->view->render('vypis', ['nadpis' => t('Novinky'), 'popis' => ''] + $this->listVariables($news, $total, $pageNumber, $home ? '' : 'novinky')), [
            'hlavni' => $home,
            'popis' => $this->app->settings()->get('popis_webu'),
            'cast' => 'vypis',
        ]);
    }

    private function category(string $seo): Response
    {
        $category = $this->app->db()->one('SELECT * FROM {kategorie} WHERE seo_link = ? AND jazyk = ?', [$seo, Language::siteColumn()]);
        if ($category === null) {
            return $this->notFound();
        }
        $this->counterpart = ['kategorie', 'idt', $category, 'novinky/kategorie/'];
        $this->breadcrumbs([t('Novinky'), $this->app->url('novinky')], [$category['nazev'], '']);
        $pageNumber = max(1, $this->app->request->getInt('strana', 1));
        [$news, $total] = $this->news->inCategory((int) $category['idt'], $pageNumber);

        return $this->page(
            $category['nazev'],
            $this->view->render('vypis', ['nadpis' => $category['nazev'], 'popis' => \Kaleta\Core\Html::safe((string) $category['popis'])] + $this->listVariables($news, $total, $pageNumber, 'novinky/kategorie/' . $seo)),
            ['popis' => strip_tags($category['popis']), 'cast' => 'vypis'],
        );
    }

    private function tag(string $seo): Response
    {
        $tag = $this->app->db()->one('SELECT * FROM {stitky} WHERE seo_link = ?', [$seo]);
        if ($tag === null) {
            return $this->notFound();
        }
        $pageNumber = max(1, $this->app->request->getInt('strana', 1));
        [$news, $total] = $this->news->withTag((int) $tag['ids'], $pageNumber);
        // štítek s popisem je stránka tématu: úvod a vlastní popis pro vyhledávače
        $colorScheme = trim((string) $tag['popis']) !== '';

        return $this->page($colorScheme ? $tag['nazev'] : t('Štítek') . ' ' . $tag['nazev'], $this->view->render('vypis', [
            'nadpis' => ($colorScheme ? '' : '#') . $tag['nazev'], 'popis' => $colorScheme ? \Kaleta\Core\Html::safe((string) $tag['popis']) : '',
        ] + $this->listVariables($news, $total, $pageNumber, 'novinky/stitek/' . $seo)), [
            'popis' => $colorScheme ? mb_strimwidth(trim(strip_tags((string) $tag['popis'])), 0, 300, '…') : '',
            'cast' => 'vypis',
        ]);
    }

    private function newsItem(string $seo): Response
    {
        $preview = $this->app->request->get('nahled') === '1' && $this->app->auth()->user() !== null;
        $newsItem = $this->news->bySlug($seo, $preview);
        if ($newsItem === null) {
            return $this->notFound();
        }
        if ($newsItem['jazyk'] !== Language::siteColumn()) {
            // novinka patří do jiné jazykové verze, než ze které přišel požadavek
            if ($newsItem['jazyk'] !== '' && !in_array($newsItem['jazyk'], Language::additional($this->app->settings()), true)) {
                return $this->notFound(); // její jazyková verze je vypnutá: přesměrování by vedlo zpět na tutéž adresu
            }
            $this->app->languagePrefix = $newsItem['jazyk'];

            return Response::redirect($this->app->url('novinky/' . $newsItem['seo_link']) . ($preview ? '?nahled=1' : ''), 301);
        }
        // úprava přímo na webu pracuje se surovým textem z databáze (bez osnovy a vložených přehrávačů)
        $raw = $this->app->auth()->user() === null ? null : $this->app->db()->one('SELECT * FROM {novinky} WHERE idc = ?', [$newsItem['idc']]);
        if ($raw !== null && ($form = $this->editInPlace('novinka', $raw, 'novinky/' . $newsItem['seo_link'])) !== null) {
            return $this->page($newsItem['titulek'], $form, ['noindex' => true]);
        }
        if (!$preview) {
            $this->app->db()->run('UPDATE {novinky} SET visit = visit + 1 WHERE idc = ?', [$newsItem['idc']]);
        }

        $this->breadcrumbs([t('Novinky'), $this->app->url('novinky')], [$newsItem['tema_jm'], $this->app->url('novinky/kategorie/' . $newsItem['tema_seo'])], [$newsItem['titulek'], '']);
        $newsItem['faq_html'] = (new View([KALETA_SYSTEM . '/views/front']))->render('faq', ['faq' => Seo::faq($newsItem['faq'])]);
        $newsItem = (new NewsText($this->app))->complete($newsItem);
        $newsItem['stitky'] = $this->app->db()->all('SELECT s.nazev, s.seo_link FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY s.nazev', [$newsItem['idc']]);

        $content = $this->view->render('novinka', [
            'novinka' => $newsItem,
            'url' => $this->app->url(...),
            'souvisejici' => $this->app->settings()->bool('souvisejici_auto') ? $this->news->similar($newsItem) : [],
        ]);

        return $this->page($newsItem['seo_titulek'] !== '' ? $newsItem['seo_titulek'] : $newsItem['titulek'], $content, [
            'clanek' => $newsItem,
            'popis' => $newsItem['seo_popis'] !== '' ? $newsItem['seo_popis'] : mb_strimwidth(trim(strip_tags($newsItem['uvod'])), 0, 300, '…'),
            'klicova_slova' => $newsItem['t_slova'],
            'obrazek' => $newsItem['obrazek'],
            'noindex' => (bool) $newsItem['noindex'],
            'typ' => 'article',
            'cast' => 'novinka',
        ]);
    }

    private function search(): Response
    {
        $q = mb_substr($this->app->request->get('q'), 0, 100);
        // hledání je nejdražší dotaz webu a necachuje se: nejvýš 30 hledání za minutu z jedné adresy
        $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
        if (mb_strlen($q) >= 3) {
            if ($antispam->count($this->app->request->ip(), 'hledani', 0, 1) >= 30) {
                return new Response(t('Příliš mnoho hledání za sebou. Zkuste to prosím za chvíli.'), 429, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '60']);
            }
            $antispam->write($this->app->request->ip(), 'hledani', 0);
        }
        $pageNumber = max(1, $this->app->request->getInt('strana', 1));
        [$news, $total] = mb_strlen($q) >= 3 && Extensions::isEnabled($this->app->settings(), 'novinky') ? $this->news->search($q, $pageNumber) : [[], 0];
        // stránky a položky kolekcí s vlastní stránkou – bez ohledu na diakritiku, s úryvkem (novinky hledá fulltext výše)
        $pages = [];
        if (mb_strlen($q) >= 3 && $pageNumber === 1) {
            $db = $this->app->db();
            $home = $this->homePageId();
            $candidates = array_map(fn (array $s): array => ['titulek' => $s['titulek'], 'adresa' => (int) $s['ids'] === $home ? '' : $s['seo_link'], 'text' => (string) $s['text']],
                $db->all('SELECT ids, titulek, seo_link, text FROM {stranky} WHERE zobrazit = 1 AND noindex = 0 AND smazano IS NULL AND jazyk = ? ORDER BY poradi LIMIT 500', [Language::siteColumn()]));
            foreach ($db->all('SELECT p.nazev, p.seo_link, p.data, k.seo_link AS kolekce, k.pole FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.jazyk = ? ORDER BY p.poradi LIMIT 2000', [Language::siteColumn()]) as $p) {
                $data = json_decode((string) $p['data'], true);
                // hledá se jen v textových polích – cesty k obrázkům a adresy odkazů by dělaly šum ve výsledcích i úryvcích
                $textFields = array_column(array_filter(json_decode((string) $p['pole'], true) ?: [], fn (array $f): bool => in_array($f['typ'] ?? '', ['text', 'radky', 'html'], true)), 'klic');
                $candidates[] = ['titulek' => $p['nazev'], 'adresa' => $p['kolekce'] . '/' . $p['seo_link'],
                    'text' => implode(' ', array_filter(array_intersect_key(is_array($data) ? $data : [], array_flip($textFields)), 'is_string'))];
            }
            $pages = array_map(fn (array $v): array => ['titulek' => $v['titulek'], 'seo_link' => $v['adresa'], 'uryvek' => $v['uryvek']], \Kaleta\Core\Search::find($q, $candidates));
        }

        return $this->page(
            t('Vyhledávání'),
            $this->view->render('vypis', ['nadpis' => t('Vyhledávání'), 'popis' => '', 'hledano' => $q, 'nalezeneStranky' => $pages] + $this->listVariables($news, $total, $pageNumber, 'hledani', ['q' => $q])),
            ['noindex' => true],
        );
    }

    private function rss(): Response
    {
        $xml = Cache::text($this->app, 'rss|' . Language::siteColumn(), fn (): string => $this->view->render('rss', [
            'web' => $this->app->settings(),
            'novinky' => $this->news->listPublished(1, 20)[0],
            'adresa' => $this->app->request->origin() . $this->app->url(''),
        ]));

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    private function notFound(): Response
    {
        // než web odpoví 404, zkusí přesměrování ze staré adresy (ruční, po importu i po změně adresy)
        $target = Extensions::isEnabled($this->app->settings(), 'presmerovani')
            ? $this->app->db()->one('SELECT * FROM {presmerovani} WHERE z_adresy = ?', [trim($this->app->request->path(), '/')])
            : null;
        if ($target !== null) {
            $this->app->db()->run('UPDATE {presmerovani} SET pocet = pocet + 1 WHERE idp = ?', [$target['idp']]);

            return Response::redirect(preg_match('#^https?://#i', $target['na_adresu']) ? $target['na_adresu'] : $this->app->url($target['na_adresu']), (int) ($target['typ'] ?? 301) === 302 ? 302 : 301);
        }

        // přehled nenalezených adres pro správce (Přesměrování); roboti zkoušející cizí systémy se nezapisují
        $path = mb_substr(trim($this->app->request->path(), '/'), 0, 255);
        if ($path !== '' && $this->app->request->get('cast') === '' && !preg_match('#\.(php|asp|aspx|env|git|sql|bak|ini|xml|txt|js|css|map|png|jpe?g|gif|ico|webp)$|^(wp-|\.|cgi-bin|vendor/|admin/)#i', $path) && mb_check_encoding($path, 'UTF-8')) {
            try {
                if ((int) $this->app->db()->value('SELECT COUNT(*) FROM {nenalezeno}') < 2000 || $this->app->db()->value('SELECT 1 FROM {nenalezeno} WHERE cesta = ?', [$path]) !== null) {
                    $this->app->db()->run('INSERT INTO {nenalezeno} (cesta, pocet, naposledy) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE pocet = pocet + 1, naposledy = NOW()', [$path]);
                }
            } catch (\Throwable) {
                // přehled je jen pomůcka - chyba zápisu nesmí změnit odpověď
            }
        }

        return $this->page(t('Stránka nenalezena'), $this->view->render('nenalezeno', ['url' => $this->app->url(...), 'stranky' => $this->menuPages(), 'novinky' => Extensions::isEnabled($this->app->settings(), 'novinky')]), ['noindex' => true, 'cast' => 'nenalezeno'], 404);
    }

    /**
     * @param list<array<string, mixed>> $news
     * @param array<string, string> $params další parametry stránkovacích odkazů
     * @return array<string, mixed>
     */
    private function listVariables(array $news, int $total, int $pageNumber, string $path, array $params = []): array
    {
        $this->pastEnd = $pageNumber > 1 && $news === [];

        return [
            'novinky' => $news,
            'celkem' => $total,
            'strana' => $pageNumber,
            'stran' => max(1, (int) ceil($total / $this->news->perPage())),
            'strankaUrl' => fn (int $s): string => $this->app->url($path) . (($query = http_build_query($params + ($s > 1 ? ['strana' => $s] : []))) !== '' ? '?' . $query : ''),
            'hledano' => null,
            'nalezeneStranky' => [],
            'url' => $this->app->url(...),
        ];
    }

    /**
     * Přepínač jazyků pro šablonu: kód => [název, adresa, je aktivní]. U novinky vede na její překlad, jinak na protějšek
     * stránky či kategorie, a když ho nemá, na úvod verze. Prázdné pole = web má jediný jazyk.
     *
     * @param array<string, mixed>|null $newsItem
     * @return array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}>
     */
    private function languages(?array $newsItem): array
    {
        $siteSettings = $this->app->settings();
        // rozpracovaný jazyk (bez zveřejněného překladu úvodu) přepínač nenabízí; na jeho vlastních stránkách zůstane
        $publishedLanguages = Language::published($siteSettings, $this->app->db());
        $additional = array_values(array_filter(Language::additional($siteSettings), fn (string $j): bool => in_array($j, $publishedLanguages, true) || $j === Language::siteColumn()));
        if ($additional === []) {
            return [];
        }
        $translations = [];
        if ($newsItem !== null) {
            $original = (int) ($newsItem['preklad_z'] ?: $newsItem['idc']);
            $translations = array_map(fn (string $seo): string => 'novinky/' . $seo, $this->app->db()->pairs('SELECT jazyk, seo_link FROM {novinky} WHERE (idc = ? OR preklad_z = ?) AND visible = 1 AND datum <= NOW()', [$original, $original]));
        } elseif ($this->collectionItem !== null) {
            [$idk, $collection, $seo] = $this->collectionItem;
            $translations = array_map(fn (string $s): string => $collection . '/' . $s, $this->app->db()->pairs('SELECT jazyk, seo_link FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND zobrazit = 1', [$idk, $seo]));
        } elseif ($this->counterpart !== null && !$this->isHome) {
            // kategorie nebo stránka: originál + jeho překlady
            [$table, $key, $row, $path] = $this->counterpart;
            $original = (int) ($row['preklad_z'] ?: $row[$key]);
            $condition = $table === 'stranky' ? ' AND zobrazit = 1' : '';
            $translations = array_map(fn (string $seo): string => $path . $seo, $this->app->db()->pairs("SELECT jazyk, seo_link FROM {{$table}} WHERE ({$key} = ? OR preklad_z = ?){$condition}", [$original, $original]));
        }
        $root = $this->app->request->basePath() . '/';
        $result = [];
        foreach ([Language::defaults($siteSettings), ...$additional] as $code) {
            $column = Language::column($siteSettings, $code);
            $prefix = $column === '' ? '' : $column . '/';
            $result[$code] = [
                'nazev' => Language::AVAILABLE[$code][0],
                'url' => $root . $prefix . ($translations[$column] ?? ''),
                'aktivni' => $code === Language::code(),
                'preklad' => isset($translations[$column]),
            ];
        }

        return $result;
    }

    /**
     * Úprava stránky nebo novinky přímo na webu. Bez práva nedělá nic; s právem připraví odkaz „Upravit zde“
     * a při ?upravit=text vrátí formulář s editorem místo obsahu. Ukládá administrace (akce uloz_text).
     *
     * @param array<string, mixed> $record řádek ka_stranky nebo ka_novinky
     */
    private function editInPlace(string $type, array $record, string $path): ?string
    {
        $auth = $this->app->auth();
        if ($auth->user() === null || !($type === 'novinka' ? $auth->canEditArticle($record) : $auth->hasModule('stranky'))) {
            return null;
        }
        $url = $this->app->url($path);
        if ($this->app->request->get('upravit') !== 'text') {
            // koncept je vidět jen v náhledu – bez něj by „Upravit zde“ skončilo na stránce Nenalezeno
            $this->editHereUrl = $url . ($this->app->request->get('nahled') === '1' ? '?nahled=1&upravit=text' : '?upravit=text');

            return null;
        }

        return $this->view->render('upravit', [
            'app' => $this->app, 'typ' => $type, 'zaznam' => $record,
            'zpet' => $url . ($this->app->request->get('nahled') === '1' ? '?nahled=1' : ''),
            'akce' => $this->app->url('admin.php?modul=' . ($type === 'novinka' ? 'novinky' : 'stranky') . '&akce=uloz_text'),
            'chyba' => $this->app->request->get('chyba') === '1',
        ]);
    }

    /** @var list<array{titulek:string, seo_link:string, uvod:bool}>|null */
    private ?array $menuPages = null;

    /** Stránky do hlavní navigace šablony (úvodní stránka vede na kořen webu). */
    private function menuPages(): array
    {
        if ($this->menuPages === null) {
            $home = $this->homePageId();
            $this->menuPages = array_map(
                fn (array $s): array => ['titulek' => $s['titulek'], 'seo_link' => (int) $s['ids'] === $home ? '' : $s['seo_link'], 'uvod' => (int) $s['ids'] === $home],
                $this->app->db()->all('SELECT ids, titulek, seo_link FROM {stranky} WHERE zobrazit = 1 AND v_menu = 1 AND jazyk = ? ORDER BY poradi, titulek', [Language::siteColumn()]),
            );
        }

        return $this->menuPages;
    }

    /** Drobečková navigace zobrazené stránky (prvek Drobečky a BreadcrumbList): Úvod a zadané úrovně. */
    private function breadcrumbs(array ...$levels): void
    {
        $this->context()->breadcrumbs = [[t('Úvod'), $this->app->url('')], ...$levels];
    }

    /** @var array<string, list<array<string, mixed>>> umístění => položky menu (Core\Menu) */
    private array $menu = [];

    /** @return list<array<string, mixed>> */
    private function menu(string $location): array
    {
        return $this->menu[$location] ??= \Kaleta\Core\Menu::items($this->app, $location, Language::siteColumn(), $this->homePageId());
    }

    /**
     * Složí stránku: obsah obalí layoutem webu (hlavička s navigací, patička), doplní SEO a uloží do cache.
     *
     * @param array<string, mixed> $meta
     */
    private function context(): \Kaleta\Builder\Context
    {
        if ($this->context === null) {
            $this->context = new \Kaleta\Builder\Context($this->app);
            // cesta zobrazené stránky už pro obsah (odkazy filtru a stránkování výpisu kolekce, aktivní položka navigace)
            $this->context->path = (string) parse_url($this->app->url(ltrim($this->app->request->path(), '/')), PHP_URL_PATH);
        }

        return $this->context;
    }

    /**
     * Části webu z builderu: obálka kolem obsahu (novinka, výpis, 404), záhlaví a patička. Část bez publikované stavby
     * vrátí null a layout vykreslí svou. Správce vidí v editoru koncept části (?cast=<typ>&stavba=koncept&editor=1).
     *
     * @param array<string, mixed> $meta
     * @return array{0: string, 1: array{hlavicka: ?string, paticka: ?string}, 2: array<string, mixed>}
     */
    /** Smí návštěvník vidět koncept: kdo upravuje stránky (u částí webu správce), nebo platný podepsaný odkaz na náhled cíle. */
    private function canSeeDraft(string $target): bool
    {
        $auth = $this->app->auth();
        if (str_starts_with($target, 'cast:') || str_starts_with($target, 'kolekce:') || str_starts_with($target, 'popup:') ? $auth->isAdmin() : $auth->hasModule('stranky')) {
            return true;
        }
        $key = $this->app->request->get('nahled_klic');

        return $key !== '' && \Kaleta\Core\Preview::verify($this->app->db(), $this->app->settings(), $target, $key);
    }

    private function siteParts(string $content, array $meta, string $languageSwitcher, string $path): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $k = $this->context();
        $k->menu = ['hlavni' => $this->menu('hlavni'), 'paticka' => $this->menu('paticka')];
        $k->path = $path;
        $k->languages = $languageSwitcher;
        $preview = isset(\Kaleta\Builder\SiteParts::TYPES[$r->get('cast')]) && $r->get('stavba') === 'koncept'
            && ($this->app->auth()->isAdmin() || $this->canSeeDraft('cast:' . $r->get('cast') . ':' . Language::siteColumn() . ($r->get('varianta') !== '' ? ':' . $r->get('varianta') : ''))) ? $r->get('cast') : '';
        $editor = $r->get('editor') === '1' && ($preview !== '' || ($r->get('stavba') === 'koncept' && $r->get('cast') === ''));
        $language = Language::siteColumn();
        // stránka webu může mít vlastní variantu záhlaví a patičky; v editoru varianty rozhoduje parametr ?varianta=
        $ids = ($this->counterpart[0] ?? '') === 'stranky' ? (int) $this->counterpart[2]['ids'] : null;
        $previewVariant = preg_match(\Kaleta\Builder\SiteParts::VARIANT_PATTERN, $r->get('varianta')) ? $r->get('varianta') : '';
        $render = function (string $type) use ($db, $k, $preview, $editor, $language, $ids, $previewVariant): ?string {
            try {
                $variant = $preview === $type ? $previewVariant : \Kaleta\Builder\SiteParts::pageVariant($db, $type, $language, $ids);
                $build = \Kaleta\Builder\SiteParts::build($db, $type, $language, $preview === $type, $variant);
            } catch (\Throwable $e) {
                error_log('Části webu: ' . $e->getMessage()); // web bez tabulky (před migrací) vykreslí části ze šablony

                return null;
            }
            if ($build === null) {
                return null;
            }
            $k->editor = $editor && $preview === $type;
            $k->source = 'cast:' . $type . ':' . $language . ($variant !== '' ? ':' . $variant : '');
            $html = \Kaleta\Builder\Build::html($build, $k);
            $k->editor = false;

            return $html;
        };

        $wrapper = $meta['cast'] ?? null;
        unset($meta['cast']);
        if ($wrapper !== null) {
            $k->content = $content;
            if (($html = $render($wrapper)) !== null) {
                $content = $html;
                $meta['stavba'] = true;
            }
            $k->content = '';
        }
        $parts = ['hlavicka' => $render('hlavicka'), 'paticka' => $render('paticka')];
        $meta['popupy'] = $this->popups($k, in_array($wrapper, ['novinka', 'vypis'], true));
        if ($r->get('popup') !== '' || $this->previewPopup > 0) {
            $meta['noindex'] = true; // náhled konceptu pop-up okna
        }

        if ($k->types !== []) {
            $meta['css'] = \Kaleta\Builder\Build::css($db, $k)
                // plátno builderu se po každé změně načítá znovu – přechod mezi stránkami by jen blikal a v prohlížeči hlásil přerušení
                . ($editor ? '@view-transition{navigation:none}' : '');
            if ($k->faq !== [] && !isset($meta['faq'])) {
                $meta['faq'] = $k->faq;
            }
        }
        if ($preview !== '') {
            $meta['noindex'] = true;
        }

        return [$content, $parts, $meta];
    }

    /**
     * Pop-up okna pro zobrazenou stránku (Stavitel\Popupy): zapnutá a publikovaná, podle pravidel serveru. Náhled konceptu
     * (?popup=<id>&stavba=koncept nebo /_popup/<id>) přidá dané okno i vypnuté a otevře ho hned.
     */
    private function popups(\Kaleta\Builder\Context $k, bool $news): string
    {
        $r = $this->app->request;
        if ($r->get('nahled') === 'vzhled' || ($r->get('editor') === '1' && !$this->previewPopup)) {
            return ''; // náhled ve Vzhledu webu a plátno builderu (mimo builder okna) ukazují stránku bez oken
        }
        $db = $this->app->db();
        $preview = $this->previewPopup ?: ($r->get('stavba') === 'koncept' && preg_match('/^\d{1,9}$/', $r->get('popup')) && $this->canSeeDraft('popup:' . $r->get('popup')) ? (int) $r->get('popup') : 0);
        try {
            $popups = \Kaleta\Builder\Popups::forPage($db, ['ids' => ($this->counterpart[0] ?? '') === 'stranky' ? (int) $this->counterpart[2]['ids'] : null,
                'kolekce' => $this->pageCollection, 'novinky' => $news, 'jazyk' => Language::code(), 'dnes' => date('Y-m-d')]);
            $draft = $preview > 0 ? \Kaleta\Builder\Popups::byId($db, $preview) : null;
        } catch (\Throwable $e) {
            error_log('Pop-up okna: ' . $e->getMessage()); // web před migrací

            return '';
        }
        if ($draft !== null) {
            $popups = [...array_filter($popups, fn (array $p): bool => $p['idpp'] !== $preview), ['stavba' => $draft['stavba_koncept'] ?? $draft['stavba'], 'nahled' => true] + $draft];
            $k->withoutCache = true;
        }
        $html = '';
        foreach ($popups as $p) {
            $build = \Kaleta\Builder\Build::fromJson($p['stavba']);
            if ($build === null) {
                continue;
            }
            if ($p['pravidla']['od'] !== '' || $p['pravidla']['do'] !== '') {
                $k->withoutCache = true; // okno s obdobím se nesmí dostat do cache stránky po jeho konci
            }
            $k->source = 'popup:' . $p['idpp'];
            // přihlášení (správci, redaktoři) si okna prohlížejí, ale do počitadel se nepočítají
            $html .= \Kaleta\Builder\Popups::wrapper($p, \Kaleta\Builder\Build::html($build, $k), $this->app->auth()->user() === null ? $this->app->url('popup') : '', !empty($p['nahled']));
        }

        return $html;
    }

    /**
     * Pop-up okno pro builder a sdílený náhled: s editor=1 (správce) okno stojí na plátně k úpravám, jinak se koncept
     * otevře přes prázdnou stránku webu. Bez práva správce nebo platného podepsaného odkazu 404.
     */
    private function previewPopup(int $idpp): Response
    {
        $p = null;
        try {
            $p = \Kaleta\Builder\Popups::byId($this->app->db(), $idpp);
        } catch (\Throwable) {
            // web před migrací
        }
        if ($p === null || $this->app->request->get('stavba') !== 'koncept' || !$this->canSeeDraft('popup:' . $idpp)) {
            return $this->notFound();
        }
        if ($this->app->request->get('editor') === '1' && $this->app->auth()->isAdmin()) {
            $k = $this->context();
            $k->editor = true;
            $k->source = 'popup:' . $idpp;
            $html = \Kaleta\Builder\Build::html(\Kaleta\Builder\Build::fromJson($p['stavba_koncept'] ?? $p['stavba']) ?? ['deti' => []], $k);
            $k->editor = false;
            $content = '<div class="ka-popup-platno">' . \Kaleta\Builder\Popups::editorWrapper($p, $html) . '</div>';
        } else {
            $this->previewPopup = $idpp;
            $content = '<div class="ka-popup-platno"></div>';
        }

        return $this->page($p['nazev'], $this->view->render('stranka', ['stranka' => ['titulek' => ''], 'uvod' => false, 'stavba' => $content]), ['stavba' => true, 'noindex' => true]);
    }

    private function page(string $title, string $content, array $meta = [], int $status = 200): Response
    {
        if ($this->pastEnd) {
            $this->pastEnd = false;

            return $this->notFound();
        }
        $siteSettings = $this->app->settings();
        $seo = new Seo($this->app);
        $newsItem = $meta['clanek'] ?? null;
        unset($meta['clanek']);
        if ($status === 200 && empty($meta['noindex'])) {
            Stats::record($this->app, $newsItem === null ? null : (int) $newsItem['idc']);
        }

        if (($meta['obrazek'] ?? '') !== '' && !preg_match('#^https?://#', $meta['obrazek'])) {
            // sociální sítě berou jen úplnou adresu obrázku
            $meta['obrazek'] = $this->app->request->origin() . $this->app->url(ltrim((string) preg_replace('#^' . preg_quote($this->app->request->basePath(), '#') . '/#', '', $meta['obrazek']), '/'));
        }
        $meta['drobecky'] ??= $this->context()->breadcrumbs;
        $languages = $this->languages($newsItem);
        $languageSwitcher = $languages === [] ? '' : $this->view->render('jazyky', ['jazyky' => $languages]);
        // přepínač světlý / tmavý vzhled pro návštěvníky – vedle jazyků (šablona, prvek Navigace)
        $colorScheme = in_array($this->app->settings()->get('tmavy_rezim'), ['auto', 'tmavy'], true) && $this->app->settings()->bool('tmavy_prepinac')
            ? $this->view->render('tema', ['vychozi' => $this->app->settings()->get('tmavy_rezim') === 'tmavy' ? 'tmavy' : 'auto']) : '';
        $languagesHtml = $languageSwitcher . $colorScheme;
        // prvky builderu: Navigace přidá přepínač jazyků (volitelně) a vzhledu, Přepínač jazyků skládá z tohoto seznamu
        $this->context()->languageList = $languages;
        $this->context()->colorScheme = $colorScheme;
        // kanonická adresa: cesta bez parametrů, u stránkování s číslem strany (strana 2 není kopie strany 1)
        $listPageNumber = $this->app->request->getInt('strana', 1);
        $canonicalUrl = $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')) . ($listPageNumber > 1 ? '?strana=' . $listPageNumber : '');
        [$content, $parts, $meta] = $this->siteParts($content, $meta, $languageSwitcher, (string) parse_url($canonicalUrl, PHP_URL_PATH));
        $popups = (string) ($meta['popupy'] ?? '');
        unset($meta['popupy']);
        $html = $this->view->render('base', [
            'web' => $siteSettings,
            'titulek' => $title,
            'meta' => $meta + ['hlavni' => false, 'popis' => '', 'klicova_slova' => $siteSettings->get('klicova_slova'), 'obrazek' => '', 'typ' => 'website', 'noindex' => false],
            'obsah' => $content,
            'hlava' => $seo->head($title, $meta + ['jazyky' => $languages], $newsItem),
            'pata' => $seo->foot() . $popups . ($this->editHereUrl !== '' ? '<a class="ka-upravit-zde" href="' . e($this->editHereUrl) . '">' . e(t('Upravit zde')) . '</a>' : ''),
            'stranky' => $this->menuPages(),
            'menu' => $this->menu('hlavni'),
            'menu_paticka' => $this->menu('paticka'),
            'menu_html' => \Kaleta\Core\Menu::html(...),
            'jazyk' => Language::code(),
            'jazyky_html' => $languagesHtml,
            'sNovinkami' => Extensions::isEnabled($siteSettings, 'novinky'), // zapnuté rozšíření Novinky (odkazy na RSS v šabloně)
            'casti' => $parts,
            'url' => $this->app->url(...),
            'kanonicka' => $canonicalUrl,
        ]);
        $html = ImageHtml::complete($this->app->db(), $html); // rozměry a barva podkladu obrázků – méně poskakování stránky
        $html = $this->localizeSystemLinks($html);
        // image/web.js jen na stránkách, které ho potřebují (galerie a fotky v textu, video, sdílení, záložky, karusel, okno, formulář,
        // počítadlo, odpočet, podmenu – Esc ho zavře, pop-up okna, jazykové verze – při první návštěvě jazyk prohlížeče)
        if (!preg_match('/data-(vlozit|sdilet|kopirovat|zalozky|karusel|formular|odeslano|pocitadlo|odpocet|tema-volba)|popover role="dialog"|galerie|class="(?:text|perex)[" ][\s\S]*?<img|cookies-|<li class="podmenu|data-popup=|rel="alternate" hreflang=/', $html)) {
            $html = (string) preg_replace('#<script src="[^"]*/image/web\.js[^"]*"[^>]*></script>\n?#', '', $html);
        }
        // prvky s podmínkou zobrazení (datum, přihlášení) se skládají pokaždé znovu – cache by je ukazovala podle stavu v okamžiku uložení
        if ($status === 200 && empty($meta['noindex']) && $this->app->request->get('nahled') === '' && !($this->context?->withoutCache ?? false)) {
            Cache::save($this->app, $html, $newsItem === null ? null : (int) $newsItem['idc']);
        }

        return Response::html($html, $status);
    }

    /**
     * Odkazy na systémové adresy uložené v obsahu (href="/novinky" ze starší stavby nebo startovacího webu) v podobě jazyka
     * verze, aby nevedly přes přesměrování (Core\Cesty).
     */
    private function localizeSystemLinks(string $html): string
    {
        $language = $this->app->languagePrefix !== '' ? $this->app->languagePrefix : Language::defaults($this->app->settings());
        if (!\Kaleta\Core\Routes::isEnglish($language) || !preg_match('#href="[^"]*/(?:novinky|hledani)#', $html)) {
            return $html;
        }
        $base = preg_quote($this->app->request->basePath() . ($this->app->languagePrefix !== '' ? '/' . $this->app->languagePrefix : ''), '#');

        return (string) preg_replace_callback('#href="' . $base . '/((?:novinky|hledani)(?:[/?\#][^"]*)?)"#',
            fn (array $m): string => 'href="' . e($this->app->url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5))) . '"', $html);
    }
}
