<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Updater;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\Health;
use Kaleta\Core\Backup;
use Kaleta\Front\Layouts;

/**
 * Nastavení webu (tabulka ka_nastaveni) rozdělené do záložek.
 * Každá záložka má šablonu views/admin/config/<zalozka>.php a seznam polí s typem - podle něj se hodnoty čistí.
 */
class Settings extends Module
{
    public const string IDENT = 'config';
    public const string NAME = 'Nastavení';
    public const string GROUP = 'Správa';
    public const string ICON = 'nastaveni';
    public const bool ADMIN_ONLY = true;

    public const array TABS = [
        'zakladni' => 'Základní', 'firma' => 'Firma', 'seo' => 'SEO a GEO',
        'mereni' => 'Měření', 'cookies' => 'Soukromí a cookies', 'posta' => 'Pošta', 'zalohy' => 'Zálohy a aktualizace', 'stav' => 'Stav systému',
    ];

    /** Typy firmy pro pole firma_typ (vyber:…). */
    private const string COMPANY_TYPES = 'Organization|LocalBusiness|HomeAndConstructionBusiness|ProfessionalService|LegalService|AccountingService|MedicalBusiness|AutomotiveBusiness|Store|FoodEstablishment|LodgingBusiness|SportsActivityLocation|EducationalOrganization';

    public const array SOCIAL_NETWORKS = ['soc_facebook' => 'Facebook', 'soc_instagram' => 'Instagram', 'soc_x' => 'X (Twitter)', 'soc_youtube' => 'YouTube', 'soc_linkedin' => 'LinkedIn'];

