<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Check of broken links in published news items. Runs in the background in small batches: one news item per five minutes,
 * each news item once per 30 days. Only links that do not work are stored ("Novinky → Nefunkční odkazy" (News → Broken links)).
 *
 * During the check the server connects to URLs from news items, so only http(s) to public addresses and standard ports,
 * without following redirects - a link in a news item must not be abusable to probe the hosting's internal network.
 */
final class Links
{
    /** Codes that do not mean a broken link: sites use them only to refuse bots or the HEAD method. */
    private const array INCONCLUSIVE_CODES = [401, 403, 405, 406, 429, 999];

    public static function runInBackground(App $app): void
    {
        $s = $app->settings();
        if (!$s->bool('link_check') || !function_exists('curl_init') || time() - $s->int('link_check_time') < 300) {
            return;
        }
        $s->set('link_check_time', (string) time());
        $db = $app->db();
        $newsItem = $db->one('SELECT idc, uvod, text FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND (odkazy_cas IS NULL OR odkazy_cas < NOW() - INTERVAL 30 DAY) ORDER BY odkazy_cas IS NOT NULL, odkazy_cas, datum DESC LIMIT 1');
        if ($newsItem === null) {
            return;
        }
        $db->update('novinky', ['odkazy_cas' => date('Y-m-d H:i:s')], ['idc' => $newsItem['idc']]);
        $db->delete('odkazy_vadne', ['idc' => $newsItem['idc']]);
        $end = microtime(true) + 12; // at most 12 seconds per run
        foreach (self::links($newsItem['uvod'] . $newsItem['text']) as $url) {
            if (microtime(true) > $end) {
                break;
            }
            $state = self::verify($app, $url);
            if ($state !== null) {
                $db->insert('odkazy_vadne', ['idc' => $newsItem['idc'], 'url' => mb_substr($url, 0, 500), 'stav' => $state, 'cas' => date('Y-m-d H:i:s')]);
            }
        }
    }

    /** @return list<string> unique links from the HTML (at most 25) */
    public static function links(string $html): array
    {
        preg_match_all('#<a\b[^>]*\bhref="([^"]+)"#i', $html, $m);
        $links = array_filter(array_map(fn (string $u): string => html_entity_decode(trim($u), ENT_QUOTES | ENT_HTML5), $m[1]), fn (string $u): bool => $u !== '' && !preg_match('#^(mailto:|tel:|\#|javascript:)#i', $u));

        return array_slice(array_values(array_unique($links)), 0, 25);
    }

    /** @return int|null error code (0 = no response), null = the link is OK or cannot be judged */
    public static function verify(App $app, string $url): ?int
    {
        // a link to the site itself: looking into the database is enough
        $custom = $app->request->origin();
        $path = str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : (str_starts_with($url, $custom . '/') ? substr($url, strlen($custom)) : null);
        if ($path !== null) {
            $path = (string) parse_url(substr($path, strlen($app->request->basePath())), PHP_URL_PATH);
            if (preg_match('#^/(?:[a-z]{2}/)?novinky/([a-z0-9-]+)$#', $path, $m)) {
                return $app->db()->value('SELECT idc FROM {novinky} WHERE seo_link = ?', [$m[1]]) === null
                    && $app->db()->value('SELECT idp FROM {presmerovani} WHERE z_adresy = ?', ['novinky/' . $m[1]]) === null ? 404 : null;
            }

            return null; // other URLs of the site itself (categories, files) are not checked
        }
        if (!self::isPublic($url)) {
            return null;
        }
        // the address is resolved once and the connection is pinned to it - it cannot be spoofed between the check and the connection (DNS rebinding)
        $c = parse_url($url);
        $ip = filter_var(trim((string) $c['host'], '[]'), FILTER_VALIDATE_IP) !== false ? null : (gethostbynamel((string) $c['host'])[0] ?? null);
        $port = $c['port'] ?? (strtolower((string) $c['scheme']) === 'https' ? 443 : 80);
        $ch = curl_init($url);
        if ($ip !== null) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$c['host'] . ':' . $port . ':' . $ip]);
        }
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Kaleta kontrola odkazu)',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $code >= 200 && $code < 400 || in_array($code, self::INCONCLUSIVE_CODES, true) ? null : $code;
    }

    /** Only http(s), a standard port and an address that does not lead into an internal network. */
    public static function isPublic(string $url): bool
    {
        $c = parse_url($url);
        if (!is_array($c) || !in_array(strtolower($c['scheme'] ?? ''), ['http', 'https'], true) || empty($c['host']) || (isset($c['port']) && !in_array($c['port'], [80, 443], true))) {
            return false;
        }
        $host = trim($c['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            return true; // a nonexistent domain is not an internal network - the check evaluates it as unreachable
        }
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
