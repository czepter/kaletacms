<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Export celého webu do jednoho archivu – aby obsah nikdy nezůstal v Kaletě zamčený.
 *
 * Archiv storage/zalohy/export-RRRRMMDD-HHMMSS.zip obsahuje obsah.json (stránky, kategorie, štítky, novinky, přesměrování,
 * knihovnu médií a veřejná nastavení), README.txt s popisem formátu a složku media/.
 *
 * Co v exportu NIKDY není: hesla, klíče API, tokeny, údaje SMTP a FTP, účty uživatelů administrace.
 * Nastavení se proto vybírají ze seznamu povolených (NASTAVENI), ne vylučováním.
 * Není to záloha pro obnovu Kalety (tou je záloha databáze), ale přenosný otevřený formát.
 */
final class SiteExport
{
    /** Horní mez velikosti médií v archivu; nad ni (nebo když nestačí místo na disku) vznikne export bez médií. */
    public const int MAX_MEDIA = 1024 * 1024 * 1024;
    private const int KEEP = 3;

    /** Jediná nastavení, která se exportují: název, popis, identita a jazyky webu. */
    private const array SETTINGS = ['site_name', 'site_description', 'keywords', 'site_url', 'logo', 'favicon', 'design_system', 'company_name', 'company_type', 'company_id', 'company_vat_id', 'company_register', 'company_representative', 'company_street', 'company_city', 'company_postcode', 'company_country', 'company_phone', 'company_hours', 'company_map', 'company_gps', 'brand_accent', 'dark_mode',
        'brand_heading_font', 'brand_text_font', 'footer_text', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin',
        'time_zone', 'site_language', 'additional_languages', 'layout', 'home_page'];

    /** Sloupce novinky, které jsou jen provozní (index hledání, kontrola odkazů…) a do exportu nepatří. */
    private const array EXCLUDED_ARTICLE_COLUMNS = ['hledani', 'odkazy_cas', 'oznameno', 'autor', 'autor_jmeno'];

