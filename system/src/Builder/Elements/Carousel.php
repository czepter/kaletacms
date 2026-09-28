<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;
use Kaleta\Builder\Build;

/**
 * Carousel: a horizontal strip of slides (nested elements) with scroll snapping – with a finger on a phone, with arrows on a desktop
 * (image/web.js). The strip can be scrolled without the script too. Nothing scrolls by itself – automatic rotation hurts reading and accessibility.
 */
final class Carousel extends Element
{
    public const string TYPE = 'karusel';
    public const string NAME = 'Karusel';
    public const string DESCRIPTION = 'Snímky vedle sebe s posunem šipkami nebo prstem – reference, fotky, karty.';
    public const string ICON = 'karusel';
    public const string GROUP = 'Rozložení';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'section'];

    public static function properties(): array
    {
        return [
            'naraz' => ['typ' => 'vyber', 'popisek' => 'Snímků vedle sebe na počítači', 'vychozi' => '1', 'moznosti' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4']],
            'popis' => ['typ' => 'text', 'popisek' => 'Název pro čtečky (např. Reference)', 'vychozi' => '', 'max' => 120],
        ];
    }

    public static function defaultChildren(): array
    {
        $slide = fn (string $n): array => ['styl' => ['zaklad' => ['odsazeni_y' => 'l', 'odsazeni_x' => 'l', 'pozadi' => 'plocha', 'zaobleni' => 'm']]] + Build::fresh('kontejner', [], [
            ['znacka' => 'h3'] + Build::fresh('nadpis', ['text' => $n]),
            Build::fresh('text', ['html' => '<p>' . t('Text snímku.') . '</p>']),
        ]);

        return [$slide(t('První snímek')), $slide(t('Druhý snímek')), $slide(t('Třetí snímek'))];
    }

    public static function baseCss(): string
    {
        return '.ka-karusel { position: relative; }
.ka-karusel-pas { display: flex; gap: var(--ka-mezera-m); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: thin; padding-block-end: var(--ka-mezera-2xs); }
.ka-karusel-pas > * { flex: 0 0 calc((100% - (var(--ka-naraz) - 1) * var(--ka-mezera-m)) / var(--ka-naraz)); scroll-snap-align: start; min-width: 0; }
@media (max-width: 767px) { .ka-karusel-pas > * { flex-basis: 85%; } }
.ka-karusel-sipky { display: flex; justify-content: flex-end; gap: var(--ka-mezera-2xs); margin-block-start: var(--ka-mezera-xs); }
.ka-karusel-sipky button { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid var(--ka-barva-linka); border-radius: 50%; background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font-size: 1.2em; cursor: pointer; }
.ka-karusel-sipky button:disabled { opacity: 0.35; cursor: default; }
.ka-karusel:not([data-zapnuto]) .ka-karusel-sipky { display: none; }
@media (prefers-reduced-motion: reduce) { .ka-karusel-pas { scroll-behavior: auto; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $description = $o['popis'] !== '' ? ' aria-label="' . e($o['popis']) . '"' : '';

        return '<' . $p['znacka'] . Text::withClass($a, 'ka-karusel') . ' data-karusel aria-roledescription="' . e(t('karusel')) . '"' . $description . ' style="--ka-naraz:' . (int) $o['naraz'] . '">'
            . '<div class="ka-karusel-pas" tabindex="0">' . $children . '</div>'
            . '<div class="ka-karusel-sipky"><button type="button" data-krok="-1" aria-label="' . e(t('Předchozí')) . '">‹</button><button type="button" data-krok="1" aria-label="' . e(t('Další')) . '">›</button></div>'
            . '</' . $p['znacka'] . '>';
    }
}
