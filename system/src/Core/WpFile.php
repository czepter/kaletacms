<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Čtení exportu z WordPressu (soubor WXR: Nástroje → Export → Veškerý obsah). Nic nezapisuje, jen čte.
 *
 * Bezpečnost a velikost:
 *  - čte se proudem přes XMLReader, takže ani export o stovkách MB nezabere paměť – v paměti je vždy jediný příspěvek;
 *  - soubor s DOCTYPE se odmítá celý. Export z WordPressu ho nikdy nemá a bez DOCTYPE nejde definovat žádná entita
 *    (ani vnější soubor či adresa – útok XXE, ani „miliarda smíchů“). Navíc se čte s LIBXML_NONET a bez LIBXML_NOENT;
 *  - z komentářů se e-mail ani IP adresa vůbec nečtou, takže se nemohou dostat dál.
 */
final class WpFile
{
    public const string FOLDER = KALETA_ROOT . '/storage/import';

    /** Horní mez velikosti souboru: větší export je lepší rozdělit (WordPress to umí podle data nebo autora). */
    public const int MAX_BYTES = 1024 * 1024 * 1024;

    /** @param string $path úplná cesta k souboru na disku */
    public function __construct(private readonly string $path)
    {
    }

    /* ---------- složka storage/import ---------- */

    /** Složka pro exporty; vznikne sama i se zákazem přístupu z webu. */
    public static function folder(): string
    {
        if (!is_dir(self::FOLDER) && !@mkdir(self::FOLDER, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku storage/import – zkontrolujte práva k zápisu.');
        }
        if (!is_file(self::FOLDER . '/.htaccess')) {
            @file_put_contents(self::FOLDER . '/.htaccess', "Require all denied\n");
        }

        return self::FOLDER;
    }

    /**
     * Soubory *.xml, které ve složce leží (nahrané formulářem i přes FTP), nejnovější nahoře.
     *
     * @return list<array{soubor:string, velikost:int, cas:int}>
     */
    public static function listAll(): array
    {
        $files = [];
        foreach (glob(self::FOLDER . '/*.{xml,XML}', GLOB_BRACE) ?: [] as $path) {
            if (self::isValidName(basename($path))) {
                $files[] = ['soubor' => basename($path), 'velikost' => (int) filesize($path), 'cas' => (int) filemtime($path)];
            }
        }
        usort($files, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $files;
    }

    /** Název souboru z formuláře nesmí vést jinam než do složky importu. */
    public static function isValidName(string $file): bool
    {
        return $file !== '' && strlen($file) <= 150 && basename($file) === $file && !str_starts_with($file, '.')
            && !preg_match('#[/\\\\\x00-\x1f]#', $file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xml';
    }

    /** Cesta k existujícímu souboru podle názvu z formuláře; null = neplatný název nebo soubor není. */
    public static function path(string $file): ?string
    {
        return self::isValidName($file) && is_file(self::FOLDER . '/' . $file) ? self::FOLDER . '/' . $file : null;
    }

    /** Bezpečný název pro nahraný soubor: bez diakritiky a mezer, vždy s příponou .xml. */
    public static function uploadName(string $previous): string
    {
        return slugify(pathinfo($previous, PATHINFO_FILENAME), 80) . '.xml';
    }

    /* ---------- ověření a čtení ---------- */

    /**
     * Rychlé ověření před přijetím souboru: přípona, velikost, správně utvořený začátek XML a jmenný prostor exportu WordPressu.
     * Zbytek souboru se ověří při prvním průchodu (náhled) – chyba v XML ho zastaví s číslem řádku.
     *
     * @throws \RuntimeException s českou hláškou pro uživatele
     */
    public function verify(): void
    {
        if (strtolower(pathinfo($this->path, PATHINFO_EXTENSION)) !== 'xml') {
            throw new \RuntimeException('Soubor musí mít příponu .xml – je to export z WordPressu (Nástroje → Export).');
        }
        $this->verifyContent();
    }

    /**
     * Totéž bez kontroly přípony – pro právě nahraný soubor, který ještě leží v dočasné složce pod náhodným jménem.
     *
     * @throws \RuntimeException
     */
    public function verifyContent(): void
    {
        $size = (int) @filesize($this->path);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Soubor je prázdný nebo větší než 1 GB. Velký web exportujte z WordPressu po částech (podle data).');
        }
        $this->header();
    }

    /**
     * Údaje ze začátku souboru (před prvním příspěvkem): starý web, autoři, rubriky, štítky.
     *
     * @return array{nazev:string, adresa:string, autori:array<string,string>, rubriky:array<string,array{nazev:string, predek:string}>, stitky:array<string,string>}
     */
    public function header(): array
    {
        $h = ['nazev' => '', 'adresa' => '', 'autori' => [], 'rubriky' => [], 'stitky' => []];
        $link = '';
        $reader = $this->open();
        try {
            $this->findChannel($reader);
            // potomci <channel>: čtou se jeden po druhém až k prvnímu <item>
            $hasMore = $this->read($reader);
            while ($hasMore && !($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === 1)) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== 2) {
                    $hasMore = $this->read($reader);
                    continue;
                }
                if ($reader->name === 'item') {
                    break;
                }
                $field = self::fields($this->node($reader));
                match ($reader->name) {
                    'title' => $h['nazev'] = self::plainText($field['title'] ?? ''),
                    'link' => $link = trim($field['link'] ?? ''),
                    'wp:base_site_url' => $h['adresa'] = trim($field['wp:base_site_url'] ?? ''),
                    'wp:author' => $h['autori'][(string) ($field['wp:author_login'] ?? '')] = self::plainText(($field['wp:author_display_name'] ?? '') !== '' ? $field['wp:author_display_name'] : ($field['wp:author_login'] ?? '')),
                    'wp:category' => $h['rubriky'][(string) ($field['wp:category_nicename'] ?? '')] = ['nazev' => self::plainText($field['wp:cat_name'] ?? ''), 'predek' => (string) ($field['wp:category_parent'] ?? '')],
                    'wp:tag' => $h['stitky'][(string) ($field['wp:tag_slug'] ?? '')] = self::plainText($field['wp:tag_name'] ?? ''),
                    default => null,
                };
                $hasMore = $this->additional($reader);
            }
        } finally {
            $reader->close();
        }
        // adresa starého webu: <link> kanálu je adresa, na které web opravdu běžel; base_site_url jen jako záloha
        $h['adresa'] = $link !== '' ? $link : $h['adresa'];
        unset($h['autori'][''], $h['rubriky'][''], $h['stitky']['']);

        return $h;
    }

