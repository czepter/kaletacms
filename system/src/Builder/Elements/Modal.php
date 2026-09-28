<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;
use Kaleta\Builder\Build;

/**
 * Modal (Popover API): a link or button with the url #<modal anchor> opens it (Pokročilé → Kotva, i.e. Advanced → Anchor, otherwise
 * #okno-<element id> – the editor shows it next to the modal), or it opens by itself after a set time
 * (once per visit – image/web.js). The cross, Esc and a click outside close it. In the editor it is visible as an ordinary block.
 */
final class Modal extends Element
{
    public const string TYPE = 'okno';
    public const string NAME = 'Vyskakovací okno';
    public const string DESCRIPTION = 'Okno přes stránku – otevře ho tlačítko s odkazem na kotvu okna, nebo samo po chvíli.';
    public const string ICON = 'okno';
    public const string GROUP = 'Rozložení';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'samo' => ['typ' => 'vyber', 'popisek' => 'Otevřít samo', 'vychozi' => '0', 'moznosti' => ['0' => 'ne, jen odkazem', '5' => 'po 5 s', '15' => 'po 15 s', '30' => 'po 30 s', 'posun' => 'po odrolování poloviny stránky', 'odchod' => 'když se návštěvník chystá odejít']],
            'znovu' => ['typ' => 'vyber', 'popisek' => 'Samo znovu', 'vychozi' => 'relace', 'moznosti' => ['relace' => 'jednou za návštěvu', 'tyden' => 'jednou za týden', 'nikdy' => 'už nikdy (po zavření)']],
        ];
    }

    public static function defaultChildren(): array
    {
        return [
            ['znacka' => 'h2'] + Build::fresh('nadpis', ['text' => t('Nezávazná konzultace zdarma')]),
            Build::fresh('text', ['html' => '<p>' . t('Nechte nám kontakt, ozveme se do druhého dne.') . '</p>']),
            Build::fresh('tlacitko', ['text' => t('Kontaktujte nás'), 'odkaz' => '/kontakt']),
        ];
    }

    public static function baseCss(): string
    {
        // the modal lays out its content itself: a custom "display" from the style would show a closed modal (the popover hides with display: none)
        return '.ka-okno:popover-open, .ka-okno--editor { display: flex; flex-direction: column; align-items: flex-start; gap: var(--ka-mezera-m); }
.ka-okno { inset: 0; margin: auto; width: min(36rem, calc(100vw - 2rem)); max-height: calc(100dvh - 2rem); overflow: auto; padding: var(--ka-mezera-xl) var(--ka-mezera-l) var(--ka-mezera-l); border: 0; border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); box-shadow: var(--ka-stin-l); }
.ka-okno::backdrop { background: rgb(0 0 0 / 0.45); }
.ka-okno-zavrit { position: absolute; top: 0.5rem; right: 0.5rem; width: 2.5rem; height: 2.5rem; border: 0; border-radius: 50%; background: none; color: inherit; font-size: 1.5rem; line-height: 1; cursor: pointer; }
.ka-okno-zavrit:hover { background: var(--ka-barva-plocha); }
.ka-okno--editor { position: relative; margin: var(--ka-mezera-m) auto; outline: 2px dashed var(--ka-barva-linka); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        // modal id: the element's anchor, or the style id; without both okno-<id> (the element's style relies on the id too, so it does not change)
        $anchor = $p['kotva'] ?? 'okno-' . $p['id'];
        if (preg_match('/ id="([^"]*)"/', $a, $m)) {
            $anchor = $m[1];
        } else {
            $a = ' id="' . e($anchor) . '"' . $a;
        }
        $closeButton = '<button type="button" class="ka-okno-zavrit" popovertarget="' . e($anchor) . '" popovertargetaction="hide" aria-label="' . e(t('Zavřít')) . '">×</button>';
        if ($k->editor) {
            // in the editor the modal shows in place so that it can be edited, together with the url a button opens it with
            return '<div' . Text::withClass($a, 'ka-okno ka-okno--editor') . '><small style="position:absolute;top:.6rem;left:1rem;color:var(--ka-barva-tlumeny)">'
                . e(t('Otevře ho odkaz #%s', $anchor)) . '</small>' . $children . '</div>';
        }

        return '<div' . Text::withClass($a, 'ka-okno') . ' popover role="dialog" aria-label="' . e((string) ($p['popis'] ?? '') !== '' ? (string) $p['popis'] : t('Vyskakovací okno')) . '"'
            . ($o['samo'] !== '0' ? ' data-samo="' . e($o['samo']) . '" data-znovu="' . e($o['znovu'] ?? 'relace') . '"' : '') . '>' . $closeButton . $children . '</div>';
    }
}
