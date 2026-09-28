<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Icons;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Icon from the built-in set (Builder\Icons) as inline SVG: color = the element's text color, size = the font size.
 * Without a description it is decorative (screen readers skip it), with a description they read it.
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
        // default size and color (the element's style overrides them); an element inserted via AI or MCP thus looks the same as from the editor
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
