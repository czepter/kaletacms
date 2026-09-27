<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * Cesty v nabídce jako odkazy. Hlášky, Stav systému a nápovědy u polí říkají „Nastavení → Zálohy a aktualizace“ -
 * místo aby uživatel cestu hledal v nabídce, stane se z ní odkaz. Texty zůstávají obyčejné věty (a obyčejné klíče
 * slovníku); odkaz doplní až vykreslení, v jazyce, ve kterém se text právě zobrazuje.
 */
final class MenuPaths
{
    /**
     * Známé cesty: části cesty tak, jak stojí v nabídce (česky = klíče slovníku) => [modul potřebný k přístupu, dotaz adresy].
     * Víceslovné cesty mají přednost před kratšími (řadí se podle délky).
     *
     * @var list<array{0: list<string>, 1: string, 2: string}>
     */
    private const array PATHS = [
        [['Nastavení', 'Zálohy a aktualizace'], 'config', 'modul=config&zalozka=zalohy'],
        [['Nastavení', 'Stav systému'], 'config', 'modul=config&zalozka=stav'],
        [['Nastavení', 'Soukromí a cookies'], 'config', 'modul=config&zalozka=cookies'],
        [['Nastavení', 'SEO a GEO'], 'config', 'modul=config&zalozka=seo'],
        [['Nastavení', 'Základní'], 'config', 'modul=config&zalozka=zakladni'],
        [['Nastavení', 'Měření'], 'config', 'modul=config&zalozka=mereni'],
        [['Nastavení', 'Pošta'], 'config', 'modul=config&zalozka=posta'],
        [['Vzhled', 'Vzhled webu'], 'vzhled', 'modul=vzhled'],
        [['Vzhled', 'Menu'], 'menu', 'modul=menu'],
        [['Zálohy a aktualizace'], 'config', 'modul=config&zalozka=zalohy'],
        [['Novinky', 'Koš'], 'novinky', 'modul=novinky&stav=kos'],
        [['Vzhled webu'], 'vzhled', 'modul=vzhled'],
        [['Stav systému'], 'config', 'modul=config&zalozka=stav'],
        [['Můj účet'], '', 'akce=ucet'],
    ];

    /**
     * Vrátí text připravený do HTML (escapovaný) se známými cestami proměněnými v odkazy.
     *
     * @param string $adminUrl adresa admin.php (např. $app->url('admin.php'))
     * @param list<string> $modules identifikátory modulů, do kterých přihlášený smí - jinam se neodkazuje
     */
    public static function links(string $adminUrl, string $text, array $modules): string
    {
        $html = e($text);
        $replacements = [];
        foreach (self::PATHS as [$parts, $module, $query]) {
            if ($module !== '' && !in_array($module, $modules, true)) {
                continue;
            }
            $phrase = e(implode(' → ', array_map(static fn (string $c): string => t($c), $parts)));
            $replacements[$phrase] = '<a href="' . e($adminUrl . '?' . $query) . '">' . $phrase . '</a>';
        }
        uksort($replacements, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        // jedním průchodem: delší fráze vyhrává a už vložený odkaz se znovu nepřepisuje
        $pattern = '/' . implode('|', array_map(static fn (string $f): string => preg_quote($f, '/'), array_keys($replacements))) . '/u';

        return $replacements === [] ? $html : (string) preg_replace_callback($pattern, static fn (array $m): string => $replacements[$m[0]], $html);
    }
}
