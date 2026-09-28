<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Updater;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\Health;
use Kaleta\Core\Backup;

/**
 * Site settings (table ka_nastaveni) split into tabs.
 * Each tab has a template views/admin/settings/<tab>.php and a list of fields with a type - the values are cleaned by it.
 */
class Settings extends Module
{
    public const string IDENT = 'settings';
    public const string NAME = 'Nastavení';
    public const string GROUP = 'Administration';
    public const string ICON = 'nastaveni';
    public const bool ADMIN_ONLY = true;

    public const array TABS = [
        'general' => 'General', 'company' => 'Company', 'seo' => 'SEO and GEO',
        'analytics' => 'Analytics', 'cookies' => 'Privacy and cookies', 'mail' => 'Mail', 'backups' => 'Backups and updates', 'health' => 'System status',
    ];

    /** Company types for the field company_type (vyber:…). */
    private const string COMPANY_TYPES = 'Organization|LocalBusiness|HomeAndConstructionBusiness|ProfessionalService|LegalService|AccountingService|MedicalBusiness|AutomotiveBusiness|Store|FoodEstablishment|LodgingBusiness|SportsActivityLocation|EducationalOrganization';

    public const array SOCIAL_NETWORKS = ['social_facebook' => 'Facebook', 'social_instagram' => 'Instagram', 'social_x' => 'X (Twitter)', 'social_youtube' => 'YouTube', 'social_linkedin' => 'LinkedIn'];

    /**
     * Fields of the individual tabs: key in ka_nastaveni => type.
     * text | tajne (secret key: not printed back, empty field = no change; tajne:/regex/ also checks the format) | radky (multi-line text) | kod (HTML/JS - entered only by the administrator) | url | email | ano (yes/no) | cislo:min:max (number) | vyber:a|b (choice) | seznam:a|b (checkboxes, saved as "a,b") | vzor:/regex/ (pattern)
     */
    private const array FIELDS = [
        'general' => [
            'site_name' => 'text', 'site_url' => 'vzor:#^https?://[a-z0-9.-]+(:\d+)?$#i', 'site_description' => 'radky', 'site_email' => 'email', 'footer_text' => 'text',
            'social_facebook' => 'url', 'social_instagram' => 'url', 'social_x' => 'url', 'social_youtube' => 'url', 'social_linkedin' => 'url',
            'home_page' => 'cislo:0:4294967295', 'news_per_page' => 'cislo:1:100', 'share_buttons' => 'ano', 'link_check' => 'ano', 'article_outline' => 'ano', 'related_news_auto' => 'ano', 'page_cache' => 'ano', 'maintenance' => 'ano', 'maintenance_text' => 'text', 'webhook_url' => 'url', 'webhook_enquiries' => 'url', 'require_2fa' => 'vyber:|spravci|vsichni',
            'time_zone' => 'pasmo', 'site_language' => 'vyber:' . \Kaleta\Core\Language::CODES, 'additional_languages' => 'seznam:' . \Kaleta\Core\Language::CODES,
        ],
        // the site appearance is saved by the Appearance module; here only types for checking values from the Claude connection (it is not a Settings tab)
        'vzhled' => ['dark_mode' => 'vyber:vypnuto|auto|tmavy', 'theme_switcher' => 'ano'],
        'company' => [
            'company_name' => 'text', 'company_type' => 'vyber:' . self::COMPANY_TYPES, 'company_id' => 'vzor:/^((?=.*\d)[A-Za-z0-9 .\/-]{1,24})?$/', 'company_register' => 'text', 'company_representative' => 'text', 'company_vat_id' => 'vzor:/^([A-Z]{2}[A-Z0-9]{6,12})?$/',
            'company_street' => 'text', 'company_city' => 'text', 'company_postcode' => 'vzor:/^[A-Z0-9 -]{0,10}$/i', 'company_country' => 'vzor:/^[A-Z]{2}$/',
            'company_phone' => 'vzor:/^[+()\d\s\/.-]{0,30}$/', 'company_email' => 'email', 'company_hours' => 'hodiny', 'company_map' => 'url', 'company_gps' => 'vzor:/^(-?\d{1,2}(\.\d+)?,\s*-?\d{1,3}(\.\d+)?)?$/',
        ],
        'seo' => [
            'indexing' => 'ano', 'schema_org' => 'ano', 'share_image' => 'text', 'verification_google' => 'vzor:/^[A-Za-z0-9_-]{0,100}$/',
            'verification_bing' => 'vzor:/^[A-Za-z0-9]{0,64}$/', 'robots_extra' => 'radky', 'ai_crawlers' => 'vyber:povolit|zakazat', 'llms_txt' => 'ano', 'markdown_news' => 'ano', 'indexnow' => 'ano',
        ],
        'analytics' => [
            'ga4_id' => 'vzor:/^(G-[A-Z0-9]{4,20})?$/', 'matomo_url' => 'url', 'matomo_id' => 'cislo:0:99999',
            'plausible_domain' => 'vzor:/^([a-z0-9.-]{3,100})?$/', 'head_code' => 'kod', 'stats' => 'ano',
        ],
        'cookies' => ['cookies_mode' => 'vyber:zadna|vestavena|externi', 'cookies_external_code' => 'kod', 'cookies_text' => 'radky', 'cookies_policy_url' => 'text', 'marketing_code' => 'kod', 'cookies_log' => 'ano', 'cookies_log_months' => 'cislo:0:120'],
        'mail' => ['mail_mode' => 'vyber:mail|smtp', 'mail_from' => 'email', 'mail_reply_to' => 'email', 'smtp_host' => 'vzor:/^[A-Za-z0-9.-]{0,120}$/', 'smtp_port' => 'cislo:1:65535',
            'smtp_encryption' => 'vyber:tls|ssl|zadne', 'smtp_user' => 'text', 'smtp_password' => 'tajne', 'newsletter_hourly_limit' => 'cislo:10:100000'],
        'extensions' => ['ai_provider' => 'vyber:' . \Kaleta\Core\Assistant::PROVIDER_KEYS, 'ai_key' => 'tajne', 'ai_model' => 'vzor:#^[A-Za-z0-9._:/-]{0,80}$#',
            'newsletter_service' => 'vyber:|brevo|mailerlite|mailchimp|ecomail|smartemailing|webhook', 'newsletter_key' => 'tajne',
            'newsletter_list' => 'vzor:#^[A-Za-z0-9_-]{0,64}$#', 'newsletter_webhook' => 'url'],
        'backups' => ['remote_backup' => 'vyber:vypnuto|ftp|s3', 'backup_host' => 'vzor:#^[A-Za-z0-9.:/-]{0,150}$#', 'backup_user' => 'text', 'backup_password' => 'tajne',
            'backup_folder' => 'vzor:#^[A-Za-z0-9._/-]{0,150}$#', 'backup_region' => 'vzor:/^[a-z0-9-]{0,40}$/', 'auto_backups' => 'ano', 'auto_updates' => 'ano', 'update_url' => 'url'],
        'health' => ['health_token' => 'vzor:/^[A-Za-z0-9]{0,64}$/'],
    ];

