<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Mřížka: sloupce, které se samy zalomí, když se nevejdou (výchozí „kolik se vejde po 16rem“). */
final class Grid extends Element
{
    public const string TYPE = 'mrizka';
    public const string NAME = 'Mřížka';
    public const string DESCRIPTION = 'Sloupce, které se na menší obrazovce samy zalomí pod sebe.';
    public const string ICON = 'mrizka';
    public const string GROUP = 'Rozložení';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'ul'];

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:16rem', 'mezera' => 'l']];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        return '<' . $p['znacka'] . $a . '>' . $children . '</' . $p['znacka'] . '>';
    }
}
