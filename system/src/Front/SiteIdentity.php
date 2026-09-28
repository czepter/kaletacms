<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Settings;

/**
 * Site identity ("Vzhled → Identita webu", Appearance → Site identity): the main color and fonts that flow into layouts.
 *
 * Layouts use the identity through the CSS custom properties --ka-akcent, --ka-pismo-titulky and --ka-pismo-text:
 * in their style.css they use them with their own default value, e.g. --akcent: var(--ka-akcent, #326891).
 * Fonts are system fonts only (no downloads from third-party servers - speed and GDPR).
 */
final class SiteIdentity
{
    /** key => [name, description, CSS font-family] */
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
     * Site icons, manifest and browser bar color for <head>. The site's colors and fonts are output by the design system
     * (Builder\DesignSystem). The PNG sizes (media/ikona-<n>.png) are prepared by Appearance when the icon is saved.
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

    /** Site manifest: name, colors and icons – a phone then pins the site to the home screen with its own icon and name. */
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
