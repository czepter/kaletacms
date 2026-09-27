<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Icons;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Ikona z vestavěné sady (Stavitel\Ikony) jako vložené SVG: barva = barva textu prvku, velikost = velikost písma.
 * Bez popisu je dekorativní (čtečky ji přeskočí), s popisem ji přečtou.
 */
final class Icon extends Element
{
    public const string TYPE = 'ikona';
    public const string NAME = 'Ikona';
    public const string DESCRIPTION = 'Jednoduchá ikona (fajfka, telefon, hvězda…) – barvu a velikost nastavíte stylem.';
    public const string ICON = 'ikona';
    public const array HTML_TAGS = ['span', 'div'];

    public static function properties(): array
    {
        return [
            'ikona' => ['typ' => 'vyber', 'popisek' => 'Ikona', 'vychozi' => 'fajfka-kruh', 'moznosti' => Icons::options()],
            'tvar' => ['typ' => 'vyber', 'popisek' => 'Podklad', 'vychozi' => '', 'moznosti' => ['' => 'bez podkladu', 'kruh' => 'kruh', 'ctverec' => 'zaoblený čtverec']],
            'popis' => ['typ' => 'text', 'popisek' => 'Popis pro čtečky (prázdné = jen ozdoba)', 'vychozi' => '', 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        // výchozí velikost a barva (styl prvku je přepíše); prvek vložený přes AI nebo MCP tak vypadá stejně jako z editoru
        return '.ka-ikona { display: inline-grid; place-items: center; flex: none; width: 1em; height: 1em; line-height: 1; font-size: var(--ka-krok-3); color: var(--ka-barva-primarni); }
.ka-ikona svg { display: block; width: 100%; height: 100%; }
.ka-ikona--kruh, .ka-ikona--ctverec { width: 1.9em; height: 1.9em; padding: 0.45em; background: var(--ka-barva-primarni-jemna); }
.ka-ikona--kruh { border-radius: 50%; }
.ka-ikona--ctverec { border-radius: var(--ka-zaobleni-m); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $className = 'ka-ikona' . ($o['tvar'] !== '' ? ' ka-ikona--' . $o['tvar'] : '');
        $description = $o['popis'] !== '' ? ' role="img" aria-label="' . e($o['popis']) . '"' : ' aria-hidden="true"';

        return '<' . $p['znacka'] . Text::withClass($a, $className) . $description . '>' . Icons::svg($o['ikona']) . '</' . $p['znacka'] . '>';
    }
}
