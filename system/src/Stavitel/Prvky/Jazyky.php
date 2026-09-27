<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Přepínač jazykových verzí webu jako samostatný prvek – třeba v patičce, když ho prvek Navigace nemá (volba „Přepínač
 * jazyků“ vypnutá). Nabídka je Popover API bez skriptu; v patičce se otevírá nahoru. Web s jediným jazykem nic nevypíše.
 */
final class Jazyky extends Prvek
{
    public const string TYP = 'jazyky';
    public const string NAZEV = 'Přepínač jazyků';
    public const string POPIS = 'Výběr jazykové verze webu – řada zkratek, nebo rozbalovací nabídka (třeba v patičce).';
    public const string IKONA = 'svet';
    public const string SKUPINA = 'Části webu';
    public const array ZNACKY = ['nav'];
    public const bool JEN_CASTI = true;

    public static function vlastnosti(): array
    {
        return [
            'styl' => ['typ' => 'vyber', 'popisek' => 'Podoba', 'vychozi' => 'nabidka', 'moznosti' => ['nabidka' => 'rozbalovací nabídka', 'rada' => 'zkratky v řadě']],
            'smer' => ['typ' => 'vyber', 'popisek' => 'Nabídka se otevře', 'vychozi' => 'nahoru', 'moznosti' => ['nahoru' => 'nahoru (patička)', 'dolu' => 'dolů (záhlaví)']],
        ];
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        if ($k->jazykySeznam === []) {
            // jediný jazyk: na webu nic, v builderu jen upozornění, kde se jazyky zapínají
            return $k->editor ? '<span' . $a . ' style="display:inline-block;padding:.4rem .8rem;border:1px dashed currentColor;border-radius:999px;font-size:.85rem">'
                . e(t('Přepínač jazyků – ukáže se, když má web víc jazykových verzí')) . '</span>' : '';
        }

        return $k->app->view->render('front/jazyky', ['jazyky' => $k->jazykySeznam, 'styl' => $p['obsah']['styl'] ?? 'nabidka', 'smer' => $p['obsah']['smer'] ?? 'nahoru', 'atributy' => Text::sTridou($a, 'ka-jazyky-prvek')]);
    }
}
