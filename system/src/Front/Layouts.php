<?php

declare(strict_types=1);

namespace Kaleta\Front;

/**
 * Přehled layoutů (šablon vzhledu webu) ve složce layout/.
 * Layout se může představit souborem info.php: return ['nazev' => ..., 'popis' => ...];
 */
final class Layouts
{
    /** Šablona nové instalace a náhrada, když nastavená šablona ve složce layout/ chybí. */
    public const string DEFAULTS = 'zakladni';

    /** @return array<string, array{nazev:string, popis:string}> složka => informace, výchozí layout první */
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
