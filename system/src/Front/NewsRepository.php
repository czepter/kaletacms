<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Db;
use Kaleta\Core\Images;
use Kaleta\Core\Settings;

/**
 * Čtení novinek pro web (tabulka ka_novinky). Na webu je vidět jen novinka vydaná (visible = 1), jejíž datum vydání už nastalo.
 */
final class NewsRepository
{
    private const string SELECT = "
        SELECT c.*, t.nazev AS tema_jm, t.seo_link AS tema_seo,
               NULLIF(u.jmeno, '') AS autor_jm, -- přihlašovací jméno se na webu neukazuje; bez vyplněného jména se autor nevypisuje
               u.pozice AS autor_pozice, u.foto AS autor_foto, u.bio AS autor_bio, u.url AS autor_url
        FROM {novinky} c
        JOIN {kategorie} t ON t.idt = c.tema
        LEFT JOIN {uzivatele} u ON u.idu = c.autor";

    /**
     * Sloupce pro výpisy: bez dlouhých textů (text, FAQ), které výpis netiskne. Klíče v poli zůstávají (prázdné),
     * aby šablony nepadaly. Nový sloupec ka_novinky, který má být vidět ve výpisech, je potřeba doplnit i sem.
     */
    private const string LIST_COLUMNS = "c.idc, c.seo_link, c.titulek, c.uvod, '' AS text, c.obrazek, c.tema, c.autor, c.datum, c.visible, c.t_slova, c.noindex, '' AS faq, c.visit,
        c.zmeneno, c.aktualizovano, c.jazyk, c.preklad_z";

    private const string PUBLISHED = 'c.visible = 1 AND c.datum <= NOW()';

    /** Podmínka "vydaná novinka v jazyce právě zobrazené verze webu". */
    private readonly string $published;

