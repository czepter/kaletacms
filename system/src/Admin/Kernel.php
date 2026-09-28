<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Migration;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;

/**
 * Administrace. Adresy: admin.php?module=<ident>&action=<akce>
 */
final class Kernel
{
    /**
     * Moduly v pořadí, v jakém jsou v menu (po skupinách Obsah, Vzhled, Správa).
     *
     * @var list<class-string<Module>>
     */
    public const array MODULES = [
        Modules\Pages::class,
        Modules\News::class,
        Modules\Collections::class,
        Modules\Enquiries::class,
        Modules\Subscribers::class,
        Modules\Categories::class,
        Modules\Tags::class,
        Modules\Media::class,
        Modules\Appearance::class,
        Modules\SiteParts::class,
        Modules\Menu::class,
        Modules\Components::class,
        Modules\Popups::class,
        Modules\Users::class,
        Modules\Roles::class,
        Modules\Stats::class,
        Modules\Redirects::class,
        Modules\ChangeLog::class,
        Modules\Transfer::class,
        Modules\Extensions::class,
        Modules\Settings::class,
    ];

    public function __construct(public readonly App $app)
    {
    }

    public function handle(): Response
    {
        $app = $this->app;
        $request = $app->request;

        // jazyk administrace: volba uživatele (Můj účet); přihlašovací stránka se řídí jazykem webu. Nastavuje se jako první, aby i hláška o vypršelém formuláři byla přeložená
        $language = (string) ($app->auth()->user()['jazyk'] ?? '') ?: \Kaleta\Core\Language::defaults($app->settings());
        \Kaleta\Core\Language::set(isset(\Kaleta\Core\Language::ADMIN_LANGUAGES[$language]) ? $language : 'cs', 'admin-');
        if ($request->isPost() && !$app->session->csrfValid($request)) {
            return $this->page('Neplatný požadavek', $app->view->render('admin/error', [
                'text' => 'Platnost formuláře vypršela. Vraťte se zpět, obnovte stránku a odešlete jej znovu.',
            ]), 400);
        }

        // každá změna v administraci zneplatní cache stránek webu; průběžné požadavky editorů (rozepsaný stav, asistent,
        // koncept stavby) web nemění - kdyby cache mazaly, při práci by byla pořád studená
        if ($request->isPost() && !in_array($request->get('action'), ['draft', 'assistant', 'build_save', 'preview', 'build_ai_text'], true)) {
            \Kaleta\Front\Cache::clear();
        }
        $action = $request->get('action');
        // adresa webu: starší instalace ji ještě nemá - zapíše se podle adresy, na které pracuje přihlášený administrátor
        if ($app->settings()->get('adresa_webu') === '' && $app->auth()->isAdmin()) {
            $app->settings()->set('adresa_webu', $request->origin());
        }
        $request->setOrigin($app->settings()->get('adresa_webu'));
        $app->applyTimezone();
        if ($app->auth()->user() === null) {
            return $action === 'password' ? (new PasswordReset($app))->handle() : $this->login();
        }
        if ($action === 'logout' && $request->isPost()) {
            $app->auth()->logout();

            return Response::redirect($app->url('admin.php'));
        }

        // po přechodu na novou verzi jednorázově uklidit známé zrušené soubory (viz Aktualizace::ZRUSENE)
        if ($app->auth()->isAdmin() && $app->settings()->get('uklizeno_verze') !== KALETA_VERSION) {
            \Kaleta\Core\Updater::cleanUpRemoved(KALETA_ROOT, $app->settings()->get('layout'));
            $app->settings()->set('uklizeno_verze', KALETA_VERSION);
        }

        // aktualizace struktury databáze po nahrání nové verze systému
        if ($app->auth()->isAdmin() && $app->settings()->int('verze_db') < Migration::latest()) {
            try {
                foreach (Migration::apply($app->db(), $app->settings()) as $migration) {
                    $app->session->flash('info', t('Databáze byla aktualizována: %s', $migration));
                }
            } catch (\Throwable $e) {
                // administrace musí zůstat použitelná, aby šla nainstalovat oprava (Nastavení → Zálohy a aktualizace)
                Migration::writeError($e);
                $app->session->flash('chyba', t('Aktualizace databáze se nepovedla: %s. Web běží dál; nainstalujte opravu v Nastavení → Zálohy a aktualizace, nebo napište na info@kaletacms.com.', $e->getMessage()));
            }
        }

        if ($app->auth()->isAdmin()) {
            \Kaleta\Core\Backup::createAutomatic($app->db(), $app->settings());
        }
        if ($app->auth()->user() !== null) {
            Modules\News::emptyTrash($app->db()); // koš drží novinky i stránky 30 dní
            Modules\Pages::emptyTrash($app->db());
        }

        $ident = $request->get('module');
        // povinné dvoufázové přihlášení: kdo ho ještě nemá, smí jen do Můj účet (a odhlásit se), dokud ho nezapne
        if ($app->auth()->isMissingRequired2fa($app->settings()) && !in_array($action, ['account', 'token'], true)) {
            $app->session->flash('chyba', t('Web vyžaduje dvoufázové přihlášení. Zapněte si ho prosím níže – do té doby je administrace zamčená.'));

            return Response::redirect($app->url('admin.php?action=account'));
        }
        if ($action === 'token') {
            // editor po novém přihlášení v jiné záložce si tu vezme platný token formulářů a pokračuje v ukládání
            return Response::json(['csrf' => $app->session->csrfToken()]);
        }
        if ($action === 'account') {
            return (new Account($this))->handle();
        }
        if ($action === 'oauth') {
            return $this->handleOAuthConsent();
        }
        if ($action === 'hide_first_steps' && $request->isPost() && $app->auth()->isAdmin()) {
            $app->settings()->set('pruvodce_skryt', '1');

            return Response::redirect($app->url('admin.php'));
        }
        if ($ident === '') {
            $newVersion = $app->auth()->isAdmin() ? (new \Kaleta\Core\Updater($app->settings()))->state()['nova'] : null;
            if ($newVersion !== null) {
                // text se překládá tady (s číslem verze); cestu v nabídce promění v odkaz až vykreslení hlášky (Admin\Cesty)
                $app->session->flash(!empty($newVersion['bezpecnostni']) ? 'chyba' : 'info', !empty($newVersion['bezpecnostni'])
                    ? t('Je k dispozici BEZPEČNOSTNÍ aktualizace %s – nainstalujete ji v Nastavení → Zálohy a aktualizace.', (string) $newVersion['verze'])
                    : t('Je k dispozici nová verze %s – nainstalujete ji v Nastavení → Zálohy a aktualizace.', (string) $newVersion['verze']));
            }

            return $this->page('', $app->view->render('admin/dashboard', $this->desktop()));
        }
        $class = $this->modules()[$ident] ?? null;
        if ($class === null) {
            return $this->page('Chyba', $app->view->render('admin/error', ['text' => 'K tomuto modulu nemáte přístup.']), 403);
        }

        $response = (new $class($this))->handle($action === '' ? 'list' : $action);
        if ($request->isPost() && $response->status === 302 && $action !== 'poradi') {
            // každá provedená změna v administraci jde do protokolu
            $description = $request->post('titulek') ?: ($request->post('nazev') ?: ($request->post('user') ?: $request->post('tab')));
            ChangeLog::write($app, $ident, $action, $description);
        }

        return $response;
    }

