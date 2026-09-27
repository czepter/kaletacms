<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Stahování obrázků ze starého webu při importu z WordPressu.
 *
 * Adresy obrázků pocházejí z importovaného souboru, tedy z nedůvěryhodného vstupu. Kdyby server stahoval cokoli, co soubor řekne,
 * šlo by ho zneužít k ohledávání vnitřní sítě hostingu (útok SSRF). Proto platí bez výjimky:
 *  1. jen http a https, jen doména starého webu (nebo její varianta s „www.“), jen výchozí port, žádné jméno a heslo v adrese;
 *  2. doména se přeloží na IP adresy a VŠECHNY musí být veřejné (ne 10.x, 192.168.x, 127.x, 169.254.x, 100.64.x, ::1, fc00::/7…);
 *     spojení se pak připne na ověřenou adresu, aby ji mezi kontrolou a stažením nešlo vyměnit (DNS rebinding);
 *  3. přesměrování nejvýš 3, nikdy automaticky – každý krok projde znovu body 1 a 2;
 *  4. spojení do 5 s, celé stažení do 20 s, nejvýš 15 MB (hlídá se už při čtení);
 *  5. přijme se jen JPEG, PNG, GIF a WebP – podle hlavičky odpovědi I podle skutečného obsahu. SVG nikdy;
 *  6. neposílají se cookies, přihlašovací údaje ani hlavičky z importu; prohlížeč se hlásí jako „Kaleta-import“.
 * Stažená data jdou dál jen přes Core\Obrazky, který obrázek znovu zakóduje.
 */
final class ImageDownloader
{
    public const int MAX_BYTES = 15 * 1024 * 1024;
    public const int MAX_REDIRECTS = 3;
    public const int CONNECT_TIMEOUT = 5;
    public const int TOTAL_TIMEOUT = 20;
    private const string USER_AGENT = 'Kaleta-import';
    private const array TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Rozsahy, kam se server nikdy nepřipojí: vnitřní sítě, smyčka, link-local, CGNAT, multicast, vyhrazené a dokumentační adresy
     * a také přechodové IPv6 rozsahy, do kterých jde zabalit vnitřní IPv4 adresa (::a.b.c.d, NAT64, Teredo, 6to4).
     */
    private const array BLOCKED_NETWORKS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** Doména starého webu malými písmeny a bez „www.“. */
    private readonly string $domain;

    /** @param string $siteUrl adresa starého webu z importovaného souboru (<channel><link>) */
    public function __construct(string $siteUrl)
    {
        $this->domain = self::domainFromUrl($siteUrl);
    }

