<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Vyhledávací index článků: sloupec ka_novinky.hledani drží text malými písmeny bez diakritiky, takže čtenář
 * najde "nábřeží" i po zadání "nabrezi". U zamčených článků se indexuje jen titulek a perex - z výsledků
 * hledání tak nejde po kouskách vyčíst zamčený text.
 */
final class Search
{
    /** Malá písmena bez diakritiky, jen písmena a číslice oddělené mezerou. */
    public static function normalize(string $text): string
    {
        $text = remove_diacritics(mb_strtolower(html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $text)), ENT_QUOTES | ENT_HTML5)));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Přepočítá index jednoho článku; volá se po každém uložení (administrace, Claude). */
    public static function index(Db $db, int $idc): void
    {
        $c = $db->one('SELECT titulek, uvod, text, t_slova FROM {novinky} WHERE idc = ?', [$idc]);
        if ($c !== null) {
            $db->update('novinky', ['hledani' => self::normalize($c['titulek'] . ' ' . $c['t_slova'] . ' ' . $c['uvod'] . ' ' . $c['text'])], ['idc' => $idc]);
        }
    }

    /** Doplní index článkům, které ho ještě nemají (po aktualizaci systému); po dávkách, aby nezdržel požadavek. */
    public static function complete(Db $db, int $batch = 100): int
    {
        $ids = array_column($db->all('SELECT idc FROM {novinky} WHERE hledani IS NULL LIMIT ' . max(1, $batch)), 'idc');
        foreach ($ids as $idc) {
            self::index($db, (int) $idc);
        }

        return count($ids);
    }

    /**
     * Hledání ve stránkách a položkách kolekcí bez indexu (je jich na firemním webu stovky, ne tisíce): všechna slova
     * dotazu bez ohledu na diakritiku a velikost písmen. Vrací shody s úryvkem textu kolem prvního nalezeného slova,
     * seřazené podle relevance (shoda v názvu váží nejvíc, pak počet výskytů v textu; při shodě zůstává pořadí webu).
     *
     * @param list<array{titulek: string, adresa: string, text: string}> $candidates
     * @return list<array{titulek: string, adresa: string, uryvek: string}>
     */
    public static function find(string $q, array $candidates, int $limit = 20): array
    {
        $words = array_values(array_filter(explode(' ', self::normalize($q)), fn (string $s): bool => strlen($s) >= 2));
        if ($words === []) {
            return [];
        }
        $results = [];
        $score = [];
        foreach ($candidates as $k) {
            $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $k['text'])), ENT_QUOTES | ENT_HTML5)));
            $search = self::normalize($k['titulek'] . ' ' . $plain);
            foreach ($words as $word) {
                if (!str_contains($search, $word)) {
                    continue 2;
                }
            }
            // úryvek: české znaky se bez diakritiky mapují 1:1, pozice v textu bez diakritiky tedy sedí i v originále
            $position = mb_strpos(remove_diacritics(mb_strtolower($plain)), $words[0]);
            $from = $position === false ? 0 : max(0, $position - 60);
            $excerpt = mb_substr($plain, $from, 180);
            $results[] = ['titulek' => $k['titulek'], 'adresa' => $k['adresa'], 'uryvek' => ($from > 0 ? '…' : '') . $excerpt . (mb_strlen($plain) > $from + 180 ? '…' : '')];
            $name = self::normalize($k['titulek']);
            $text = self::normalize($plain);
            $score[] = array_sum(array_map(fn (string $s): int => (str_contains($name, $s) ? 100 : 0) + min(20, substr_count($text, $s)), $words));
        }
        // stabilní řazení: stejné skóre = pořadí, v jakém je web řadí
        $order = array_keys($results);
        array_multisort($score, SORT_DESC, $order, SORT_ASC, $results);

        return array_slice($results, 0, $limit);
    }

    /** Dotaz pro MATCH … AGAINST v režimu BOOLEAN: všechna slova od 3 znaků s libovolnou koncovkou. */
    public static function query(string $q): string
    {
        $words = array_filter(explode(' ', self::normalize($q)), fn (string $s): bool => strlen($s) >= 3);

        return implode(' ', array_map(fn (string $s): string => '+' . $s . '*', array_slice($words, 0, 8)));
    }
}
