<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Logo webu z Vzhledu (bez něj název webu) jako odkaz na úvodní stránku. Výšku mění styl „Výška“. */
final class Logo extends Element
{
    public const string TYPE = 'logo';
    public const string NAME = 'Logo';
    public const string DESCRIPTION = 'Logo webu z nastavení Vzhledu, jinak název webu – odkaz na úvodní stránku.';
    public const string ICON = 'logo';
    public const string GROUP = 'Části webu';
    public const array HTML_TAGS = ['a'];
    public const bool PARTS_ONLY = true;

    public static function properties(): array
    {
        return ['nazev' => ['typ' => 'prepinac', 'popisek' => 'Vedle loga i název webu', 'vychozi' => false]];
    }

    public static function baseCss(): string
    {
        return '.ka-logo { display: inline-flex; align-items: center; gap: var(--ka-mezera-xs); height: 2.75rem; color: inherit; font-family: var(--ka-pismo-titulky); font-size: var(--ka-krok-1); font-weight: 800; line-height: 1.1; text-decoration: none; }
.ka-logo img { display: block; width: auto; height: 100%; max-width: none; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $name = $siteSettings->get('nazev_webu');
        $logo = $siteSettings->get('logo_webu');
        $home = $k->url('');
        $content = $logo !== ''
            ? '<img src="' . e($k->image($logo)) . '" alt="' . e($p['obsah']['nazev'] ? '' : $name) . '">' . ($p['obsah']['nazev'] ? '<span>' . e($name) . '</span>' : '')
            : e($name);

        return '<a' . Text::withClass($a, 'ka-logo') . ' href="' . e($home) . '"' . ($k->path === $home ? ' aria-current="page"' : '') . '>' . $content . '</a>';
    }
}
