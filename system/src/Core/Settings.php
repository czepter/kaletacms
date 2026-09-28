<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Nastavení webu z tabulky ka_nastaveni (promenna => hodnota).
 */
final class Settings
{
    /** Výchozí hodnoty; zároveň seznam všech známých proměnných. */
    public const array DEFAULTS = [
        'site_name' => 'Můj web',
        'site_url' => '',          // https://www.example.cz - z ní se skládají odkazy v e-mailech, kanálech a oznámeních (ne z hlavičky Host)
        'site_description' => '',
        'keywords' => '',
        'site_email' => '',
        'logo' => '',
        'favicon' => '',
        'company_name' => '',          // obchodní firma (s.r.o., OSVČ…) – Nastavení → Firma; na webu prvek Údaje firmy, pro vyhledávače schema.org
        'company_type' => 'LocalBusiness',
        'company_id' => '',
        'company_vat_id' => '',
        'company_register' => '',       // zápis v obchodním rejstříku (soud a spisová značka, Handelsregister…) – tiráž
        'company_representative' => '',       // kdo firmu zastupuje (jednatel, Geschäftsführer…) – tiráž
        'company_street' => '',
        'company_city' => '',
        'company_postcode' => '',
        'company_country' => 'CZ',
        'company_phone' => '',
        'company_email' => '',          // veřejný kontaktní e-mail firmy (prvek Údaje firmy, schema.org); e-mail webu se nezveřejňuje
        'company_hours' => '',         // otevírací doba po řádcích: „Po–Pá 8:00–17:00“ (Front\Firma::hodiny)
        'company_map' => '',
        'company_gps' => '',
        'enquiries_months' => '24',    // poptávky z formulářů starší než tolik měsíců se mažou (osobní údaje nemají ležet věčně); 0 = nemazat
        'design_system' => '',        // barvy, písma, škála a rozměry webu (JSON, Stavitel\DesignSystem); prázdné = výchozí
        'brand_accent' => '',         // starší: hlavní barva webu, čte se jen dokud není uložen design_system
        'dark_mode' => 'vypnuto',   // tmavý vzhled webu: vypnuto | auto (podle zařízení návštěvníka) | tmavy (vždy tmavý)
        'theme_switcher' => '0',      // přepínač světlý / tmavý / podle zařízení pro návštěvníky (v záhlaví vedle jazyků)
        'brand_heading_font' => 'vychozi', // klíč z Front\Identita::PISMA_TITULKU
        'brand_text_font' => 'vychozi',            // obrázek místo textového názvu v záhlaví
        'footer_text' => '',
        'social_facebook' => '',
        'social_instagram' => '',
        'social_x' => '',
        'social_youtube' => '',
        'social_linkedin' => '',
        'time_zone' => 'Europe/Prague', // časové pásmo webu: data novinek, plánované vydání, statistiky (App::casovePasmo)
        'site_language' => 'cs',         // jazyk webu: texty šablon, <html lang>, strukturovaná data (Core\Jazyk)
        'additional_languages' => '',         // další jazykové verze na /en/, /de/… (rozšíření Jazykové verze), kódy oddělené čárkou
        'layout' => 'zakladni',       // = Front\Layouty::VYCHOZI
        'home_page' => '0',     // stránka (ka_stranky.ids) jako úvod webu; 0 = výpis novinek
        'news_per_page' => '9',        // novinek na jednu stránku výpisu
        'maintenance' => '0',              // režim údržby: návštěvníci vidí oznámení, přihlášení správci web
        'maintenance_text' => 'Na webu právě pracujeme. Zkuste to prosím za chvíli.',
        'webhook_url' => '',          // kam poslat údaje o právě vydané novince (Make, Zapier...)
        'webhook_enquiries' => '',
        'require_2fa' => '',          // '' | spravci | vsichni – povinné dvoufázové přihlášení     // kam poslat novou poptávku z formuláře (CRM, Make, Zapier, n8n…)
        'page_cache' => '1',       // cache celých stránek pro nepřihlášené návštěvníky (5 minut)
        'link_check' => '1',     // na pozadí hledat v novinkách nefunkční odkazy
        'link_check_time' => '0',
        'share_buttons' => '1',             // odkazy pro sdílení pod novinkou
        'article_outline' => '1',       // obsah novinky z mezititulků (od tří H2)
        'related_news_auto' => '1',    // související novinky podle štítků a kategorie
        'tasks_token' => '',          // tajná část adresy /ulohy pro cron
        'stats' => '1',          // vlastní měření návštěvnosti bez cookies
        'secret_key' => '',           // vznikne sám; podepisuje odkazy a solí otisky statistiky
        // SEO a GEO
        'indexing' => '1',          // 0 = celý web noindex + Disallow v robots.txt
        'schema_org' => '1',          // strukturovaná data JSON-LD
        'share_image' => '',           // výchozí obrázek pro sdílení
        'verification_google' => '',
        'verification_bing' => '',
        'robots_extra' => '',
        'ai_crawlers' => 'povolit',   // povolit | zakazat (GPTBot, ClaudeBot, PerplexityBot...)
        'llms_txt' => '1',
        'markdown_news' => '1',     // /novinky/<adresa>.md
        'indexnow' => '0',            // po vydání novinky oznámit adresu vyhledávačům (Bing, Seznam, Yandex)
        'indexnow_key' => '',
        // měření
        'ga4_id' => '',
        'matomo_url' => '',
        'matomo_id' => '0',
        'plausible_domain' => '',
        'head_code' => '',
        // soukromí a cookies
        'cookies_mode' => 'vestavena', // zadna | vestavena | externi
        'cookies_external_code' => '',
        'cookies_text' => 'Používáme cookies k měření návštěvnosti. Pomáhají nám zlepšovat web.',
        'cookies_policy_url' => '',
        'marketing_code' => '',
        'cookies_log' => '1',    // zapisovat udělené souhlasy (doklad pro případnou kontrolu)
        'cookies_log_months' => '36', // záznamy o souhlasech starší než tolik měsíců se mažou; 0 = nemazat
        'health_token' => '',
        'remote_backup' => 'vypnuto', // kopie zálohy mimo server: vypnuto | ftp | s3
        'backup_host' => '',          // FTP server, nebo adresa úložiště S3 (s3.eu-central-1.amazonaws.com)
        'backup_user' => '',      // jméno FTP / přístupový klíč S3
        'backup_password' => '',         // heslo FTP / tajný klíč S3 (typ "tajne")
        'backup_folder' => '',        // složka na FTP / název bucketu
        'backup_region' => '',        // region S3 (eu-central-1…)
        'remote_backup_status' => '', // "RRRR-MM-DD HH:MM|ok" nebo text chyby
        'auto_backups' => '1',         // týdenní automatická záloha databáze
        'update_url' => '',      // adresa souboru aktualizace.json; prázdné = výchozí zdroj projektu
        'update_cache' => '',
        'auto_updates' => '1',    // bezpečnostní vydání instalovat automaticky
        'update_attempt' => '',    // verze, kterou už údržba na pozadí zkoušela / oznámila
        'extensions' => '',            // zapnutá rozšíření (Core\Rozsireni); prázdné = výchozí sada
        'mail_mode' => 'mail',      // mail = funkce mail() serveru | smtp = vlastní SMTP server
        'mail_from' => '',             // adresa odesílatele; prázdné = e-mail webu
        'mail_reply_to' => '',        // adresa pro odpovědi (Reply-To)
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',    // tls (STARTTLS, port 587) | ssl (port 465) | zadne
        'smtp_user' => '',
        'smtp_password' => '',           // typ "tajne": nikdy se nevypisuje zpět do formuláře
        'notification_check' => '0',   // kdy naposledy proběhla kontrola nově vydaných novinek
        'data_cleanup' => '0',         // kdy naposledy proběhl denní úklid osobních údajů (Core\Oznameni)
        'ai_provider' => 'anthropic', // anthropic | openai | google | mistral (Core\Asistent::POSKYTOVATELE)
        'ai_key' => '',              // klíč API AI asistenta (nikdy se nevypisuje zpět do formuláře)
        'ai_model' => 'claude-sonnet-5',
        'first_steps_hidden' => '0',      // administrátor skryl první kroky na přehledu
        'appearance_saved' => '',        // správce už uložil Vzhled webu (první kroky nepočítají vzhled ze startovacího webu)
        'cleaned_version' => '',       // verze, po jejímž nasazení už proběhl jednorázový úklid zrušených souborů
        'db_version' => '1',            // číslo poslední provedené migrace (system/sql/migrace)
    ];