    /**
     * Pole jednotlivých záložek: klíč v ka_nastaveni => typ.
     * text | tajne (klíč: nevypisuje se zpět, prázdné pole = beze změny; tajne:/regex/ navíc hlídá tvar) | radky (víceřádkový text) | kod (HTML/JS - zadává jen administrátor) | url | email | ano | cislo:min:max | vyber:a|b | seznam:a|b (zaškrtávací pole, ukládá se "a,b") | vzor:/regex/
     */
    private const array FIELDS = [
        'zakladni' => [
            'nazev_webu' => 'text', 'adresa_webu' => 'vzor:#^https?://[a-z0-9.-]+(:\d+)?$#i', 'popis_webu' => 'radky', 'email_webu' => 'email', 'text_paticky' => 'text',
            'soc_facebook' => 'url', 'soc_instagram' => 'url', 'soc_x' => 'url', 'soc_youtube' => 'url', 'soc_linkedin' => 'url',
            'titulni_stranka' => 'cislo:0:4294967295', 'pocet_clanku' => 'cislo:1:100', 'sdileni' => 'ano', 'kontrola_odkazu' => 'ano', 'osnova_clanku' => 'ano', 'souvisejici_auto' => 'ano', 'cache_stranek' => 'ano', 'udrzba' => 'ano', 'udrzba_text' => 'text', 'webhook_url' => 'url', 'webhook_poptavky' => 'url', 'vynutit_2fa' => 'vyber:|spravci|vsichni',
            'casove_pasmo' => 'pasmo', 'jazyk_webu' => 'vyber:' . \Kaleta\Core\Language::CODES, 'jazyky_dalsi' => 'seznam:' . \Kaleta\Core\Language::CODES,
        ],
        // Vzhled webu ukládá modul Vzhled; tady jen typy pro kontrolu hodnot z napojení na Claude (není to záložka Nastavení)
        'vzhled' => ['tmavy_rezim' => 'vyber:vypnuto|auto|tmavy', 'tmavy_prepinac' => 'ano'],
        'firma' => [
            'firma_nazev' => 'text', 'firma_typ' => 'vyber:' . self::COMPANY_TYPES, 'firma_ico' => 'vzor:/^((?=.*\d)[A-Za-z0-9 .\/-]{1,24})?$/', 'firma_rejstrik' => 'text', 'firma_zastupce' => 'text', 'firma_dic' => 'vzor:/^([A-Z]{2}[A-Z0-9]{6,12})?$/',
            'firma_ulice' => 'text', 'firma_mesto' => 'text', 'firma_psc' => 'vzor:/^[A-Z0-9 -]{0,10}$/i', 'firma_zeme' => 'vzor:/^[A-Z]{2}$/',
            'firma_telefon' => 'vzor:/^[+()\d\s\/.-]{0,30}$/', 'firma_email' => 'email', 'firma_hodiny' => 'hodiny', 'firma_mapa' => 'url', 'firma_gps' => 'vzor:/^(-?\d{1,2}(\.\d+)?,\s*-?\d{1,3}(\.\d+)?)?$/',
        ],
        'seo' => [
            'indexovani' => 'ano', 'schema_org' => 'ano', 'og_obrazek' => 'text', 'overeni_google' => 'vzor:/^[A-Za-z0-9_-]{0,100}$/',
            'overeni_bing' => 'vzor:/^[A-Za-z0-9]{0,64}$/', 'robots_extra' => 'radky', 'ai_crawlery' => 'vyber:povolit|zakazat', 'llms_txt' => 'ano', 'markdown_clanky' => 'ano', 'indexnow' => 'ano',
        ],
        'mereni' => [
            'ga4_id' => 'vzor:/^(G-[A-Z0-9]{4,20})?$/', 'matomo_url' => 'url', 'matomo_id' => 'cislo:0:99999',
            'plausible_domena' => 'vzor:/^([a-z0-9.-]{3,100})?$/', 'kod_hlava' => 'kod', 'statistika' => 'ano',
        ],
        'cookies' => ['cookies_rezim' => 'vyber:zadna|vestavena|externi', 'cookies_externi_kod' => 'kod', 'cookies_text' => 'radky', 'cookies_zasady_url' => 'text', 'kod_marketing' => 'kod', 'cookies_evidence' => 'ano', 'cookies_evidence_mesice' => 'cislo:0:120'],
        'posta' => ['posta_rezim' => 'vyber:mail|smtp', 'posta_od' => 'email', 'posta_odpoved' => 'email', 'smtp_host' => 'vzor:/^[A-Za-z0-9.-]{0,120}$/', 'smtp_port' => 'cislo:1:65535',
            'smtp_sifrovani' => 'vyber:tls|ssl|zadne', 'smtp_uzivatel' => 'text', 'smtp_heslo' => 'tajne'],
        'rozsireni' => ['ai_poskytovatel' => 'vyber:' . \Kaleta\Core\Assistant::PROVIDER_KEYS, 'ai_klic' => 'tajne', 'ai_model' => 'vzor:#^[A-Za-z0-9._:/-]{0,80}$#',
            'newsletter_sluzba' => 'vyber:|brevo|mailerlite|mailchimp|ecomail|smartemailing|webhook', 'newsletter_klic' => 'tajne',
            'newsletter_seznam' => 'vzor:#^[A-Za-z0-9_-]{0,64}$#', 'newsletter_webhook' => 'url'],
        'zalohy' => ['zaloha_vzdalena' => 'vyber:vypnuto|ftp|s3', 'zaloha_host' => 'vzor:#^[A-Za-z0-9.:/-]{0,150}$#', 'zaloha_uzivatel' => 'text', 'zaloha_heslo' => 'tajne',
            'zaloha_slozka' => 'vzor:#^[A-Za-z0-9._/-]{0,150}$#', 'zaloha_region' => 'vzor:/^[a-z0-9-]{0,40}$/', 'zalohy_auto' => 'ano', 'aktualizace_auto' => 'ano', 'aktualizace_url' => 'url'],
        'stav' => ['stav_token' => 'vzor:/^[A-Za-z0-9]{0,64}$/'],
    ];

    /**
     * Pole záložky. Základní záložka má navíc název a popis webu pro každou další jazykovou verzi
     * (nazev_webu_en, popis_webu_de…) - prázdná hodnota znamená „stejné jako ve výchozím jazyce“.
     *
     * @return array<string, string>
     */
    private function fields(string $tab): array
    {
        $field = self::FIELDS[$tab];
        if ($tab === 'zakladni') {
            foreach (\Kaleta\Core\Language::additional($this->app->settings()) as $language) {
                $field += ['nazev_webu_' . $language => 'text', 'popis_webu_' . $language => 'radky'];
            }
        }

        return $field;
    }

    /** Neplatné hodnoty: hláška s názvy polí, jak je vidí uživatel, a zadané hodnoty zpět do zvýrazněných polí. */
    private function rejectInvalid(string $tab, array $errors, array $given): Response
    {
        $template = (string) @file_get_contents(KALETA_SYSTEM . '/views/admin/config/' . $tab . '.php');
        $names = array_map(fn (string $key): string => preg_match('/\$pole\(\s*\'' . preg_quote($key, '/') . '\',\s*\'([^\']+)\'/', $template, $m) ? '„' . t($m[1]) . '“' : $key, $errors);
        $this->app->session->set('konfigurace_chybne', ['zalozka' => $tab, 'pole' => $errors, 'hodnoty' => $given]);

        return $this->back(t('Tato pole nemají platný tvar a neuložila se: %s. Opravte je prosím (jsou zvýrazněná), ostatní nastavení je uložené.', implode(', ', $names)), '', static::IDENT === 'config' ? ['zalozka' => $tab] : [], 'chyba');
    }

