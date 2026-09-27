<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;

/**
 * Stav vykreslení jedné stránky webu: co se použilo (kvůli CSS jen toho potřebného) napříč stavbou stránky i částmi webu
 * (záhlaví, patička, obálka), aby stránka dostala jediný blok CSS. Režim editoru se přepíná podle právě vykreslované stavby.
 */
final class Context
{
    /** Stránka má prvek s podmínkou zobrazení – nesmí do cache stránek. */
    public bool $withoutCache = false;

    /** @var array<string, true> typy prvků na stránce */
    public array $types = [];

    /** @var array<string, true> třídy na stránce */
    public array $classes = [];

    /** CSS prvků s vlastním stylem (vrstva „prvky“). */
    public string $css = '';

    /** @var list<array{0:string, 1:string}> otázky a odpovědi z prvků FAQ – pro strukturovaná data stránky */
    public array $faq = [];

    /** @var list<array{titulek:string, seo_link:string}> stránky hlavní navigace (dodává web) */
    public array $menu = [];

    /** Cesta zobrazené stránky (kvůli aria-current v navigaci). */
    public string $path = '';

    /** Hotový přepínač jazykových verzí webu (prázdný u jednojazyčného webu). */
    public string $languages = '';

    /** @var array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}> jazykové verze pro prvek Přepínač jazyků */
    public array $languageList = [];

    /** Přepínač světlého a tmavého vzhledu pro návštěvníky (prázdný, když je vypnutý); prvek Navigace ho přidá za menu. */
    public string $colorScheme = '';

    /** @var array<string, array{0: string, 1: string}>|null hodnoty položky kolekce pro {{značky}} (uvnitř Výpisu kolekce a na detailu) */
    public ?array $item = null;

    /** Hloubka Výpisu kolekce: prvky uvnitř se opakují, proto mají styl přes třídu, ne přes id. */
    public int $inLoop = 0;

    /** @var array<int, array<string, mixed>|null> načtené komponenty (jedna komponenta bývá na stránce víckrát) */
    public array $components = [];

    /** @var list<int> komponenty, které se právě vykreslují (ochrana proti komponentě v sobě samé) */
    public array $nesting = [];

    /** @var array<string, array{pred: string, za: string}> ovládání kolem prvku (filtry a stránkování výpisu kolekce) podle id */
    public array $surroundings = [];

    /** @var array<string, true> prvky, jejichž CSS už na stránce je */
    public array $styles = [];

    /** Odkud právě vykreslovaná stavba je: „stranka:<id>“ nebo „cast:<typ>:<jazyk>“ (formulář podle něj najde svá pole). */
    public string $source = '';

    /** @var list<array{0: string, 1: string}> drobečková navigace zobrazené stránky: [text, adresa]; poslední je stránka sama (adresa '') */
    public array $breadcrumbs = [];

    /** Obsah, který systém vkládá do obálky (prvek „Obsah stránky“): novinka, výpis, stránka 404. */
    public string $content = '';

    /** Kotvy nadpisů z textů na stránce (Prvky\Text) – ať se na jedné stránce neopakují. @var array<string, true> */
    public array $anchors = [];

    public function __construct(public readonly App $app, public bool $editor = false)
    {
    }

    public function url(string $path): string
    {
        return $this->app->url($path);
    }

    /** Adresa obrázku z media/ doplněná o cestu k instalaci; cizí adresa zůstane. */
    public function image(string $src): string
    {
        return preg_match('#^(https?:)?//|^/#', $src) ? $src : $this->app->request->basePath() . '/' . $src;
    }
}