    /**
     * Keys of 1.4.0 and older => current keys. Migration 0026 renames the stored rows; get() and set() still accept an old key
     * (custom layouts, and the previous release that runs the update and may write its own keys once more), and an old row
     * that is still there stands in for a missing current one. The old keys stay until 2.0.
     *
     * @var array<string, string>
     */
    public const array LEGACY_KEYS = [
        'nazev_webu' => 'site_name',
        'adresa_webu' => 'site_url',
        'popis_webu' => 'site_description',
        'klicova_slova' => 'keywords',
        'email_webu' => 'site_email',
        'logo_webu' => 'logo',
        'firma_nazev' => 'company_name',
        'firma_typ' => 'company_type',
        'firma_ico' => 'company_id',
        'firma_dic' => 'company_vat_id',
        'firma_rejstrik' => 'company_register',
        'firma_zastupce' => 'company_representative',
        'firma_ulice' => 'company_street',
        'firma_mesto' => 'company_city',
        'firma_psc' => 'company_postcode',
        'firma_zeme' => 'company_country',
        'firma_telefon' => 'company_phone',
        'firma_email' => 'company_email',
        'firma_hodiny' => 'company_hours',
        'firma_mapa' => 'company_map',
        'firma_gps' => 'company_gps',
        'poptavky_mesice' => 'enquiries_months',
        'brand_akcent' => 'brand_accent',
        'tmavy_rezim' => 'dark_mode',
        'tmavy_prepinac' => 'theme_switcher',
        'brand_pismo_titulky' => 'brand_heading_font',
        'brand_pismo_text' => 'brand_text_font',
        'text_paticky' => 'footer_text',
        'soc_facebook' => 'social_facebook',
        'soc_instagram' => 'social_instagram',
        'soc_x' => 'social_x',
        'soc_youtube' => 'social_youtube',
        'soc_linkedin' => 'social_linkedin',
        'casove_pasmo' => 'time_zone',
        'jazyk_webu' => 'site_language',
        'jazyky_dalsi' => 'additional_languages',
        'titulni_stranka' => 'home_page',
        'pocet_clanku' => 'news_per_page',
        'udrzba' => 'maintenance',
        'udrzba_text' => 'maintenance_text',
        'webhook_poptavky' => 'webhook_enquiries',
        'vynutit_2fa' => 'require_2fa',
        'cache_stranek' => 'page_cache',
        'kontrola_odkazu' => 'link_check',
        'kontrola_odkazu_cas' => 'link_check_time',
        'sdileni' => 'share_buttons',
        'osnova_clanku' => 'article_outline',
        'souvisejici_auto' => 'related_news_auto',
        'ulohy_token' => 'tasks_token',
        'statistika' => 'stats',
        'tajny_klic' => 'secret_key',
        'indexovani' => 'indexing',
        'og_obrazek' => 'share_image',
        'overeni_google' => 'verification_google',
        'overeni_bing' => 'verification_bing',
        'ai_crawlery' => 'ai_crawlers',
        'markdown_clanky' => 'markdown_news',
        'indexnow_klic' => 'indexnow_key',
        'plausible_domena' => 'plausible_domain',
        'kod_hlava' => 'head_code',
        'cookies_rezim' => 'cookies_mode',
        'cookies_externi_kod' => 'cookies_external_code',
        'cookies_zasady_url' => 'cookies_policy_url',
        'kod_marketing' => 'marketing_code',
        'cookies_evidence' => 'cookies_log',
        'cookies_evidence_mesice' => 'cookies_log_months',
        'stav_token' => 'health_token',
        'zaloha_vzdalena' => 'remote_backup',
        'zaloha_host' => 'backup_host',
        'zaloha_uzivatel' => 'backup_user',
        'zaloha_heslo' => 'backup_password',
        'zaloha_slozka' => 'backup_folder',
        'zaloha_region' => 'backup_region',
        'zaloha_vzdalena_stav' => 'remote_backup_status',
        'zalohy_auto' => 'auto_backups',
        'aktualizace_url' => 'update_url',
        'aktualizace_cache' => 'update_cache',
        'aktualizace_auto' => 'auto_updates',
        'aktualizace_pokus' => 'update_attempt',
        'rozsireni' => 'extensions',
        'posta_rezim' => 'mail_mode',
        'posta_od' => 'mail_from',
        'posta_odpoved' => 'mail_reply_to',
        'smtp_sifrovani' => 'smtp_encryption',
        'smtp_uzivatel' => 'smtp_user',
        'smtp_heslo' => 'smtp_password',
        'oznameni_kontrola' => 'notification_check',
        'uklid_udaju' => 'data_cleanup',
        'ai_poskytovatel' => 'ai_provider',
        'ai_klic' => 'ai_key',
        'pruvodce_skryt' => 'first_steps_hidden',
        'vzhled_ulozen' => 'appearance_saved',
        'uklizeno_verze' => 'cleaned_version',
        'verze_db' => 'db_version',
        'newsletter_klic' => 'newsletter_key',
        'newsletter_seznam' => 'newsletter_list',
        'newsletter_sluzba' => 'newsletter_service',
    ];

