<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Jazyk webu a překlady textů šablon.
 *
 * Texty v šablonách jsou česky a obalené funkcí t('Číst dál'); pro jiný jazyk se hledají ve slovníku
 * system/jazyky/<kód>.php (česky => překlad). Co ve slovníku chybí, vezme se z anglického (a u češtiny zůstane česky) - web se nikdy nerozbije.
 * Jazyk celého webu určuje Nastavení (jazyk_webu); rozšíření "jazyky" přidává další jazykové verze
 * na adresách /en/… - každá má své stránky, kategorie a novinky. Nový jazyk = slovníky system/jazyky/<kód>.php
 * (web), admin-<kód>.php a install-<kód>.php + záznam v DOSTUPNE.
 */
final class Language
{
    /**
     * kód => [název v daném jazyce, locale (Open Graph, formát data), číselný tvar data pro date()]. Jazyky zprava doleva
     * (arabština, hebrejština) zatím nejsou – šablony a builder s nimi nepočítají.
     */
    public const array AVAILABLE = [
        'cs' => ['Čeština', 'cs_CZ', 'j. n. Y'],
        'en' => ['English', 'en_US', 'j M Y'],
        'bg' => ['Български', 'bg_BG', 'd.m.Y'],
        'ca' => ['Català', 'ca_ES', 'd/m/Y'],
        'da' => ['Dansk', 'da_DK', 'd.m.Y'],
        'de' => ['Deutsch', 'de_DE', 'd.m.Y'],
        'el' => ['Ελληνικά', 'el_GR', 'd/m/Y'],
        'es' => ['Español', 'es_ES', 'd/m/Y'],
        'et' => ['Eesti', 'et_EE', 'd.m.Y'],
        'fi' => ['Suomi', 'fi_FI', 'j.n.Y'],
        'fr' => ['Français', 'fr_FR', 'd/m/Y'],
        'ga' => ['Gaeilge', 'ga_IE', 'd/m/Y'],
        'hr' => ['Hrvatski', 'hr_HR', 'd.m.Y.'],
        'hu' => ['Magyar', 'hu_HU', 'Y. m. d.'],
        'is' => ['Íslenska', 'is_IS', 'j.n.Y'],
        'it' => ['Italiano', 'it_IT', 'd/m/Y'],
        'lt' => ['Lietuvių', 'lt_LT', 'Y-m-d'],
        'lv' => ['Latviešu', 'lv_LV', 'd.m.Y.'],
        'mt' => ['Malti', 'mt_MT', 'd/m/Y'],
        'nl' => ['Nederlands', 'nl_NL', 'd-m-Y'],
        'no' => ['Norsk', 'nb_NO', 'd.m.Y'],
        'pl' => ['Polski', 'pl_PL', 'd.m.Y'],
        'pt' => ['Português', 'pt_PT', 'd/m/Y'],
        'ro' => ['Română', 'ro_RO', 'd.m.Y'],
        'sk' => ['Slovenčina', 'sk_SK', 'j. n. Y'],
        'sl' => ['Slovenščina', 'sl_SI', 'j. n. Y'],
        'sq' => ['Shqip', 'sq_AL', 'd.m.Y'],
        'sr' => ['Srpski', 'sr_RS', 'd.m.Y.'],
        'bs' => ['Bosanski', 'bs_BA', 'd.m.Y.'],
        'mk' => ['Македонски', 'mk_MK', 'd.m.Y'],
        'sv' => ['Svenska', 'sv_SE', 'Y-m-d'],
        'tr' => ['Türkçe', 'tr_TR', 'd.m.Y'],
        'uk' => ['Українська', 'uk_UA', 'd.m.Y'],
        'ru' => ['Русский', 'ru_RU', 'd.m.Y'],
        'hi' => ['हिन्दी', 'hi_IN', 'd/m/Y'],
        'id' => ['Bahasa Indonesia', 'id_ID', 'd/m/Y'],
        'ja' => ['日本語', 'ja_JP', 'Y/m/d'],
        'ko' => ['한국어', 'ko_KR', 'Y. m. d.'],
        'vi' => ['Tiếng Việt', 'vi_VN', 'd/m/Y'],
        'zh' => ['中文', 'zh_CN', 'Y-m-d'],
    ];

    /** Kódy z DOSTUPNE pro typy polí Nastavení (vyber:… / seznam:…). */
    public const string CODES = 'cs|en|bg|ca|da|de|el|es|et|fi|fr|ga|hr|hu|is|it|lt|lv|mt|nl|no|pl|pt|ro|sk|sl|sq|sr|bs|mk|sv|tr|uk|ru|hi|id|ja|ko|vi|zh';

    private static string $code = 'cs';
    private static string $column = '';

    /** @var array<string, string> */
    private static array $dictionary = [];

    /** Jazyky, do kterých je přeložená administrace (slovník system/jazyky/admin-<kód>.php). */
    public const array ADMIN_LANGUAGES = ['cs' => 'Čeština', 'en' => 'English'];

    /** Jazyk nemá vlastní slovník, texty jsou z anglického (datum slovy pak dá rozšíření intl, je-li na serveru). */
    private static bool $baseOnly = false;

