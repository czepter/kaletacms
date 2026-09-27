<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Podepsaný odkaz na náhled konceptu (stránka nebo část webu) bez přihlášení – pro Clauda přes MCP a pro sdílení s kolegou.
 * Klíč „platnost.podpis“ platí jen pro jeden cíl a do vypršení; podpis je HMAC tajným klíčem instalace (Antispam::klic).
 * Náhled nemá noindex jen v meta: stránka s parametrem se neukládá do mezipaměti a vyhledávače ji nedostanou do indexu.
 */
final class Preview
{
    public const int MAX_MINUTES = 7 * 24 * 60;

    /** Klíč náhledu pro cíl „stranka:12“ nebo „cast:hlavicka:en“ platný zadaný počet minut. */
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