    /** Nastavení, která jdou vyplnit zvlášť pro každou další jazykovou verzi webu (klíč_en, klíč_de…). */
    public const array PER_LANGUAGE = ['site_name', 'site_description'];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** Databáze, ze které nastavení pochází (potřebuje ji fronta pošty). */
    public function db(): Db
    {
        return $this->db;
    }

    /** Výchozí hodnoty, které jsou text pro návštěvníky – překládají se do jazyka webu, dokud je správce nezmění. */
    private const array TRANSLATED_DEFAULTS = ['maintenance_text', 'cookies_text'];

    public function get(string $key): string
    {
        $key = self::LEGACY_KEYS[$key] ?? $key;
        $this->values ??= self::withCurrentKeys($this->db->pairs('SELECT promenna, hodnota FROM {nastaveni}'));
        // název a popis webu může mít jazyková verze (/en/, /de/…) vlastní; prázdné = jako ve výchozím jazyce
        if (in_array($key, self::PER_LANGUAGE, true) && ($language = Language::siteColumn()) !== '' && ($this->values[$key . '_' . $language] ?? '') !== '') {
            return $this->values[$key . '_' . $language];
        }

        if (isset($this->values[$key])) {
            return $this->values[$key];
        }

        // výchozí texty, které vidí návštěvník, v jazyce webu (anglický web nesmí ukázat českou údržbu ani cookie lištu)
        return in_array($key, self::TRANSLATED_DEFAULTS, true) ? t(self::DEFAULTS[$key]) : (self::DEFAULTS[$key] ?? '');
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === '1';
    }

