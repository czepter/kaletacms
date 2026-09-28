<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Site language and translations of template texts.
 *
 * Texts in templates are in Czech and wrapped in the function t('Číst dál'); for another language they are looked up in the dictionary
 * system/jazyky/<code>.php (Czech => translation). What is missing in the dictionary is taken from the English one (and for Czech it stays Czech) - the site never breaks.
 * The language of the whole site is set in Settings (site_language); the extension "jazyky" adds more language versions
 * at URLs /en/… - each has its own pages, categories and news. A new language = dictionaries system/jazyky/<code>.php
 * (site), admin-<code>.php and install-<code>.php + an entry in AVAILABLE.
 */
final class Language
{
    /**
     * code => [name in that language, locale (Open Graph, date format), numeric date format for date()]. Right-to-left languages
     * (Arabic, Hebrew) are not available yet – templates and the builder do not account for them.
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

    /** Codes from AVAILABLE for Settings field types (vyber:… / seznam:…). */
    public const string CODES = 'cs|en|bg|ca|da|de|el|es|et|fi|fr|ga|hr|hu|is|it|lt|lv|mt|nl|no|pl|pt|ro|sk|sl|sq|sr|bs|mk|sv|tr|uk|ru|hi|id|ja|ko|vi|zh';

    private static string $code = 'cs';
    private static string $column = '';

    /** @var array<string, string> */
    private static array $dictionary = [];

    /** Languages the administration is translated into (dictionary system/jazyky/admin-<code>.php). */
    public const array ADMIN_LANGUAGES = ['cs' => 'Čeština', 'en' => 'English'];

    /** The language has no dictionary of its own, texts come from the English one (the date in words then comes from the intl extension, if the server has it). */
    private static bool $baseOnly = false;

    /**
     * The language's dictionary: its own (system/jazyky/<set><code>.php), and what is missing there, from the English one – a language
     * without a dictionary thus has template texts in English and the date in its own numeric format. Czech needs no dictionary (texts in the code are Czech).
     *
     * @param string $dictionarySet "" = site texts, "admin-" = administration texts
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
            // the date format from the English dictionary is not taken over: its own, otherwise the language's numeric format and the date in words from the Czech keys of days and months
            if (!isset($custom['datum_format'])) {
                self::$dictionary['datum_format'] = self::AVAILABLE[self::$code][2];
            }
            if (!isset($custom['datum_slovy'])) {
                unset(self::$dictionary['datum_slovy']);
            }
            self::$baseOnly = $custom === [];
        }
    }

    /** Locale for the date in words through the intl extension – only for a language without its own dictionary; otherwise null. */
    public static function intlLocale(): ?string
    {
        return self::$baseOnly && class_exists(\IntlDateFormatter::class) ? self::AVAILABLE[self::$code][1] : null;
    }

    /**
     * Language of the currently shown version of the site; also remembers the value of the "jazyk" column for queries.
     */
    public static function setSite(Settings $s, string $code): void
    {
        self::set($code);
        self::$column = self::column($s, self::$code);
    }

    /**
     * Runs a function with the site texts in another language and switches the language back. For content whose language does not
     * depend on who is creating it – typically an e-mail to a visitor (triggered by an administrator in the administration or by a background task).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runWith(string $code, callable $callback, string $dictionarySet = ''): mixed
    {
        [$previousCode, $previousDictionary, $previousBase] = [self::$code, self::$dictionary, self::$baseOnly];
        self::set($code, $dictionarySet); // set "admin-" = an e-mail to an administration user in the language of their administration
        try {
            return $callback();
        } finally {
            [self::$code, self::$dictionary, self::$baseOnly] = [$previousCode, $previousDictionary, $previousBase];
        }
    }

    /** Value of the "jazyk" column for the currently shown version of the site ('' = default language). Only '' or two lowercase letters. */
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

    /** Default language of the site. */
    public static function defaults(Settings $s): string
    {
        return isset(self::AVAILABLE[$s->get('site_language')]) ? $s->get('site_language') : 'cs';
    }

    /**
     * Additional language versions of the site (without the default language); empty when the extension is disabled.
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
     * Additional languages the site offers to visitors and search engines (language switcher, hreflang, sitemap). When the home
     * of the site is a page, only languages with its published translation – a language version in progress is not shown yet.
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

    /** Language of content by the "jazyk" column (page, category, news item): empty = the site's default language. */
    public static function ofContent(Settings $s, string $column): string
    {
        return isset(self::AVAILABLE[$column]) ? $column : self::defaults($s);
    }

    /** Value of the "jazyk" column for the given language: the site's default language is stored as an empty string. */
    public static function column(Settings $s, string $code): string
    {
        return $code === self::defaults($s) || !in_array($code, self::additional($s), true) ? '' : $code;
    }
}
