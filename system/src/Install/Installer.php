<?php

declare(strict_types=1);

namespace Kaleta\Install;

use Kaleta\Core\Auth;
use Kaleta\Core\Db;
use Kaleta\Core\Migration;
use Kaleta\Core\Request;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\View;
use Kaleta\Front\Layouts;
use Kaleta\Builder\Library;
use Kaleta\Builder\Build;

/**
 * Web installer: checks the server, creates the tables and the first administrator and writes config.php.
 */
final class Installer
{
    private readonly Request $request;
    private readonly View $view;

    public function __construct()
    {
        $this->request = Request::fromGlobals();
        $this->view = new View([KALETA_SYSTEM . '/views']);
    }

    /** Installation languages (= admin languages) and the default time zone we offer for them. */
    private const array TIME_ZONES = ['cs' => 'Europe/Prague', 'en' => 'Europe/London'];

    private string $language = 'cs';

    /** Installation language: an explicit choice (?jazyk=, hidden form field), otherwise the first known language from the browser header. */
    private function chooseLanguage(): string
    {
        $choice = (string) ($_POST['jazyk'] ?? $_GET['jazyk'] ?? '');
        if (isset(self::TIME_ZONES[$choice])) {
            return $choice;
        }
        foreach (explode(',', strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
            $code = substr(trim($part), 0, 2);
            if (isset(self::TIME_ZONES[$code])) {
                return $code;
            }
        }

        return 'cs';
    }

    public function handle(): Response
    {
        if (is_file(KALETA_ROOT . '/config.php')) {
            \Kaleta\Core\Language::set($this->chooseLanguage(), 'install-');

            return $this->page('done', ['alreadyInstalled' => true, 'deleted' => $this->deleteSelf()]);
        }

        $this->language = $this->chooseLanguage();
        \Kaleta\Core\Language::set($this->language, 'install-');
        $requirements = $this->requirements();
        $data = [
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_password' => '', 'db_prefix' => 'ka_',
            'nazev_webu' => t('Můj web'), 'user' => 'admin', 'jmeno' => '', 'email' => '',
            'casove_pasmo' => self::TIME_ZONES[$this->language], 'web' => 'firemni', 'jazyk_webu' => $this->language,
        ];
        $errors = [];
        // extensions enabled after installation: the default set, after the form is submitted the user's choice
        $extensions = array_keys(array_filter(Extensions::CATALOG, fn (array $r): bool => $r[2]));

        if ($this->request->isPost() && !in_array(false, array_column($requirements, 'ok'), true)) {
            foreach (array_keys($data) as $key) {
                // the database password is not trimmed - it can contain spaces
                $data[$key] = $key === 'db_password' ? (string) ($_POST[$key] ?? '') : $this->request->post($key);
            }
            $data['jazyk_webu'] = isset(\Kaleta\Core\Language::AVAILABLE[$data['jazyk_webu']]) ? $data['jazyk_webu'] : $this->language;
            $extensions = array_values(array_intersect($this->request->postList('rozsireni'), array_keys(Extensions::CATALOG)));
            $errors = $this->install($data, (string) ($_POST['password'] ?? ''), (string) ($_POST['password2'] ?? ''), $extensions);
            if ($errors === []) {
                return $this->page('done', ['alreadyInstalled' => false, 'deleted' => $this->deleteSelf()]);
            }
        }

        return $this->page('form', ['requirements' => $requirements, 'data' => $data, 'errors' => $errors, 'extensions' => $extensions]);
    }

    /**
     * After installation the installer deletes itself, so the administrator does not have to use FTP. When the hosting does
     * not allow it (file permissions), a prompt to delete it remains and the system health page keeps warning about the file.
     * In a development copy (a .git folder) it is not deleted.
     */
    private function deleteSelf(): bool
    {
        if (is_dir(KALETA_ROOT . '/.git')) {
            return false;
        }

        return !is_file(KALETA_ROOT . '/install.php') || @unlink(KALETA_ROOT . '/install.php');
    }

    /** @return list<array{nazev:string, ok:bool, info:string}> */
    private function requirements(): array
    {
        $write = fn (string $path): bool => is_writable(KALETA_ROOT . $path);

        return [
            ['nazev' => t('PHP 8.4 nebo novější'), 'ok' => PHP_VERSION_ID >= 80400, 'info' => t('běží') . ' ' . PHP_VERSION],
            ['nazev' => t('Rozšíření pdo_mysql'), 'ok' => extension_loaded('pdo_mysql'), 'info' => t('připojení k databázi MySQL / MariaDB')],
            ['nazev' => t('Rozšíření mbstring'), 'ok' => extension_loaded('mbstring'), 'info' => t('práce s češtinou')],
            ['nazev' => t('Zápis do kořenové složky'), 'ok' => $write(''), 'info' => t('kvůli vytvoření config.php')],
            ['nazev' => t('Zápis do složky storage/'), 'ok' => $write('/storage/log') && $write('/storage/cache'), 'info' => t('logy a cache')],
        ];
    }

    /**
     * @param array<string, string> $d
     * @param list<string> $extensions enabled extensions
     * @return array<string, string> errors; empty array = installed
     */
    private function install(array $d, string $password, string $password2, array $extensions): array
    {
        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $d['db_prefix'])) {
            $errors['db_prefix'] = t('Předpona: malá písmena, číslice a podtržítko, nejvýše 16 znaků (např. ka_).');
        }
        if ($d['db_name'] === '' || $d['db_user'] === '') {
            $errors['db_name'] = t('Vyplňte název databáze a uživatele.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $d['user'])) {
            $errors['user'] = t('Přihlašovací jméno: 2-40 znaků, písmena bez diakritiky, číslice, tečka, pomlčka, podtržítko.');
        }
        if (mb_strlen($password) < 10) {
            $errors['password'] = t('Heslo musí mít alespoň 10 znaků.');
        } elseif ($password !== $password2) {
            $errors['password'] = t('Hesla se neshodují.');
        }
        if ($d['email'] !== '' && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('E-mail nemá platný tvar.');
        }
        if ($errors !== []) {
            return $errors;
        }

        $config = [
            'db' => [
                'host' => $d['db_host'] !== '' ? $d['db_host'] : 'localhost',
                'port' => (int) $d['db_port'] ?: 3306,
                'name' => $d['db_name'],
                'user' => $d['db_user'],
                'password' => $d['db_password'],
                'prefix' => $d['db_prefix'],
            ],
            'debug' => false,
        ];

        try {
            $db = Db::fromConfig($config['db']);
            $db->pdo();
        } catch (\PDOException $e) {
            return self::connectionError($e);
        }
        $exists = $db->value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$d['db_prefix'] . 'user'],
        );
        if ((int) $exists > 0) {
            return ['db_prefix' => t('V databázi už tabulky s touto předponou existují. Zvolte jinou předponu, nebo je nejprve odstraňte.')];
        }

        try {
            foreach (Migration::statements((string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'), $d['db_prefix']) as $sql) {
                $db->pdo()->exec($sql);
            }
            $this->createDefaultData($db, $d, $password, $extensions);
        } catch (\PDOException $e) {
            return ['db_name' => t('Vytvoření tabulek selhalo:') . ' ' . $e->getMessage()];
        }

        $content = "<?php\n/**\n * Kaleta - konfigurace vytvořená instalátorem " . date('j. n. Y') . ".\n */\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents(KALETA_ROOT . '/config.php', $content, LOCK_EX) === false) {
            return ['db_name' => t('Tabulky jsou vytvořeny, ale nepodařilo se zapsat config.php. Zkontrolujte práva k zápisu.')];
        }

        return [];
    }

    /**
     * A database connection error in human terms and at the field that needs fixing (MySQL/MariaDB error code); an unknown error with the driver's text.
     *
     * @return array<string, string>
     */
    private static function connectionError(\PDOException $e): array
    {
        return match ((int) ($e->errorInfo[1] ?? $e->getCode())) {
            1045 => ['db_user' => t('Uživatelské jméno nebo heslo k databázi nesedí. Zkontrolujte je v administraci hostingu.')],
            1044 => ['db_name' => t('Uživatel k této databázi nemá přístup. Přidělte mu ji v administraci hostingu.')],
            1049 => ['db_name' => t('Databáze s tímto názvem na serveru není. Založte ji v administraci hostingu, nebo opravte název.')],
            2002, 2005, 2006 => ['db_host' => t('K databázovému serveru se nepodařilo připojit. Zkontrolujte server a port.')],
            default => ['db_name' => t('K databázi se nepodařilo připojit:') . ' ' . $e->getMessage()],
        };
    }

    /**
     * @param array<string, string> $d
     * @param list<string> $extensions
     */
    private function createDefaultData(Db $db, array $d, string $password, array $extensions): void
    {
        // the chosen time zone already applies to the initial content: otherwise the welcome news item could have a date "in the future" and the site would not show it
        $timeZone = in_array($d['casove_pasmo'], \DateTimeZone::listIdentifiers(), true) ? $d['casove_pasmo'] : self::TIME_ZONES[$this->language];
        date_default_timezone_set($timeZone);
        $db->pdo()->exec("SET time_zone = '" . date('P') . "'");
        $d['casove_pasmo'] = $timeZone;
        $db->transaction(function (Db $db) use ($d, $password, $extensions): void {
            $admin = $db->insert('uzivatele', [
                'user' => $d['user'],
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'jmeno' => $d['jmeno'],
                'email' => $d['email'],
                'admin' => Auth::ADMIN,
                'jazyk' => $this->language === 'cs' ? '' : $this->language, // the admin of the first account in the installation language
            ]);

            // the site content is created in the site language (the site dictionary), the admin of the first account stays in the installation language
            $siteLanguage = $d['jazyk_webu'];
            $x = fn (string $text): string => \Kaleta\Core\Language::runWith($siteLanguage, fn (): string => t($text));
            // skeleton of a typical company site: home, about us, services, contact – the texts are only a guide to what belongs on the page
            $pages = [
                [$x('Úvod'), 'uvod', 0, '<h1>' . e($d['nazev_webu']) . '</h1><p>' . e($x('Jednou větou: co děláte a pro koho. Tuto stránku upravíte v administraci v sekci Stránky.')) . '</p>'],
                [$x('O nás'), slugify($x('O nás')), 1, '<p>' . e($x('Kdo jste, jak dlouho to děláte a proč vám zákazníci věří.')) . '</p>'],
                [$x('Služby'), slugify($x('Služby')), 1, '<p>' . e($x('Co nabízíte – každou službu krátce a srozumitelně.')) . '</p>'],
                [$x('Kontakt'), slugify($x('Kontakt')), 1, '<p>' . e($x('Adresa, telefon, e-mail a otevírací doba.')) . '</p>'],
            ];
            // pages straight from builder sections according to the chosen sample site – a new site looks like a site, not like an empty template
            $siteSettings = Library::SITES[$d['web']] ?? Library::SITES['firemni'];
            $home = 0;
            foreach ($pages as $i => [$title, $url, $inMenu, $text]) {
                $row = ['titulek' => $title, 'seo_link' => $url, 'text' => $text, 'v_menu' => $inMenu, 'poradi' => ($i + 1) * 10];
                if (($siteSettings['stranky'][$i] ?? []) !== []) {
                    // sections with elements of disabled extensions (news list, form) are not put on the initial pages, neither are empty images
                    $build = Library::page($db, $siteSettings['stranky'][$i], $title, $siteLanguage, Build::disabledTypes($extensions), true);
                    $row['stavba'] = Build::toJson($build);
                    $row['text'] = Build::asText($build);
                }
                $id = $db->insert('stranky', $row);
                $home = $home ?: $id;
            }

            // privacy policy: a skeleton to fill in, in the site language (the site dictionary, not the installer's), hidden until the
            // administrator fills it in and publishes it (First steps remind of it); outside the main menu, linked from the footer,
            // the cookie bar and the consent in the form
            [$privacyPolicy, $privacyPolicyText] = \Kaleta\Core\Language::runWith($siteLanguage, fn (): array => [t('Zásady ochrany osobních údajů'), Library::privacyPolicyText()]);
            $privacyPolicyId = $db->insert('stranky', ['titulek' => $privacyPolicy, 'seo_link' => slugify($privacyPolicy), 'text' => $privacyPolicyText, 'zobrazit' => 0, 'v_menu' => 0, 'poradi' => 90]);
            \Kaleta\Core\Menu::save($db, 'paticka', '', [['typ' => 'stranka', 'ids' => $privacyPolicyId, 'text' => '']]);

            \Kaleta\Core\Search::complete($db);
            $settings = ['site_name' => $d['nazev_webu'], 'site_url' => $this->request->origin(), 'site_email' => $d['email'], 'site_language' => $siteLanguage,
                'design_system' => (string) json_encode(\Kaleta\Builder\DesignSystem::preset($siteSettings['predvolba']), JSON_UNESCAPED_SLASHES),
                'time_zone' => $d['casove_pasmo'], 'layout' => Layouts::DEFAULTS, 'home_page' => (string) $home, 'db_version' => (string) Migration::latest(),
                'extensions' => $extensions === [] ? '-' : implode(',', $extensions), 'cookies_policy_url' => $this->request->basePath() . '/' . slugify($privacyPolicy)];
            foreach ($settings as $key => $value) {
                $db->insert('nastaveni', ['promenna' => $key, 'hodnota' => $value]);
            }

            if (!in_array('novinky', $extensions, true)) {
                return; // without news and without the welcome news item
            }
            $category = $db->insert('kategorie', ['nazev' => $x('Aktuality'), 'seo_link' => slugify($x('Aktuality')), 'popis' => '']);
            $db->insert('novinky', [
                'seo_link' => slugify($x('Vítejte v Kaletě')),
                'titulek' => $x('Vítejte v Kaletě'),
                'uvod' => '<p>' . e($x('Web je nainstalovaný a připravený. Tuto novinku můžete v administraci upravit nebo smazat.')) . '</p>',
                'text' => '<p>' . e($x('Do administrace se dostanete na adrese admin.php. Na přehledu vás provedou První kroky: dejte webu tvář, vyplňte údaje o firmě a připravte stránky.')) . '</p>',
                'tema' => $category,
                'autor' => $admin,
                'datum' => date('Y-m-d H:i:s'),
                'visible' => 1,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        return Response::html($this->view->render('install/' . $template, $data + [
            'base' => $this->request->basePath(), 'language' => \Kaleta\Core\Language::code(),
            'languages' => array_intersect_key(\Kaleta\Core\Language::ADMIN_LANGUAGES, self::TIME_ZONES),
        ]));
    }
}
