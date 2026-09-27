<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

final class Heading extends Element
{
    public const string TYPE = 'nadpis';
    public const string NAME = 'Nadpis';
    public const string DESCRIPTION = 'Nadpis H1–H6 nebo zvýrazněný řádek.';
    public const string ICON = 'nadpis';
    public const array HTML_TAGS = ['h2', 'h1', 'h3', 'h4', 'h5', 'h6', 'p'];

    public static function properties(): array
    {
        return ['text' => ['typ' => 'inline', 'popisek' => 'Text', 'vychozi' => t('Nadpis'), 'max' => 400]];
    }

    /** Zvýraznění části nadpisu (<mark>): doplňková barva bez podbarvení – tečka za titulkem, klíčové slovo. */
    public static function baseCss(): string
    {
        return ':where(.stavba) mark { background: none; color: var(--ka-barva-sekundarni); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        return '<' . $p['znacka'] . $a . '>' . $p['obsah']['text'] . '</' . $p['znacka'] . '>';
    }
}
