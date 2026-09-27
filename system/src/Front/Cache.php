<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\Response;

/**
 * Cache celých stránek pro nepřihlášené návštěvníky (soubory ve storage/cache/stranky, platnost 5 minut).
 * Stránka z cache stojí jeden dotaz do databáze místo desítek. Jakákoli změna v administraci cache smaže.
 * Necachuje se nic osobního: přihlášení uživatelé administrace, náhledy.
 */
final class Cache
{
    private const string FOLDER = KALETA_ROOT . '/storage/cache/stranky';
    private const int LINK_LIFETIME = 300;

    /** Parametry reklam a sledování kampaní: stránku nemění, cache se kvůli nim obcházet nemá. */
    private const string TRACKING_PARAMS = '/^(utm_[a-z]+|fbclid|gclid|gbraid|wbraid|msclkid|dclid|ka_[a-z]+|_ga|_gl|yclid|igshid|ref)$/';

    /** @var resource|null zámek stránky, kterou tenhle požadavek právě přegenerovává */
    private static $lock = null;

    public static function load(App $app): ?Response
    {
        $file = self::file($app);
        if ($file === null || !is_file($file)) {
            return null;
        }
        if (filemtime($file) < time() - self::LINK_LIFETIME) {
            // Prošlou stránku přegeneruje první, kdo přijde; ostatní, kteří dorazí ve stejné vteřině, dostanou ještě tu starou
            // (nejdéle o minutu déle). Bez toho by po vypršení skládalo tutéž stránku z databáze najednou všech sto návštěvníků.
            $lock = @fopen($file . '.zamek', 'c');
            if ($lock === false || flock($lock, LOCK_EX | LOCK_NB)) {
                self::$lock = $lock ?: null; // drží se do uloz() nebo do konce požadavku

                return null;
            }
            fclose($lock);
            if (filemtime($file) < time() - self::LINK_LIFETIME - 60) {
                return null;
            }
        }
        [$header, $html] = explode("\n", (string) file_get_contents($file), 2) + [1 => ''];
        $meta = json_decode($header, true);
        if (!is_array($meta) || $html === '') {
            return null;
        }
        Stats::record($app, $meta['idc'] ?? null);
        if (!empty($meta['idc'])) {
            $app->db()->run('UPDATE {novinky} SET visit = visit + 1 WHERE idc = ?', [(int) $meta['idc']]);
        }
        // prohlížeč si stránku může nechat a jen se zeptat, jestli se změnila (304 bez těla)
        $etag = '"' . substr(md5($file . filemtime($file)), 0, 16) . '"';
        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'X-Cache' => 'kaleta', 'ETag' => $etag, 'Cache-Control' => 'no-cache'];
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response($html, 200, $headers);
    }

    public static function save(App $app, string $html, ?int $idc): void
    {
        $file = self::file($app);
        if ($file === null) {
            return;
        }
        if (!is_dir(self::FOLDER)) {
            @mkdir(self::FOLDER, 0775, true);
        }
        @file_put_contents($file, json_encode(['idc' => $idc]) . "\n" . $html, LOCK_EX);
        if (self::$lock !== null) {
            flock(self::$lock, LOCK_UN);
            fclose(self::$lock);
            self::$lock = null;
            @unlink($file . '.zamek');
        }
        if (random_int(1, 100) === 1) {
            // občasný úklid: prošlé soubory by jinak mizely jen při změně v administraci
            foreach (glob(self::FOLDER . '/*.html') ?: [] as $old) {
                if (filemtime($old) < time() - self::LINK_LIFETIME) {
                    @unlink($old);
                }
            }
        }
    }

    /**
     * Krátká cache textových výstupů, které jsou pro všechny stejné (RSS, mapa webu, feed.json, llms.txt): čtečky a roboti
     * si je stahují pořád dokola a bez cache se pokaždé skládají z databáze. Klíč musí zahrnout vše, na čem výstup závisí.
     *
     * @param callable(): string $produce
     */
    public static function text(App $app, string $key, callable $produce): string
    {
        if (!$app->settings()->bool('cache_stranek')) {
            return $produce();
        }
        // přípona .html jen kvůli vymaz() - jakákoli změna v administraci smaže i tyhle soubory
        $file = self::FOLDER . '/zdroj-' . sha1($app->request->basePath() . '|' . $key) . '.html';
        if (is_file($file) && filemtime($file) >= time() - self::LINK_LIFETIME && ($content = file_get_contents($file)) !== false && $content !== '') {
            return $content;
        }
        $content = $produce();
        if (!is_dir(self::FOLDER)) {
            @mkdir(self::FOLDER, 0775, true);
        }
        @file_put_contents($file, $content, LOCK_EX);

        return $content;
    }

    public static function clear(): void
    {
        foreach (array_merge(glob(self::FOLDER . '/*.html') ?: [], glob(self::FOLDER . '/*.zamek') ?: []) as $file) {
            @unlink($file);
        }
    }

    /** Soubor cache pro tento požadavek, nebo null, když se cachovat nemá. */
    private static function file(App $app): ?string
    {
        $r = $app->request;
        $s = $app->settings();
        if (!$s->bool('cache_stranek') || $r->isPost()) {
            return null;
        }
        $params = array_filter(array_keys($_GET), fn (int|string $k): bool => !preg_match(self::TRACKING_PARAMS, (string) $k));
        if (array_diff($params, ['strana']) !== []) {
            return null;
        }
        foreach (array_keys($_COOKIE) as $cookie) {
            if ($cookie === 'kaleta') { // přihlášený uživatel administrace (session)
                return null;
            }
        }

        return self::FOLDER . '/' . md5($r->origin() . '|' . \Kaleta\Core\Language::code() . '|' . $r->path() . '|' . $r->getInt('strana', 1) . '|' . $s->get('layout')) . '.html';
    }
}
