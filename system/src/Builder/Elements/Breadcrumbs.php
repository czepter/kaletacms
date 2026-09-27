<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Drobečková navigace: Úvod › Novinky › Kategorie › Novinka. Cestu skládá web podle zobrazené stránky (Kontext::$drobecky),
 * do obálky novinky nebo šablony detailu kolekce ji tak stačí vložit jednou. Vyhledávače dostanou i BreadcrumbList.
 */
final class Breadcrumbs extends Element
{
    public const string TYPE = 'drobecky';
    public const string NAME = 'Drobečková navigace';
    public const string DESCRIPTION = 'Cesta ke stránce (Úvod › Novinky › …) – sestaví se sama podle zobrazené stránky.';
    public const string ICON = 'drobecky';
    public const array HTML_TAGS = ['nav'];

    public static function baseCss(): string
    {
        return '.ka-drobecky ol { display: flex; flex-wrap: wrap; gap: 0.35em; margin: 0; padding: 0; list-style: none; color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-drobecky li + li::before { content: "›"; margin-inline-end: 0.35em; }
.ka-drobecky a { color: inherit; }
.ka-drobecky [aria-current] { color: var(--ka-barva-text); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $path = $k->breadcrumbs !== [] ? $k->breadcrumbs : ($k->editor ? [[t('Úvod'), '#'], [t('Tato stránka'), '']] : []);
        if (count($path) < 2) {
            return ''; // na úvodní stránce drobečky nemají smysl
        }
        $html = '';
        foreach ($path as $i => [$text, $url]) {
            // aktuální je jen poslední článek; úroveň bez vlastní stránky (kolekce bez rozcestníku) je prostý text
            $html .= match (true) {
                $i === array_key_last($path) => '<li><span aria-current="page">' . e($text) . '</span></li>',
                $url === '' => '<li><span>' . e($text) . '</span></li>',
                default => '<li><a href="' . e($url) . '">' . e($text) . '</a></li>',
            };
        }

        return '<nav' . Text::withClass($a, 'ka-drobecky') . ' aria-label="' . e(t('Drobečková navigace')) . '"><ol>' . $html . '</ol></nav>';
    }
}