    /**
     * Příspěvky souboru (články, stránky, přílohy…) jeden po druhém. Klíčem je pořadí od nuly – podle něj import ví, kde minule skončil.
     *
     * @param int $skip kolik příspěvků od začátku přeskočit (přeskakuje se rychle, bez rozebírání obsahu)
     * @return \Generator<int, array<string, mixed>>
     */
    public function items(int $skip = 0): \Generator
    {
        $reader = $this->open();
        try {
            $this->findChannel($reader);
            $order = 0;
            $hasMore = $this->read($reader);
            while ($hasMore) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 2) {
                    if ($reader->name === 'item') {
                        if ($order >= $skip) {
                            yield $order => self::item($this->node($reader));
                        }
                        $order++;
                    }
                    $hasMore = $this->additional($reader);
                } else {
                    $hasMore = $this->read($reader);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Rozebere jeden <item>. Komentáře se nečtou – firemní web je nepřebírá.
     *
     * @return array<string, mixed>
     */
    public static function item(\DOMElement $item): array
    {
        $p = [
            'id' => 0, 'typ' => 'post', 'stav' => '', 'titulek' => '', 'odkaz' => '', 'adresa' => '', 'datum' => '', 'datum_gmt' => '', 'vydano' => '',
            'autor' => '', 'obsah' => '', 'perex' => '', 'heslo' => '', 'pripnuty' => false, 'priloha_url' => '', 'nahled' => 0,
            'rubriky' => [], 'stitky' => [],
        ];
        foreach ($item->childNodes as $n) {
            if (!$n instanceof \DOMElement) {
                continue;
            }
            $text = $n->textContent;
            switch ($n->nodeName) {
                case 'title': $p['titulek'] = self::plainText($text); break;
                case 'link': $p['odkaz'] = trim($text); break;
                case 'pubDate': $p['vydano'] = trim($text); break;
                case 'dc:creator': $p['autor'] = trim($text); break;
                case 'content:encoded': $p['obsah'] = $text; break;
                case 'excerpt:encoded': $p['perex'] = $text; break;
                case 'wp:post_id': $p['id'] = (int) $text; break;
                case 'wp:post_date': $p['datum'] = trim($text); break;
                case 'wp:post_date_gmt': $p['datum_gmt'] = trim($text); break;
                case 'wp:post_name': $p['adresa'] = trim($text); break;
                case 'wp:status': $p['stav'] = trim($text); break;
                case 'wp:post_type': $p['typ'] = trim($text); break;
                case 'wp:post_password': $p['heslo'] = trim($text); break;
                case 'wp:is_sticky': $p['pripnuty'] = trim($text) === '1'; break;
                case 'wp:attachment_url': $p['priloha_url'] = trim($text); break;
                case 'category':
                    $kind = $n->getAttribute('domain') === 'post_tag' ? 'stitky' : ($n->getAttribute('domain') === 'category' ? 'rubriky' : '');
                    if ($kind !== '' && $n->getAttribute('nicename') !== '') {
                        $p[$kind][$n->getAttribute('nicename')] = self::plainText($text);
                    }
                    break;
                case 'wp:postmeta':
                    $meta = self::fields($n);
                    if (($meta['wp:meta_key'] ?? '') === '_thumbnail_id') {
                        $p['nahled'] = (int) ($meta['wp:meta_value'] ?? 0);
                    }
                    break;
            }
        }

        return $p;
    }

    /* ---------- vnitřní pomůcky ---------- */

    private function open(): \XMLReader
    {
        if (!class_exists(\XMLReader::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('Na serveru chybí rozšíření PHP xmlreader nebo dom – bez nich nejde export z WordPressu přečíst.');
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        // LIBXML_NONET: nic se nenačítá ze sítě. Záměrně BEZ LIBXML_NOENT (nahrazování entit) a BEZ LIBXML_DTDLOAD.
        $reader = new \XMLReader();
        if (!@$reader->open($this->path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Soubor se nepodařilo otevřít.');
        }

        return $reader;
    }

    /** Posune čtečku na <channel> a cestou ověří, že jde o export WordPressu bez DOCTYPE. */
    private function findChannel(\XMLReader $reader): void
    {
        while ($this->read($reader)) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->depth === 0) {
                $namespaceUri = (string) $reader->getAttribute('xmlns:wp');
                if ($reader->name !== 'rss' || !preg_match('#^https?://wordpress\.org/export/\d+\.\d+/?$#', $namespaceUri)) {
                    throw new \RuntimeException('Tohle není export z WordPressu. Ve WordPressu otevřete Nástroje → Export, zvolte „Veškerý obsah“ a stáhněte soubor .xml.');
                }
            } elseif ($reader->depth === 1 && $reader->name === 'channel') {
                return;
            }
        }
        throw new \RuntimeException('V souboru chybí obsah webu (značka channel).');
    }

    /** Jeden krok čtečky; hlídá DOCTYPE a chyby v XML. */
    private function read(\XMLReader $reader): bool
    {
        return $this->check($reader, @$reader->read());
    }

    /** Přeskočí celý právě čtený prvek (i s potomky) na jeho dalšího sourozence. */
    private function additional(\XMLReader $reader): bool
    {
        return $this->check($reader, @$reader->next());
    }

    private function check(\XMLReader $reader, bool $ok): bool
    {
        if ($ok && ($reader->nodeType === \XMLReader::DOC_TYPE || $reader->nodeType === \XMLReader::ENTITY_REF || $reader->nodeType === \XMLReader::ENTITY)) {
            throw new \RuntimeException('Soubor obsahuje DOCTYPE nebo vlastní entity. Export z WordPressu nic takového nemá – soubor byl z bezpečnostních důvodů odmítnut.');
        }
        $error = libxml_get_last_error();
        if (!$ok && $error !== false) {
            libxml_clear_errors();
            throw new \RuntimeException('Soubor není platné XML – je poškozený nebo neúplný. Stáhněte export z WordPressu znovu. Chyba je na řádku:', $error->line);
        }

        return $ok;
    }

    /** Právě čtený prvek jako samostatný uzel DOM (jen tento jeden prvek – zbytek souboru v paměti není). */
    private function node(\XMLReader $reader): \DOMElement
    {
        $node = @$reader->expand(new \DOMDocument());
        if (!$node instanceof \DOMElement) {
            $error = libxml_get_last_error();
            libxml_clear_errors();
            throw new \RuntimeException('Soubor není platné XML – je poškozený nebo neúplný. Stáhněte export z WordPressu znovu. Chyba je na řádku:', $error !== false ? $error->line : 0);
        }
        foreach ($node->getElementsByTagName('*') as $child) {
            foreach ($child->childNodes as $n) {
                if ($n instanceof \DOMEntityReference) {
                    throw new \RuntimeException('Soubor obsahuje DOCTYPE nebo vlastní entity. Export z WordPressu nic takového nemá – soubor byl z bezpečnostních důvodů odmítnut.');
                }
            }
        }

        return $node;
    }

    /**
     * Přímí potomci prvku jako pole "název značky => text".
     *
     * @return array<string, string>
     */
    private static function fields(\DOMElement $element): array
    {
        $field = [$element->nodeName => $element->textContent];
        foreach ($element->childNodes as $n) {
            if ($n instanceof \DOMElement) {
                $field[$n->nodeName] = $n->textContent;
            }
        }

        return $field;
    }

    /** Titulky a jména: bez značek, entity převedené na znaky, bez okrajových mezer. */
    private static function plainText(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
