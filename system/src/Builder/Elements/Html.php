<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Custom HTML (a map, a booking system, embed code of a service). Only an administrator inserts and changes it; scripts are not let through. */
final class Html extends Element
{
    public const string TYPE = 'html';
    public const string NAME = 'Vlastní HTML';
    public const string DESCRIPTION = 'Vložený kód jiné služby (mapa, rezervace). Jen pro správce.';
    public const string ICON = 'kod';
    public const string GROUP = 'Pokročilé';
    public const array HTML_TAGS = ['div'];
    public const bool ADMIN_ONLY = true;

    public static function properties(): array
    {
        return ['kod' => ['typ' => 'kod', 'popisek' => 'HTML', 'vychozi' => '', 'max' => 20000]];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        return '<div' . $a . '>' . $p['obsah']['kod'] . '</div>';
    }
}