    /**
     * Slovník jazyka: vlastní (system/jazyky/<sada><kód>.php), a co v něm chybí, z anglického – jazyk bez slovníku tak má
     * texty šablony anglicky a datum ve svém číselném tvaru. Čeština slovník nepotřebuje (texty v kódu jsou česky).
     *
     * @param string $dictionarySet "" = texty webu, "admin-" = texty administrace
     */
    public static function set(string $code, string $dictionarySet = ''): void
    {
        self::$code = isset(self::AVAILABLE[$code]) ? $code : 'cs';
        self::$dictionary = [];
        self::$baseOnly = false;
        if (self::$code === 'cs') {
            return;
        }
        $file = KALETA_SYSTEM . '/jazyky/' . $dictionarySet . self::$code . '.php';
        $custom = is_file($file) ? require $file : [];
        $baseDictionary = self::$code !== 'en' && is_file(KALETA_SYSTEM . '/jazyky/' . $dictionarySet . 'en.php') ? require KALETA_SYSTEM . '/jazyky/' . $dictionarySet . 'en.php' : [];
        self::$dictionary = $custom + $baseDictionary;
        if (self::$code !== 'en') {
            // tvar data z anglického slovníku se nepřebírá: vlastní, jinak číselný tvar jazyka a datum slovy z českých klíčů dnů a měsíců
            if (!isset($custom['datum_format'])) {
                self::$dictionary['datum_format'] = self::AVAILABLE[self::$code][2];
            }
            if (!isset($custom['datum_slovy'])) {
                unset(self::$dictionary['datum_slovy']);
            }
            self::$baseOnly = $custom === [];
        }
    }

    /** Locale pro datum slovy přes rozšíření intl – jen u jazyka bez vlastního slovníku; jinak null. */
    public static function intlLocale(): ?string
    {
        return self::$baseOnly && class_exists(\IntlDateFormatter::class) ? self::AVAILABLE[self::$code][1] : null;
    }

    /**
     * Jazyk právě zobrazené verze webu; zároveň si zapamatuje hodnotu sloupce "jazyk" pro dotazy.
     */
    public static function setSite(Settings $s, string $code): void
    {
        self::set($code);
        self::$column = self::column($s, self::$code);
    }

    /**
     * Provede funkci s texty webu v jiném jazyce a vrátí jazyk zpět. Pro obsah, jehož jazyk nezávisí na tom,
     * kdo ho zrovna vytváří – typicky e-mail návštěvníkovi (spouští ho správce v administraci nebo úloha na pozadí).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runWith(string $code, callable $callback, string $dictionarySet = ''): mixed
    {
        [$previousCode, $previousDictionary, $previousBase] = [self::$code, self::$dictionary, self::$baseOnly];
        self::set($code, $dictionarySet); // sada "admin-" = e-mail uživateli administrace v jazyce jeho administrace
        try {
            return $callback();
        } finally {
            [self::$code, self::$dictionary, self::$baseOnly] = [$previousCode, $previousDictionary, $previousBase];
        }
    }

    /** Hodnota sloupce "jazyk" pro právě zobrazenou verzi webu ('' = výchozí jazyk). Jen '' nebo dvě malá písmena. */
    public static function siteColumn(): string
    {
        return self::$column;
    }

    public static function code(): string
    {
        return self::$code;
    }

    public static function t(string $text, string|int ...$values): string
    {
        $translation = self::$dictionary[$text] ?? $text;

        return $values === [] ? $translation : sprintf($translation, ...$values);
    }

    /** Výchozí jazyk webu. */
    public static function defaults(Settings $s): string
    {
        return isset(self::AVAILABLE[$s->get('site_language')]) ? $s->get('site_language') : 'cs';
    }

    /**
     * Další jazykové verze webu (bez výchozího jazyka); prázdné, když je rozšíření vypnuté.
     *
     * @return list<string>
     */
    public static function additional(Settings $s): array
    {
        if (!Extensions::isEnabled($s, 'jazyky')) {
            return [];
        }

        return array_values(array_diff(array_intersect(explode(',', $s->get('additional_languages')), array_keys(self::AVAILABLE)), [self::defaults($s)]));
    }

    /**
     * Další jazyky, které web nabízí návštěvníkům a vyhledávačům (přepínač jazyků, hreflang, mapa webu). Když je úvodem
     * webu stránka, jen jazyky s jejím zveřejněným překladem – rozpracovaná jazyková verze se zatím neukazuje.
     *
     * @return list<string>
     */
    public static function published(Settings $s, Db $db): array
    {
        $additional = self::additional($s);
        $home = $s->int('home_page');
        if ($additional === [] || $home === 0) {
            return $additional;
        }
        $done = array_column($db->all('SELECT DISTINCT jazyk FROM {stranky} WHERE preklad_z = ? AND zobrazit = 1 AND smazano IS NULL', [$home]), 'jazyk');

        return array_values(array_intersect($additional, $done));
    }

    /** Jazyk obsahu podle sloupce "jazyk" (stránka, kategorie, novinka): prázdný = výchozí jazyk webu. */
    public static function ofContent(Settings $s, string $column): string
    {
        return isset(self::AVAILABLE[$column]) ? $column : self::defaults($s);
    }

    /** Hodnota sloupce "jazyk" pro daný jazyk: výchozí jazyk webu se ukládá jako prázdný řetězec. */
    public static function column(Settings $s, string $code): string
    {
        return $code === self::defaults($s) || !in_array($code, self::additional($s), true) ? '' : $code;
    }
}
