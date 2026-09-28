<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Expandable items (accordion) as <details> – without JavaScript. Optionally only one open at a time (the name attribute)
 * and FAQPage structured data – only in a page, not in the header and footer (otherwise every page of the site would be an FAQ).
 */
final class Faq extends Element
{
    public const string TYPE = 'faq';
    public const string NAME = 'Otázky a odpovědi (akordeon)';
    public const string DESCRIPTION = 'Rozbalovací položky – otázky (FAQ pro vyhledávače) nebo jakýkoli obsah, který nemusí být vidět hned.';
    public const string ICON = 'faq';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return ['polozky' => ['typ' => 'polozky', 'popisek' => 'Otázky', 'max' => 30, 'pole' => [
            'otazka' => ['typ' => 'text', 'popisek' => 'Otázka', 'vychozi' => '', 'max' => 300],
            'odpoved' => ['typ' => 'html', 'popisek' => 'Odpověď', 'vychozi' => ''],
        ], 'vychozi' => [['otazka' => t('Jak dlouho trvá realizace?'), 'odpoved' => '<p>' . t('Obvykle dva až čtyři týdny podle rozsahu.') . '</p>'], ['otazka' => t('Kolik to stojí?'), 'odpoved' => '<p>' . t('Cenu vám připravíme na míru – ozvěte se nám.') . '</p>']]],
            'jedna' => ['typ' => 'prepinac', 'popisek' => 'Otevřená vždy jen jedna položka', 'vychozi' => false],
            'faq' => ['typ' => 'prepinac', 'popisek' => 'Jsou to otázky a odpovědi (FAQ pro vyhledávače)', 'vychozi' => true]];
    }

    public static function baseCss(): string
    {
        return '.ka-faq details { border-block-end: 1px solid var(--ka-barva-linka); }
.ka-faq summary { display: flex; justify-content: space-between; gap: 1em; padding-block: var(--ka-mezera-s); font-weight: 600; cursor: pointer; list-style: none; }
.ka-faq summary::-webkit-details-marker { display: none; }
.ka-faq summary::after { content: "+"; font-size: 1.4em; line-height: 1; color: var(--ka-barva-primarni); transition: rotate 0.2s; }
.ka-faq details[open] summary::after { rotate: 45deg; }
.ka-faq details > div { padding-block-end: var(--ka-mezera-s); color: var(--ka-barva-tlumeny); }
.ka-faq details > div > :last-child { margin-block-end: 0; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = '';
        $faq = $p['obsah']['faq'] && !str_starts_with($k->source, 'cast:');
        $group = $p['obsah']['jedna'] ? ' name="faq-' . e($p['id']) . '"' : '';
        foreach ($p['obsah']['polozky'] as $i => $item) {
            if ($item['otazka'] === '') {
                continue;
            }
            if ($faq) {
                $k->faq[] = [$item['otazka'], trim(strip_tags($item['odpoved']))];
            }
            $html .= '<details' . $group . ($i === 0 && $k->editor ? ' open' : '') . '><summary>' . e($item['otazka']) . '</summary><div>' . $item['odpoved'] . '</div></details>';
        }

        return '<div' . Text::withClass($a, 'ka-faq') . '>' . $html . '</div>';
    }
}
