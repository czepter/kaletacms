<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Systémové adresy webu v jazyce verze: česká verze má /novinky, /novinky/kategorie/…, /novinky/stitek/… a /hledani,
 * každá jiná /news, /news/category/…, /news/tag/… a /search.
 *
 * Kód uvnitř systému pracuje s českými (vnitřními) cestami; App::url() je převede na veřejné a Front\Kernel veřejné
 * zase na vnitřní. Druhá podoba adresy (třeba stará /novinky na anglickém webu) přesměruje natrvalo na platnou, takže
 * odkazy a pozice ve vyhledávačích zůstanou. Má-li web vlastní stránku s adresou news nebo search, zůstane jí a systém
 * použije české slovo.
 */
final class Routes
{
    /** vnitřní (české) slovo => anglické */
    private const array FIRST_SEGMENTS = ['novinky' => 'news', 'hledani' => 'search'];
    private const array SECOND_SEGMENTS = ['kategorie' => 'category', 'stitek' => 'tag'];

    /** @var array<string, bool>|null anglická slova, která na webu zabírá vlastní stránka */
    private static ?array $taken = null;

    /** Anglická slova se použijí pro každý jazyk kromě češtiny. */
    public static function isEnglish(string $language): bool
    {
        return $language !== 'cs';
    }

    /** Vnitřní cesta (bez úvodního lomítka, i s ?dotazem) na veřejnou pro daný jazyk verze. */
    public static function publicPath(string $path, string $language, ?Db $db): string
    {
        if (!self::isEnglish($language) || !preg_match('#^(novinky|hledani)(?=$|[/?.])#', $path, $m) || self::isTaken(self::FIRST_SEGMENTS[$m[1]], $db)) {
            return $path;
        }
        $rest = substr($path, strlen($m[1]));
        if ($m[1] === 'novinky' && preg_match('#^/(kategorie|stitek)(?=/)#', $rest, $d)) {
            $rest = '/' . self::SECOND_SEGMENTS[$d[1]] . substr($rest, strlen($d[0]));
        }

        return self::FIRST_SEGMENTS[$m[1]] . $rest;
    }

    /**
     * Veřejná cesta požadavku (s úvodním lomítkem, bez jazykové předpony) na vnitřní. Vrací [vnitřní, kanonická]:
     * kanonická je podoba, kterou má adresa v tomto jazyce mít; když se liší od požadované, Front\Kernel přesměruje.
     *
     * @return array{0: string, 1: string}
     */
    public static function internalPath(string $path, string $language, ?Db $db): array
    {
        if (!preg_match('#^/(novinky|hledani|news|search)(?=$|[/.])#', $path, $m)) {
            return [$path, $path];
        }
        $word = $m[1];
        $czech = array_search($word, self::FIRST_SEGMENTS, true);
        if ($czech !== false && self::isTaken($word, $db)) {
            return [$path, $path]; // vlastní stránka webu
        }
        $internal = '/' . ($czech !== false ? $czech : $word) . substr($path, strlen($m[0]));
        if (($czech !== false ? $czech : $word) === 'novinky') {
            $internal = (string) preg_replace_callback('#^/novinky/(category|tag|kategorie|stitek)(?=/)#',
                fn (array $d): string => '/novinky/' . (array_search($d[1], self::SECOND_SEGMENTS, true) ?: $d[1]), $internal);
        }

        return [$internal, '/' . self::publicPath(ltrim($internal, '/'), $language, $db)];
    }

    /** Zabírá anglické slovo vlastní stránka webu (třeba stránka „news“ z doby před 1.2)? */
    private static function isTaken(string $word, ?Db $db): bool
    {
        if ($db === null) {
            return false;
        }
        if (self::$taken === null) {
            self::$taken = [];
            try {
                foreach ($db->all("SELECT seo_link FROM {stranky} WHERE seo_link IN ('news', 'search') AND smazano IS NULL") as $r) {
                    self::$taken[$r['seo_link']] = true;
                }
            } catch (\Throwable) {
                // web před instalací nebo bez tabulky – žádná vlastní stránka
            }
        }

        return isset(self::$taken[$word]);
    }
}
