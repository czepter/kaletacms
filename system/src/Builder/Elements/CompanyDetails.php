<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Údaj z Nastavení (adresa, telefon, IČO, otevírací doba, copyright, sociální sítě…) – vyplní se jednou a změní se všude.
 * Firma v Nastavení → Firma, web v Nastavení → Základní.
 */
final class CompanyDetails extends Element
{
    public const string TYPE = 'udaje';
    public const string NAME = 'Údaje firmy';
    public const string DESCRIPTION = 'Adresa, telefon, e-mail, IČO, otevírací doba, mapa, copyright nebo sociální sítě z Nastavení.';
    public const string ICON = 'udaje';
    public const string GROUP = 'Dynamické';
    public const array HTML_TAGS = ['p', 'div', 'span', 'address'];

    /** Značka nevyplněné otevírací doby na webu: kontejner, ve kterém kromě ní zbude jen nadpis, se vynechá (Kontejner::vykresli). */
    public const string EMPTY_HOURS = '<!--ka-prazdne-hodiny-->';

    public static function properties(): array
    {
        return ['udaj' => ['typ' => 'vyber', 'popisek' => 'Údaj', 'vychozi' => 'copyright', 'moznosti' => [
            'adresa' => 'Adresa', 'telefon' => 'Telefon', 'email' => 'E-mail', 'hodiny' => 'Otevírací doba', 'mapa' => 'Odkaz na mapu',
            'firma' => 'Obchodní firma a IČO', 'tiraz' => 'Tiráž (všechny údaje o provozovateli)', 'copyright' => '© rok a název webu', 'nazev' => 'Název webu', 'popis' => 'Popis webu',
            'text_paticky' => 'Text patičky', 'site' => 'Sociální sítě', 'rss' => 'Odkaz na RSS',
        ]]];
    }