    /**
     * Fields of a tab. The general tab also has the site name and description for each additional language version
     * (nazev_webu_en, popis_webu_de…) - an empty value means "the same as in the default language".
     *
     * @return array<string, string>
     */
    private function fields(string $tab): array
    {
        $field = self::FIELDS[$tab];
        if ($tab === 'general') {
            foreach (\Kaleta\Core\Language::additional($this->app->settings()) as $language) {
                $field += ['nazev_webu_' . $language => 'text', 'popis_webu_' . $language => 'radky'];
            }
        }

        return $field;
    }

    /** Invalid values: a message with the field names as the user sees them, and the entered values back into the highlighted fields. */
    private function rejectInvalid(string $tab, array $errors, array $given): Response
    {
        $template = (string) @file_get_contents(KALETA_SYSTEM . '/views/admin/settings/' . $tab . '.php');
        $names = array_map(fn (string $key): string => preg_match('/\$pole\(\s*\'' . preg_quote($key, '/') . '\',\s*\'([^\']+)\'/', $template, $m) ? '„' . t($m[1]) . '“' : $key, $errors);
        $this->app->session->set('konfigurace_chybne', ['tab' => $tab, 'pole' => $errors, 'hodnoty' => $given]);

        return $this->back(t('These fields have an invalid format and were not saved: %s. Please correct them (they are highlighted); the other settings are saved.', implode(', ', $names)), '', static::IDENT === 'settings' ? ['tab' => $tab] : [], 'chyba');
    }

