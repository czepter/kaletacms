<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Místo, kam systém vloží obsah stránky v obálce: text novinky, výpis novinek, hlášení 404. Obálka kolem něj přidá
 * sekce (výzva, novinky, kontakt) – obsah sám zůstává ze šablony, takže vypadá stejně jako bez obálky.
 */
final class PageContent extends Element
{
    public const string TYPE = 'obsah';
    public const string NAME = 'Obsah stránky';
    public const string DESCRIPTION = 'Sem systém vloží novinku, výpis novinek nebo hlášení 404. V obálce patří právě jednou.';
    public const string ICON = 'clanek';
    public const string GROUP = 'Části webu';
    public const array HTML_TAGS = ['div', 'article'];
    public const bool PARTS_ONLY = true;

    public static function baseCss(): string
    {
        // v obálce navazují další sekce hned pod obsahem – výška „aspoň na celou obrazovku“ ze šablony tu nepatří
        return ':where(.stavba) > .obsah { min-height: 0; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $content = $k->content !== '' ? $k->content : ($k->editor ? '<p>' . e(t('Sem se vloží obsah stránky (novinka, výpis novinek, hlášení 404).')) . '</p>' : '');

        // třídy šablony „obal obsah“: obsah vypadá stejně jako bez obálky
        return '<' . $p['znacka'] . Text::withClass($a, 'obal obsah') . '>' . $content . '</' . $p['znacka'] . '>';
    }
}