    /** @param string $base cesta k instalaci ("" nebo "/web") - doplňuje se před adresy obrázků z media/ */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly string $base = '')
    {
        $this->published = self::PUBLISHED . " AND c.jazyk = '" . \Kaleta\Core\Language::siteColumn() . "'";
    }

    /**
     * Úprava novinky před předáním šabloně: adresa hlavního obrázku z media/ dostane cestu k instalaci.
     *
     * @param array<string, mixed> $newsItem
     * @return array<string, mixed>
     */
    private function prepare(array $newsItem): array
    {
        if ($newsItem['obrazek'] !== '' && !preg_match('#^(https?:)?/#', $newsItem['obrazek'])) {
            $newsItem['obrazek'] = $this->base . '/' . $newsItem['obrazek'];
        }
        // responzivní obrázky: hlavní obrázek i obrázky v textu dostanou srcset z variant, které vznikly při nahrání
        $newsItem['obrazek_srcset'] = Images::srcset(ltrim(substr($newsItem['obrazek'], strlen($this->base)), '/'), $this->base);
        foreach (['uvod', 'text'] as $part) {
            if (str_contains($newsItem[$part], 'media/')) {
                $newsItem[$part] = preg_replace_callback('#<img\b(?![^>]*\bsrcset=)([^>]*?)\bsrc="([^"]*?(media/\d{4}/\d{2}/[^"]+))"#i', function (array $m): string {
                    $srcset = Images::srcset($m[3], $this->base);

                    return $srcset === '' ? $m[0] : '<img' . $m[1] . 'src="' . $m[2] . '" srcset="' . e($srcset) . '" sizes="(max-width: 800px) 100vw, 800px"';
                }, $newsItem[$part]) ?? $newsItem[$part];
            }
        }

        return $newsItem;
    }

    public function perPage(): int
    {
        return max(1, $this->settings->int('pocet_clanku'));
    }

    /**
     * Vydané novinky od nejnovější.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} novinky a jejich celkový počet
     */
    public function listPublished(int $pageNumber, ?int $limit = null, bool $withText = false): array
    {
        return $this->query($this->published, [], 'c.datum DESC, c.idc DESC', $pageNumber, $limit, $withText);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function inCategory(int $idt, int $pageNumber, ?int $limit = null): array
    {
        return $this->query($this->published . ' AND c.tema = ?', [$idt], 'c.datum DESC, c.idc DESC', $pageNumber, $limit);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function withTag(int $ids, int $pageNumber): array
    {
        return $this->query($this->published . ' AND EXISTS (SELECT 1 FROM {novinky_stitky} cs WHERE cs.idc = c.idc AND cs.ids = ?)', [$ids], 'c.datum DESC, c.idc DESC', $pageNumber);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function search(string $q, int $pageNumber): array
    {
        // index bez diakritiky (Core\Hledani): "nabrezi" najde "nábřeží"; krátká slova a části slov se hledají v titulku
        \Kaleta\Core\Search::complete($this->db); // novinky z doby před indexem se doplní samy
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $query = \Kaleta\Core\Search::query($q);
        if ($query === '') {
            return $this->query($this->published . ' AND c.titulek LIKE ?', [$like], 'c.datum DESC, c.idc DESC', $pageNumber);
        }

        return $this->query(
            $this->published . ' AND (MATCH(c.hledani) AGAINST (? IN BOOLEAN MODE) OR c.titulek LIKE ?)',
            [$query, $like],
            'c.datum DESC, c.idc DESC',
            $pageNumber,
        );
    }

    /** @return array<string, mixed>|null */
    public function bySlug(string $seo, bool $includeUnpublished = false): ?array
    {
        $newsItem = $this->db->one(self::SELECT . ' WHERE c.seo_link = ? AND c.smazano IS NULL' . ($includeUnpublished ? '' : ' AND ' . self::PUBLISHED), [$seo]);
        if ($newsItem === null) {
            return null;
        }
        // popisek, autor a alt hlavního obrázku: z novinky, jinak z knihovny médií
        $library = $newsItem['obrazek'] !== '' && !preg_match('#^(https?:)?//#', $newsItem['obrazek'])
            ? $this->db->one('SELECT nazev, popis, autor FROM {media} WHERE obr_poloha = ? LIMIT 1', [ltrim($newsItem['obrazek'], '/')]) : null;
        $description = $newsItem['obrazek_popis'] !== '' ? $newsItem['obrazek_popis'] : (string) ($library['popis'] ?? '');
        $author = $newsItem['obrazek_autor'] !== '' ? $newsItem['obrazek_autor'] : (string) ($library['autor'] ?? '');
        $newsItem['obrazek_alt'] = (string) ($library['nazev'] ?? '') !== '' ? (string) $library['nazev'] : $description;
        $parts = array_filter([e($description), $author !== '' ? '<span class="clanek-foto-autor">' . e(t('Foto: %s', $author)) . '</span>' : '']);
        $newsItem['obrazek_popisek_html'] = $parts === [] ? '' : '<figcaption class="clanek-popisek">' . implode(' ', $parts) . '</figcaption>';

        return $this->prepare($newsItem);
    }

    /**
     * Podobné novinky: nejdřív podle počtu společných štítků, potom novější ze stejné kategorie.
     *
     * @param array<string, mixed> $newsItem
     * @return list<array<string, mixed>>
     */
    public function similar(array $newsItem, int $count = 4): array
    {
        return $this->db->all(
            'SELECT c.titulek, c.seo_link, c.datum, COUNT(cs.ids) AS shoda
             FROM {novinky} c LEFT JOIN {novinky_stitky} cs ON cs.idc = c.idc AND cs.ids IN (SELECT ids FROM {novinky_stitky} WHERE idc = ?)
             WHERE ' . $this->published . ' AND c.idc <> ? AND (c.tema = ? OR cs.ids IS NOT NULL) AND c.datum > NOW() - INTERVAL 2 YEAR
             GROUP BY c.idc, c.titulek, c.seo_link, c.datum ORDER BY shoda DESC, c.datum DESC LIMIT ?',
            [$newsItem['idc'], $newsItem['idc'], $newsItem['tema'], $count],
        );
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function query(string $where, array $params, string $order, int $pageNumber, ?int $limit = null, bool $withText = false): array
    {
        // pevný počet (RSS, kanály, API) = nikdo nestránkuje, celkový počet se nepočítá
        $total = $limit !== null ? 0 : (int) $this->db->value("SELECT COUNT(*) FROM {novinky} c WHERE {$where}", $params);
        $limit ??= $this->perPage();
        $pageNumber = max(1, min($pageNumber, 100000));
        $newsItems = $this->db->all(
            ($withText ? self::SELECT : str_replace('SELECT c.*,', 'SELECT ' . self::LIST_COLUMNS . ',', self::SELECT)) . " WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $limit, ($pageNumber - 1) * $limit],
        );

        return [array_map($this->prepare(...), $newsItems), $total];
    }
}
