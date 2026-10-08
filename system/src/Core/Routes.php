<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site's system URLs in the language of the version: the Czech version has /novinky, /novinky/kategorie/…,
 * /novinky/stitek/… and /hledani, every other one /news, /news/category/…, /news/tag/… and /search.
 *
 * Code inside the system works with the Czech (internal) paths; App::url() converts them to public ones and Front\Kernel
 * converts public ones back to internal. The other form of a URL (e.g. the old /novinky on an English site) redirects
 * permanently to the valid one, so links and search engine rankings stay. If the site has its own page with the slug news
 * or search, the page keeps it and the system uses the Czech word.
 *
 * The setting news_slug (Settings → General) replaces the first segment of the news URLs in every language (/blog,
 * /blog/category/x); the old forms /novinky and /news redirect to it, and so do the slugs the site used before (setting
 * news_slug_old, kept by Settings::set).
 */
final class Routes
{
    /** internal (Czech) word => English one */
    private const array FIRST_SEGMENTS = ['novinky' => 'news', 'hledani' => 'search'];
    private const array SECOND_SEGMENTS = ['kategorie' => 'category', 'stitek' => 'tag'];

    /** @var array<string, bool>|null English words that a page of the site itself occupies */
    private static ?array $taken = null;

    /** Paths the system answers itself before any page (OAuth, the newsletter, the manifest…) – a news slug must not capture them. */
    private const array NEWS_RESERVED = ['oauth', 'odber', 'fleet', 'manifest', 'favicon', 'index'];

    /** How many previous news slugs keep redirecting. */
    private const int OLD_SLUGS = 10;

    /** The custom news slug (setting news_slug), null = not read yet. */
    private static ?string $news = null;

    /** @var list<string>|null previous news slugs (setting news_slug_old, comma separated), null = not read yet */
    private static ?array $old = null;

    /** The custom first segment of the news URLs ('' = the default word of the version's language). */
    public static function newsSlug(?Db $db): string
    {
        if (self::$news === null && $db !== null) {
            try {
                $stored = (string) ($db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'news_slug'") ?? '');
                self::$news = self::systemSlugError($stored) === null ? $stored : ''; // an import or a direct write must not point /mcp or /api at the news
            } catch (\Throwable) {
                self::$news = ''; // site before installation
            }
        }

        return self::$news ?? '';
    }

    /** Settings::set keeps the value current; null forgets it (read again from the database). */
    public static function setNewsSlug(?string $slug): void
    {
        self::$news = $slug;
    }

    /**
     * Slugs the news used before the current one: their URLs redirect to the current form. Invalid stored values are ignored
     * (same as newsSlug()).
     *
     * @return list<string>
     */
    public static function oldSlugs(?Db $db): array
    {
        if (self::$old === null && $db !== null) {
            try {
                $stored = (string) ($db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'news_slug_old'") ?? '');
                self::$old = array_values(array_filter(explode(',', $stored), fn (string $s): bool => $s !== '' && self::systemSlugError($s) === null));
            } catch (\Throwable) {
                self::$old = []; // site before installation
            }
        }

        return array_values(array_diff(self::$old ?? [], [self::newsSlug($db)]));
    }

    /** Settings::set keeps the list current; null forgets it (read again from the database). */
    public static function setOldSlugs(?string $list): void
    {
        self::$old = $list === null ? null : array_values(array_filter(explode(',', $list), fn (string $s): bool => $s !== ''));
    }

    /** The value of news_slug_old after the news slug changes from $previous to $new (newest first, the new one is dropped). */
    public static function rememberSlug(string $stored, string $previous, string $new): string
    {
        $list = array_filter(explode(',', $stored), fn (string $s): bool => $s !== '' && $s !== $new);
        if ($previous !== '' && $previous !== $new) {
            array_unshift($list, $previous);
        }

        return implode(',', array_slice(array_values(array_unique($list)), 0, self::OLD_SLUGS));
    }

    /** Does a page or collection slug collide with the custom news slug (or one the news used before)? */
    public static function isNewsSlug(string $slug, ?Db $db): bool
    {
        return $slug !== '' && ($slug === self::newsSlug($db) || in_array($slug, self::oldSlugs($db), true));
    }

    /** Format and system addresses only (no database): the slug is one lowercase segment and not a path of the system. */
    public static function systemSlugError(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }
        $system = [...array_diff(\Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, ['novinky', 'news']), ...self::NEWS_RESERVED];
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 40 || in_array($slug, $system, true) || isset(Language::AVAILABLE[$slug])) {
            return 'This URL is used by the system, choose another one.';
        }

        return null;
    }

