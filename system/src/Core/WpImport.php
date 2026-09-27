<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Redirects;
use Kaleta\Admin\Modules\Pages;

/**
 * Import z WordPressu: stránky, příspěvky (→ novinky), kategorie, štítky, přesměrování ze starých adres a (zvlášť) obrázky.
 * Komentáře se nepřenášejí – firemní web je nemá.
 *
 * Jak to drží pohromadě:
 *  - Soubor se čte proudem (Core\WpSoubor) a pracuje se PO DÁVKÁCH – nejvýš DAVKA příspěvků nebo SEKUND vteřin na jeden požadavek,
 *    aby import přežil časové limity sdíleného hostingu. Kde se skončilo (kolikátý <item>), drží stavový soubor
 *    storage/import/stav-<otisk>.json; další požadavek naváže.
 *  - Tabulka ka_import_mapa si pamatuje, který cizí záznam se stal kterým naším. Stejný soubor jde proto pustit znovu bez duplicit
 *    (už převedená novinka se přeskočí a pozdější úpravy se nepřepíší) a obrázky se nestahují dvakrát.
 *  - Průchody jsou tři: náhled (jen počítá, do databáze nesahá), import obsahu a – až na výslovné přání – stažení obrázků.
 *  - Účty se nezakládají: novinka patří tomu, kdo importuje.
 *  - Importované novinky jsou rovnou „oznámené“ – stovky starých textů nesmí spustit webhook ani IndexNow.
 */
final class WpImport
{
    public const int BATCH = 100;
    public const int IMAGE_BATCH = 10;
    public const int SECONDS = 8;

    /** Výchozí volby importu (krok Náhled). */
    public const array DEFAULT_OPTIONS = ['jazyk' => '', 'koncepty' => true, 'stranky' => true, 'stavitel' => true, 'presmerovani' => true, 'rubrika' => 0];

    /** Typy příspěvků, které umíme; ostatní (menu, vlastní typy doplňků…) náhled jen vyjmenuje. */
    private const array TYPES = ['post', 'page', 'attachment'];

    private string $source = 'wp';

    /** @var array{nazev:string, adresa:string, autori:array<string,string>, rubriky:array<string,array{nazev:string, predek:string}>, stitky:array<string,string>} */
    private array $header = ['nazev' => '', 'adresa' => '', 'autori' => [], 'rubriky' => [], 'stitky' => []];

    /** @var array<string, int> rubriky převedené v tomto požadavku (adresa rubriky ve WordPressu => naše idt) */
    private array $categories = [];

    private int $downloadsLeft = 0;
    private float $end = 0.0;