    /**
     * Moduly dostupné přihlášenému uživateli.
     *
     * @return array<string, class-string<Module>> ident => třída
     */
    public function modules(): array
    {
        $auth = $this->app->auth();
        $modules = [];
        foreach (self::MODULES as $class) {
            if (!Extensions::isEnabled($this->app->settings(), $class::EXTENSION)) {
                continue;
            }
            $allowed = $class::ADMIN_ONLY ? $auth->isAdmin() : $auth->hasModule($class::IDENT, $class::FOR_ALL_USERS);
            if ($allowed) {
                $modules[$class::IDENT] = $class;
            }
        }

        return $modules;
    }

    /** Obalí obsah společným rámcem administrace (menu, login proužek, hlášky). */
    public function page(string $heading, string $content, int $status = 200): Response
    {
        $app = $this->app;

        return Response::html($app->view->render('admin/layout', [
            'app' => $app,
            'heading' => t($heading),
            'content' => $content,
            'modules' => $app->auth()->user() !== null ? $this->modules() : [],
            'active' => $app->request->get('module'),
            'user' => $app->auth()->user(),
            'flashes' => $app->session->takeFlashes(),
        ]), $status);
    }

    /**
     * Data úvodní obrazovky: přehled webu.
     *
     * @return array<string, mixed>
     */
    private function desktop(): array
    {
        $data = ['app' => $this->app, 'modules' => $this->modules()];
        $db = $this->app->db();
        $scope = ' AND smazano IS NULL' . $this->app->auth()->articleScope();      // pro dotazy bez aliasu (novinky v koši se nepočítají)
        $aliasedScope = ' AND c.smazano IS NULL' . $this->app->auth()->articleScope('c.');  // pro dotazy s aliasem c

        $modules = $this->modules();
        $warnings = [];
        if (isset($modules['redirects'])) {
            $missing = (int) $db->value('SELECT COUNT(*) FROM {nenalezeno} WHERE naposledy > NOW() - INTERVAL 7 DAY AND pocet >= 3');
            if ($missing > 0) {
                $warnings[] = [t('Návštěvníci za poslední týden opakovaně nenašli %d adres (chyba 404). Přesměrujte je na správné stránky.', $missing), $this->app->url('admin.php?module=redirects')];
            }
        }
        if ($this->app->auth()->isAdmin()) {
            $backup = \Kaleta\Core\Backup::listAll()[0]['cas'] ?? 0;
            if (time() - $backup > 8 * 86400) {
                $warnings[] = [$backup === 0 ? t('Web zatím nemá žádnou zálohu databáze.') : t('Poslední záloha databáze je z %s.', format_date(date('Y-m-d H:i:s', $backup))), $this->app->url('admin.php?module=settings&tab=backups')];
            }
        }
        // naposledy upravený obsah: stránky i novinky dohromady
        $edited = [];
        if (isset($modules['pages'])) {
            foreach ($db->all('SELECT ids, titulek, zmeneno, zobrazit, stavba_koncept IS NOT NULL AS koncept FROM {stranky} WHERE smazano IS NULL AND zmeneno IS NOT NULL ORDER BY zmeneno DESC LIMIT 6') as $r) {
                $edited[] = ['druh' => t('Stránka'), 'titulek' => $r['titulek'], 'kdy' => (string) $r['zmeneno'], 'url' => $this->app->url('admin.php?module=pages&action=edit&id=' . (int) $r['ids']),
                    'stav' => !$r['zobrazit'] ? t('skrytá') : ($r['koncept'] ? t('nepublikované změny') : '')];
            }
        }
        if (isset($modules['news'])) {
            foreach ($db->all('SELECT c.idc, c.titulek, COALESCE(c.zmeneno, c.datum) AS kdy, c.visible, c.datum > NOW() AS plan FROM {novinky} c WHERE 1 = 1' . $aliasedScope . ' ORDER BY COALESCE(c.zmeneno, c.datum) DESC LIMIT 6') as $r) {
                $edited[] = ['druh' => t('Novinka'), 'titulek' => $r['titulek'], 'kdy' => (string) $r['kdy'], 'url' => $this->app->url('admin.php?module=news&action=edit&id=' . (int) $r['idc']),
                    'stav' => !$r['visible'] ? t('koncept') : ($r['plan'] ? t('naplánovaná') : '')];
            }
        }
        usort($edited, fn (array $a, array $b): int => strcmp($b['kdy'], $a['kdy']));

        return $data + [
            'firstSteps' => $this->firstSteps(),
            'warnings' => $warnings,
            // návštěvnost za 14 dní (vlastní měření bez cookies)
            'traffic' => Extensions::isEnabled($this->app->settings(), 'statistika') && isset($modules['stats'])
                ? $db->all('SELECT den, navstevy, zobrazeni FROM {stat_dny} WHERE den > CURDATE() - INTERVAL 14 DAY ORDER BY den') : [],
            'counts' => array_filter([
                // koncepty autorů novinek čekají na editora – dlaždice jen, když nějaké jsou
                'Novinky od autorů čekají na vydání' => isset($modules['news']) && ($pending = Modules\News::countAwaitingPublication($this->app)) > 0 ? [$pending, 'admin.php?module=news&stav=ke_vydani'] : null,
                'Nové poptávky' => isset($modules['enquiries']) ? [(int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE stav = 0'), 'admin.php?module=enquiries'] : null,
                'Zveřejněné stránky' => isset($modules['pages']) ? [(int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL'), 'admin.php?module=pages'] : null,
                'Stránky s nepublikovanými změnami' => isset($modules['pages']) ? [(int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE stavba_koncept IS NOT NULL AND smazano IS NULL'), 'admin.php?module=pages'] : null,
                'Vydané novinky' => isset($modules['news']) ? [(int) $db->value("SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW(){$scope}"), 'admin.php?module=news&stav=vydane'] : null,
                'Koncepty novinek' => isset($modules['news']) ? [(int) $db->value("SELECT COUNT(*) FROM {novinky} WHERE visible = 0{$scope}"), 'admin.php?module=news&stav=koncepty'] : null,
            ]),
            'enquiries' => isset($modules['enquiries']) ? $db->all('SELECT idp, datum, formular, email, stav FROM {poptavky} ORDER BY idp DESC LIMIT 5') : [],
            'edited' => array_slice($edited, 0, 8),
        ];
    }

    /**
     * První kroky po instalaci: co už je hotové, se pozná z dat. Vidí je jen administrátor, dokud je neskryje nebo nesplní.
     *
     * @return list<array{nazev:string, popis:string, url:string, hotovo:bool}>
     */
    private function firstSteps(): array
    {
        $app = $this->app;
        $s = $app->settings();
        if (!$app->auth()->isAdmin() || $s->bool('pruvodce_skryt')) {
            return [];
        }
        $db = $app->db();
        $steps = [
            // hotovo až po vlastní volbě: vzhled a stránky ze startovacího webu se nepočítají
            ['Dejte webu tvář', 'Logo, hlavní barva a písmo.', 'admin.php?module=appearance', $s->get('logo_webu') !== '' || $s->bool('vzhled_ulozen') || $s->get('brand_akcent') !== ''],
            ['Vyplňte údaje o firmě', 'Adresa, telefon a otevírací doba se ukážou na kontaktu, v patičce i vyhledávačům.', 'admin.php?module=settings&tab=company', $s->get('firma_ulice') !== '' && ($s->get('firma_telefon') !== '' || $s->get('firma_email') !== '' || $s->get('email_webu') !== '')],
            ['Připravte stránky', 'O nás, Služby, Kontakt – a v Nastavení vyberte, která bude úvodní.', 'admin.php?module=pages', (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1') >= 3 && $s->int('titulni_stranka') > 0
                && $db->value('SELECT 1 FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1 AND zmeneno IS NOT NULL LIMIT 1') !== null],
            ['Doplňte zásady ochrany osobních údajů', 'Formulář s poptávkou sbírá osobní údaje – návštěvník musí vědět, jak s nimi naložíte. Kostru stránky máte připravenou jako skrytou: doplňte údaje v hranatých závorkách a stránku zveřejněte.', 'admin.php?module=pages',
                // hotovo, až stránka existuje a nemá v sobě hranaté závorky ke kostře z instalace ([NÁZEV FIRMY]…)
                $db->value("SELECT 1 FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1 AND (seo_link LIKE '%soukromi%' OR seo_link LIKE '%osobni%' OR seo_link LIKE '%gdpr%' OR seo_link LIKE '%privacy%') AND text NOT LIKE '%[%]%' LIMIT 1") !== null],
            ['Nastavte poštu', 'Odkud web odesílá e-maily (formuláře, obnova hesla).', 'admin.php?module=settings&tab=mail', $s->get('posta_rezim') === 'smtp' || $s->get('posta_od') !== ''],
        ];
        $result = array_map(fn (array $k): array => ['nazev' => $k[0], 'popis' => $k[1], 'url' => $app->url($k[2]), 'hotovo' => (bool) $k[3]], $steps);

        return array_filter($result, fn (array $k): bool => !$k['hotovo']) === [] ? [] : $result;
    }

    /** Kam po přihlášení: čeká-li připojení aplikace přes OAuth (konektor Claude), rovnou na souhlas, jinak na přehled. */
    private function resolveAfterSignIn(): string
    {
        return $this->app->url(is_array($this->app->session->get('oauth_ceka')) ? 'admin.php?action=oauth' : 'admin.php');
    }

    private function login(): Response
    {
        $app = $this->app;
        $error = null;
        // druhý krok přihlašovacím klíčem (otisk prstu, Face ID): skript image/klice.js si řekne o výzvu a pošle podpis zařízení
        if ($app->request->isPost() && in_array($app->request->post('krok'), ['klic_moznosti', 'klic'], true)) {
            $url = $app->settings()->get('adresa_webu') ?: $app->request->origin();
            if ($app->request->post('krok') === 'klic_moznosti') {
                $options = $app->auth()->keyChallenge($url);

                return Response::json($options ?? ['chyba' => t('Přihlášení vypršelo, začněte prosím znovu.')], $options === null ? 400 : 200);
            }
            $error = $app->auth()->verifyKey((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $url, $app->request->ip());
            if ($error === null) {
                ChangeLog::write($app, 'prihlaseni', 'login', 'přihlašovacím klíčem');
            }

            return Response::json($error === null ? ['ok' => true, 'kam' => $this->resolveAfterSignIn()] : ['chyba' => $error], $error === null ? 200 : 401);
        }
        if ($app->request->isPost()) {
            $secondStep = $app->request->post('kod') !== '' || $app->request->post('krok') === 'kod';
            $error = $secondStep
                ? $app->auth()->verifyCode($app->request->post('kod'), $app->request->ip())
                : $app->auth()->login($app->request->post('user'), $app->request->post('password'), $app->request->ip());
            if ($error === null && $app->auth()->user() !== null) {
                ChangeLog::write($app, 'prihlaseni', 'login', $secondStep ? 'dvoufázově' : '');

                return Response::redirect($this->resolveAfterSignIn());
            }
            if ($error !== null && !$secondStep) {
                ChangeLog::write($app, 'prihlaseni', 'neuspech', 'účet: ' . mb_substr($app->request->post('user'), 0, 40));
            }
        }

        return Response::html($app->view->render('admin/login', [
            'app' => $app,
            'error' => $error,
            'login' => $app->request->post('user'),
            'code' => $app->auth()->isAwaitingCode(),
            'keys' => $app->auth()->isAwaitingKey(),
        ]), $error === null ? 200 : 401);
    }

    /**
     * Souhlas s připojením aplikace přes OAuth (konektor Claude): ukáže, kdo žádá a s jakými právy, a po potvrzení vrátí
     * aplikaci jednorázový kód. Žádost čeká v relaci (Front\OAuth::autorizace) nejvýš 15 minut.
     */
    private function handleOAuthConsent(): Response
    {
        $app = $this->app;
        $pending = $app->session->get('oauth_ceka');
        if (!is_array($pending) || time() - (int) ($pending['cas'] ?? 0) > 900) {
            $app->session->set('oauth_ceka', null);

            return $this->page('Připojení aplikace', $app->view->render('admin/error', ['text' => 'Žádost o připojení aplikace vypršela nebo neexistuje. Spusťte připojení v aplikaci znovu.']), 400);
        }
        $oauth = new \Kaleta\Front\OAuth($app);
        if ($app->request->isPost()) {
            $app->session->set('oauth_ceka', null);
            if (!$app->request->postBool('povolit')) {
                return Response::redirect($oauth->deny($pending));
            }
            ChangeLog::write($app, 'claude', 'připojení aplikace', mb_substr((string) $pending['nazev'], 0, 100));

            return Response::redirect($oauth->issueCode($pending, $app->auth()->id()));
        }

        $page = $this->page('Připojení aplikace', $app->view->render('admin/oauth', [
            'app' => $app, 'csrf' => $app->session->csrfField(), 'pending' => $pending, 'user' => $app->auth()->user(),
            'url' => (string) parse_url((string) $pending['redirect_uri'], PHP_URL_HOST),
        ]));
        // odeslání souhlasu končí přesměrováním do aplikace – CSP form-action ho musí povolit (admin.php)
        $target = parse_url((string) $pending['redirect_uri']);
        $origin = ($target['scheme'] ?? '') . '://' . ($target['host'] ?? '') . (isset($target['port']) ? ':' . $target['port'] : '');

        return new Response($page->body, $page->status, $page->headers + ['X-Kaleta-Form-Action' => $origin]);
    }
}
