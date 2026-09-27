<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Hodnocení hvězdičkami („4,8 z 5 · 120 recenzí“). Hvězdy jsou jedno SVG s oříznutou výplní (i půl hvězdy),
 * čtečka přečte větu z aria-label. Nic se nepočítá – hodnotu zadává správce podle skutečných recenzí.
 */
final class Rating extends Element
{
    public const string TYPE = 'hodnoceni';
    public const string NAME = 'Hodnocení';
    public const string DESCRIPTION = 'Hvězdičky s hodnotou a počtem recenzí (např. z Google).';
    public const string ICON = 'hvezda';
    public const array HTML_TAGS = ['div', 'p'];

    public static function properties(): array
    {
        return [
            'hodnota' => ['typ' => 'text', 'popisek' => 'Hodnocení (0–5, např. 4,8)', 'vychozi' => '4,8', 'max' => 4],
            'text' => ['typ' => 'text', 'popisek' => 'Text vedle hvězdiček', 'vychozi' => t('z 5 · 120 recenzí'), 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-hodnoceni { display: flex; flex-wrap: wrap; align-items: center; gap: var(--ka-mezera-xs); }
.ka-hodnoceni svg { width: 6.5em; height: 1.3em; flex: none; }
.ka-hodnoceni-plne { fill: #f5a524; }
.ka-hodnoceni-prazdne { fill: var(--ka-barva-linka); }
.ka-hodnoceni strong { font-variant-numeric: tabular-nums; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $value = max(0.0, min(5.0, (float) str_replace(',', '.', (string) $o['hodnota'])));
        // hvězdy vedle sebe po 26 jednotkách; výplň se ořízne na podíl hodnoty
        $stars = '';
        for ($i = 0; $i < 5; $i++) {
            $x = $i * 26;
            $stars .= '<path d="M' . ($x + 12) . ' 1.5l3.1 6.6 7.2.9-5.3 5 1.4 7.1-6.4-3.4-6.4 3.4 1.4-7.1-5.3-5 7.2-.9z"/>';
        }
        $width = round($value / 5 * 128, 2);
        $number = rtrim(rtrim(format_number($value), '0'), ',.');
        $svg = '<svg viewBox="0 0 128 24" aria-hidden="true" focusable="false"><defs><clipPath id="hv-' . e($p['id']) . '"><rect width="' . $width . '" height="24"/></clipPath></defs>'
            . '<g class="ka-hodnoceni-prazdne">' . $stars . '</g><g class="ka-hodnoceni-plne" clip-path="url(#hv-' . e($p['id']) . ')">' . $stars . '</g></svg>';

        return '<' . $p['znacka'] . Text::withClass($a, 'ka-hodnoceni') . ' role="img" aria-label="' . e(t('Hodnocení %s z 5', $number) . ($o['text'] !== '' ? ' – ' . $o['text'] : '')) . '">'
            . $svg . '<strong aria-hidden="true">' . e($number) . '</strong>' . ($o['text'] !== '' ? '<span aria-hidden="true">' . e($o['text']) . '</span>' : '') . '</' . $p['znacka'] . '>';
    }
}