    /**
     * @param string $base složka webu pro adresy obrázků v textu (Request::basePath(), u webu v kořeni prázdné)
     * @param int $author účet, kterému budou importované články patřit
     */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly string $base, private readonly int $author)
    {
    }

    /* ---------- stavový soubor ---------- */

    /** @return array<string, mixed> */
    public static function newState(string $file): array
    {
        return [
            'soubor' => $file, 'faze' => 'analyza', 'pozice' => 0, 'celkem' => 0, 'web' => ['nazev' => '', 'adresa' => ''],
            'prehled' => ['clanky' => [], 'stranky' => [], 'rubriky' => 0, 'stitky' => 0, 'autori' => 0, 'prilohy' => 0, 'obrazky' => 0, 'jine' => [], 'zkratky' => []],
            'prilohy' => [], 'volby' => self::DEFAULT_OPTIONS, 'nahledy' => [],
            'vysledek' => ['clanky' => 0, 'stranky' => 0, 'rubriky' => 0, 'presmerovani' => 0, 'preskoceno' => 0],
            'obr' => ['typ' => 'clanek', 'id' => 0, 'hotovo' => 0, 'celkem' => 0, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function loadState(string $file): ?array
    {
        $path = self::stateFile($file);
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($state) && ($state['soubor'] ?? '') === $file ? array_replace_recursive(self::newState($file), $state) : null;
    }

    /** @param array<string, mixed> $state */
    public static function saveState(array $state): void
    {
        $path = self::stateFile((string) $state['soubor']);
        // nejdřív vedle, pak přejmenovat: přerušený zápis nesmí nechat poloviční soubor
        file_put_contents($path . '.tmp', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($path . '.tmp', $path);
    }

    public static function deleteState(string $file): void
    {
        @unlink(self::stateFile($file));
    }

    private static function stateFile(string $file): string
    {
        return WpFile::folder() . '/stav-' . substr(sha1($file), 0, 16) . '.json';
    }

    /* ---------- 1. průchod: náhled (nic nezapisuje do databáze) ---------- */

    /**
     * Projde další kus souboru a přičte ho do přehledu. Až dojde na konec, přepne fázi na "nahled".
     *
     * @param array<string, mixed> $state
     */
    public static function analyze(array &$state, float $seconds = self::SECONDS, ?string $path = null): void
    {
        $wp = new WpFile($path ?? (string) WpFile::path((string) $state['soubor'])); // $cesta jen pro testy; jinak vždy soubor ze storage/import
        if ($state['pozice'] === 0) {
            $h = $wp->header();
            $state['web'] = ['nazev' => $h['nazev'], 'adresa' => $h['adresa']];
            $state['prehled']['rubriky'] = count($h['rubriky']);
            $state['prehled']['stitky'] = count($h['stitky']);
            $state['prehled']['autori'] = count($h['autori']);
        }
        $end = microtime(true) + $seconds;
        foreach ($wp->items((int) $state['pozice']) as $order => $p) {
            self::tally($state, $p);
            $state['pozice'] = $order + 1;
            if (microtime(true) > $end) {
                return;
            }
        }
        $state['celkem'] = $state['pozice'];
        $state['pozice'] = 0;
        $state['faze'] = 'nahled';
        arsort($state['prehled']['zkratky']);
        $state['prehled']['zkratky'] = array_slice($state['prehled']['zkratky'], 0, 15, true);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $p příspěvek z WpFile::item()
     */
    private static function tally(array &$state, array $p): void
    {
        $overview = &$state['prehled'];
        if ($p['typ'] === 'attachment') {
            $overview['prilohy']++;
            if ($p['priloha_url'] !== '') {
                $state['prilohy'][(int) $p['id']] = $p['priloha_url']; // pro [gallery ids] a hlavní obrázky článků
            }
        } elseif ($p['typ'] === 'post' || $p['typ'] === 'page') {
            $destination = $p['typ'] === 'post' ? 'clanky' : 'stranky';
            $overview[$destination][$p['stav']] = ($overview[$destination][$p['stav']] ?? 0) + 1;
            $overview['obrazky'] += substr_count(strtolower($p['obsah']), '<img');
            foreach (WpContent::unknownShortcodes($p['obsah']) as $shortcode) {
                $overview['zkratky'][$shortcode] = ($overview['zkratky'][$shortcode] ?? 0) + 1;
            }
        } elseif (!in_array($p['typ'], self::TYPES, true)) {
            $overview['jine'][$p['typ']] = ($overview['jine'][$p['typ']] ?? 0) + 1;
        }
    }

    /* ---------- čisté převody (hlídá je tools/unit-tests.php) ---------- */

    /**
     * Stav příspěvku ve WordPressu → naše novinka; null = neimportuje se (soukromé, koš, automatické koncepty, revize).
     * Naplánovaný příspěvek je u nás vydaná novinka s budoucím datem; „čeká na schválení“ je koncept.
     *
     * @return array{visible:int}|null
     */
    public static function articleStatus(string $wpStatus, bool $passwordProtected = false): ?array
    {
        $state = match ($wpStatus) {
            'publish', 'future' => ['visible' => 1],
            'draft', 'pending' => ['visible' => 0],
            default => null,
        };

        // příspěvek chráněný heslem u nás nemá obdobu – nesmí se tiše zveřejnit, zůstane jako koncept
        return $state !== null && $passwordProtected ? ['visible' => 0] : $state;
    }

    /**
     * Datum vydání: místní čas starého webu; koncepty ho mívají nulové, pak poslouží čas GMT, datum z RSS, nakonec dnešek.
     *
     * @param array<string, mixed> $p
     */
    public static function date(array $p, ?int $now = null): string
    {
        foreach ([$p['datum'] ?? '', $p['datum_gmt'] ?? '', $p['vydano'] ?? ''] as $i => $value) {
            $time = $value === '' || str_starts_with((string) $value, '0000') ? false : strtotime($value . ($i === 1 ? ' UTC' : ''));
            if ($time !== false && $time > 0) {
                return date('Y-m-d H:i:s', $time);
            }
        }

        return date('Y-m-d H:i:s', $now ?? time());
    }

    /**
     * Volná adresa (seo_link): když je základ obsazený, dostane pořadové číslo – stejně jako v administraci.
     *
     * @param callable(string): bool $isTaken
     */
    public static function availableSlug(string $base, callable $isTaken): string
    {
        return Slug::makeUnique($base, $isTaken, 120);
    }

    /** Cesta staré adresy pro přesměrování (bez domény a lomítek na krajích); prázdná = není co přesměrovat. */
    public static function oldPath(string $link): string
    {
        $path = trim(rawurldecode((string) parse_url($link, PHP_URL_PATH)), '/ ');

        return mb_strlen($path) > 255 || !mb_check_encoding($path, 'UTF-8') ? '' : $path;
    }

    /** Adresa obrázku bez rozměru náhledu a bez parametrů: foto-300x200.jpg?x=1 → foto.jpg (WordPress vkládá do textu zmenšeniny). */
    public static function withoutSize(string $url): string
    {
        $url = (string) preg_replace('/[?#].*$/', '', $url);

        return (string) preg_replace('#-\d{2,5}x\d{2,5}(?=\.(?:jpe?g|png|gif|webp)$)#i', '', $url);
    }

    /** Označení zdroje v ka_import_mapa: dva různé staré weby mají stejná čísla příspěvků, proto je v něm doména. */
    public static function source(string $siteUrl): string
    {
        $domain = ImageDownloader::domainFromUrl($siteUrl);

        return mb_substr($domain === '' ? 'wp' : 'wp:' . $domain, 0, 40);
    }

    /* ---------- 2. průchod: import obsahu ---------- */

    /**
     * Převede další dávku příspěvků. Každý příspěvek je jedna transakce: buď je v databázi celý (s komentáři a mapou), nebo vůbec.
     *
     * @param array<string, mixed> $state
     */
    public function import(array &$state, ?string $path = null, int $batch = self::BATCH): void
    {
        $wp = new WpFile($path ?? (string) WpFile::path((string) $state['soubor']));
        $this->header = $wp->header();
        $this->source = self::source((string) $state['web']['adresa']);
        $end = microtime(true) + self::SECONDS;
        $count = 0;
        foreach ($wp->items((int) $state['pozice']) as $order => $p) {
            $this->db->transaction(function () use ($p, &$state): void {
                match ($p['typ']) {
                    'post' => $this->article($p, $state),
                    'page' => $state['volby']['stranky'] ? $this->page($p, $state) : null,
                    default => null,
                };
            });
            $state['pozice'] = $order + 1;
            if ((++$count >= $batch || microtime(true) > $end) && $state['pozice'] < $state['celkem']) {
                return; // zbytek příště; počet příspěvků (celkem) zná import z náhledu
            }
        }
        $state['faze'] = 'hotovo';
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function article(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['stav'], $p['heslo'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['volby']['koncepty'])) {
            return;
        }
        $idc = $this->convertedId('clanek', (string) $p['id'], 'novinky', 'idc');
        if ($idc !== null) {
            $state['vysledek']['preskoceno']++; // už převedená novinka zůstává, jak je – mezitím ji mohl někdo upravit
        } else {
            $idc = $this->createArticle($p, $articleStatus, $state);
        }
        // hlavní obrázek si zatím jen poznamenáme – stahuje se až ve zvláštním kroku (i u dříve převedené novinky, která ho ještě nemá)
        $preview = (string) ($state['prilohy'][$p['nahled']] ?? '');
        if ($preview !== '' && (string) $this->db->value('SELECT obrazek FROM {novinky} WHERE idc = ?', [$idc]) === '') {
            $state['nahledy'][$idc] = $preview;
        }
    }

    /**
     * @param array<string, mixed> $p
     * @param array{visible:int} $articleStatus
     * @param array<string, mixed> $state
     */
    private function createArticle(array $p, array $articleStatus, array &$state): int
    {
        [$home, $text] = WpContent::introAndText($p['perex'], $p['obsah'], $state['prilohy']);
        $colorScheme = $p['rubriky'] === [] ? $this->defaultCategory($state) : $this->category((string) array_key_first($p['rubriky']), (string) reset($p['rubriky']), $state);
        $language = (string) $this->db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$colorScheme]); // novinka přebírá jazyk kategorie, jako při uložení v administraci
        $title = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(bez názvu)'), 0, 255);
        $now = date('Y-m-d H:i:s');

        $seo = self::availableSlug(
            slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $title, 150),
            fn (string $url): bool => $this->db->value('SELECT idc FROM {novinky} WHERE seo_link = ?', [$url]) !== null,
        );
        $idc = $this->db->insert('novinky', [
            'seo_link' => $seo, 'titulek' => $title, 'uvod' => $home, 'text' => $text, 'tema' => $colorScheme, 'jazyk' => $language,
            'autor' => $this->author,
            'datum' => self::date($p),
            'visible' => $articleStatus['visible'],
            'zmeneno' => $now,
            'oznameno' => $now, // stará novinka se neoznamuje (webhook, IndexNow)
        ]);
        foreach (array_slice($p['stitky'], 0, 20, true) as $url => $name) {
            $this->tag($idc, (string) $url, $name !== '' ? $name : (string) ($this->header['stitky'][$url] ?? $url));
        }
        Search::index($this->db, $idc);
        Media::recordUsage($this->db, $idc, '', $home, $text);
        $this->writeMap('clanek', (string) $p['id'], $idc);
        $state['vysledek']['clanky']++;

        if ($state['volby']['presmerovani']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . 'novinky/' . $seo);
        }

        return $idc;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function page(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['stav'], $p['heslo'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['volby']['koncepty'])) {
            return;
        }
        if ($this->convertedId('stranka', (string) $p['id'], 'stranky', 'ids') !== null) {
            $state['vysledek']['preskoceno']++;

            return;
        }
        $title = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(bez názvu)'), 0, 200);
        $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
        // stránka má adresu přímo pod kořenem webu, nesmí proto zabrat adresu, kterou používá systém
        $seo = self::availableSlug(
            slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $title, 110),
            fn (string $url): bool => in_array($url, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$url])
                || $this->db->value('SELECT ids FROM {stranky} WHERE seo_link = ?', [$url]) !== null,
        );
        $text = WpContent::sanitize($p['obsah'], $state['prilohy']);
        $ids = $this->db->insert('stranky', [
            'seo_link' => $seo, 'titulek' => $title, 'text' => $text,
            'stavba' => ($state['volby']['stavitel'] ?? false) ? $this->build($title, $text) : null,
            'popis' => mb_substr(trim(html_entity_decode(strip_tags($p['perex']), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300),
            'zobrazit' => $articleStatus['visible'],
            'v_menu' => 0, // desítky starých stránek by zaplavily navigaci; do nabídky si je správce zařadí sám
            'zmeneno' => date('Y-m-d H:i:s'), 'jazyk' => $language,
        ]);
        $this->writeMap('stranka', (string) $p['id'], $ids);
        $state['vysledek']['stranky']++;
        if ($state['volby']['presmerovani']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . $seo);
        }
    }

    /**
     * Kategorie podle adresy kategorie ve WordPressu (strom se zplošťuje); založí ji, když ještě není. Zakládají se jen kategorie s příspěvky.
     *
     * @param array<string, mixed> $state
     */
    private function category(string $url, string $name, array &$state): int
    {
        if (isset($this->categories[$url])) {
            return $this->categories[$url];
        }
        $idt = $this->convertedId('rubrika', $url, 'kategorie', 'idt');
        if ($idt === null) {
            $description = $this->header['rubriky'][$url] ?? ['nazev' => $name, 'predek' => ''];
            $name = mb_substr($description['nazev'] !== '' ? $description['nazev'] : ($name !== '' ? $name : $url), 0, 100);
            $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
            $seo = slugify(rawurldecode($url), 110);
            // stejná adresa, název i jazyk = tatáž kategorie, která na webu už je; jinak nová s volnou adresou
            $idt = $this->db->value('SELECT idt FROM {kategorie} WHERE seo_link = ? AND jazyk = ? AND LOWER(nazev) = LOWER(?)', [$seo, $language, $name]);
            if ($idt === null) {
                $idt = $this->db->insert('kategorie', [
                    'nazev' => $name, 'popis' => '', 'jazyk' => $language,
                    'seo_link' => self::availableSlug($seo, fn (string $a): bool => $this->db->value('SELECT idt FROM {kategorie} WHERE seo_link = ?', [$a]) !== null),
                ]);
                $state['vysledek']['rubriky']++;
            }
            $this->writeMap('rubrika', $url, (int) $idt);
        }

        return $this->categories[$url] = (int) $idt;
    }

    /**
     * Kategorie pro příspěvky bez kategorie: zvolená v náhledu, jinak se založí „Nezařazené“.
     *
     * @param array<string, mixed> $state
     */
    private function defaultCategory(array &$state): int
    {
        $idt = (int) $state['volby']['rubrika'];
        if ($idt > 0 && $this->db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$idt]) !== null) {
            return $idt;
        }

        return $state['volby']['rubrika'] = $this->category('nezarazene', t('Nezařazené'), $state);
    }

    /** Štítek se hledá podle adresy z názvu a neznámý se založí – stejně jako při uložení novinky v administraci. */
    private function tag(int $idc, string $wpSlug, string $name): void
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            return;
        }
        $seo = slugify($name, 90);
        $ids = $this->db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]);
        $ids = $ids !== null ? (int) $ids : $this->db->insert('stitky', ['nazev' => $name, 'seo_link' => $seo]);
        $this->db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) VALUES (?, ?)', [$idc, $ids]);
        $this->writeMap('stitek', $wpSlug, $ids);
    }

    /**
     * Přesměrování ze staré adresy (hezké i číselné /?p=123) na novou. Totožnou adresu Presmerovani::pridej přeskočí samo.
     *
     * @param array<string, mixed> $p
     */
    private function redirect(array $p, string $newVersion): int
    {
        $count = 0;
        foreach (array_unique([self::oldPath($p['odkaz']), $p['id'] > 0 ? '?p=' . (int) $p['id'] : '']) as $old) {
            if ($old !== '' && $old !== $newVersion) {
                Redirects::add($this->db, $old, $newVersion);
                $count++;
            }
        }

        return $count;
    }

    /* ---------- 3. průchod: obrázky ze starého webu ---------- */

    /**
     * Začátek (nebo opakování) stahování obrázků: počitadla od nuly; co se minule nepovedlo stáhnout, dostane druhou šanci.
     *
     * @param array<string, mixed> $state
     */
    public function startImages(array &$state): void
    {
        $this->source = self::source((string) $state['web']['adresa']);
        $this->db->run("DELETE FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND nase_id = 0", [$this->source]);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {import_mapa} WHERE zdroj = ? AND typ IN ('clanek', 'stranka')", [$this->source]);
        $state['obr'] = ['typ' => 'clanek', 'id' => 0, 'hotovo' => 0, 'celkem' => $total, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []];
        $state['faze'] = 'obrazky';
    }

    /**
     * Stáhne další dávku obrázků: hlavní obrázek článku a obrázky v textu, které leží na doméně starého webu.
     * Pracuje nad už převedenými záznamy (ne nad souborem); pozice = poslední hotový článek nebo stránka.
     *
     * @param array<string, mixed> $state
     */
    public function images(array &$state, ImageDownloader $downloader): void
    {
        $this->source = self::source((string) $state['web']['adresa']);
        $this->downloadsLeft = self::IMAGE_BATCH;
        $this->end = microtime(true) + self::SECONDS;
        while (true) {
            $id = $this->db->value('SELECT MIN(nase_id) FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND nase_id > ?', [$this->source, $state['obr']['typ'], (int) $state['obr']['id']]);
            if ($id === null && $state['obr']['typ'] === 'clanek') {
                $state['obr'] = ['typ' => 'stranka', 'id' => 0] + $state['obr']; // po článcích stránky
                continue;
            }
            if ($id === null) {
                $state['faze'] = 'obrazky-hotovo';

                return;
            }
            if (!$this->recordImages((string) $state['obr']['typ'], (int) $id, $state, $downloader)) {
                return; // dávka je vyčerpaná uprostřed záznamu – příště se pokračuje tímtéž
            }
            $state['obr']['id'] = (int) $id;
            $state['obr']['hotovo']++;
            if ($this->downloadsLeft <= 0 || microtime(true) > $this->end) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return bool false = došel rozpočet dávky, záznam ještě není celý
     */
    private function recordImages(string $type, int $id, array &$state, ImageDownloader $downloader): bool
    {
        $record = $type === 'clanek'
            ? $this->db->one('SELECT idc, titulek, uvod, text, obrazek FROM {novinky} WHERE idc = ?', [$id])
            : $this->db->one("SELECT ids, titulek, '' AS uvod, text, '' AS obrazek FROM {stranky} WHERE ids = ?", [$id]);
        if ($record === null) {
            return true; // záznam mezitím někdo smazal
        }
        $complete = true;
        $newItems = ['uvod' => (string) $record['uvod'], 'text' => (string) $record['text'], 'obrazek' => (string) $record['obrazek']];
        foreach (['uvod', 'text'] as $field) {
            $newItems[$field] = (string) preg_replace_callback('#<img\b[^>]*>#i', function (array $m) use ($downloader, &$state, &$complete, $record): string {
                $src = preg_match('#\bsrc="([^"]+)"#i', $m[0], $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5) : '';
                if (!$complete || !$downloader->isAllowedUrl($src)) {
                    return $m[0]; // cizí obrázky (jiná doména) zůstávají, jak jsou – nestahují se nikdy
                }
                $alt = preg_match('#\balt="([^"]*)"#i', $m[0], $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5) : '';
                $image = $this->image($src, $alt !== '' ? $alt : (string) $record['titulek'], $state, $downloader);
                if ($image === false) {
                    $complete = false;
                }

                return is_array($image)
                    ? '<img src="' . e($this->base . '/' . $image['obr_poloha']) . '" alt="' . e($alt) . '" width="' . (int) $image['obr_width'] . '" height="' . (int) $image['obr_height'] . '" loading="lazy" data-id="' . (int) $image['ido'] . '">'
                    : $m[0];
            }, $newItems[$field]);
        }
        $preview = (string) ($state['nahledy'][$id] ?? '');
        if ($type === 'clanek' && $complete && $preview !== '') {
            $image = $this->image($preview, (string) $record['titulek'], $state, $downloader);
            $complete = $image !== false;
            if (is_array($image) && $newItems['obrazek'] === '') {
                $newItems['obrazek'] = (string) $image['obr_poloha'];
            }
            if ($complete) {
                unset($state['nahledy'][$id]);
            }
        }
        if ($type === 'clanek' && $newItems !== ['uvod' => $record['uvod'], 'text' => $record['text'], 'obrazek' => $record['obrazek']]) {
            $this->db->update('novinky', $newItems, ['idc' => $id]);
            Media::recordUsage($this->db, $id, $newItems['obrazek'], $newItems['uvod'], $newItems['text']);
        } elseif ($type === 'stranka' && $newItems['text'] !== $record['text']) {
            // obrázky jsou už v Médiích: stavba importované stránky se převede znovu, aby odkazovala na ně
            $build = $this->db->value('SELECT stavba FROM {stranky} WHERE ids = ?', [$id]) !== null && $this->db->value('SELECT stavba_koncept FROM {stranky} WHERE ids = ?', [$id]) === null
                ? ['stavba' => $this->build((string) $record['titulek'], $newItems['text'])] : [];
            $this->db->update('stranky', ['text' => $newItems['text']] + $build, ['ids' => $id]);
        }

        return $complete;
    }

    /**
     * Stránka z WordPressu jako stavba (Stavitel\ZHtml): nadpis a obsah v úzké sekci, bloky Gutenbergu jako prvky, třídy
     * WordPressu bez stylu pryč. Vlastní HTML (vložené mapy, iframe) smí vzniknout – import spouští správce.
     */
    private function build(string $title, string $html): ?string
    {
        $conversion = \Kaleta\Builder\HtmlConverter::convert('<h1>' . e($title) . '</h1>' . $html, true);
        $build = \Kaleta\Builder\HtmlConverter::withoutClasses($conversion['stavba'], array_column($this->db->all('SELECT nazev FROM {tridy}'), 'nazev'));
        foreach ($build['deti'] as &$section) {
            if ($section['typ'] === 'sekce' && !isset($section['kotva'])) {
                $section['obsah']['sirka'] = 'uzka'; // text stránky se čte lépe v užším sloupci
            }
        }
        unset($section);
        [$clean] = \Kaleta\Builder\Build::sanitize($build, true);

        return $clean['deti'] === [] ? null : \Kaleta\Builder\Build::toJson($clean);
    }

    /**
     * Jeden obrázek: z mapy (už stažený), nebo ze starého webu přes Core\Obrazky do Médií.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null|false řádek ka_media; null = nejde stáhnout; false = dávka je vyčerpaná
     */
    private function image(string $url, string $name, array &$state, ImageDownloader $downloader): array|null|false
    {
        $original = self::withoutSize($url);
        $key = sha1($original);
        $ido = $this->db->value("SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND cizi_id = ?", [$this->source, $key]);
        $row = $ido === null ? null : $this->db->one('SELECT * FROM {media} WHERE ido = ?', [(int) $ido]);
        if ($row !== null || ($ido !== null && (int) $ido === 0)) {
            return $row; // hotovo dřív, nebo už jednou selhalo (null)
        }
        if ($this->downloadsLeft <= 0 || microtime(true) > $this->end) {
            return false;
        }
        $this->downloadsLeft--;
        $temporary = WpFile::folder() . '/obrazek-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            try {
                $data = $downloader->download($original);
            } catch (\RuntimeException $e) {
                if ($original === $url) {
                    throw $e;
                }
                $data = $downloader->download((string) preg_replace('/[?#].*$/', '', $url)); // originál chybí, zkusí se aspoň zmenšenina z textu
            }
            file_put_contents($temporary, $data);
            $saved = Images::saveFile($temporary, basename((string) parse_url($original, PHP_URL_PATH)));
            $saved['nazev'] = mb_substr($name !== '' ? $name : $saved['nazev'], 0, 150);
            $saved['ido'] = $this->db->insert('media', $saved + ['vlastnik' => $this->author, 'datum' => date('Y-m-d H:i:s')]);
            $this->writeMap('obrazek', $key, (int) $saved['ido']);
            $state['obr']['stazeno']++;

            return $saved;
        } catch (\RuntimeException $e) {
            $this->writeMap('obrazek', $key, 0); // nezkoušet znovu u každého článku, který obrázek používá
            $state['obr']['chyb']++;
            $state['obr']['chyby'] = array_slice(array_merge($state['obr']['chyby'], [mb_substr($original, 0, 200) . ' – ' . t($e->getMessage()) . ($e->getCode() > 0 ? ' ' . $e->getCode() : '')]), -10);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /* ---------- mapa cizích a našich záznamů ---------- */

    /** Číslo našeho záznamu, do kterého byl cizí už převeden – jen pokud pořád existuje (smazaný se importuje znovu). */
    private function convertedId(string $type, string $foreignId, string $table, string $key): ?int
    {
        $ourId = $this->db->value('SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND cizi_id = ?', [$this->source, $type, mb_substr($foreignId, 0, 190)]);
        if ($ourId === null || $this->db->value('SELECT ' . $key . ' FROM {' . $table . '} WHERE ' . $key . ' = ?', [(int) $ourId]) === null) {
            return null;
        }

        return (int) $ourId;
    }

    private function writeMap(string $type, string $foreignId, int $ourId): void
    {
        $this->db->run(
            'INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nase_id = VALUES(nase_id)',
            [$this->source, $type, mb_substr($foreignId, 0, 190), $ourId],
        );
    }
}
