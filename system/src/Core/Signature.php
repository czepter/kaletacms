<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Ověřování podpisů vydavatele (Ed25519). Soubor system/aktualizace.pub smí nést VÍC veřejných klíčů - na každém řádku jeden
 * (base64, za mezerou volitelný popis, řádky s # jsou poznámky). Podpis platí, když sedí na kterýkoli z nich.
 *
 * Proč víc klíčů: vedle provozního klíče existuje záložní, který leží offline a nepoužívá se. Při ztrátě provozního klíče
 * se jím podepíše vydání s novým provozním klíčem; při úniku vydání, které kompromitovaný klíč ze souboru odstraní.
 * Postup je v docs/RELEASING.md.
 */
final class Signature
{
    /** @return array<string, string> identifikátor klíče => veřejný klíč (binárně) */
    public static function keys(string $file): array
    {
        $keys = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $row) {
            $row = trim($row);
            if ($row === '' || $row[0] === '#') {
                continue;
            }
            $key = base64_decode((string) strtok($row, " \t"), true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[self::id($key)] = $key;
            }
        }

        return $keys;
    }

    /** Krátký identifikátor klíče (prvních 8 znaků otisku) - do manifestu a do dokumentace, ať je jasné, čím se podepisovalo. */
    public static function id(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 8);
    }

    /** Platí podpis (base64) zprávy vůči některému z klíčů v souboru? */
    public static function isValid(string $message, string $signatureBase64, string $file): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        foreach (self::keys($file) as $key) {
            if (sodium_crypto_sign_verify_detached($signature, $message, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Co přesně se u balíčku podepisuje: verze, otisk ZIPu i příznak bezpečnostního vydání (to se instaluje samo). */
    public static function packageMessage(string $version, string $sha256, bool $securityRelease): string
    {
        return $version . '|' . strtolower($sha256) . '|' . ($securityRelease ? 'bezpecnostni' : 'bezne');
    }
}
