<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Tlačítko = odkaz vzhledu tlačítka. Varianty z design systému: hlavní, doplňkové, obrys, textový odkaz. */
final class Button extends Element
{
    public const string TYPE = 'tlacitko';
    public const string NAME = 'Tlačítko';
    public const string DESCRIPTION = 'Výzva k akci: odkaz ve tvaru tlačítka.';
    public const string ICON = 'tlacitko';
    public const array HTML_TAGS = ['a'];
    public const array VARIANTS = ['primarni' => 'hlavní', 'sekundarni' => 'doplňkové', 'obrys' => 'obrys', 'odkaz' => 'textový odkaz'];

    public static function properties(): array
    {
        return [
            'text' => ['typ' => 'text', 'popisek' => 'Text', 'vychozi' => t('Kontaktujte nás'), 'max' => 120],
            'odkaz' => ['typ' => 'odkaz', 'popisek' => 'Odkaz', 'vychozi' => '#'],
            'varianta' => ['typ' => 'vyber', 'popisek' => 'Vzhled', 'vychozi' => 'primarni', 'moznosti' => self::VARIANTS],
            'nove_okno' => ['typ' => 'prepinac', 'popisek' => 'Otevřít v novém okně', 'vychozi' => false],
            'ikona' => ['typ' => 'vyber', 'popisek' => 'Ikona', 'vychozi' => '', 'moznosti' => ['' => 'bez ikony'] + \Kaleta\Builder\Icons::options()],
            'ikona_vlevo' => ['typ' => 'prepinac', 'popisek' => 'Ikona vlevo od textu', 'vychozi' => false],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-tlacitko { display: inline-flex; align-items: center; justify-content: center; gap: 0.5em; padding: 0.75em 1.35em; border: 2px solid transparent; border-radius: var(--ka-zaobleni); font: 600 var(--ka-krok-0) / 1.2 var(--ka-pismo-text); text-decoration: none; cursor: pointer; transition: background-color 0.15s, color 0.15s, border-color 0.15s; }
.ka-tlacitko--primarni { background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); }
.ka-tlacitko--primarni:hover { background: color-mix(in oklch, var(--ka-barva-primarni) 85%, black); }
.ka-tlacitko--sekundarni { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-primarni); }
.ka-tlacitko--sekundarni:hover { background: color-mix(in oklch, var(--ka-barva-primarni) 20%, var(--ka-barva-pozadi)); }
.ka-tlacitko--obrys { border-color: currentColor; color: inherit; background: transparent; }
.ka-tlacitko--obrys:hover { background: color-mix(in oklch, currentColor 8%, transparent); }
.ka-tlacitko--odkaz { padding-inline: 0; color: var(--ka-barva-primarni); text-decoration: underline; text-underline-offset: 0.2em; }
.ka-tlacitko svg { flex: none; width: var(--ka-tlacitko-ikona, 1.15em); height: var(--ka-tlacitko-ikona, 1.15em); }
.ka-tlacitko:focus-visible { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];

        $icon = ($o['ikona'] ?? '') !== '' ? \Kaleta\Builder\Icons::svg($o['ikona']) : '';

        return '<a' . Text::withClass($a, 'ka-tlacitko ka-tlacitko--' . $o['varianta']) . ' href="' . e($o['odkaz'] !== '' ? $o['odkaz'] : '#') . '"'
            . ($o['nove_okno'] ? ' target="_blank" rel="noopener"' : '') . '>' . (!empty($o['ikona_vlevo']) ? $icon . e($o['text']) : e($o['text']) . $icon) . '</a>';
    }
}
