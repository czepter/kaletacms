<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Images;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Obrázek z Médií: srcset z připravených variant, líné načítání (kromě hlavního obrázku stránky), volitelně popisek a odkaz. */
final class Image extends Element
{
    public const string TYPE = 'obrazek';
    public const string NAME = 'Obrázek';
    public const string DESCRIPTION = 'Fotka nebo ilustrace z Médií, volitelně s popiskem a odkazem.';
    public const string ICON = 'obrazek';
    public const array HTML_TAGS = ['img'];

    public static function properties(): array
    {
        return [
            'src' => ['typ' => 'obrazek', 'popisek' => 'Obrázek', 'vychozi' => ''],
            'alt' => ['typ' => 'text', 'popisek' => 'Popis pro nevidomé (alt)', 'vychozi' => '', 'max' => 300],
            'popisek' => ['typ' => 'text', 'popisek' => 'Popisek pod obrázkem', 'vychozi' => '', 'max' => 300],
            'odkaz' => ['typ' => 'odkaz', 'popisek' => 'Odkaz', 'vychozi' => ''],
            'priorita' => ['typ' => 'prepinac', 'popisek' => 'Hlavní obrázek stránky (načíst hned)', 'vychozi' => false],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['sirka' => '100%', 'zaobleni' => 'm']];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        if ($o['src'] === '') {
            return $k->editor ? '<div' . $a . ' style="display:grid;place-items:center;min-height:10rem;background:var(--ka-barva-plocha);color:var(--ka-barva-tlumeny)">' . e(t('Vyberte obrázek')) . '</div>' : '';
        }
        $src = $k->image($o['src']);
        $srcset = Images::srcset(ltrim(preg_replace('#^' . preg_quote($k->app->request->basePath(), '#') . '/#', '', $src) ?? $src, '/'), $k->app->request->basePath());
        $labelText = $o['popisek'] !== '';
        $img = '<img' . ($labelText || $o['odkaz'] !== '' ? '' : $a) . ' src="' . e($src) . '"' . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="(max-width: 900px) 100vw, 900px"' : '')
            . ' alt="' . e($o['alt']) . '"' . ($o['priorita'] ? ' fetchpriority="high"' : ' loading="lazy"') . '>';
        if ($o['odkaz'] !== '') {
            $img = '<a' . ($labelText ? '' : $a) . ' href="' . e($o['odkaz']) . '">' . $img . '</a>';
        }

        return $labelText ? '<figure' . Text::withClass($a, 'ka-figura') . '>' . $img . '<figcaption>' . e($o['popisek']) . '</figcaption></figure>' : $img;
    }

    public static function baseCss(): string
    {
        return '.ka-figura { margin: 0; }
.ka-figura img { display: block; width: 100%; height: auto; border-radius: inherit; }
.ka-figura figcaption { margin-block-start: var(--ka-mezera-xs); font-size: var(--ka-krok--1); color: var(--ka-barva-tlumeny); }';
    }
}