    /** Why a news slug cannot be used (the message for the administrator), null = it is free. */
    public static function slugError(string $slug, Db $db): ?string
    {
        if (($error = self::systemSlugError($slug)) !== null || $slug === '') {
            return $error;
        }
        if ($db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND smazano IS NULL', [$slug]) !== null
            || $db->value('SELECT 1 FROM {kolekce} WHERE seo_link = ?', [$slug]) !== null) {
            return 'A page or a collection already uses this URL.';
        }

        return null;
    }

    /** English words are used for every language except Czech. */
    public static function isEnglish(string $language): bool
    {
        return $language !== 'cs';
    }

    /** Internal path (without the leading slash, including a ?query) to the public one for the given version language. */
    public static function publicPath(string $path, string $language, ?Db $db): string
    {
        if (!preg_match('#^(novinky|hledani)(?=$|[/?.])#', $path, $m)) {
            return $path;
        }
        $custom = $m[1] === 'novinky' ? self::newsSlug($db) : '';
        if ($custom === '' && (!self::isEnglish($language) || self::isTaken(self::FIRST_SEGMENTS[$m[1]], $db))) {
            return $path;
        }
        $rest = substr($path, strlen($m[1]));
        if ($m[1] === 'novinky' && self::isEnglish($language) && preg_match('#^/(kategorie|stitek)(?=/)#', $rest, $d)) {
            $rest = '/' . self::SECOND_SEGMENTS[$d[1]] . substr($rest, strlen($d[0]));
        }

        return ($custom !== '' ? $custom : self::FIRST_SEGMENTS[$m[1]]) . $rest;
    }

    /**
     * Public request path (with the leading slash, without the language prefix) to the internal one. Returns [internal, canonical]:
     * canonical is the form the URL should have in this language; when it differs from the requested one, Front\Kernel redirects.
     *
     * @return array{0: string, 1: string}
     */
    public static function internalPath(string $path, string $language, ?Db $db): array
    {
        $custom = self::newsSlug($db);
        $slugs = array_filter([$custom, ...self::oldSlugs($db)], fn (string $s): bool => $s !== '');
        if (!preg_match('#^/(novinky|hledani|news|search' . ($slugs !== [] ? '|' . implode('|', array_map(fn (string $s): string => preg_quote($s, '#'), $slugs)) : '') . ')(?=$|[/.])#', $path, $m)) {
            return [$path, $path];
        }
        $word = $m[1];
        $czech = in_array($word, $slugs, true) ? 'novinky' : array_search($word, self::FIRST_SEGMENTS, true);
        if ($czech !== false && !in_array($word, $slugs, true) && self::isTaken($word, $db)) {
            return [$path, $path]; // the site's own page
        }
        $internal = '/' . ($czech !== false ? $czech : $word) . substr($path, strlen($m[0]));
        if (($czech !== false ? $czech : $word) === 'novinky') {
            $internal = (string) preg_replace_callback('#^/novinky/(category|tag|kategorie|stitek)(?=/)#',
                fn (array $d): string => '/novinky/' . (array_search($d[1], self::SECOND_SEGMENTS, true) ?: $d[1]), $internal);
        }

        return [$internal, '/' . self::publicPath(ltrim($internal, '/'), $language, $db)];
    }

    /** Does a page of the site itself occupy the English word (e.g. a page "news" from before 1.2)? */
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
                // site before installation or without the table – no own page
            }
        }

        return isset(self::$taken[$word]);
    }
}