    /**
     * @return array{soubor:string, media:bool, duvod:string} název vytvořeného souboru; media = false, když jsou v něm jen data (duvod říká proč)
     * @throws \RuntimeException
     */
    public static function create(Db $db, Settings $settings): array
    {
        if (!is_dir(Backup::FOLDER) && !mkdir(Backup::FOLDER, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku storage/zalohy - zkontrolujte práva k zápisu.');
        }
        @set_time_limit(300);
        $base = Backup::FOLDER . '/export-' . date('Ymd-His');
        $json = $base . '.json';
        self::writeContent($db, $settings, $json);
        if (!class_exists(\ZipArchive::class)) {
            self::cleanUp();

            return ['soubor' => basename($json), 'media' => false, 'duvod' => 'Na serveru chybí rozšíření PHP zip, export proto obsahuje jen data (JSON). Složku media/ si stáhněte přes FTP.'];
        }

        $files = self::media();
        $size = array_sum($files);
        $freeSpace = @disk_free_space(Backup::FOLDER);
        $reason = match (true) {
            $size > self::MAX_MEDIA => 'Média mají přes 1 GB, export proto obsahuje jen data (JSON). Složku media/ si stáhněte přes FTP.',
            $freeSpace !== false && $size * 1.1 + (int) filesize($json) > $freeSpace => 'Na disku není dost místa pro archiv s médii, export proto obsahuje jen data (JSON). Složku media/ si stáhněte přes FTP.',
            default => '',
        };
        $zip = new \ZipArchive();
        if ($zip->open($base . '.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Archiv se nepodařilo vytvořit - zkontrolujte práva k zápisu do storage/zalohy.');
        }
        $zip->addFile($json, 'obsah.json');
        $zip->addFromString('README.txt', self::readme($reason === ''));
        if ($reason === '') {
            // soubor po souboru; obrázky už komprimované jsou, proto se jen ukládají (CM_STORE) – archiv je pak hotový rychle
            foreach (array_keys($files) as $path) {
                $zip->addFile(KALETA_ROOT . '/' . $path, $path);
                $zip->setCompressionName($path, \ZipArchive::CM_STORE);
            }
        }
        if (!$zip->close()) {
            @unlink($base . '.zip');
            throw new \RuntimeException('Archiv se nepodařilo dokončit - nejspíš došlo místo na disku.');
        }
        unlink($json);
        self::cleanUp();

        return ['soubor' => basename($base) . '.zip', 'media' => $reason === '', 'duvod' => $reason];
    }

    /** @return list<array{soubor:string, velikost:int, cas:int}> nejnovější nahoře */
    public static function listAll(): array
    {
        $exports = [];
        foreach (glob(Backup::FOLDER . '/export-*') ?: [] as $path) {
            if (self::path(basename($path)) !== null) {
                $exports[] = ['soubor' => basename($path), 'velikost' => (int) filesize($path), 'cas' => (int) filemtime($path)];
            }
        }
        usort($exports, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $exports;
    }

    /** Cesta k existujícímu exportu podle názvu z adresy; null = neplatný název. */
    public static function path(string $file): ?string
    {
        return preg_match('/^export-\d{8}-\d{6}\.(zip|json)$/', $file) && is_file(Backup::FOLDER . '/' . $file) ? Backup::FOLDER . '/' . $file : null;
    }

    /* ---------- obsah.json ---------- */

    /** Zapisuje se rovnou do souboru a po řádcích z databáze – ani web s desítkami tisíc článků se tak nemusí vejít do paměti. */
    private static function writeContent(Db $db, Settings $settings, string $path): void
    {
        $f = fopen($path, 'wb');
        if ($f === false) {
            throw new \RuntimeException('Nelze zapisovat do storage/zalohy - zkontrolujte práva k zápisu.');
        }
        fwrite($f, '{"format":"kaleta-export","verze_formatu":1,"kaleta":' . self::json(KALETA_VERSION) . ',"vytvoreno":' . self::json(date('c')) . ',"nastaveni":' . self::json(self::settings($db)));

        $authors = "(SELECT NULLIF(u.jmeno, '') FROM {uzivatele} u WHERE u.idu = c.autor) AS autor_jmeno";
        self::fields($f, 'stranky', self::streamRows($db, 'SELECT * FROM {stranky} WHERE ids > ? AND smazano IS NULL ORDER BY ids LIMIT 200', 'ids')); // koš se nevyváží
        self::fields($f, 'kategorie', self::streamRows($db, 'SELECT idt, nazev, seo_link, popis, hodnost, jazyk, preklad_z FROM {kategorie} WHERE idt > ? ORDER BY idt LIMIT 500', 'idt'));
        self::fields($f, 'stitky', self::streamRows($db, 'SELECT ids, nazev, seo_link, popis, obrazek FROM {stitky} WHERE ids > ? ORDER BY ids LIMIT 500', 'ids'));
        self::fields($f, 'novinky', self::articles($db, $authors));
        self::fields($f, 'presmerovani', self::streamRows($db, 'SELECT idp, z_adresy, na_adresu FROM {presmerovani} WHERE idp > ? ORDER BY idp LIMIT 1000', 'idp'));
        // builder: sdílené třídy, části webu (záhlaví, patička, obálky) a kolekce; poptávky ne – jsou to osobní údaje návštěvníků
        self::fields($f, 'tridy', $db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev'));
        self::fields($f, 'casti', $db->all('SELECT typ, jazyk, varianta, nazev, stranky, stavba FROM {casti} WHERE stavba IS NOT NULL ORDER BY typ, jazyk, varianta'));
        // komponenty (prvky „komponenta“ na ně odkazují číslem) a vlastní sekce knihovny
        self::fields($f, 'komponenty', $db->all('SELECT idm, nazev, vlastnosti, stavba FROM {komponenty} ORDER BY idm'));
        self::fields($f, 'sekce', $db->all('SELECT idx, nazev, prvek FROM {sekce} ORDER BY idx'));
        self::fields($f, 'menu', $db->all('SELECT umisteni, jazyk, polozky FROM {menu} ORDER BY umisteni, jazyk'));
        self::fields($f, 'kolekce', $db->all('SELECT idk, nazev, seo_link, pole, detail, stavba FROM {kolekce} ORDER BY idk'));
        self::fields($f, 'kolekce_sablony', $db->all('SELECT idk, jazyk, stavba FROM {kolekce_sablony} WHERE stavba IS NOT NULL ORDER BY idk, jazyk'));
        // pop-up okna s pravidly a publikovanou stavbou; počitadla ne (jsou jen statistika tohoto webu)
        self::fields($f, 'popupy', $db->all('SELECT idpp, nazev, adresa, typ, spoustec, hodnota, pravidla, cetnost, dni, aktivni, poradi, stavba FROM {popupy} ORDER BY idpp'));
        self::fields($f, 'kolekce_polozky', self::streamRows($db, 'SELECT idp, idk, nazev, seo_link, data, poradi, zobrazit, jazyk, datum FROM {kolekce_polozky} WHERE idp > ? ORDER BY idp LIMIT 500', 'idp'));
        self::fields($f, 'media', self::streamRows($db, 'SELECT ido, nazev, popis, obr_poloha AS soubor, obr_width AS sirka, obr_height AS vyska, nahl_poloha AS nahled, datum FROM {media} WHERE ido > ? ORDER BY ido LIMIT 500', 'ido'));
        fwrite($f, "}\n");
        fclose($f);
    }

    /**
     * Novinky se všemi sloupci; autor jako jméno (účty se neexportují), k tomu štítky.
     * Překlady drží sloupec preklad_z = idc novinky ve výchozím jazyce, tedy číslo platné i uvnitř exportu.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function articles(Db $db, string $authors): \Generator
    {
        foreach (self::streamRows($db, "SELECT c.*, {$authors} FROM {novinky} c WHERE c.idc > ? AND c.smazano IS NULL ORDER BY c.idc LIMIT 100", 'idc') as $c) {
            $newsItem = array_diff_key($c, array_flip(self::EXCLUDED_ARTICLE_COLUMNS));
            $newsItem['autor'] = (string) ($c['autor_jmeno'] ?? '');
            $newsItem['stitky'] = array_map(intval(...), array_column($db->all('SELECT ids FROM {novinky_stitky} WHERE idc = ?', [$c['idc']]), 'ids'));
            yield $newsItem;
        }
    }

    /**
     * Čte tabulku po stránkách podle primárního klíče (dotaz má jediný otazník: "klíč > ?").
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function streamRows(Db $db, string $sql, string $key): \Generator
    {
        $from = 0;
        do {
            $rows = $db->all($sql, [$from]);
            foreach ($rows as $row) {
                $from = (int) $row[$key];
                yield $row;
            }
        } while ($rows !== []);
    }

    /**
     * @param resource $f
     * @param iterable<array<string, mixed>> $rows
     */
    private static function fields($f, string $name, iterable $rows): void
    {
        fwrite($f, ",\n" . self::json($name) . ':[');
        $first = true;
        foreach ($rows as $row) {
            fwrite($f, ($first ? "\n" : ",\n") . self::json($row));
            $first = false;
        }
        fwrite($f, "\n]");
    }

    /** @return array<string, string> */
    private static function settings(Db $db): array
    {
        $all = $db->pairs('SELECT promenna, hodnota FROM {nastaveni}');
        $selection = [];
        foreach ($all as $key => $value) {
            // název a popis webu mohou mít variantu pro další jazyk (nazev_webu_en)
            $base = (string) preg_replace('/_[a-z]{2}$/', '', (string) $key);
            if (in_array($key, self::SETTINGS, true) || (in_array($base, Settings::PER_LANGUAGE, true) && $base !== $key)) {
                $selection[(string) $key] = (string) $value;
            }
        }
        ksort($selection);

        return $selection;
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /* ---------- média a úklid ---------- */

    /** @return array<string, int> cesta od kořene webu => velikost; bez skrytých souborů a bez PHP */
    private static function media(): array
    {
        $files = [];
        if (!is_dir(KALETA_ROOT . '/media')) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = 'media/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(KALETA_ROOT . '/media'))), '/');
            if ($file->isFile() && !$file->isLink() && !preg_match('#(^|/)\.|\.php\d?$#i', $path)) {
                $files[$path] = (int) $file->getSize();
            }
        }
        ksort($files);

        return $files;
    }

    /** Exporty bývají velké: nechávají se jen poslední tři. */
    private static function cleanUp(): void
    {
        foreach (array_slice(self::listAll(), self::KEEP) as $old) {
            @unlink(Backup::FOLDER . '/' . $old['soubor']);
        }
    }

    private static function readme(bool $withMedia): string
    {
        return "Export webu z Kalety " . KALETA_VERSION . " (" . date('j. n. Y H:i') . ")\n"
            . "==========================================\n\n"
            . "obsah.json  všechen obsah webu v kódování UTF-8\n"
            . ($withMedia ? "media/      nahrané obrázky a přílohy; cesty odpovídají sloupcům \"obrazek\" a poli \"media\"\n" : "media/      v archivu NENÍ (příliš velká nebo málo místa) – stáhněte si složku media/ z webu přes FTP\n")
            . "\nStruktura obsah.json\n--------------------\n"
            . "format, verze_formatu, kaleta, vytvoreno – hlavička\n"
            . "nastaveni     název a popis webu, identita (logo, barva, písma, sítě), časové pásmo, jazyky, šablona, úvodní stránka\n"
            . "stranky       ids, seo_link, titulek, popis, text (HTML), jazyk ('' = výchozí jazyk webu), preklad_z\n"
            . "kategorie     idt, nazev, seo_link, popis, jazyk, preklad_z\n"
            . "stitky        ids, nazev, seo_link, popis\n"
            . "novinky       titulek, uvod a text (HTML), datum, visible (1 = vydaná), tema (= kategorie.idt), jazyk,\n"
            . "              preklad_z (= idc novinky ve výchozím jazyce, jejíž je tato překladem), autor (jméno), stitky (seznam stitky.ids) a další\n"
            . "presmerovani  z_adresy → na_adresu\n"
            . "media         knihovna médií: soubor (cesta ve složce media/), nazev (alternativní text), popis, rozměry\n"
            . "\nAdresy na webu: stránka /<seo_link>, novinka /novinky/<seo_link>, kategorie /novinky/kategorie/<seo_link>,\n"
            . "štítek /novinky/stitek/<seo_link>; další jazykové verze mají předponu /<jazyk>/.\n"
            . "\nCo v exportu záměrně není: hesla a účty uživatelů, klíče a tokeny, údaje k poště a zálohám, statistiky návštěvnosti.\n"
            . "Pro obnovu téhož webu použijte zálohu databáze (Nastavení → Zálohy).\n";
    }
}
