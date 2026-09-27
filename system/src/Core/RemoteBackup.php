<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Kopie zálohy databáze mimo server: FTP/FTPS (jiný hosting, NAS) nebo úložiště kompatibilní s S3
 * (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2…). Záloha na stejném disku jako web nechrání před
 * ztrátou hostingu. Bez knihoven: FTP přes rozšíření PHP, S3 přes cURL s podpisem AWS Signature V4.
 */
final class RemoteBackup
{
    /** @return string|null text chyby, null = nahráno (nebo je vzdálené zálohování vypnuté) */
    public static function upload(Settings $s, string $path): ?string
    {
        $mode = $s->get('zaloha_vzdalena');
        if (!in_array($mode, ['ftp', 's3'], true) || $s->get('zaloha_host') === '') {
            return null;
        }
        try {
            $mode === 'ftp' ? self::ftp($s, $path) : self::s3($s, $path);
            $error = null;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $s->set('zaloha_vzdalena_stav', date('Y-m-d H:i') . '|' . ($error ?? 'ok'));

        return $error;
    }

    private static function ftp(Settings $s, string $path): void
    {
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('Na serveru chybí rozšíření PHP pro FTP.');
        }
        $host = $s->get('zaloha_host');
        // jen šifrované FTPS: záloha obsahuje hesla a tajné klíče, po nešifrovaném FTP by šly sítí čitelně (i heslo k FTP)
        if (!function_exists('ftp_ssl_connect')) {
            throw new \RuntimeException('Server neumí šifrované FTP (FTPS). Zálohu posílejte do úložiště S3, nebo si ji stahujte ručně.');
        }
        $connection = @ftp_ssl_connect($host, 21, 15);
        if ($connection === false) {
            throw new \RuntimeException('FTP server ' . $host . ' nepodporuje šifrované spojení (FTPS). Nešifrované FTP Kaleta nepoužívá – zvolte úložiště S3.');
        }
        if (!@ftp_login($connection, $s->get('zaloha_uzivatel'), $s->get('zaloha_heslo'))) {
            throw new \RuntimeException('K FTP serveru ' . $host . ' se nepodařilo přihlásit.');
        }
        ftp_pasv($connection, true);
        $folder = trim($s->get('zaloha_slozka'), '/');
        if ($folder !== '' && !@ftp_chdir($connection, '/' . $folder)) {
            @ftp_mkdir($connection, '/' . $folder);
            if (!@ftp_chdir($connection, '/' . $folder)) {
                throw new \RuntimeException('Složka ' . $folder . ' na FTP serveru neexistuje a nejde vytvořit.');
            }
        }
        $ok = @ftp_put($connection, basename($path), $path, FTP_BINARY);
        ftp_close($connection);
        if (!$ok) {
            throw new \RuntimeException('Soubor se na FTP server nepodařilo nahrát.');
        }
    }

    private static function s3(Settings $s, string $path): void
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Na serveru chybí rozšíření cURL.');
        }
        $host = preg_replace('#^https?://|/.*$#', '', $s->get('zaloha_host')) ?? '';
        $bucket = trim($s->get('zaloha_slozka'), '/');
        if ($bucket === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new \RuntimeException('Vyplňte adresu úložiště (např. s3.eu-central-1.amazonaws.com) a název bucketu.');
        }
        $uri = '/' . implode('/', array_map(rawurlencode(...), explode('/', $bucket . '/' . basename($path))));
        $headers = self::signS3('PUT', $host, $uri, hash_file('sha256', $path), $s->get('zaloha_region') ?: 'us-east-1', $s->get('zaloha_uzivatel'), $s->get('zaloha_heslo'), time());
        $f = fopen($path, 'rb');
        $ch = curl_init('https://' . $host . $uri);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true, CURLOPT_INFILE => $f, CURLOPT_INFILESIZE => filesize($path), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers),
        ]);
        $response = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        fclose($f);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Úložiště nahrání odmítlo (kód ' . $code . ')' . (preg_match('#<Message>([^<]+)#', $response, $m) ? ': ' . $m[1] : ($code === 0 ? ': nepodařilo se připojit' : '')) . '.');
        }
    }

    /**
     * Hlavičky požadavku podepsané AWS Signature Version 4 (služba s3).
     *
     * @return array<string, string>
     */
    public static function signS3(string $method, string $host, string $uri, string $bodyHash, string $region, string $key, string $secret, int $time): array
    {
        $date = gmdate('Ymd\THis\Z', $time);
        $day = substr($date, 0, 8);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $bodyHash, 'x-amz-date' => $date];
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = $method . "\n" . $uri . "\n\n" . implode('', array_map(fn (string $k, string $v): string => $k . ':' . $v . "\n", array_keys($headers), $headers)) . "\n" . $signedHeaders . "\n" . $bodyHash;
        $scope = $day . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4' . $secret, true), true), true), true);

        return [
            'Host' => $host, 'x-amz-content-sha256' => $bodyHash, 'x-amz-date' => $date,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $key . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . hash_hmac('sha256', $stringToSign, $k),
        ];
    }
}