    protected function akceVypis(): Response
    {
        if (static::IDENT === 'config' && $this->request->get('zalozka') === 'rozsireni') {
            return Response::redirect($this->app->url('admin.php?modul=rozsireni')); // Rozšíření mají vlastní položku v nabídce
        }
        $tab = $this->tab($this->request->get('zalozka'));
        $settings = $this->app->settings();
        $values = [];
        foreach ($this->fields($tab) as $key => $type) {
            $values[$key] = $settings->get($key);
            if (str_starts_with($type, 'tajne') && $values[$key] !== '') {
                $values[$key] = '…' . substr($values[$key], -4); // do stránky jde jen konec klíče pro kontrolu
            }
        }

        $invalid = $this->app->session->get('konfigurace_chybne');
        $this->app->session->set('konfigurace_chybne', null);
        $invalid = is_array($invalid) && ($invalid['zalozka'] ?? '') === $tab ? $invalid : ['pole' => [], 'hodnoty' => []];

        return $this->view('vypis', 'Nastavení', [
            'zalozka' => $tab,
            'chybnaPole' => $invalid['pole'],
            'hodnoty' => $invalid['hodnoty'] + $values + ['layout' => $settings->get('layout')],
            'layouty' => Layouts::listAll(),
            'kontroly' => $tab === 'stav' ? Health::checks($this->app) : [],
            'vzdalenaStav' => $settings->get('zaloha_vzdalena_stav'),
            'ulohyToken' => $settings->get('ulohy_token'),
            'chybyLog' => $tab === 'stav' ? self::readFileTail(KALETA_ROOT . '/storage/log/chyby.log', 40) : [],
            'posta' => $tab === 'posta' ? $this->db->all('SELECT komu, predmet, vytvoreno, odeslano, pokusu, dalsi_pokus, chyba FROM {posta} ORDER BY idp DESC LIMIT 30') : [],
            'zapnutaRozsireni' => Extensions::enabled($settings),
            'stranky' => $tab === 'zakladni' ? $this->db->pairs("SELECT ids, titulek FROM {stranky} WHERE zobrazit = 1 AND jazyk = '' ORDER BY poradi, titulek") : [],
            'zalohy' => $tab === 'zalohy' ? Backup::listAll() : [],
            'aktualizace' => $tab === 'zalohy' ? (new Updater($settings))->state() : null,
            'adresaWebu' => $this->app->request->origin() . $this->app->url(''),
            'souhlasy' => $tab === 'cookies' ? $this->db->all("SELECT kategorie, COUNT(*) AS pocet FROM {souhlasy} WHERE cas > NOW() - INTERVAL 30 DAY GROUP BY kategorie ORDER BY pocet DESC") : [],
        ]);
    }

    protected function akceUloz(): Response
    {
        $tab = $this->tab($this->request->post('zalozka'));
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $settings = $this->app->settings();
        $errors = [];
        $given = [];
        foreach ($this->fields($tab) as $key => $type) {
            // "kod" se neořezává ani jinak neupravuje - je to HTML/JS vložené administrátorem
            $value = $type === 'kod' ? (string) ($_POST[$key] ?? '') : $this->request->post($key);
            if (str_starts_with($type, 'seznam:')) {
                $settings->set($key, implode(',', array_intersect($this->request->postList($key), explode('|', substr($type, 7)))));
                continue;
            }
            if (str_starts_with($type, 'tajne')) {
                if ($this->request->postBool($key . '_smazat')) {
                    $settings->set($key, '');
                } elseif ($value !== '' && $type !== 'tajne' && !preg_match(substr($type, 6), $value)) {
                    $errors[] = $key; // hodnota se do hlášky nikdy nevypisuje, jen název pole
                } elseif ($value !== '') {
                    $settings->set($key, mb_substr($value, 0, 300));
                }
                continue;
            }
            $clean = self::sanitize($type, $value, $this->request->postBool($key));
            if ($clean === null) {
                $errors[] = $key;
                $given[$key] = mb_substr($value, 0, 2000); // vrátí se do formuláře k opravě (tajné klíče ne)
                continue;
            }
            $settings->set($key, $clean);
        }
        if ($tab === 'seo' && $settings->bool('indexnow') && $settings->get('indexnow_klic') === '') {
            $settings->set('indexnow_klic', bin2hex(random_bytes(16)));
        }
        if ($tab === 'rozsireni') {
            Extensions::save($settings, $this->request->postList('rozsireni'));
            if (Extensions::isEnabled($settings, 'novinky')) {
                Categories::createDefault($this->db, $settings); // novinky zapnuté po instalaci: rovnou s kategorií, jako z instalace
            }
            if (($this->request->post('ai_klic') !== '' || $this->request->post('ai_poskytovatel') !== $this->request->post('ai_poskytovatel_puvodni')) && $settings->get('ai_klic') !== '' && ($keyError = (new \Kaleta\Core\Assistant($settings))->verifyKey()) !== null) {
                return $this->back(t('Nastavení je uložené, ale klíč asistenta nefunguje: %s', t($keyError)), '', static::IDENT === 'config' ? ['zalozka' => $tab] : [], 'chyba');
            }
        }
        if ($this->request->postBool('novy_token_ulohy')) {
            $settings->set('ulohy_token', bin2hex(random_bytes(16)));
        }
        if ($this->request->postBool('novy_token')) {
            $settings->set('stav_token', bin2hex(random_bytes(16)));
        }

        return $errors === []
            ? $this->back('Nastavení bylo uloženo.', '', static::IDENT === 'config' ? ['zalozka' => $tab] : [])
            : $this->rejectInvalid($tab, $errors, $given);
    }

