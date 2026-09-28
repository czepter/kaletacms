<?php

declare(strict_types=1);

namespace Kaleta\Front;

/**
 * Overview of layouts (site appearance templates) in the layout/ folder.
 * A layout can introduce itself with an info.php file: return ['nazev' => ..., 'popis' => ...];
 */
final class Layouts
{
    /** Layout of a new installation and the fallback when the configured layout is missing from the layout/ folder. */
    public const string DEFAULTS = 'zakladni';

    /** @return array<string, array{nazev:string, popis:string}> folder => information, default layout first */
    public static function listAll(): array
    {
        $layouts = [];
        foreach (glob(KALETA_ROOT . '/layout/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $folder = basename($dir);
            if (!preg_match('/^[a-z0-9_-]+$/i', $folder) || !is_file($dir . '/base.php')) {
                continue;
            }
            $info = is_file($dir . '/info.php') ? (array) require $dir . '/info.php' : [];
            $layouts[$folder] = ['nazev' => (string) ($info['nazev'] ?? $folder), 'popis' => (string) ($info['popis'] ?? '')];
        }
        uksort($layouts, fn (string $a, string $b): int => [$a !== self::DEFAULTS, $a] <=> [$b !== self::DEFAULTS, $b]);

        return $layouts;
    }
}