    public static function baseCss(): string
    {
        return '.ka-hodiny { margin: 0; padding: 0; list-style: none; }
.ka-udaj:is(address) { font-style: normal; }
.ka-site { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs) var(--ka-mezera-s); margin: 0; padding: 0; list-style: none; }
.ka-site a, .ka-udaj a { color: inherit; }
.ka-tiraz { display: grid; grid-template-columns: max-content 1fr; gap: var(--ka-mezera-2xs) var(--ka-mezera-m); margin: 0; }
.ka-tiraz dt { font-weight: 600; }
.ka-tiraz dd { margin: 0; }
@media (max-width: 600px) { .ka-tiraz { grid-template-columns: 1fr; } .ka-tiraz dd { margin-block-end: var(--ka-mezera-xs); } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $z = $p['znacka'];
        $wrapper = fn (string $html): string => $html === '' && !$k->editor ? '' : '<' . $z . Text::withClass($a, 'ka-udaj') . '>' . ($html !== '' ? $html : e(t('(doplňte v Nastavení → Firma)'))) . '</' . $z . '>';

        return match ($p['obsah']['udaj']) {
            'copyright' => $wrapper('&copy; ' . date('Y') . ' ' . e($siteSettings->get('nazev_webu'))),
            'nazev' => $wrapper(e($siteSettings->get('nazev_webu'))),
            'popis' => $wrapper(e($siteSettings->get('popis_webu'))),
            'text_paticky' => $wrapper(e($siteSettings->get('text_paticky'))),
            'email' => $wrapper(($mail = $siteSettings->get('firma_email')) !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : ''),
            'rss' => \Kaleta\Core\Extensions::isEnabled($siteSettings, 'novinky') ? $wrapper('<a href="' . e($k->url('rss.xml')) . '">RSS</a>') : '', // bez novinek RSS není
            'adresa' => $wrapper(implode('<br>', array_map(e(...), \Kaleta\Front\Company::address($siteSettings)))),
            'telefon' => $wrapper($siteSettings->get('firma_telefon') !== '' ? '<a href="tel:' . e((string) preg_replace('/[^\d+]/', '', $siteSettings->get('firma_telefon'))) . '">' . e($siteSettings->get('firma_telefon')) . '</a>' : ''),
            'mapa' => $wrapper($siteSettings->get('firma_mapa') !== '' ? '<a href="' . e($siteSettings->get('firma_mapa')) . '" target="_blank" rel="noopener">' . e(t('Zobrazit na mapě')) . '</a>' : ''),
            'firma' => $wrapper(implode('<br>', array_map(e(...), array_filter([
                $siteSettings->get('firma_nazev'),
                trim(($siteSettings->get('firma_ico') !== '' ? t('IČO') . ' ' . $siteSettings->get('firma_ico') : '') . ($siteSettings->get('firma_dic') !== '' ? ', ' . t('DIČ') . ' ' . $siteSettings->get('firma_dic') : ''), ', '),
            ])))),
            'hodiny' => ($rows = \Kaleta\Front\Company::openingHoursLines($siteSettings)) !== []
                ? '<ul' . Text::withClass($a, 'ka-hodiny') . '>' . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $rows)) . '</ul>'
                : ($k->editor ? $wrapper('') : self::EMPTY_HOURS),
            'site' => self::networks($siteSettings, $a, $k),
            'tiraz' => self::imprint($siteSettings, $a, $k),
            default => '',
        };
    }

    /**
     * Tiráž (Impressum): kdo web provozuje – obchodní firma, sídlo, identifikační čísla, zápis v rejstříku, zastoupení
     * a kontakt. Vypíše jen vyplněné údaje z Nastavení → Firma.
     */
    private static function imprint(\Kaleta\Core\Settings $siteSettings, string $a, Context $k): string
    {
        $phone = $siteSettings->get('firma_telefon');
        $mail = $siteSettings->get('firma_email');
        $rows = array_filter([
            t('Provozovatel') => e($siteSettings->get('firma_nazev') !== '' ? $siteSettings->get('firma_nazev') : $siteSettings->get('nazev_webu')),
            t('Sídlo') => implode('<br>', array_map(e(...), \Kaleta\Front\Company::address($siteSettings))),
            t('IČO') => e($siteSettings->get('firma_ico')),
            t('DIČ') => e($siteSettings->get('firma_dic')),
            t('Zápis v rejstříku') => e($siteSettings->get('firma_rejstrik')),
            t('Zastoupení') => e($siteSettings->get('firma_zastupce')),
            t('Telefon') => $phone !== '' ? '<a href="tel:' . e((string) preg_replace('/[^\d+]/', '', $phone)) . '">' . e($phone) . '</a>' : '',
            t('E-mail') => $mail !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : '',
        ], fn (string $h): bool => $h !== '');
        if (count($rows) < 2 && $k->editor) {
            return '<p' . $a . '>' . e(t('(doplňte v Nastavení → Firma)')) . '</p>';
        }

        return '<dl' . Text::withClass($a, 'ka-tiraz') . '>' . implode('', array_map(fn (string $n, string $h): string => '<dt>' . e($n) . '</dt><dd>' . $h . '</dd>', array_keys($rows), $rows)) . '</dl>';
    }

    private static function networks(\Kaleta\Core\Settings $siteSettings, string $a, Context $k): string
    {
        $networks = array_filter(['LinkedIn' => $siteSettings->get('soc_linkedin'), 'Facebook' => $siteSettings->get('soc_facebook'), 'Instagram' => $siteSettings->get('soc_instagram'), 'YouTube' => $siteSettings->get('soc_youtube'), 'X' => $siteSettings->get('soc_x')]);
        if ($networks === []) {
            return $k->editor ? '<p' . $a . '>' . e(t('Sociální sítě doplníte v Nastavení.')) . '</p>' : '';
        }

        return '<ul' . Text::withClass($a, 'ka-site') . '>' . implode('', array_map(fn (string $n, string $u): string => '<li><a href="' . e($u) . '" rel="me noopener" target="_blank">' . e($n) . '</a></li>', array_keys($networks), $networks)) . '</ul>';
    }
}
