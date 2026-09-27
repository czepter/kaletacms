<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Skupina prvků (flex): karta, řada tlačítek, sloupec textu. S odkazem se celá stane odkazem. */
final class Container extends Element
{
    public const string TYPE = 'kontejner';
    public const string NAME = 'Kontejner';
    public const string DESCRIPTION = 'Skupina prvků pod sebou nebo vedle sebe – karta, řada tlačítek.';
    public const string ICON = 'kontejner';
    public const string GROUP = 'Rozložení';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'article', 'aside', 'nav', 'header', 'footer', 'ul', 'li'];

    public static function properties(): array
    {
        return ['odkaz' => ['typ' => 'odkaz', 'popisek' => 'Celý kontejner jako odkaz (nepovinné)', 'vychozi' => '']];
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']];
    }

    public static function baseCss(): string
    {
        // karta jako odkaz: text zůstane v barvách karty, ne v barvě odkazu; najetí myší ji jemně zvedne
        return '.ka-karta-odkaz { display: block; color: inherit; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease; }
.ka-karta-odkaz:hover { transform: translateY(-2px); box-shadow: var(--ka-stin-m); }
.ka-karta-odkaz:focus-visible { outline: 2px solid var(--ka-barva-primarni); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { .ka-karta-odkaz { transition: none; } .ka-karta-odkaz:hover { transform: none; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        if (str_contains($children, CompanyDetails::EMPTY_HOURS)) {
            // karta „Otevírací doba“ bez vyplněné doby: na webu by zůstal jen nadpis v prázdném rámečku
            $children = str_replace(CompanyDetails::EMPTY_HOURS, '', $children);
            if (trim(strip_tags((string) preg_replace('#<(h[1-6])\b.*?</\1>#s', '', $children))) === '') {
                return '';
            }
        }
        $link = (string) ($p['obsah']['odkaz'] ?? '');
        if ($link !== '') {
            // odkaz v odkazu HTML nedovoluje (prohlížeč by kartu rozlomil): tlačítka a odkazy uvnitř zůstanou jen vzhledem
            $children = (string) preg_replace_callback('#<a\b([^>]*)>#', fn (array $m): string => '<span' . preg_replace('#\s(?:href|target|rel|download|hreflang|aria-current)="[^"]*"#', '', $m[1]) . '>', $children);
            $children = str_replace('</a>', '</span>', $children);

            return '<a' . Text::withClass($a, 'ka-karta-odkaz') . ' href="' . e($link) . '">' . $children . '</a>';
        }

        return '<' . $p['znacka'] . $a . '>' . $children . '</' . $p['znacka'] . '>';
    }
}