    protected function actionList(): Response
    {
        if (static::IDENT === 'settings' && $this->request->get('tab') === 'extensions') {
            return Response::redirect($this->app->url('admin.php?module=extensions')); // Extensions have their own menu item
        }
        $tab = $this->tab($this->request->get('tab'));
        $settings = $this->app->settings();
        $values = [];
        foreach ($this->fields($tab) as $key => $type) {
            $values[$key] = $settings->get($key);
            if (str_starts_with($type, 'tajne') && $values[$key] !== '') {
                $values[$key] = '…' . substr($values[$key], -4); // only the end of the key goes to the page, for checking
            }
        }

        $invalid = $this->app->session->get('konfigurace_chybne');
        $this->app->session->set('konfigurace_chybne', null);
        $invalid = is_array($invalid) && ($invalid['tab'] ?? '') === $tab ? $invalid : ['pole' => [], 'hodnoty' => []];

        return $this->view('list', 'Nastavení', [
            'tab' => $tab,
            'invalidFields' => $invalid['pole'],
            'values' => $invalid['hodnoty'] + $values,
            'checks' => $tab === 'health' ? Health::checks($this->app) : [],
            'remoteStatus' => $settings->get('remote_backup_status'),
            'tasksToken' => $settings->get('tasks_token'),
            'errorLog' => $tab === 'health' ? self::readFileTail(KALETA_ROOT . '/storage/log/chyby.log', 40) : [],
            'mail' => $tab === 'mail' ? $this->db->all('SELECT komu, predmet, vytvoreno, odeslano, pokusu, dalsi_pokus, chyba FROM {posta} ORDER BY idp DESC LIMIT 30') : [],
            'enabledExtensions' => Extensions::enabled($settings),
            'pages' => $tab === 'general' ? $this->db->pairs("SELECT ids, titulek FROM {stranky} WHERE zobrazit = 1 AND jazyk = '' ORDER BY poradi, titulek") : [],
            'backups' => $tab === 'backups' ? Backup::listAll() : [],
            'update' => $tab === 'backups' ? (new Updater($settings))->state() : null,
            'siteUrl' => $this->app->request->origin() . $this->app->url(''),
            'consents' => $tab === 'cookies' ? $this->db->all("SELECT kategorie, COUNT(*) AS pocet FROM {souhlasy} WHERE cas > NOW() - INTERVAL 30 DAY GROUP BY kategorie ORDER BY pocet DESC") : [],
        ]);
    }

    protected function actionSave(): Response
    {
        $tab = $this->tab($this->request->post('tab'));
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $settings = $this->app->settings();
        $errors = [];
        $given = [];
        foreach ($this->fields($tab) as $key => $type) {
            // "kod" is not trimmed or modified in any other way - it is HTML/JS inserted by the administrator
            $value = $type === 'kod' ? (string) ($_POST[$key] ?? '') : $this->request->post($key);
            if (str_starts_with($type, 'seznam:')) {
                $settings->set($key, implode(',', array_intersect($this->request->postList($key), explode('|', substr($type, 7)))));
                continue;
            }
            if (str_starts_with($type, 'tajne')) {
                if ($this->request->postBool($key . '_smazat')) {
                    $settings->set($key, '');
                } elseif ($value !== '' && $type !== 'tajne' && !preg_match(substr($type, 6), $value)) {
                    $errors[] = $key; // the value is never printed in the message, only the field name
                } elseif ($value !== '') {
                    $settings->set($key, mb_substr($value, 0, 300));
                }
                continue;
            }
            $clean = self::sanitize($type, $value, $this->request->postBool($key));
            if ($clean === null) {
                $errors[] = $key;
                $given[$key] = mb_substr($value, 0, 2000); // returned to the form for correction (secret keys not)
                continue;
            }
            $settings->set($key, $clean);
        }
        if ($tab === 'seo' && $settings->bool('indexnow') && $settings->get('indexnow_key') === '') {
            $settings->set('indexnow_key', bin2hex(random_bytes(16)));
        }
        if ($tab === 'extensions') {
            Extensions::save($settings, $this->request->postList('rozsireni'));
            if (Extensions::isEnabled($settings, 'novinky')) {
                Categories::createDefault($this->db, $settings); // news enabled after installation: right away with a category, as from the installation
            }
            if (($this->request->post('ai_key') !== '' || $this->request->post('ai_provider') !== $this->request->post('ai_poskytovatel_puvodni')) && $settings->get('ai_key') !== '' && ($keyError = (new \Kaleta\Core\Assistant($settings))->verifyKey()) !== null) {
                return $this->back(t('The settings are saved, but the assistant key does not work: %s', t($keyError)), '', static::IDENT === 'settings' ? ['tab' => $tab] : [], 'chyba');
            }
        }
        if ($this->request->postBool('novy_token_ulohy')) {
            $settings->set('tasks_token', bin2hex(random_bytes(16)));
        }
        if ($this->request->postBool('novy_token')) {
            $settings->set('health_token', bin2hex(random_bytes(16)));
        }

        return $errors === []
            ? $this->back('Settings saved.', '', static::IDENT === 'settings' ? ['tab' => $tab] : [])
            : $this->rejectInvalid($tab, $errors, $given);
    }

