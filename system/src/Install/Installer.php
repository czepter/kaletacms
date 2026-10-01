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
use Kaleta\Builder\Library;
use Kaleta\Builder\Build;

/**
 * Installer: checks the server, creates the tables and the first administrator and writes config.php. In the browser
 * (install.php) or, for hosting panels, containers and scripts, from the command line (php install.php --help, 2.5).
 * The database can come from the environment (KALETA_DB_*): Docker, Coolify and similar platforms set it, so nobody
 * types it in.
 */
final class Installer
{
    private Request $request;
    private readonly View $view;

    public function __construct()
    {
        $this->request = Request::fromGlobals();
        $this->view = new View([KALETA_SYSTEM . '/views']);
    }

    /** Installation languages (= admin languages) and the default time zone we offer for them. */
    private const array TIME_ZONES = ['cs' => 'Europe/Prague', 'en' => 'Europe/London', 'de' => 'Europe/Berlin'];

    /** Database settings from the environment: form field => variable. The name and the user must be set for the rest to count. */
    public const array ENVIRONMENT = ['db_host' => 'KALETA_DB_HOST', 'db_port' => 'KALETA_DB_PORT', 'db_name' => 'KALETA_DB_NAME',
        'db_user' => 'KALETA_DB_USER', 'db_password' => 'KALETA_DB_PASSWORD', 'db_prefix' => 'KALETA_DB_PREFIX'];

    /**
     * The database set by the server or platform, or null when the installer has to ask for it.
     *
     * @return array<string, string>|null
     */
    public static function databaseFromEnvironment(): ?array
    {
        $values = array_map(fn (string $name): string => (string) getenv($name), self::ENVIRONMENT);
        if ($values['db_name'] === '' || $values['db_user'] === '') {
            return null;
        }

        return array_filter(['db_host' => $values['db_host'], 'db_port' => $values['db_port'], 'db_prefix' => $values['db_prefix']], fn (string $v): bool => $v !== '')
            + ['db_name' => $values['db_name'], 'db_user' => $values['db_user'], 'db_password' => $values['db_password']];
    }

    /**
     * With the database from the environment the web installer would otherwise give the site to whoever opens it first, so it asks for
     * a one-time code (2.5.1): the Docker image prints it to the container log, and it is stored on the server in this file.
     */
    public const string CODE_FILE = '/storage/install-code';

    /** The installation code (lowercase hex digits only); created when it does not exist yet, '' when it cannot be written. */
    public static function installCode(): string
    {
        $file = KALETA_ROOT . self::CODE_FILE;
        if (!is_file($file) && @file_put_contents($file, implode('-', str_split(bin2hex(random_bytes(8)), 4)) . "\n", LOCK_EX) !== false) {
            @chmod($file, 0600);
        }

        return strtolower((string) preg_replace('/[^a-f0-9]/i', '', (string) @file_get_contents($file)));
    }

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

