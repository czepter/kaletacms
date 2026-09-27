<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Animované počítadlo („1 200 spokojených zákazníků“): číslo je v HTML celé (vyhledávače, čtečky, web bez skriptu),
 * web.js ho při objevení na obrazovce jednou napočítá od nuly. Kdo nechce pohyb (nastavení systému), vidí rovnou výsledek.
 */
final class Counter extends Element
{
    public const string TYPE = 'pocitadlo';
    public const string NAME = 'Počítadlo';
    public const string DESCRIPTION = 'Velké číslo s popiskem, které se při zobrazení napočítá (roky praxe, zákazníci, projekty).';
    public const string ICON = 'pocitadlo';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'cislo' => ['typ' => 'cislo', 'popisek' => 'Číslo', 'vychozi' => 1200, 'min' => 0, 'max' => 999999999],
            'pred' => ['typ' => 'text', 'popisek' => 'Před číslem (např. „+“)', 'vychozi' => '', 'max' => 10],
            'za' => ['typ' => 'text', 'popisek' => 'Za číslem (např. „ %“, „+“, „ let“)', 'vychozi' => '+', 'max' => 20],
            'popisek' => ['typ' => 'text', 'popisek' => 'Popisek', 'vychozi' => t('spokojených zákazníků'), 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-pocitadlo { display: grid; gap: var(--ka-mezera-2xs); }
.ka-pocitadlo-cislo { font: 800 var(--ka-krok-5)/1 var(--ka-pismo-titulky); font-variant-numeric: tabular-nums; color: var(--ka-barva-primarni); }
.ka-pocitadlo-popisek { color: var(--ka-barva-tlumeny); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $number = (int) $o['cislo'];
        $format = format_count($number); // 1 200 česky, 1,200 anglicky (jako Intl.NumberFormat v image/web.js)

        return '<div' . Text::withClass($a, 'ka-pocitadlo') . '><span class="ka-pocitadlo-cislo">' . e($o['pred'])
            . '<span data-pocitadlo="' . $number . '">' . $format . '</span>' . e($o['za']) . '</span>'
            . ($o['popisek'] !== '' ? '<span class="ka-pocitadlo-popisek">' . e($o['popisek']) . '</span>' : '') . '</div>';
    }
}