    protected function actionBackup(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $file = Backup::create($this->db);
        } catch (\Throwable $e) {
            return $this->back(t('The backup could not be created: %s', t($e->getMessage())), '', ['tab' => 'backups'], 'chyba');
        }
        $remote = \Kaleta\Core\RemoteBackup::upload($this->app->settings(), (string) Backup::path($file));
        if ($remote !== null) {
            return $this->back(t('Backup %s is ready, but the off-site copy could not be uploaded: %s', $file, t($remote)), '', ['tab' => 'backups'], 'chyba');
        }

        return $this->back(t('Backup %s is ready.', $file), '', ['tab' => 'backups']);
    }

    protected function actionDownloadBackup(): Response
    {
        $path = Backup::path($this->request->get('soubor'));
        if ($path === null) {
            return $this->error('Backup does not exist.', 404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            'Content-Length' => (string) filesize($path),
        ]);
    }

    protected function actionDeleteBackup(): Response
    {
        $path = Backup::path($this->request->post('soubor'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
        }

        return $this->back('Backup deleted.', '', ['tab' => 'backups']);
    }

    /** Empties the application error log. */
    protected function actionDeleteLog(): Response
    {
        if ($this->request->isPost() && is_file(KALETA_ROOT . '/storage/log/chyby.log')) {
            file_put_contents(KALETA_ROOT . '/storage/log/chyby.log', '');
        }

        return $this->back('The error log is empty.', '', ['tab' => 'health']);
    }

    /**
     * The last N lines of a file without loading the whole file into memory.
     *
     * @return list<string>
     */
    private static function readFileTail(string $file, int $lines): array
    {
        if (!is_file($file) || filesize($file) === 0) {
            return [];
        }
        $f = fopen($file, 'rb');
        fseek($f, -min(filesize($file), 64 * 1024), SEEK_END);
        $end = (string) stream_get_contents($f);
        fclose($f);

        return array_slice(array_values(array_filter(explode("\n", $end), fn (string $r): bool => trim($r) !== '')), -$lines);
    }

    /** Restoring the database from a backup; right before it, a safety backup of the current state is created. */
    protected function actionRestoreBackup(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'backups']);
        }
        try {
            $safetyBackup = Backup::create($this->db, 'predobnovou');
            $statementCount = Backup::restore($this->db, $this->request->post('soubor'));
        } catch (\Throwable $e) {
            $reverted = false;
            if (isset($safetyBackup)) {
                // failure in the middle of the restore: the database returns by itself to the state before the restore
                try {
                    Backup::restore($this->db, $safetyBackup);
                    $reverted = true;
                } catch (\Throwable) {
                    // the revert failed – the administrator runs it manually from the backup $safetyBackup
                }
            }
            \Kaleta\Front\Cache::clear();

            return $this->back(t('Obnova se nezdařila: %s', t($e->getMessage())) . ' ' . ($reverted ? t('The database is back in its state before the restore.') : (isset($safetyBackup) ? t('The state before the restore is in backup %s – please restore it.', $safetyBackup) : '')), '', ['tab' => 'backups'], 'chyba');
        }
        \Kaleta\Front\Cache::clear();

        return $this->back(t('The database has been restored from the backup (statements: %d). The state before the restore is saved in backup %s.', $statementCount, $safetyBackup), '', ['tab' => 'backups']);
    }

    /** Checks again whether a newer version is available. */
    protected function actionCheck(): Response
    {
        if ($this->request->isPost()) {
            (new Updater($this->app->settings()))->state(true);
        }

        return $this->back('', '', ['tab' => 'backups']);
    }

    /** Downloads, verifies and installs the new version. Before that it backs up the database. */
    protected function actionUpdate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            Backup::create($this->db, 'predaktualizaci');
            $version = (new Updater($this->app->settings()))->install($this->app->db());
        } catch (\Throwable $e) {
            return $this->back(t('The update failed: %s Nothing has changed on the site.', t($e->getMessage())), '', ['tab' => 'backups'], 'chyba');
        }

        return $this->back(t('The system has been updated to version %s. The database will update itself the next time the administration loads.', $version), '', ['tab' => 'backups']);
    }

    /** Backup of uploaded media: a ZIP of the media/ folder for download. */
    protected function actionMediaBackup(): Response
    {
        if (!class_exists(\ZipArchive::class) || !is_dir(KALETA_ROOT . '/media')) {
            return $this->back('The zip extension is missing on the server – download the media via FTP.', '', ['tab' => 'backups'], 'chyba');
        }
        $file = KALETA_ROOT . '/storage/cache/media-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS)) as $item) {
            // variants for srcset and the WebP/AVIF siblings (foto.jpg.webp) can be recreated at any time - only originals go to the backup,
            // including an original uploaded as WebP (foto.webp, one extension)
            if ($item->isFile() && !preg_match('/(-1200|-nahled)\.[a-z]+$|\.[a-z0-9]+\.(webp|avif)$/i', $item->getFilename()) && !str_starts_with($item->getFilename(), '.')) {
                $zip->addFile($item->getPathname(), substr($item->getPathname(), strlen(KALETA_ROOT) + 1));
            }
        }
        $zip->close();
        if (!is_file($file)) {
            return $this->back('There is nothing in the media/ folder yet.', '', ['tab' => 'backups'], 'chyba');
        }
        register_shutdown_function(static fn () => @unlink($file));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="media-' . date('Ymd') . '.zip"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /** Test e-mail to the site e-mail - verifies that the server can send mail. */
    protected function actionTestMail(): Response
    {
        $recipient = $this->app->settings()->get('site_email');
        if (!$this->request->isPost() || $recipient === '') {
            return $this->back('First fill in the Site e-mail on the General tab.', '', ['tab' => $this->request->post('tab') === 'mail' ? 'mail' : 'health'], 'chyba');
        }
        $siteSettings = $this->app->settings()->get('site_name');
        // the site e-mail has no account with a language: the message goes in the site's default language (like the rest of the site's mail)
        [$subject, $text] = \Kaleta\Core\Language::runWith(\Kaleta\Core\Language::defaults($this->app->settings()), fn (): array => [
            t('Test message from %s', $siteSettings),
            t('Hello,') . "\n\n" . t('this message confirms that the website %s can send e-mail.', $siteSettings) . "\n\nKaleta " . KALETA_VERSION,
        ], 'admin-');
        $ok = \Kaleta\Core\Mail::send($this->app->settings(), $recipient, $subject, $text, queueOnFailure: false);
        $back = $this->request->post('tab') === 'mail' ? 'mail' : 'health';

        return $this->back(
            match (true) {
                !$ok => t('Sending failed: %s', t(\Kaleta\Core\Mail::$error)),
                $this->app->settings()->get('mail_mode') === 'smtp' => t('The message has been handed over for delivery to %s. If it does not arrive, check your spam folder.', $recipient),
                default => t('The message has been handed over for delivery to %s. If it does not arrive, check your spam folder – or set up sending via SMTP (Settings → Mail).', $recipient),
            },
            '',
            ['tab' => $back],
            $ok ? 'ok' : 'chyba',
        );
    }

    protected function tab(string $tab): string
    {
        return isset(self::TABS[$tab]) ? $tab : 'general';
    }

    /** @return string|null the cleaned value, null = invalid */
    /**
     * A settings value validated the same way as in the admin form (for MCP). null = unknown key or invalid value.
     * Switches (type ano) take 1/0, true/false.
     */
    public static function verifyValue(string $key, string $value): ?string
    {
        $type = null;
        foreach (self::FIELDS as $field) {
            $type ??= $field[$key] ?? null;
        }
        if ($type === null && preg_match('/^(nazev|popis)_webu_([a-z]{2})$/', $key, $m)) {
            $type = $m[1] === 'nazev' ? 'text' : 'radky';
        }
        if ($type === null || str_starts_with($type, 'tajne') || str_starts_with($type, 'seznam')) {
            return null;
        }

        return self::sanitize($type, trim($value), in_array(strtolower(trim($value)), ['1', 'true', 'ano'], true));
    }

    private static function sanitize(string $type, string $value, bool $checked): ?string
    {
        [$kind, $parameter] = explode(':', $type, 2) + [1 => ''];

        return match ($kind) {
            'ano' => $checked ? '1' : '0',
            'text' => mb_substr(str_replace(["\r", "\n"], ' ', $value), 0, 500),
            'radky' => mb_substr($value, 0, 5000),
            'kod' => mb_substr($value, 0, 20000),
            'email' => $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null,
            'url' => $value === '' || (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL)) ? rtrim($value) : null,
            'cislo' => (function () use ($value, $parameter): string {
                [$min, $max] = array_map(intval(...), explode(':', $parameter));

                return (string) max($min, min($max, (int) $value));
            })(),
            'vyber' => in_array($value, explode('|', $parameter), true) ? $value : null,
            'pasmo' => in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null,
            'vzor' => preg_match($parameter, $value) ? $value : null,
            'hodiny' => \Kaleta\Front\Company::parseOpeningHours($value) !== null ? mb_substr(trim($value), 0, 1000) : null,
            default => null,
        };
    }
}