    public function set(string $key, string $value): void
    {
        $key = self::LEGACY_KEYS[$key] ?? $key;
        $this->db->run(
            'INSERT INTO {nastaveni} (promenna, hodnota) VALUES (?, ?) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)',
            [$key, $value],
        );
        if ($this->values !== null) {
            $this->values[$key] = $value;
        }
    }

    /**
     * Moves rows still stored under an old key to the current key and deletes them. Such a row is newer than the current
     * one: the release that runs an update writes its own keys again after migration 0026 renamed them (the migration
     * number above all). Called before migrations run.
     */
    public static function adoptLegacyRows(Db $db): void
    {
        $keys = array_keys(self::LEGACY_KEYS);
        $rows = $db->pairs('SELECT promenna, hodnota FROM {nastaveni} WHERE promenna IN (' . implode(',', array_fill(0, count($keys), '?')) . ") OR promenna LIKE 'nazev\\_webu\\_%' OR promenna LIKE 'popis\\_webu\\_%'", $keys);
        foreach ($rows as $old => $value) {
            $current = self::LEGACY_KEYS[$old] ?? (preg_match('/^(nazev_webu|popis_webu)_([a-z]{2})$/', $old, $m) ? self::LEGACY_KEYS[$m[1]] . '_' . $m[2] : null);
            if ($current === null) {
                continue;
            }
            if ($current === 'db_version') {
                $value = (string) max((int) $value, (int) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'db_version'"));
            }
            $db->run('INSERT INTO {nastaveni} (promenna, hodnota) VALUES (?, ?) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)', [$current, $value]);
            $db->run('DELETE FROM {nastaveni} WHERE promenna = ?', [$old]);
        }
    }

    /**
     * Rows under an old key (LEGACY_KEYS, also with a language suffix: nazev_webu_en) count under the current key when
     * that one is missing.
     *
     * @param array<string, string> $rows
     * @return array<string, string>
     */
    private static function withCurrentKeys(array $rows): array
    {
        foreach ($rows as $key => $value) {
            $current = self::LEGACY_KEYS[$key] ?? null;
            if ($current === null && preg_match('/^(.+)_([a-z]{2})$/', $key, $m) && isset(self::LEGACY_KEYS[$m[1]])) {
                $current = self::LEGACY_KEYS[$m[1]] . '_' . $m[2];
            }
            if ($current !== null && !array_key_exists($current, $rows)) {
                $rows[$current] = $value;
            }
        }

        return $rows;
    }
}
