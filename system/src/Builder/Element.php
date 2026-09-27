<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Typ prvku builderu. Každý typ = jedna třída v Stavitel\Prvky se schématem obsahu (VLASTNOSTI), povolenými HTML značkami,
 * výchozím stylem a vykreslením. Výstup je vždy jedna značka na prvek (výjimky jsou jen složené prvky jako FAQ nebo výpis novinek).
 *
 * Pole obsahu – typy: text (řádek), inline (krátký text s tučným/kurzívou/odkazem), html (formátovaný text), radky (víc řádků),
 * odkaz, obrazek, vyber, cislo, prepinac, polozky (seznam objektů s poli „pole“).
 */
abstract class Element
{
    public const string TYPE = '';
    public const string NAME = '';
    public const string DESCRIPTION = '';
    public const string ICON = 'blok';
    public const string GROUP = 'Obsah';
    /** Může obsahovat další prvky. */
    public const bool CONTAINER = false;
    /** Povolené značky, první je výchozí. */
    public const array HTML_TAGS = ['div'];
    /** Smí vložit a měnit jen správce (vlastní HTML). */
    public const bool ADMIN_ONLY = false;
    /** Klíč rozšíření (Core\Rozsireni), bez kterého prvek nejde vložit a na webu se nevykreslí; prázdné = vždy. */
    public const string EXTENSION = '';
    /** Nabízí se jen v částech webu (záhlaví, patička, obálky) – logo, navigace, obsah stránky. */
    public const bool PARTS_ONLY = false;

    /** @return array<string, array<string, mixed>> pole obsahu: klíč => [typ, popisek, vychozi, moznosti, pole, max] */
    public static function properties(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> výchozí vnitřek nově vloženého kontejneru (editor mu dá nová id) */
    public static function defaultChildren(): array
    {
        return [];
    }

    /** @return array<string, array<string, string>> výchozí styl nově vloženého prvku */
    public static function defaultStyle(): array
    {
        return [];
    }

    /** Základní CSS typu (vrstva „stavitel“), vypíše se jen na stránkách, kde typ je. */
    public static function baseCss(): string
    {
        return '';
    }

    /**
     * @param array<string, mixed> $p       vyčištěný prvek (typ, znacka, obsah, …)
     * @param string               $a       hotové atributy (id, class, data-ka-id) začínající mezerou
     * @param string               $children    vykreslené vnořené prvky
     */
    abstract public static function render(array $p, string $a, string $children, Context $k): string;
}
