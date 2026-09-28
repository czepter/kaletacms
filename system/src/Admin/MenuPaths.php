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
        [['Nastavení', 'Zálohy a aktualizace'], 'settings', 'module=settings&tab=backups'],
        [['Nastavení', 'Stav systému'], 'settings', 'module=settings&tab=health'],
        [['Nastavení', 'Soukromí a cookies'], 'settings', 'module=settings&tab=cookies'],
        [['Nastavení', 'SEO a GEO'], 'settings', 'module=settings&tab=seo'],
        [['Nastavení', 'Základní'], 'settings', 'module=settings&tab=general'],
        [['Nastavení', 'Měření'], 'settings', 'module=settings&tab=analytics'],
        [['Nastavení', 'Pošta'], 'settings', 'module=settings&tab=mail'],
        [['Vzhled', 'Vzhled webu'], 'appearance', 'module=appearance'],
        [['Vzhled', 'Menu'], 'menu', 'module=menu'],
        [['Zálohy a aktualizace'], 'settings', 'module=settings&tab=backups'],
        [['Novinky', 'Koš'], 'news', 'module=news&stav=kos'],
        [['Vzhled webu'], 'appearance', 'module=appearance'],
        [['Stav systému'], 'settings', 'module=settings&tab=health'],
        [['Můj účet'], '', 'action=account'],
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