    /** Umí server vůbec stahovat? Bez curl i bez allow_url_fopen je potřeba obrázky přenést ručně. */
    public static function isAvailable(): bool
    {
        return function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Doména z adresy: malá písmena, bez „www.“ a bez tečky na konci; prázdný řetězec = adresa není http(s). */
    public static function domainFromUrl(string $url): string
    {
        $c = parse_url(trim($url));
        if (!is_array($c) || !in_array(strtolower($c['scheme'] ?? ''), ['http', 'https'], true)) {
            return '';
        }
        $host = rtrim(strtolower(trim((string) ($c['host'] ?? ''), '[]')), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    /** Bod 1: smí se tahle adresa vůbec zkusit? Čistá funkce – nic nepřekládá ani nestahuje. */
    public function isAllowedUrl(string $url): bool
    {
        if ($this->domain === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return false;
        }
        $c = parse_url($url);
        if (!is_array($c) || isset($c['user']) || isset($c['pass'])) {
            return false;
        }
        $schema = strtolower($c['scheme'] ?? '');
        if (!in_array($schema, ['http', 'https'], true) || (isset($c['port']) && $c['port'] !== ($schema === 'https' ? 443 : 80))) {
            return false;
        }

        return self::domainFromUrl($url) === $this->domain;
    }

    /** Bod 2: je IP adresa veřejná? IPv4 zabalená v IPv6 (::ffff:10.0.0.1) se posuzuje jako IPv4. */
    public static function isPublicIp(string $ip): bool
    {
        $binary = @inet_pton(trim($ip, '[]'));
        if ($binary === false) {
            return false;
        }
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            $binary = substr($binary, 12); // ::ffff:a.b.c.d
        }
        foreach (self::BLOCKED_NETWORKS as $network) {
            [$url, $mask] = explode('/', $network);
            $networkBinary = (string) inet_pton($url);
            if (strlen($networkBinary) === strlen($binary) && self::isInNetwork($binary, $networkBinary, (int) $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bod 5: typ obrázku podle hlavičky Content-Type A ZÁROVEŇ podle skutečných bajtů; null = odmítnuto.
     * Obojí musí být na seznamu povolených (SVG, HTML ani nic jiného neprojde, ať se tváří jakkoli).
     */
    public static function imageType(string $contentTypeHeader, string $data): ?string
    {
        $fromHeader = strtolower(trim(explode(';', $contentTypeHeader)[0]));
        $info = $data === '' ? false : @getimagesizefromstring($data);
        $fromContent = $info === false ? '' : (string) $info['mime'];

        return in_array($fromHeader, self::TYPES, true) && in_array($fromContent, self::TYPES, true) ? $fromContent : null;
    }

    /** Cíl přesměrování: hlavička Location smí být i relativní (/jinam/foto.jpg). */
    public static function redirectTarget(string $fromUrl, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $c = parse_url($fromUrl);
        $root = ($c['scheme'] ?? 'http') . '://' . ($c['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return ($c['scheme'] ?? 'http') . ':' . $location;
        }

        return str_starts_with($location, '/') ? $root . $location : $root . rtrim(dirname(($c['path'] ?? '/') . 'x'), '/') . '/' . $location;
    }

    /**
     * Přeloží doménu a vrátí IP adresu, na kterou se smí připojit; null = doména neexistuje nebo některá z adres není veřejná.
     */
    public function verifiedIp(string $host): ?string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? $host : null;
        }
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $addresses[] = (string) ($record['ipv6'] ?? '');
        }
        $addresses = array_values(array_filter($addresses));
        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                return null; // stačí jediná vnitřní adresa a doména je podezřelá celá
            }
        }

        return $addresses[0] ?? null;
    }

    /**
     * Stáhne obrázek a vrátí jeho obsah. S $jenObrazky = false i jiný soubor (písmo, PDF pro MCP) – jeho typ pak ověří
     * až ukládání (Core\Soubory: povolené přípony a skutečný obsah, SVG vyčistí Core\Svg); ostatní pravidla platí stejně.
     *
     * @throws \RuntimeException s důvodem, proč obrázek stáhnout nejde
     */
    public function download(string $url, bool $imagesOnly = true): string
    {
        for ($step = 0; $step <= self::MAX_REDIRECTS; $step++) {
            if (!$this->isAllowedUrl($url)) {
                throw new \RuntimeException('Adresa nepatří starému webu.');
            }
            $ip = $this->verifiedIp((string) parse_url($url, PHP_URL_HOST));
            if ($ip === null) {
                throw new \RuntimeException('Doména starého webu neexistuje nebo vede do vnitřní sítě.');
            }
            $response = function_exists('curl_init') ? $this->curlRequest($url, $ip) : $this->streamRequest($url, $ip);
            if (in_array($response['kod'], [301, 302, 303, 307, 308], true) && $response['location'] !== '') {
                $url = self::redirectTarget($url, $response['location']);
                continue;
            }
            if ($response['kod'] !== 200) {
                throw new \RuntimeException('Starý web obrázek nevydal, odpověděl chybou', $response['kod']); // kód odpovědi nese getCode()
            }
            if ($imagesOnly && self::imageType($response['typ'], $response['data']) === null) {
                throw new \RuntimeException('Soubor není obrázek JPG, PNG, GIF ani WebP.');
            }

            return $response['data'];
        }
        throw new \RuntimeException('Příliš mnoho přesměrování.');
    }

    /**
     * Jeden požadavek přes curl; spojení je připnuté na ověřenou IP adresu (CURLOPT_RESOLVE).
     *
     * @return array{kod:int, typ:string, location:string, data:string}
     */
    private function curlRequest(string $url, string $ip): array
    {
        $c = parse_url($url);
        $port = strtolower((string) $c['scheme']) === 'https' ? 443 : 80;
        $data = '';
        $headers = ['content-type' => '', 'location' => ''];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$c['host'] . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false, // přesměrování si hlídáme sami, krok po kroku
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HEADERFUNCTION => function ($ch, string $row) use (&$headers): int {
                $parts = explode(':', $row, 2);
                if (count($parts) === 2 && isset($headers[strtolower(trim($parts[0]))])) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($row);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$data): int {
                $data .= $chunk;

                return strlen($data) > self::MAX_BYTES ? -1 : strlen($chunk); // jiná hodnota než délka = curl stahování ukončí
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        if (strlen($data) > self::MAX_BYTES || $error === CURLE_FILESIZE_EXCEEDED) {
            throw new \RuntimeException('Obrázek je větší než 15 MB.');
        }
        if ($error !== 0) {
            throw new \RuntimeException('Starý web neodpovídá.');
        }

        return ['kod' => $code, 'typ' => $headers['content-type'], 'location' => $headers['location'], 'data' => $data];
    }

    /**
     * Totéž bez curl (allow_url_fopen). Připojuje se přímo na ověřenou IP adresu; doména jde v hlavičce Host a do ověření certifikátu.
     *
     * @return array{kod:int, typ:string, location:string, data:string}
     */
    private function streamRequest(string $url, string $ip): array
    {
        $c = parse_url($url);
        $host = (string) $c['host'];
        $target = $c['scheme'] . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ($c['path'] ?? '/') . (isset($c['query']) ? '?' . $c['query'] : '');
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => self::CONNECT_TIMEOUT, 'ignore_errors' => true,
                'user_agent' => self::USER_AGENT, 'header' => 'Host: ' . $host . "\r\nConnection: close\r\n"],
            'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($target, 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('Starý web neodpovídá.');
        }
        $end = microtime(true) + self::TOTAL_TIMEOUT;
        $data = '';
        while (!feof($stream)) {
            $data .= (string) fread($stream, 65536);
            if (strlen($data) > self::MAX_BYTES) {
                fclose($stream);
                throw new \RuntimeException('Obrázek je větší než 15 MB.');
            }
            if (microtime(true) > $end) {
                fclose($stream);
                throw new \RuntimeException('Starý web odpovídá příliš pomalu.');
            }
        }
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        fclose($stream);
        $response = ['kod' => 0, 'typ' => '', 'location' => '', 'data' => $data];
        foreach ($headers as $row) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $row, $m)) {
                $response['kod'] = (int) $m[1];
            } elseif (preg_match('#^(content-type|location):\s*(.*)$#i', (string) $row, $m)) {
                $response[strtolower($m[1]) === 'location' ? 'location' : 'typ'] = trim($m[2]);
            }
        }

        return $response;
    }

    /** Leží adresa v síti? Porovnává se prvních $maska bitů. */
    private static function isInNetwork(string $ip, string $network, int $mask): bool
    {
        $byteCount = intdiv($mask, 8);
        $bitCount = $mask % 8;
        if (substr($ip, 0, $byteCount) !== substr($network, 0, $byteCount)) {
            return false;
        }

        return $bitCount === 0 || ((ord($ip[$byteCount]) ^ ord($network[$byteCount])) & (0xFF << (8 - $bitCount) & 0xFF)) === 0;
    }
}
