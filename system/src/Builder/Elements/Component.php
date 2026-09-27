<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Components;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Použití komponenty: vloží její publikovanou stavbu a dosadí vlastní hodnoty jejích {{vlastností}}.
 * Na webu nemá vlastní značku (vypíše rovnou obsah komponenty), pokud použití nemá vlastní styl, třídu nebo kotvu;
 * v editoru ji obalí prvek, aby šla vybrat jako celek.
 */
final class Component extends Element
{
    public const string TYPE = 'komponenta';
    public const string NAME = 'Komponenta';
    public const string DESCRIPTION = 'Znovupoužitelný blok – úprava komponenty se projeví všude, kde je použitá.';
    public const string ICON = 'komponenta';
    public const string GROUP = 'Pokročilé';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'komponenta' => ['typ' => 'text', 'popisek' => 'Komponenta', 'vychozi' => '', 'max' => 12],
            'hodnoty' => ['typ' => 'hodnoty', 'popisek' => 'Vlastnosti', 'vychozi' => []],
        ];
    }

    /** Obsah komponenty s hodnotami tohoto použití (volá Stavba při vykreslení). */
    public static function inner(array $p, Context $k, callable $render): string
    {
        $id = (int) $p['obsah']['komponenta'];
        if (!array_key_exists($id, $k->components)) {
            $k->components[$id] = $id > 0 ? Components::byId($k->app->db(), $id) : null;
        }
        $component = $k->components[$id];
        $build = $component === null ? null : \Kaleta\Builder\Build::fromJson($component['stavba'] ?? $component['stavba_koncept']);
        if ($build === null) {
            return $k->editor ? '<p>' . e(t('Vyberte komponentu v panelu Obsah.')) . '</p>' : '';
        }
        if (in_array($id, $k->nesting, true) || count($k->nesting) >= Components::MAX_NESTING) {
            return ''; // komponenta sama v sobě by se vykreslovala donekonečna
        }
        // uvnitř se použije jen publikovaná podoba a bez značek editoru (vybírá se komponenta jako celek);
        // komponenta může být na stránce víckrát, proto styl přes třídu jako ve výpisu kolekce
        [$item, $editor, $loop] = [$k->item, $k->editor, $k->inLoop];
        $k->nesting[] = $id;
        $k->item = Components::values($component, is_array($p['obsah']['hodnoty'] ?? null) ? $p['obsah']['hodnoty'] : []);
        $k->editor = false;
        $k->inLoop++;
        try {
            return $render($build);
        } finally {
            array_pop($k->nesting);
            [$k->item, $k->editor, $k->inLoop] = [$item, $editor, $loop];
        }
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        // obal jen tam, kde má použití vlastní styl, třídu nebo kotvu (jinak by přidal zbytečnou úroveň do mřížek a flexu)
        $hasWrapper = str_contains($a, ' id="') || str_contains($a, ' class="');
        if ($hasWrapper) {
            return '<div' . $a . '>' . $children . '</div>';
        }

        return $k->editor ? '<div' . $a . ' style="display:contents">' . $children . '</div>' : $children;
    }
}
