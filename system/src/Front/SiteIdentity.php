<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Settings;

/**
 * Identita webu (Vzhled → Identita webu): hlavní barva a písma, které se propisují do šablon.
 *
 * Šablony s identitou počítají přes CSS proměnné --ka-akcent, --ka-pismo-titulky a --ka-pismo-text:
 * ve svém style.css je použijí s vlastní výchozí hodnotou, např. --akcent: var(--ka-akcent, #326891).
 * Písma jsou jen systémová (žádné stahování z cizích serverů - rychlost a GDPR).
 */
final class SiteIdentity
{
    /** klíč => [název, popis, CSS font-family] */
    public const array TITLE_FONTS = [
        'vychozi' => ['Výchozí písmo', 'písmo výchozího vzhledu webu', ''],
        'elegantni' => ['Elegantní patkové', 'Bodoni, Didot – elegance a móda', '"Bodoni 72", Didot, "Bodoni MT", "Playfair Display", Georgia, serif'],
        'klasicke' => ['Klasické patkové', 'Georgia – seriózní a dobře čitelné', 'Georgia, "Times New Roman", Times, serif'],
        'knizni' => ['Knižní', 'Charter, Cambria – klidné a literární', 'Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif'],
        'moderni' => ['Moderní bezpatkové', 'systémové písmo zařízení – čisté a neutrální', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
        'grotesk' => ['Výrazný grotesk', 'Helvetica, Arial – výrazné a sebevědomé', '"Helvetica Neue", Helvetica, "Arial Nova", Arial, sans-serif'],
        'zaoblene' => ['Zaoblené', 'přátelské, pro služby a rodinné firmy', 'ui-rounded, "SF Pro Rounded", "Hiragino Maru Gothic ProN", Quicksand, Nunito, system-ui, sans-serif'],
        'strojove' => ['Psací stroj', 'technologie a vývoj', 'ui-monospace, "SF Mono", Menlo, Consolas, "Courier New", monospace'],
    ];

    public const array TEXT_FONTS = [
        'vychozi' => ['Výchozí písmo', '', ''],
        'patkove' => ['Patkové', 'Georgia – pohodlné pro dlouhé čtení', 'Georgia, "Times New Roman", Times, serif'],
        'knizni' => ['Knižní', 'Charter, Cambria', 'Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif'],
        'moderni' => ['Bezpatkové', 'systémové písmo zařízení', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
        'grotesk' => ['Grotesk', 'Helvetica, Arial', '"Helvetica Neue", Helvetica, "Arial Nova", Arial, sans-serif'],
    ];

    /**
     * Ikony webu, manifest a barva lišty prohlížeče do <head>. Barvy a písma webu vypisuje design systém (Stavitel\DesignSystem).
     * PNG velikosti (media/ikona-<n>.png) připraví Vzhled při uložení ikony.
     */
    public static function head(Settings $siteSettings, string $base): string
    {
        $html = '';
        $icon = $siteSettings->get('favicon');
        if ($icon !== '') {
            $html .= '<link rel="icon" href="' . e((preg_match('#^(https?:)?/#', $icon) ? '' : $base . '/') . $icon) . "\">\n";
        }
        $png = KALETA_ROOT . '/media/ikona-180.png';
        if (is_file($png)) {
            $v = '?v=' . filemtime($png);
            $html .= '<link rel="icon" type="image/png" sizes="32x32" href="' . e($base . '/media/ikona-32.png' . $v) . "\">\n"
                . '<link rel="apple-touch-icon" href="' . e($base . '/media/ikona-180.png' . $v) . "\">\n";
        }
        $html .= '<link rel="manifest" href="' . e($base . '/manifest.webmanifest') . "\">\n";
        $colors = \Kaleta\Builder\DesignSystem::load($siteSettings)['barvy'];
        $html .= '<meta name="theme-color" content="' . e($colors['pozadi']) . '">' . "\n";

        return $html;
    }

    /** Manifest webu: název, barvy a ikony – telefon pak web připne na plochu s vlastní ikonou a názvem. */
    public static function manifest(Settings $siteSettings, string $base): string
    {
        $colors = \Kaleta\Builder\DesignSystem::load($siteSettings)['barvy'];
        $name = $siteSettings->get('site_name') ?: 'Web';
        $icons = [];
        foreach ([192, 512] as $n) {
            if (is_file(KALETA_ROOT . '/media/ikona-' . $n . '.png')) {
                $icons[] = ['src' => $base . '/media/ikona-' . $n . '.png', 'sizes' => $n . 'x' . $n, 'type' => 'image/png', 'purpose' => 'any'];
            }
        }

        return (string) json_encode(array_filter([
            'name' => $name, 'short_name' => mb_strimwidth($name, 0, 12, ''), 'start_url' => $base . '/', 'scope' => $base . '/',
            'display' => 'browser', 'background_color' => $colors['pozadi'], 'theme_color' => $colors['pozadi'], 'icons' => $icons,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