            return $this->page('done', ['alreadyInstalled' => true, 'deleted' => $this->deleteSelf(), 'fromExport' => false]);
        }

        $this->language = $this->chooseLanguage();
        \Kaleta\Core\Language::set($this->language, 'install-');
        $requirements = $this->requirements();
        $data = [
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_password' => '', 'db_prefix' => 'ka_',
            'nazev_webu' => t('My website'), 'user' => 'admin', 'jmeno' => '', 'email' => '',
            'casove_pasmo' => self::TIME_ZONES[$this->language], 'web' => 'firemni', 'jazyk_webu' => $this->language,
        ];
        $environment = self::databaseFromEnvironment();
        $data = ($environment ?? []) + $data;
        $errors = [];
        // extensions enabled after installation: the default set, after the form is submitted the user's choice
        $extensions = array_keys(array_filter(Extensions::CATALOG, fn (array $r): bool => $r[2]));

        if ($this->request->isPost() && !in_array(false, array_column($requirements, 'ok'), true)) {
            foreach (array_keys($data) as $key) {
                if ($environment !== null && str_starts_with($key, 'db_')) {
                    continue; // the database comes from the environment, the form does not show it
                }
                // the database password is not trimmed - it can contain spaces
                $data[$key] = $key === 'db_password' ? (string) ($_POST[$key] ?? '') : $this->request->post($key);
            }
            $data['jazyk_webu'] = isset(\Kaleta\Core\Language::AVAILABLE[$data['jazyk_webu']]) ? $data['jazyk_webu'] : $this->language;
            $extensions = array_values(array_intersect($this->request->postList('rozsireni'), array_keys(Extensions::CATALOG)));
            $code = $environment === null ? '' : self::installCode();
            $given = strtolower((string) preg_replace('/[^a-f0-9]/i', '', (string) ($_POST['install_code'] ?? '')));
            $errors = match (true) {
                $environment !== null && strlen($code) < 16 => ['install_code' => t('The installation code could not be created – make the storage folder writable.')],
                $environment !== null && !hash_equals($code, $given) => ['install_code' => t('The installation code is not correct.')],
                default => $this->install($data, (string) ($_POST['password'] ?? ''), (string) ($_POST['password2'] ?? ''), $extensions),
            };
            if ($errors === []) {
                @unlink(KALETA_ROOT . self::CODE_FILE);
                return $this->page('done', ['alreadyInstalled' => false, 'deleted' => $this->deleteSelf(), 'fromExport' => $data['web'] === 'export',
                    'mcp' => in_array('claude', $extensions, true) ? $this->request->origin() . $this->request->basePath() . '/mcp' : null]);
            }
        }

        if ($environment !== null) {
            self::installCode(); // the code exists before the form asks for it
        }

        return $this->page('form', ['requirements' => $requirements, 'data' => $data, 'errors' => $errors, 'extensions' => $extensions,
            'databaseFromEnvironment' => $environment !== null]);
    }

    private const string HELP = <<<'TXT'
        Kaleta – installation from the command line

          php install.php --url=https://example.com --admin-user=admin --admin-email=you@example.com [options]

        The administrator's password is read from the KALETA_ADMIN_PASSWORD variable, from --admin-password-file,
        or asked for (it is never taken from the command line, where other users of the server could see it).
        The database is read from KALETA_DB_HOST, KALETA_DB_PORT, KALETA_DB_NAME, KALETA_DB_USER, KALETA_DB_PASSWORD
        and KALETA_DB_PREFIX, or from the options below (the database password only from KALETA_DB_PASSWORD or
        --db-password-file).

          --url=URL                  address of the site, e.g. https://example.com or https://example.com/web (required)
          --admin-user=NAME          sign-in name of the first administrator (default admin)
          --admin-name=NAME          their name
          --admin-email=E-MAIL       their e-mail, also the site e-mail
          --site-name=NAME           name of the site (default "My website")
          --language=en|cs|de        language of the administration (default en)
          --site-language=CODE       language of the site, e.g. en, de, fr (default: the language of the administration)
          --starter=NAME             starter site: firemni (business), remeslo (crafts), poradenstvi (consulting),
                                     export (empty, for an import); default firemni
          --extensions=a,b,c         extensions to switch on (default: the recommended set); "none" for none
          --timezone=ZONE            e.g. Europe/Berlin
          --db-host=, --db-port=, --db-name=, --db-user=, --db-prefix=, --db-password-file=PATH

        TXT;

    /**
     * Installation from the command line (2.5). Returns the exit code: 0 installed, 1 not.
     *
     * @param list<string> $argv
     */
    public function cli(array $argv): int
    {
        $o = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) !== 1) {
                fwrite(STDERR, "Unknown argument: {$arg} (see php install.php --help)\n");

                return 1;
            }
            $o[$m[1]] = $m[2] ?? '';
        }
        if (isset($o['help'])) {
            fwrite(STDOUT, self::HELP);

            return 0;
        }
        $this->language = isset(self::TIME_ZONES[$o['language'] ?? '']) ? $o['language'] : 'en';
        \Kaleta\Core\Language::set($this->language, 'install-');
        if (is_file(KALETA_ROOT . '/config.php')) {
            fwrite(STDERR, t('The config.php file exists, so the installer changes nothing.') . "\n");

            return 1;
        }
        $unmet = array_filter($this->requirements(), fn (array $r): bool => !$r['ok']);
        if ($unmet !== []) {
            fwrite(STDERR, t('The server does not meet the requirements. Fix the items marked with a cross and reload the page.') . "\n");
            foreach ($unmet as $r) {
                fwrite(STDERR, '  ✗ ' . $r['nazev'] . ' – ' . $r['info'] . "\n");
            }

            return 1;
        }
        $url = parse_url((string) ($o['url'] ?? ''));
        if (!isset($url['scheme'], $url['host']) || !in_array($url['scheme'], ['http', 'https'], true)) {
            fwrite(STDERR, "--url is required: the address of the site, e.g. https://example.com\n");

            return 1;
        }
        // the site address and the path of a site in a subfolder, as the browser would give them
        $this->request = new Request([], [], ['HTTP_HOST' => $url['host'] . (isset($url['port']) ? ':' . $url['port'] : ''), 'HTTPS' => $url['scheme'] === 'https' ? 'on' : '',
            'SCRIPT_NAME' => rtrim((string) ($url['path'] ?? ''), '/') . '/install.php'], []);

        $readFile = fn (string $option): ?string => isset($o[$option]) ? (is_readable($o[$option]) ? rtrim((string) file_get_contents($o[$option]), "\r\n") : null) : '';
        $d = ['db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_password' => '', 'db_prefix' => 'ka_'];
        foreach (array_keys($d) as $key) {
            $option = str_replace('_', '-', $key);
            if ($key !== 'db_password' && isset($o[$option])) {
                $d[$key] = trim($o[$option]);
            }
        }
        $d = (self::databaseFromEnvironment() ?? []) + $d;
        $dbPassword = $readFile('db-password-file');
        if ($dbPassword === null) {
            fwrite(STDERR, "--db-password-file cannot be read.\n");

            return 1;
        }
        $d['db_password'] = $dbPassword !== '' ? $dbPassword : ($d['db_password'] !== '' ? $d['db_password'] : (string) getenv('KALETA_DB_PASSWORD'));

        $starter = $o['starter'] ?? 'firemni';
        if ($starter !== 'export' && !isset(Library::SITES[$starter])) {
            fwrite(STDERR, '--starter must be one of: ' . implode(', ', [...array_keys(Library::SITES), 'export']) . "\n");

            return 1;
        }
        $siteLanguage = $o['site-language'] ?? $this->language;
        $d += [
            'nazev_webu' => trim($o['site-name'] ?? '') ?: t('My website'), 'user' => trim($o['admin-user'] ?? 'admin'), 'jmeno' => trim($o['admin-name'] ?? ''),
            'email' => trim($o['admin-email'] ?? ''), 'casove_pasmo' => $o['timezone'] ?? self::TIME_ZONES[$this->language], 'web' => $starter,
            'jazyk_webu' => isset(\Kaleta\Core\Language::AVAILABLE[$siteLanguage]) ? $siteLanguage : $this->language,
        ];
        $extensions = match (true) {
            !isset($o['extensions']) => array_keys(array_filter(Extensions::CATALOG, fn (array $r): bool => $r[2])),
            $o['extensions'] === 'none' => [],
            default => array_values(array_intersect(array_map('trim', explode(',', $o['extensions'])), array_keys(Extensions::CATALOG))),
        };

        $password = $readFile('admin-password-file');
        if ($password === null) {
            fwrite(STDERR, "--admin-password-file cannot be read.\n");

            return 1;
        }
        $password = $password !== '' ? $password : (string) getenv('KALETA_ADMIN_PASSWORD');
        if ($password === '' && stream_isatty(STDIN)) {
            $ask = function (string $prompt): string {
                fwrite(STDOUT, $prompt);
                @shell_exec('stty -echo 2>/dev/null');
                $line = rtrim((string) fgets(STDIN), "\r\n");
                @shell_exec('stty echo 2>/dev/null');
                fwrite(STDOUT, "\n");

                return $line;
            };
            $password = $ask(t('Password') . ': ');
            if ($password !== $ask(t('Password') . ' (' . t('again') . '): ')) {
                fwrite(STDERR, t('The passwords do not match.') . "\n");

                return 1;
            }
        }
        if ($password === '') {
            fwrite(STDERR, "Set the administrator's password in KALETA_ADMIN_PASSWORD or --admin-password-file.\n");

            return 1;
        }

        $errors = $this->install($d, $password, $password, $extensions);
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                fwrite(STDERR, "{$field}: {$message}\n");
            }

            return 1;
        }
        $admin = $this->request->origin() . $this->request->basePath() . '/admin.php';
        fwrite(STDOUT, t('Done, your website is running') . "\n" . t('Go to the administration') . ": {$admin}\n"
            . ($this->deleteSelf() ? t('For security reasons install.php has deleted itself – there is nothing else you need to do.') : t('For security reasons, now delete this file from the server:') . ' install.php') . "\n");

        return 0;
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
            ['nazev' => t('PHP 8.4 or newer'), 'ok' => PHP_VERSION_ID >= 80400, 'info' => t('running') . ' ' . PHP_VERSION],
            ['nazev' => t('pdo_mysql extension'), 'ok' => extension_loaded('pdo_mysql'), 'info' => t('connection to a MySQL / MariaDB database')],
            ['nazev' => t('mbstring extension'), 'ok' => extension_loaded('mbstring'), 'info' => t('working with accented text (UTF-8)')],
            ['nazev' => t('Write access to the root folder'), 'ok' => $write(''), 'info' => t('needed to create config.php')],
            ['nazev' => t('Write access to the storage/ folder'), 'ok' => $write('/storage/log') && $write('/storage/cache'), 'info' => t('logs and cache')],
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
            $errors['db_prefix'] = t('Prefix: lowercase letters, digits and underscore, at most 16 characters (e.g. ka_).');
        }
        if ($d['db_name'] === '' || $d['db_user'] === '') {
            $errors['db_name'] = t('Fill in the database name and user.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $d['user'])) {
            $errors['user'] = t('Username: 2–40 characters, letters without accents, digits, dot, hyphen, underscore.');
        }
        if (mb_strlen($password) < 10) {
            $errors['password'] = t('The password must be at least 10 characters long.');
        } elseif ($password !== $password2) {
            $errors['password'] = t('The passwords do not match.');
        }
        if ($d['email'] !== '' && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('The e-mail address is not valid.');
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
            [$d['db_prefix'] . 'uzivatele'],
        );
        if ((int) $exists > 0) {
            return ['db_prefix' => t('Tables with this prefix already exist in the database. Choose another prefix or remove them first.')];
        }

        try {
            foreach (Migration::statements((string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'), $d['db_prefix']) as $sql) {
                $db->pdo()->exec($sql);
            }
            $this->createDefaultData($db, $d, $password, $extensions);
        } catch (\PDOException $e) {
            return ['db_name' => t('Creating the tables failed:') . ' ' . $e->getMessage()];
        }

        $content = "<?php\n/**\n * Kaleta - konfigurace vytvořená instalátorem " . date('j. n. Y') . ".\n */\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents(KALETA_ROOT . '/config.php', $content, LOCK_EX) === false) {
            return ['db_name' => t('The tables were created, but config.php could not be written. Check the write permissions.')];
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
            1045 => ['db_user' => t('The database user name or password is wrong. Check them in your hosting control panel.')],
            1044 => ['db_name' => t('This user has no access to the database. Grant it in your hosting control panel.')],
            1049 => ['db_name' => t('There is no database with this name on the server. Create it in your hosting control panel or correct the name.')],
            2002, 2005, 2006 => ['db_host' => t('Could not connect to the database server. Check the server and port.')],
            default => ['db_name' => t('Could not connect to the database:') . ' ' . $e->getMessage()],
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
            if ($d['web'] === 'export') {
                // "Start from an export" (1.8): an empty site – the content, look and settings come with the import (Import and export)
                $settings = ['site_name' => $d['nazev_webu'], 'site_url' => $this->request->origin(), 'site_email' => $d['email'], 'site_language' => $siteLanguage,
                    'time_zone' => $d['casove_pasmo'], 'db_version' => (string) Migration::latest(), 'data_migrations' => implode(',', Migration::DATA), 'extensions' => $extensions === [] ? '-' : implode(',', $extensions)];
                foreach ($settings as $key => $value) {
                    $db->insert('nastaveni', ['promenna' => $key, 'hodnota' => $value]);
                }

                return;
            }
            $x = fn (string $text): string => \Kaleta\Core\Language::runWith($siteLanguage, fn (): string => t($text));
            // skeleton of a typical company site: home, about us, services, contact – the texts are only a guide to what belongs on the page
            $pages = [
                [$x('Úvod'), 'uvod', 0, '<h1>' . e($d['nazev_webu']) . '</h1><p>' . e($x('In one sentence: what you do and for whom. Edit this page in the administration under Pages.')) . '</p>'],
                [$x('About us'), slugify($x('About us')), 1, '<p>' . e($x('Who you are, how long you have been doing it and why customers trust you.')) . '</p>'],
                [$x('Services'), slugify($x('Services')), 1, '<p>' . e($x('What you offer – each service briefly and clearly.')) . '</p>'],
                [$x('Contact'), slugify($x('Contact')), 1, '<p>' . e($x('Address, phone, e-mail and opening hours.')) . '</p>'],
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
            [$privacyPolicy, $privacyPolicyText] = \Kaleta\Core\Language::runWith($siteLanguage, fn (): array => [t('Privacy policy'), Library::privacyPolicyText()]);
            $privacyPolicyId = $db->insert('stranky', ['titulek' => $privacyPolicy, 'seo_link' => slugify($privacyPolicy), 'text' => $privacyPolicyText, 'zobrazit' => 0, 'v_menu' => 0, 'poradi' => 90]);
            \Kaleta\Core\Menu::save($db, 'paticka', '', [['typ' => 'stranka', 'ids' => $privacyPolicyId, 'text' => '']]);

            \Kaleta\Core\Search::complete($db);
            $settings = ['site_name' => $d['nazev_webu'], 'site_url' => $this->request->origin(), 'site_email' => $d['email'], 'site_language' => $siteLanguage,
                'design_system' => (string) json_encode(\Kaleta\Builder\DesignSystem::preset($siteSettings['predvolba']), JSON_UNESCAPED_SLASHES),
                'time_zone' => $d['casove_pasmo'], 'home_page' => (string) $home, 'db_version' => (string) Migration::latest(), 'data_migrations' => implode(',', Migration::DATA),
                'extensions' => $extensions === [] ? '-' : implode(',', $extensions), 'cookies_policy_url' => $this->request->basePath() . '/' . slugify($privacyPolicy)];
            foreach ($settings as $key => $value) {
                $db->insert('nastaveni', ['promenna' => $key, 'hodnota' => $value]);
            }

            if (!in_array('novinky', $extensions, true)) {
                return; // without news and without the welcome news item
            }
            $category = $db->insert('kategorie', ['nazev' => $x('Aktuality'), 'seo_link' => slugify($x('Aktuality')), 'popis' => '']);
            $db->insert('novinky', [
                'seo_link' => slugify($x('Our new website is live')),
                'titulek' => $x('Our new website is live'),
                'uvod' => '<p>' . e($x('Welcome to our new website. This is where we will share news about our work, projects and offers.')) . '</p>',
                'text' => '<p>' . e($x('We have rebuilt the website so that it is easier to see what we do and how to get in touch. Have a look around – and if you have a question, just write to us.')) . '</p>',
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
