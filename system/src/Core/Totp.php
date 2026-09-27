<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Jednorázové kódy pro dvoufázové přihlášení (TOTP, RFC 6238) - kompatibilní s Google Authenticatorem,
 * Microsoft Authenticatorem, 1Password, Aegis a dalšími. Bez knihoven.
 */
final class Totp
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $secret = '';
        foreach (str_split(random_bytes(32)) as $byte) { // 32 znaků = 160 bitů, jak doporučuje RFC 4226
            $secret .= self::ALPHABET[ord($byte) % 32];
        }

        return $secret;
    }

    /** Ověří šestimístný kód; toleruje posun hodin o jeden 30vteřinový krok. */
    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $step = intdiv($time ?? time(), 30);
        foreach ([0, -1, 1] as $offset) {
            if (hash_equals(self::code($secret, $step + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function code(string $secret, int $step): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), self::base32($secret), true);
        $from = ord($hmac[19]) & 0x0F;
        $number = ((ord($hmac[$from]) & 0x7F) << 24) | (ord($hmac[$from + 1]) << 16) | (ord($hmac[$from + 2]) << 8) | ord($hmac[$from + 3]);

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Adresa pro aplikaci (většina ji umí otevřít přímo z odkazu v mobilu). */
    public static function uri(string $secret, string $account, string $siteSettings): string
    {
        return 'otpauth://totp/' . rawurlencode($siteSettings . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($siteSettings) . '&digits=6&period=30';
    }

    /**
     * Osm jednorázových záložních kódů pro případ ztráty telefonu.
     *
     * @return array{0: list<string>, 1: string} čitelné kódy a JSON otisků k uložení
     */
    public static function backupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5);
        }

        return [$codes, (string) json_encode(array_map(fn (string $k): string => hash('sha256', $k), $codes))];
    }

    /** Spotřebuje záložní kód; vrací nový JSON otisků, nebo null když kód neplatí. */
    public static function useBackupCode(?string $json, string $code): ?string
    {
        $hashes = json_decode((string) $json, true);
        $needle = hash('sha256', strtolower(trim($code)));
        if (!is_array($hashes) || !in_array($needle, $hashes, true)) {
            return null;
        }

        return (string) json_encode(array_values(array_diff($hashes, [$needle])));
    }

    private static function base32(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper($text)) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) === 8) {
                $bytes .= chr((int) bindec($octet));
            }
        }

        return $bytes;
    }
}
