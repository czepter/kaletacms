<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Kontrola nefunkčních odkazů ve vydaných novinkách. Běží na pozadí po malých dávkách: jednu novinku za pět minut,
 * každou novinku jednou za 30 dní. Ukládají se jen odkazy, které nefungují (Novinky → Nefunkční odkazy).
 *
 * Server se při kontrole připojuje na adresy z novinek, proto jen http(s) na veřejné adresy a standardní porty,
 * bez následování přesměrování - odkaz v novince nesmí jít zneužít k ohledávání vnitřní sítě hostingu.
 */
final class Links
{
    /** Kódy, které neznamenají rozbitý odkaz: weby jimi jen odmítají roboty nebo metodu HEAD. */
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
        $end = microtime(true) + 12; // na jeden běh nejvýš 12 vteřin
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

    /** @return list<string> jedinečné odkazy z HTML (nejvýš 25) */
    public static function links(string $html): array
    {
        preg_match_all('#<a\b[^>]*\bhref="([^"]+)"#i', $html, $m);
        $links = array_filter(array_map(fn (string $u): string => html_entity_decode(trim($u), ENT_QUOTES | ENT_HTML5), $m[1]), fn (string $u): bool => $u !== '' && !preg_match('#^(mailto:|tel:|\#|javascript:)#i', $u));

        return array_slice(array_values(array_unique($links)), 0, 25);
    }

    /** @return int|null kód chyby (0 = bez odpovědi), null = odkaz je v pořádku nebo ho nejde posoudit */
    public static function verify(App $app, string $url): ?int
    {
        // odkaz na vlastní web: stačí se podívat do databáze
        $custom = $app->request->origin();
        $path = str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : (str_starts_with($url, $custom . '/') ? substr($url, strlen($custom)) : null);
        if ($path !== null) {
            $path = (string) parse_url(substr($path, strlen($app->request->basePath())), PHP_URL_PATH);
            if (preg_match('#^/(?:[a-z]{2}/)?novinky/([a-z0-9-]+)$#', $path, $m)) {
                return $app->db()->value('SELECT idc FROM {novinky} WHERE seo_link = ?', [$m[1]]) === null
                    && $app->db()->value('SELECT idp FROM {presmerovani} WHERE z_adresy = ?', ['novinky/' . $m[1]]) === null ? 404 : null;
            }

            return null; // ostatní vlastní adresy (rubriky, soubory) se neověřují
        }
        if (!self::isPublic($url)) {
            return null;
        }
        // adresa se přeloží jednou a spojení se na ni připne - mezi kontrolou a připojením ji nejde podvrhnout (DNS rebinding)
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

    /** Jen http(s), standardní port a adresa, která nevede do vnitřní sítě. */
    public static function isPublic(string $url): bool
    {
        $c = parse_url($url);
        if (!is_array($c) || !in_array(strtolower($c['scheme'] ?? ''), ['http', 'https'], true) || empty($c['host']) || (isset($c['port']) && !in_array($c['port'], [80, 443], true))) {
            return false;
        }
        $host = trim($c['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            return true; // neexistující doména není vnitřní síť - kontrola ji vyhodnotí jako nedostupnou
        }
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
