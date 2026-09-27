<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Images;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Fotogalerie: mřížka náhledů, klepnutím se fotka otevře přes celou obrazovku (prohlížečka z image/web.js, šipky i swipe).
 * Počet sloupců řídí styl (Rozložení → Sloupce), na telefonu se mřížka sama zúží.
 */
final class Gallery extends Element
{
    public const string TYPE = 'galerie';
    public const string NAME = 'Galerie';
    public const string DESCRIPTION = 'Mřížka fotek, které se po klepnutí otevřou přes celou obrazovku.';
    public const string ICON = 'galerie';
    public const array HTML_TAGS = ['figure', 'div'];

    public static function properties(): array
    {
        return [
            'fotky' => ['typ' => 'polozky', 'popisek' => 'Fotky', 'max' => 60, 'vychozi' => [], 'pole' => [
                'src' => ['typ' => 'obrazek', 'popisek' => 'Fotka', 'vychozi' => ''],
                'alt' => ['typ' => 'text', 'popisek' => 'Popis (pro nevidomé i pod fotkou v prohlížečce)', 'vychozi' => '', 'max' => 300],
            ]],
            'pomer' => ['typ' => 'vyber', 'popisek' => 'Tvar náhledů', 'vychozi' => '4 / 3', 'moznosti' => ['4 / 3' => 'na šířku 4 : 3', '1 / 1' => 'čtverec', '3 / 4' => 'na výšku 3 : 4', '16 / 9' => 'široký 16 : 9']],
            'popisek' => ['typ' => 'text', 'popisek' => 'Popisek galerie', 'vychozi' => '', 'max' => 300],
        ];
    }

    public static function baseCss(): string
    {
        // mřížka sama podle šířky; Styl → Sloupce ji přepíše (vrstva prvků je až za vrstvou builderu)
        return '.ka-galerie { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(12rem, 45%), 1fr)); gap: var(--ka-mezera-s); margin: 0; }
.ka-galerie img { display: block; width: 100%; height: auto; object-fit: cover; border-radius: var(--ka-zaobleni-s); cursor: zoom-in; }
.ka-galerie figcaption { grid-column: 1 / -1; color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $base = $k->app->request->basePath();
        $html = '';
        foreach ($o['fotky'] as $f) {
            if ($f['src'] === '') {
                continue;
            }
            $src = $k->image($f['src']);
            $srcset = Images::srcset(ltrim(preg_replace('#^' . preg_quote($base, '#') . '/#', '', $src) ?? $src, '/'), $base);
            $html .= '<img src="' . e($src) . '"' . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="(max-width: 700px) 50vw, 400px"' : '')
                . ' alt="' . e($f['alt']) . '" loading="lazy" style="aspect-ratio:' . e($o['pomer']) . '">';
        }
        if ($html === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('Přidejte fotky v panelu Obsah.')) . '</div>' : '';
        }
        if ($o['popisek'] !== '') {
            $html .= $p['znacka'] === 'figure' ? '<figcaption>' . e($o['popisek']) . '</figcaption>' : '<p>' . e($o['popisek']) . '</p>';
        }

        return '<' . $p['znacka'] . Text::withClass($a, 'ka-galerie') . '>' . $html . '</' . $p['znacka'] . '>';
    }
}
