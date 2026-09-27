<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Přepínač jazykových verzí webu jako samostatný prvek – třeba v patičce, když ho prvek Navigace nemá (volba „Přepínač
 * jazyků“ vypnutá). Nabídka je Popover API bez skriptu; v patičce se otevírá nahoru. Web s jediným jazykem nic nevypíše.
 */
final class LanguageSwitcher extends Element
{
    public const string TYPE = 'jazyky';
    public const string NAME = 'Přepínač jazyků';
    public const string DESCRIPTION = 'Výběr jazykové verze webu – řada zkratek, nebo rozbalovací nabídka (třeba v patičce).';
    public const string ICON = 'svet';
    public const string GROUP = 'Části webu';
    public const array HTML_TAGS = ['nav'];
    public const bool PARTS_ONLY = true;

    public static function properties(): array
    {
        return [
            'styl' => ['typ' => 'vyber', 'popisek' => 'Podoba', 'vychozi' => 'nabidka', 'moznosti' => ['nabidka' => 'rozbalovací nabídka', 'rada' => 'zkratky v řadě']],
            'smer' => ['typ' => 'vyber', 'popisek' => 'Nabídka se otevře', 'vychozi' => 'nahoru', 'moznosti' => ['nahoru' => 'nahoru (patička)', 'dolu' => 'dolů (záhlaví)']],
        ];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        if ($k->languageList === []) {
            // jediný jazyk: na webu nic, v builderu jen upozornění, kde se jazyky zapínají
            return $k->editor ? '<span' . $a . ' style="display:inline-block;padding:.4rem .8rem;border:1px dashed currentColor;border-radius:999px;font-size:.85rem">'
                . e(t('Přepínač jazyků – ukáže se, když má web víc jazykových verzí')) . '</span>' : '';
        }

        return $k->app->view->render('front/jazyky', ['jazyky' => $k->languageList, 'styl' => $p['obsah']['styl'] ?? 'nabidka', 'smer' => $p['obsah']['smer'] ?? 'nahoru', 'atributy' => Text::withClass($a, 'ka-jazyky-prvek')]);
    }
}