    protected function akceZalohuj(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $file = Backup::create($this->db);
        } catch (\Throwable $e) {
            return $this->back(t('Zálohu se nepodařilo vytvořit: %s', t($e->getMessage())), '', ['zalozka' => 'zalohy'], 'chyba');
        }
        $remote = \Kaleta\Core\RemoteBackup::upload($this->app->settings(), (string) Backup::path($file));
        if ($remote !== null) {
            return $this->back(t('Záloha %s je hotová, ale kopii mimo server se nepodařilo nahrát: %s', $file, t($remote)), '', ['zalozka' => 'zalohy'], 'chyba');
        }

        return $this->back(t('Záloha %s je hotová.', $file), '', ['zalozka' => 'zalohy']);
    }

    protected function akceStahniZalohu(): Response
    {
        $path = Backup::path($this->request->get('soubor'));
        if ($path === null) {
            return $this->error('Záloha neexistuje.', 404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            'Content-Length' => (string) filesize($path),
        ]);
    }

    protected function akceSmazZalohu(): Response
    {
        $path = Backup::path($this->request->post('soubor'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
        }

        return $this->back('Záloha byla smazána.', '', ['zalozka' => 'zalohy']);
    }

    /** Vyprázdní záznam chyb aplikace. */
    protected function akceSmazLog(): Response
    {
        if ($this->request->isPost() && is_file(KALETA_ROOT . '/storage/log/chyby.log')) {
            file_put_contents(KALETA_ROOT . '/storage/log/chyby.log', '');
        }

        return $this->back('Záznam chyb je prázdný.', '', ['zalozka' => 'stav']);
    }

    /**
     * Posledních N řádků souboru bez načtení celého souboru do paměti.
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

    /** Obnova databáze ze zálohy; těsně před ní vznikne pojistná záloha současného stavu. */
    protected function akceObnovZalohu(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['zalozka' => 'zalohy']);
        }
        try {
            $safetyBackup = Backup::create($this->db, 'predobnovou');
            $statementCount = Backup::restore($this->db, $this->request->post('soubor'));
        } catch (\Throwable $e) {
            $reverted = false;
            if (isset($safetyBackup)) {
                // selhání uprostřed obnovy: databáze se sama vrátí do stavu před obnovou
                try {
                    Backup::restore($this->db, $safetyBackup);
                    $reverted = true;
                } catch (\Throwable) {
                    // vrácení se nepovedlo – správce ho spustí ručně ze zálohy $pojistna
                }
            }
            \Kaleta\Front\Cache::clear();

            return $this->back(t('Obnova se nezdařila: %s', t($e->getMessage())) . ' ' . ($reverted ? t('Databáze je zpět ve stavu před obnovou.') : (isset($safetyBackup) ? t('Stav před obnovou je v záloze %s – obnovte ji prosím.', $safetyBackup) : '')), '', ['zalozka' => 'zalohy'], 'chyba');
        }
        \Kaleta\Front\Cache::clear();

        return $this->back(t('Databáze byla obnovena ze zálohy (příkazů: %d). Stav před obnovou je uložený v záloze %s.', $statementCount, $safetyBackup), '', ['zalozka' => 'zalohy']);
    }

    /** Znovu zjistí, zda je k dispozici novější verze. */
    protected function akceZkontroluj(): Response
    {
        if ($this->request->isPost()) {
            (new Updater($this->app->settings()))->state(true);
        }

        return $this->back('', '', ['zalozka' => 'zalohy']);
    }

    /** Stáhne, ověří a nainstaluje novou verzi. Před tím zazálohuje databázi. */
    protected function akceAktualizuj(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            Backup::create($this->db, 'predaktualizaci');
            $version = (new Updater($this->app->settings()))->install($this->app->db());
        } catch (\Throwable $e) {
            return $this->back(t('Aktualizace se nezdařila: %s Na webu se nic nezměnilo.', t($e->getMessage())), '', ['zalozka' => 'zalohy'], 'chyba');
        }

        return $this->back(t('Systém byl aktualizován na verzi %s. Databáze se upraví sama při příštím načtení administrace.', $version), '', ['zalozka' => 'zalohy']);
    }

    /** Záloha nahraných médií: ZIP složky media/ ke stažení. */
    protected function akceZalohaMedii(): Response
    {
        if (!class_exists(\ZipArchive::class) || !is_dir(KALETA_ROOT . '/media')) {
            return $this->back('Na serveru chybí rozšíření zip – média si stáhněte přes FTP.', '', ['zalozka' => 'zalohy'], 'chyba');
        }
        $file = KALETA_ROOT . '/storage/cache/media-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS)) as $item) {
            // varianty pro srcset a sourozenci WebP/AVIF (foto.jpg.webp) se dají kdykoli vytvořit znovu - do zálohy jdou jen originály,
            // i nahraný originál ve WebP (foto.webp, jedna přípona)
            if ($item->isFile() && !preg_match('/(-1200|-nahled)\.[a-z]+$|\.[a-z0-9]+\.(webp|avif)$/i', $item->getFilename()) && !str_starts_with($item->getFilename(), '.')) {
                $zip->addFile($item->getPathname(), substr($item->getPathname(), strlen(KALETA_ROOT) + 1));
            }
        }
        $zip->close();
        if (!is_file($file)) {
            return $this->back('Ve složce media/ zatím nic není.', '', ['zalozka' => 'zalohy'], 'chyba');
        }
        register_shutdown_function(static fn () => @unlink($file));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="media-' . date('Ymd') . '.zip"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /** Zkušební e-mail na e-mail webu - ověří, že server umí odesílat poštu. */
    protected function akceTestPosty(): Response
    {
        $recipient = $this->app->settings()->get('email_webu');
        if (!$this->request->isPost() || $recipient === '') {
            return $this->back('Nejprve vyplňte E-mail webu v záložce Základní.', '', ['zalozka' => $this->request->post('zalozka') === 'posta' ? 'posta' : 'stav'], 'chyba');
        }
        $siteSettings = $this->app->settings()->get('nazev_webu');
        // e-mail webu nemá účet s jazykem: zpráva jde ve výchozím jazyce webu (stejně jako ostatní pošta webu)
        [$subject, $text] = \Kaleta\Core\Language::runWith(\Kaleta\Core\Language::defaults($this->app->settings()), fn (): array => [
            t('Zkušební zpráva z %s', $siteSettings),
            t('Dobrý den,') . "\n\n" . t('tato zpráva potvrzuje, že web %s umí odesílat e-maily.', $siteSettings) . "\n\nKaleta " . KALETA_VERSION,
        ], 'admin-');
        $ok = \Kaleta\Core\Mail::send($this->app->settings(), $recipient, $subject, $text, queueOnFailure: false);
        $back = $this->request->post('zalozka') === 'posta' ? 'posta' : 'stav';

        return $this->back(
            match (true) {
                !$ok => t('Odeslání selhalo: %s', t(\Kaleta\Core\Mail::$error)),
                $this->app->settings()->get('posta_rezim') === 'smtp' => t('Zpráva byla předána k odeslání na %s. Pokud nedorazí, zkontrolujte spam.', $recipient),
                default => t('Zpráva byla předána k odeslání na %s. Pokud nedorazí, zkontrolujte spam – nebo nastavte odesílání přes SMTP (Nastavení → Pošta).', $recipient),
            },
            '',
            ['zalozka' => $back],
            $ok ? 'ok' : 'chyba',
        );
    }

    protected function tab(string $tab): string
    {
        return isset(self::TABS[$tab]) ? $tab : 'zakladni';
    }

    /** @return string|null vyčištěná hodnota, null = neplatná */
    /**
     * Hodnota nastavení ověřená stejně jako ve formuláři administrace (pro MCP). null = neznámý klíč nebo neplatná hodnota.
     * Přepínače (typ ano) berou 1/0, true/false.
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
