<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Signed link to a draft preview (page or site part) without login – for Claude over MCP and for sharing with a colleague.
 * The key "expiry.signature" is valid only for one target and until it expires; the signature is an HMAC with the installation's
 * secret key (Antispam::key).
 * The preview has noindex not only in meta: a page with the parameter is not cached and search engines do not get it into their index.
 */
final class Preview
{
    public const int MAX_MINUTES = 7 * 24 * 60;

    /** Preview key for the target "stranka:12" or "cast:hlavicka:en", valid for the given number of minutes. */
    public static function key(Db $db, Settings $settings, string $target, int $minutes): string
    {
        $to = time() + 60 * max(5, min(self::MAX_MINUTES, $minutes));

        return $to . '.' . self::signature($db, $settings, $target, $to);
    }

    public static function verify(Db $db, Settings $settings, string $target, string $key): bool
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $key, $m) || (int) $m[1] < time()) {
            return false;
        }

        return hash_equals(self::signature($db, $settings, $target, (int) $m[1]), $m[2]);
    }

    private static function signature(Db $db, Settings $settings, string $target, int $to): string
    {
        return hash_hmac('sha256', 'nahled|' . $target . '|' . $to, (new Antispam($db, $settings))->key());
    }
}
