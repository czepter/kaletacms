<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * Tools the MCP server offers to Claude. Every tool respects the permissions of the user whose token Claude signs in with:
 * an author works only with their own news and does not publish, an editor with all content, an administrator also with
 * the site layouts.
 *
 * An error meant for Claude (bad input, missing permission) is reported with an InvalidArgumentException / DomainException.
 */
final class Tools
{
    /** The largest file uploaded via MCP (base64 in one tool call). */
    private const int MAX_UPLOAD = 12 * 1024 * 1024;

    /** Settings MCP can change (the others – e-mail, webhooks, 2FA, mail, backups – only in the administration). */
    private const string MCP_SETTINGS = '/^(site_name|site_description|footer_text|home_page|social_(facebook|instagram|x|youtube|linkedin)|news_per_page|share_buttons|article_outline|related_news_auto|company_[a-z_]+|dark_mode|theme_switcher|site_(name|description)_[a-z]{2})$/';

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array<string, mixed>> tool definitions for tools/list */
    public function listAll(): array
    {
        $s = fn (array $properties, array $required = []): array => ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'required' => $required];
        $text = fn (string $description): array => ['type' => 'string', 'description' => $description];
        $number = fn (string $description): array => ['type' => 'integer', 'description' => $description];
        $newsItem = [
            'titulek' => $text('Titulek novinky'), 'uvod' => $text('Perex jako HTML (1-2 odstavce)'), 'text' => $text('Text jako HTML'),
            'kategorie' => $text('Název nebo adresa (seo_link) kategorie'), 'stitky' => $text('Štítky oddělené čárkou'),
            'seo_titulek' => $text('Titulek pro vyhledávače (nepovinné)'), 'seo_popis' => $text('Popis pro vyhledávače, do 160 znaků'),
            'obrazek' => $text('Adresa hlavního obrázku (z nástroje seznam_medii)'), 'obrazek_popis' => $text('Popisek hlavního obrázku (prázdné = z knihovny médií)'),
            'faq' => $text('Otázky a odpovědi: otázka na řádku, odpověď pod ní, mezi dvojicemi prázdný řádek'),
            'datum' => $text('Datum vydání RRRR-MM-DD HH:MM; budoucí = naplánování'),
            'vydat' => ['type' => 'boolean', 'description' => 'true = vydat (jen s právem vydávat a na výslovný pokyn uživatele), jinak koncept'],
        ];
        $page = [
            'titulek' => $text('Název stránky (zobrazí se v navigaci a jako nadpis)'), 'text' => $text('Obsah stránky jako HTML'),
            'adresa' => $text('Část adresy za doménou (seo_link); bez ní vznikne z názvu'), 'popis' => $text('Popis pro vyhledávače, do 160 znaků'),
            'v_menu' => ['type' => 'boolean', 'description' => 'true = odkaz v hlavní navigaci webu'], 'poradi' => $number('Pořadí v navigaci, menší = dřív'),
            'zobrazit' => ['type' => 'boolean', 'description' => 'true = stránka je na webu vidět (jen na výslovný pokyn uživatele), jinak skrytá'],
            'seo_titulek' => $text('Titulek pro vyhledávače (nepovinné, jinak název)'), 'obrazek' => $text('Obrázek pro sdílení na sociálních sítích (cesta z médií)'),
            'noindex' => ['type' => 'boolean', 'description' => 'true = skrýt stránku před vyhledávači'],
            'nadrazena' => $number('ID nadřazené stránky – adresa bude /nadrazena/stranka (0 = žádná)'),
            'jazyk' => $text('jazyková verze stránky u vícejazyčného webu (kód, např. en; prázdné = výchozí jazyk)'),
            'preklad_z' => $number('ID protějšku ve výchozím jazyce (u stránky jiné jazykové verze) – přepínač jazyků a hreflang'),
            'kopie_stavby' => ['type' => 'boolean', 'description' => 'jen u nové stránky s preklad_z: koncept začne kopií stavby originálu – pro překlad pak stavba_nacti s jen_texty a stavba_uprav'],
            'zverejnit_od' => $text('naplánované zveřejnění skryté stránky RRRR-MM-DD HH:MM (jen na výslovný pokyn uživatele; prázdné = zrušit)'),
        ];
        $target = ['id' => $number('ID stránky'), 'cast' => $text('Místo stránky část webu (jen správce): ' . implode(' | ', array_keys(SiteParts::TYPES)) . ' – záhlaví, patička, obálky detailu novinky, výpisu a 404'),
            'jazyk' => $text('Jazyk části webu nebo šablony detailu kolekce u vícejazyčného webu (prázdné = výchozí)'),
            'varianta' => $text('Varianta záhlaví nebo patičky (klíč ze seznam_casti; prázdné = výchozí podoba)'),
            'kolekce' => $text('Místo stránky šablona detailu položek kolekce (adresa kolekce z seznam_kolekci, jen správce); s „jazyk“ šablona té jazykové verze'),
            'popup' => $number('Místo stránky obsah pop-up okna (ID ze seznam_popupu, jen správce)'),
            'komponenta' => $number('Místo stránky stavba komponenty (ID z list_components, jen správce) – změna se projeví všude, kde je použitá')];
        $tools = [
            ['info_o_webu', 'Název webu, úvodní stránka, šablona, počty stránek a novinek, role přihlášeného uživatele a jeho oprávnění.', $s([])],
            ['seznam_stranek', 'Stránky webu (Úvod, O nás, Služby, Kontakt…) s adresami.', $s([])],
            ['nacti_stranku', 'Celá stránka včetně HTML obsahu.', $s(['id' => $number('ID stránky')], ['id'])],
            ['vytvor_stranku', 'Založí stránku (editor a správce). Bez "zobrazit": true zůstane skrytá.', $s($page, ['titulek'])],
            ['uprav_stranku', 'Změní zadaná pole stránky; ostatní ponechá.', $s(['id' => $number('ID stránky')] + $page, ['id'])],
            ['nacti_menu', 'Menu webu (hlavní nebo v patičce) pro jazykovou verzi: položky s podmenu a jestli se hlavní menu zatím skládá automaticky ze stránek „v menu“.',
                $s(['umisteni' => $text('hlavni (výchozí) | paticka'), 'jazyk' => $text('jazyková verze (prázdné = výchozí)')])],
            ['uloz_menu', 'Uloží celé menu (správce). Položky: {"typ":"stranka","ids":5,"text":""} (prázdný text = název stránky) | {"typ":"odkaz","text":"…","url":"https://… nebo /cesta","nove_okno":false} | {"typ":"novinky"} | {"typ":"skupina","text":"Služby"} – každá může mít "deti" (jedna úroveň podmenu). null = hlavní menu zase automaticky. Menu nemá koncept – projeví se na webu hned; skrytá stránka se v něm ukáže až po zveřejnění.',
                $s(['umisteni' => $text('hlavni | paticka'), 'jazyk' => $text('jazyková verze (prázdné = výchozí)'), 'polozky' => ['type' => ['array', 'null'], 'items' => ['type' => 'object'], 'description' => 'položky menu']], ['umisteni', 'polozky'])],
            ['stavba_schema', 'Jak se skládá stránka v builderu: typy prvků a jejich pole, vlastnosti stylu, tokeny design systému (barvy, mezery, písmo), hotové sekce knihovny a sdílené třídy webu. Načti před prvním použitím nástrojů stavba_*. Vrací stručný přehled (prvek na řádek); úplné definice vybraných prvků přes parametr prvky.',
                $s(['prvky' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'typy prvků, pro které chceš úplnou definici (popisky polí, výchozí děti), např. ["formular","karusel"]'],
                    'uplne' => ['type' => 'boolean', 'description' => 'true = celé schéma se všemi popisky (velké)']])],
            ['stavba_nacti', 'Stavba stránky nebo části webu (strom prvků s id) – rozpracovaný koncept, jinak publikovaná verze. Vynechává výchozí hodnoty. Stránka bez stavby vrátí stavbu z jejího textu. '
                . 'S jen_texty jen texty a odkazy prvků podle id (pro překlad: vrať je operacemi „uprav“ ve stavba_uprav).',
                $s($target + ['jen_texty' => ['type' => 'boolean', 'description' => 'true = místo stavby seznam texty: [{id, typ, obsah: jen textové vlastnosti a odkazy, atributy}]']])],
            ['stavba_uprav', 'Dílčí úpravy konceptu podle id prvků (id ze stavba_nacti) – oprava textu, odkazu nebo stylu bez posílání celé stavby. Operace: '
                . '{"op":"uprav","id":"…","obsah":{…},"styl":{"mobil":{"mezera":"s"}},"tridy":[…]} (obsah a styl se slučují, null hodnotu odebere) | {"op":"nahrad","id":"…","prvek":{…}} | {"op":"smaz","id":"…"} | '
                . '{"op":"vloz","prvky":[…],"do":"id rodiče nebo null = kořen","pozice":0 | "za":"id" | "pred":"id"} | {"op":"presun","id":"…","do":…,"za":…}.',
                $s($target + ['operace' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'seznam operací, provedou se postupně'],
                    'publikovat' => ['type' => 'boolean', 'description' => 'true = publikovat (jen na výslovný pokyn uživatele)']], ['operace'])],
            ['seznam_trid', 'Sdílené třídy webu (karta, tmava…) s jejich stylem po stavech a vlastním CSS. Třídu dostane prvek v poli "tridy".', $s(['nazev' => $text('jen tahle třída (nepovinné)')])],
            ['uloz_tridy', 'Založí nebo změní sdílené třídy (správce) – změna se hned projeví na celém webu. Zadej CSS jako v bloku <style>: pravidla jedné třídy (.karta { … }), '
                . '.karta:hover { … } a @media (max-width: 1023px) = tablet, (max-width: 767px) = mobil. Tokeny var(--ka-…), i přepis tokenů v třídě (--ka-barva-text: #fff) pro tmavé pásy.',
                $s(['css' => $text('pravidla tříd; slučují se se stávajícími – samotné .karta:hover nebo @media nechá základ třídy beze změny'),
                    'nahradit' => ['type' => 'boolean', 'description' => 'true = třídy z css nahradit celé (základ i všechny stavy)'],
                    'smazat' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'názvy tříd ke smazání']])],
            ['stavba_z_html', 'DOPORUČENÁ CESTA pro novou stránku nebo sekce: napiš sémantické HTML (section/header, h1–h3, p, ul, a, img, figure, blockquote, details) a vzhled do bloku <style> jako pravidla jedné třídy (.karta { … }, .karta:hover { … }) s tokeny var(--ka-…); '
                . 'breakpointy od desktopu dolů: @media (max-width: 1023px) = tablet, @media (max-width: 767px) = mobil. Prvek s třídou z <style> nedostane výchozí styl – rozložení (display:grid, gap) patří do třídy. Převede se na stavbu a třídy; vrátí hlášení, co převést nešlo. Uloží se jako koncept.',
                $s(['html' => $text('HTML obsahu (bez <html>/<head>); <style> smí být uvnitř. Záhlaví a patičku skládej z prvků logo, navigace a udaje přes stavba_uloz – HTML je nepřevede.'), 'id' => $number('ID stránky; bez něj (a bez cast) vznikne nová skrytá stránka s názvem z parametru titulek'), 'cast' => $target['cast'], 'jazyk' => $target['jazyk'], 'varianta' => $target['varianta'], 'kolekce' => $target['kolekce'], 'popup' => $target['popup'], 'titulek' => $text('Název nové stránky (když není id)'),
                    'rezim' => $text('nahradit (výchozí) = celá stavba z HTML | pridat = sekce na konec stávající stavby'), 'prepsat_tridy' => ['type' => 'boolean', 'description' => 'true = třídy, které už na webu jsou, se přepíšou stylem z <style>; jinak zůstanou'],
                    'publikovat' => ['type' => 'boolean', 'description' => 'true = hned publikovat (jen na výslovný pokyn uživatele); jinak koncept k náhledu']], ['html'])],
            ['stavba_uloz', 'Uloží celou stavbu stránky (strom z stavba_nacti s úpravami) jako koncept. Pro drobné úpravy obsahu a stylu jednotlivých prvků. Vrátí vyčištěnou stavbu, chyby a kontrolu před publikováním.',
                $s($target + ['stavba' => ['type' => 'object', 'description' => '{"v":1,"deti":[…]} podle stavba_schema'], 'publikovat' => ['type' => 'boolean', 'description' => 'true = publikovat (jen na výslovný pokyn uživatele)']], ['stavba'])],
            ['vloz_sekci', 'Vloží hotovou sekci z knihovny (úvod, výhody, služby, čísla, reference, faq, výzva, novinky, kontakt) na konec konceptu stránky nebo části webu.', $s($target + ['sekce' => $text('klíč sekce ze stavba_schema → knihovna'), 'saved_section' => $number('místo sekce z knihovny sekce uložená v builderu (ID ze stavba_schema → saved_sections)')])],
            ['publikuj_stavbu', 'Publikuje koncept stavby stránky nebo části webu (jen na výslovný pokyn uživatele). Předchozí verze zůstane v historii.', $s($target)],
            ['stavba_verze', 'Publikované verze stavby stránky nebo části webu (posledních 20): idr, kdy, kdo. Starší verzi načte do konceptu obnov_verzi.', $s($target)],
            ['obnov_verzi', 'Načte starší publikovanou verzi (idr ze stavba_verze) do konceptu – na webu se ukáže až po publikování.', $s($target + ['idr' => $number('ID verze ze stavba_verze')], ['idr'])],
            ['zahod_koncept', 'Zahodí rozpracovaný koncept stavby – vrátí se publikovaná podoba (jen na výslovný pokyn uživatele; nejde vrátit).', $s($target)],
            ['seznam_casti', 'Části webu z builderu (záhlaví, patička, obálky novinky, výpisu a 404) a varianty záhlaví a patičky: klíč, název, stránky, na kterých platí, a stav (správce).', $s([])],
            ['uloz_variantu', 'Založí nebo změní variantu záhlaví či patičky pro vybrané stránky (správce) – např. záhlaví bez menu pro kampaňovou stránku. Nová začíná kopií výchozí podoby jako koncept; '
                . 'pak ji uprav stavba_* s parametrem varianta a publikuj. smazat = true variantu odstraní (vybrané stránky dostanou výchozí podobu).',
                $s(['cast' => $text('hlavicka | paticka'), 'jazyk' => $target['jazyk'], 'varianta' => $text('klíč existující varianty – jen při úpravě nebo smazání'), 'nazev' => $text('název varianty, např. Kampaň bez menu'),
                    'stranky' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ID stránek, na kterých varianta platí'],
                    'smazat' => ['type' => 'boolean', 'description' => 'true = variantu smazat (jen na výslovný pokyn uživatele)']], ['cast'])],
            ['uprav_design_system', 'Změní vzhled celého webu (správce): barvy, písma, velikosti, šířku, zaoblení – nebo použije předvolbu. Nezadané hodnoty zůstanou. Vrátí kontrolu čitelnosti barev.',
                $s(['predvolba' => $text('firemni | remeslo | pratelsky | elegantni | technologie (nepovinné)'), 'ds' => ['type' => 'object', 'description' => 'Změny, např. {"barvy":{"primarni":"#0f766e"},"pismo_titulky":"klasicke","zaobleni":"l"} – klíče viz stavba_schema → design_system']])],
            ['seznam_popupu', 'Pop-up okna webu (jen správce): typ, spouštěč, četnost, pravidla, zapnuté, publikované a počitadla zobrazení, zavření a konverzí. Obsah okna se staví nástroji stavba_* s parametrem popup.', $s([])],
            ['uloz_popup', 'Založí pop-up okno (bez id; vzor = hotový obsah) nebo změní jeho nastavení (s id) – jen správce. Nové okno je vypnuté; zapnout (aktivni: true) jde až po publikování jeho stavby, a jen na výslovný pokyn uživatele.',
                $s(['id' => $number('ID okna – jen při úpravě'), 'nazev' => $text('Název (vidí ho čtečky obrazovky)'), 'vzor' => $text('Jen u nového: ' . implode(' | ', array_keys(\Kaleta\Builder\Popups::LIBRARY))),
                    'adresa' => $text('Adresa pro odkaz #popup-<adresa>'), 'typ' => $text(implode(' | ', array_keys(\Kaleta\Builder\Popups::TYPES))),
                    'spoustec' => $text(implode(' | ', array_keys(\Kaleta\Builder\Popups::TRIGGERS)) . ' – klik = jen odkazem #popup-<adresa>'),
                    'hodnota' => $number('Sekundy (cas, necinnost), procenta stránky (posun), počet stránek v návštěvě (stranky)'),
                    'cetnost' => $text(implode(' | ', array_keys(\Kaleta\Builder\Popups::FREQUENCIES))), 'dni' => $number('Počet dní u četnosti dni'),
                    'pravidla' => ['type' => 'object', 'description' => '{"kde":"vse|vybrane","stranky":[id],"kolekce":["adresa"],"novinky":true,"jazyk":"en","od":"RRRR-MM-DD","do":"RRRR-MM-DD","zarizeni":"vse|pocitac|telefon","utm":"text z utm_*","odkud":"část adresy webu, odkud návštěvník přišel"} – vynechané klíče zůstanou'],
                    'aktivni' => ['type' => 'boolean', 'description' => 'true = okno se ukazuje na webu (jen publikované, jen na výslovný pokyn uživatele)'],
                    'poradi' => $number('Pořadí, menší = přednost')])],
            ['seznam_kolekci', 'Kolekce webu (reference, tým, produkty…) s poli a počty položek. Na web je dostane prvek „kolekce“ (Výpis kolekce) ve stavbě; uvnitř se {{klic}} nahradí hodnotou položky ({{nazev}}, {{url}} = detail, {{datum}} a vlastní pole).', $s([])],
            ['vytvor_kolekci', 'Založí kolekci (správce). Pole: seznam {popisek, typ}; typ = ' . implode(' | ', array_keys(Collections::FIELD_TYPES)) . '. Klíč pole vznikne z popisku.',
                $s(['nazev' => $text('Název, např. Reference'), 'adresa' => $text('Adresa kolekce v URL (nepovinné, jinak z názvu), např. guide'),
                    'pole' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{"popisek":"Citát","typ":"radky"},{"popisek":"Logo","typ":"obrazek"}]'],
                    'detail' => ['type' => 'boolean', 'description' => 'true = každá položka má vlastní stránku /<kolekce>/<položka>']], ['nazev'])],
            ['uprav_kolekci', 'Změní název, adresu, stránky položek nebo pole kolekce (správce). Pole = celý nový seznam; u stávajících pošli i "klic" (hodnoty položek zůstanou), pole bez klíče je nové, vynechané pole zmizí z formuláře.',
                $s(['kolekce' => $text('současná adresa (seo_link) kolekce'), 'nazev' => $text('nový název (nepovinné)'), 'adresa' => $text('nová adresa v URL (nepovinné)'),
                    'detail' => ['type' => 'boolean', 'description' => 'stránky položek zapnuté / vypnuté (nepovinné)'],
                    'pole' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{"klic":"citat","popisek":"Citát","typ":"radky"},{"popisek":"Nové pole","typ":"text"}] (nepovinné)']], ['kolekce'])],
            ['seznam_polozek_kolekce', 'Položky kolekce včetně hodnot polí, po 50 na stránku (celkem vrací počet). Filtr: hledaný text v názvu a hodnotách, pole=hodnota, jazyk, jen zobrazené.', $s([
                'kolekce' => $text('adresa (seo_link) kolekce'), 'hledat' => $text('text v názvu nebo hodnotách polí (nepovinné)'),
                'pole' => $text('klíč pole pro přesnou shodu (nepovinné)'), 'hodnota' => $text('hodnota pole pro přesnou shodu'),
                'jazyk' => $text('jazyková verze (prázdné = výchozí; nepovinné)'), 'jen_zobrazene' => ['type' => 'boolean', 'description' => 'jen položky zobrazené na webu'],
                'strana' => $number('stránka od 1'),
            ], ['kolekce'])],
            ['uloz_polozku_kolekce', 'Přidá položku do kolekce, nebo změní existující (s id). Bez "zobrazit": true zůstane skrytá.',
                $s(['kolekce' => $text('adresa (seo_link) kolekce'), 'id' => $number('ID položky – jen při úpravě'), 'nazev' => $text('Název položky (u nové povinný, při úpravě jen když se mění)'),
                    'adresa' => $text('Adresa položky v URL (nepovinné, jinak z názvu), např. install'), 'jazyk' => $text('jazyková verze položky u vícejazyčného webu (prázdné = výchozí); překlad má stejnou adresu jako originál – přepínač jazyků a hreflang je propojí'),
                    'data' => ['type' => 'object', 'description' => 'Hodnoty polí podle klíčů ze seznam_kolekci, např. {"citat":"…","logo":"media/…"}'],
                    'poradi' => $number('Pořadí, menší = dřív'), 'zobrazit' => ['type' => 'boolean', 'description' => 'true = položka je na webu (jen na pokyn uživatele)']], ['kolekce'])],
            ['seznam_novinek', 'Seznam novinek (nejnovější první).', $s(['stav' => $text('vse | vydane | plan | koncepty'), 'kategorie' => $text('název nebo adresa kategorie'), 'hledat' => $text('text v titulku'), 'limit' => $number('1-50, výchozí 20')])],
            ['nacti_novinku', 'Celá novinka včetně textu a štítků.', $s(['id' => $number('ID novinky (idc)')], ['id'])],
            ['vytvor_novinku', 'Založí novinku. Bez "vydat": true vznikne koncept.', $s($newsItem, ['titulek', 'kategorie'])],
            ['uprav_novinku', 'Změní zadaná pole novinky; ostatní ponechá. Předchozí verze se uloží do historie.', $s(['id' => $number('ID novinky')] + $newsItem, ['id'])],
            ['seznam_kategorii', 'Kategorie novinek s počty.', $s([])],
            ['vytvor_kategorii', 'Založí kategorii novinek (editor a správce).', $s(['nazev' => $text('Název'), 'popis' => $text('Popis (HTML)')], ['nazev'])],
            ['seznam_medii', 'Naposledy nahrané obrázky a soubory s adresami a rozměry.', $s(['limit' => $number('1-50, výchozí 20'), 'hledat' => $text('text v názvu (nepovinné)')])],
            ['nahraj_soubor', 'Nahraje soubor do Médií: obrázek (JPG, PNG, WebP, GIF – zmenší se a dostane WebP/AVIF varianty), SVG (vyčistí se), písmo WOFF2 pro design system nebo přílohu (PDF…). '
                . 'Zadej url veřejného souboru (https – obrázek, písmo, PDF; u větších souborů vždy url), nebo data v base64 (nejvýš ' . (self::MAX_UPLOAD >> 20) . ' MB). Vrátí adresu pro prvek obrázek, obrazek_pozadi nebo vlastni_pisma.',
                $s(['nazev' => $text('název souboru s příponou, např. tym-praha.jpg'), 'data' => $text('obsah souboru v base64'), 'url' => $text('https adresa souboru ke stažení (místo data)'),
                    'popis' => $text('popis obrázku pro nevidomé (alt); jinak z názvu')], ['nazev'])],
            ['nahled_odkaz', 'Podepsaný odkaz na náhled konceptu stránky nebo části webu – otevře ho kdokoli i bez přihlášení (uživatel, kolega, prohlížeč), platí jen pro tenhle cíl a jen omezenou dobu. Vyhledávače ho neindexují.',
                $s($target + ['minut' => $number('platnost v minutách, výchozí 60, nejvýš ' . \Kaleta\Core\Preview::MAX_MINUTES), 'web' => ['type' => 'boolean', 'description' => 'true = celý web se všemi koncepty a konceptem vzhledu']])],
            ['uprav_nastaveni', 'Změní nastavení webu (správce) – hned se projeví na webu. Klíče: nazev_webu, popis_webu, text_paticky, logo_webu, favicon a og_obrazek – obrázek pro sdílení 1200×630 (cesta media/… z nahraj_soubor nebo image/…), titulni_stranka (ID úvodní stránky), soc_facebook|instagram|x|youtube|linkedin (URL), '
                . 'pocet_clanku, sdileni, osnova_clanku, souvisejici_auto (1/0), tmavy_rezim (vypnuto | auto = podle zařízení | tmavy = vždy tmavý), tmavy_prepinac (1/0 = přepínač vzhledu pro návštěvníky), údaje firmy firma_nazev, firma_typ, firma_ico, firma_dic, firma_rejstrik (zápis v rejstříku), firma_zastupce (kdo firmu zastupuje), firma_ulice, firma_mesto, firma_psc, firma_zeme (CZ), firma_telefon, firma_hodiny (den na řádek), firma_mapa, firma_gps; nazev_webu_en… pro jazykové verze. Bez parametru vrátí současné hodnoty.',
                $s(['nastaveni' => ['type' => 'object', 'description' => '{"klic":"hodnota"}']])],
            ['seznam_poptavek', 'Poptávky z formulářů webu (rozšíření Formuláře a poptávky; jen s právem k Poptávkám), nejnovější první: datum, formulář, stránka, kampaň (utm), e-mail, stav a vyplněná pole. Obsahují osobní údaje – používej je jen k tomu, oč uživatel žádá.',
                $s(['stav' => $text('nove | prectene | vyrizene | vse (výchozí)'), 'hledat' => $text('text v e-mailu nebo obsahu (nepovinné)'), 'limit' => $number('1-50, výchozí 20')])],
            ['seznam_presmerovani', 'Přesměrování starých adres (rozšíření Přesměrování) a nejčastější adresy, které skončily chybou 404.', $s([])],
            ['uloz_presmerovani', 'Přidá nebo změní přesměrování (správce): ze staré cesty na webu na novou cestu nebo https adresu. Typ 301 = natrvalo (výchozí), 302 = dočasně.',
                $s(['z' => $text('stará cesta, např. /docs nebo /o-nas'), 'na' => $text('nová cesta (/guide) nebo https://…'), 'typ' => $number('301 nebo 302'), 'smazat' => ['type' => 'boolean', 'description' => 'true = přesměrování ze staré cesty smazat']], ['z'])],
            ['list_trash', 'Pages, news items and collection items in the trash (deleted in the last 30 days, then removed for good), with the date of deletion – what restore_from_trash can bring back.', $s([])],
            ['restore_from_trash', 'Brings a page, news item or collection item back from the trash. It comes back hidden (a news item as a draft) – make it visible only when the user asks.',
                $s(['type' => $text('page | news | collection_item'), 'id' => $number('ID from list_trash')], ['type', 'id'])],
            ['trash_news', 'Moves a news item to the trash (only when the user explicitly asks). It disappears from the site and can be restored for 30 days. A published one needs the publishing permission.', $s(['id' => $number('news item ID')], ['id'])],
            ['delete_collection_item', 'Moves a collection item to the trash (only when the user explicitly asks). It disappears from the site and can be restored for 30 days.',
                $s(['collection' => $text('collection slug'), 'id' => $number('item ID')], ['collection', 'id'])],
            ['delete_collection', 'Deletes a whole collection with all its items and its item template, for good (administrators; only when the user explicitly asks for this collection). Lists and pages that show it become empty.',
                $s(['collection' => $text('collection slug')], ['collection'])],
            ['update_category', 'Changes a news category: name, description, slug (the old address redirects) or order (editors and administrators).',
                $s(['id' => $number('category ID from list_categories'), 'name' => $text('new name'), 'description' => $text('description as HTML'), 'slug' => $text('new slug'), 'order' => $number('order, lower = first')], ['id'])],
            ['delete_category', 'Deletes an empty news category (editors and administrators; only when the user explicitly asks). A category with news items – even in the trash – cannot be deleted.', $s(['id' => $number('category ID')], ['id'])],
            ['delete_popup', 'Deletes a pop-up window for good, with its counters (administrators; only when the user explicitly asks).', $s(['id' => $number('pop-up ID from list_popups')], ['id'])],
            ['list_components', 'Components of the site: a reusable block with properties (name, button text…) placed on pages with the komponenta element. Edit the build with the *_build tools and the component parameter.', $s([])],
            ['save_component', 'Creates a component (without id – it starts with an empty section) or renames it and changes its properties (administrators). Properties: [{"klic":"title","popisek":"Title","typ":"text","vychozi":"…"}].',
                $s(['id' => $number('component ID – only when changing it'), 'name' => $text('component name'),
                    'properties' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'the whole list of properties: klic, popisek, typ (text | radky | obrazek | odkaz), vychozi']])],
            ['delete_component', 'Deletes a component for good (administrators; only when the user explicitly asks). Places where it is used become empty.', $s(['id' => $number('component ID')], ['id'])],
            ['save_section', 'Saves an element of a build (usually a section) as a reusable section – it then appears under saved sections in the builder and in builder_schema.',
                $s($target + ['element' => $text('element id from get_build'), 'name' => $text('name of the saved section')], ['element', 'name'])],
            ['delete_section', 'Deletes a saved section (administrators; only when the user explicitly asks). Pages where it was inserted keep their copy.', $s(['id' => $number('saved section ID')], ['id'])],
            ['update_media', 'Changes the description of a file in Media: alt (the text for screen readers, also the name), caption and author. Only the owner or an administrator.',
                $s(['id' => $number('file ID from list_media'), 'alt' => $text('alternative text'), 'caption' => $text('caption below the image'), 'author' => $text('photo author')], ['id'])],
            ['delete_media', 'Deletes a file from Media for good (only when the user explicitly asks; the owner or an administrator). A file still used on the site is not deleted – the error says where it is used.', $s(['id' => $number('file ID from list_media')], ['id'])],
            ['update_enquiry', 'Marks an enquiry new, read or resolved and writes an internal note (users with the Enquiries section).',
                $s(['id' => $number('enquiry ID from list_enquiries'), 'status' => $text('new | read | resolved'), 'note' => $text('internal note (replaces the previous one)')], ['id'])],
            ['delete_enquiry', 'Deletes an enquiry with its attachments for good (only when the user explicitly asks – for example a request to erase personal data).', $s(['id' => $number('enquiry ID')], ['id'])],
            ['apply_part_template', 'Puts a ready-made template into the draft of a site part (administrators): a clean skeleton of the header, the footer or a wrapper whose look comes from the design system. The published version stays until publish_build with the part. Templates are in builder_schema → part_templates.',
                $s(['part' => $text('header | footer | news_item | news_list | not_found'), 'template' => $text('template key from builder_schema → part_templates'),
                    'language' => $text('language version of the part (empty = default)'), 'variant' => $text('header or footer variant (empty = the default version)')], ['part', 'template'])],
            ['publish_look', 'Publishes the draft look – design system, shared classes and menus changed by update_design_system, save_classes and save_menu (administrators; only when the user explicitly asks, after they saw the preview). The published look is kept as a version first.', $s([])],
            ['discard_look', 'Throws the draft look away – the published look stays (administrators; only when the user explicitly asks).', $s([])],
            ['list_look_versions', 'Earlier published looks (the last 20), with what the next publishing changed. restore_look_version brings one back into the draft.', $s([])],
            ['restore_look_version', 'Loads an earlier published look into the draft look (administrators) – check it with preview_link site: true, then publish_look.', $s(['id' => $number('version ID from list_look_versions')], ['id'])],
            ['list_newsletters', 'Newsletters (Newsletter extension; users with the Newsletters section): drafts, scheduled, being sent and sent, with counts of recipients, sent and failed e-mails, the number of confirmed subscribers and sending_problem – why the site cannot send now (no SMTP server, cron not running).', $s([])],
            ['draft_newsletter', 'Creates a newsletter draft (without id) or changes a draft or a scheduled one (with id). There is no e-mail builder: one template styled by the design system (colours, fonts, logo) with the subject, an introduction, news items, an optional button and the company footer with an unsubscribe link. Returns the plain-text version to check; the admin shows the HTML preview.',
                $s(['id' => $number('newsletter ID – only when changing it'), 'subject' => $text('subject of the e-mail, also its heading'), 'preheader' => $text('preview text next to the subject in the inbox (optional)'),
                    'intro' => $text('introduction as plain text; an empty line starts a new paragraph, web addresses become links'),
                    'news_mode' => $text('latest (the latest news_count items at the time of sending, default) | chosen (news_ids) | none'),
                    'news_count' => $number('how many of the latest news items, 1–' . \Kaleta\Core\Mailing::MAX_NEWS . ', default 3'),
                    'news_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'chosen news item IDs in order (list_news), with news_mode chosen'],
                    'button_label' => $text('button text (optional, with button_url)'), 'button_url' => $text('button link: a path on the site (/contact) or https://…'),
                    'language' => $text('language of the footer texts and the latest news on a multilingual site (code; empty = default)')])],
            ['send_test_newsletter', 'Sends the newsletter as a test to the connected user\'s own e-mail address – subscribers get nothing. Use it before asking the user to send.', $s(['id' => $number('newsletter ID')], ['id'])],
            ['send_newsletter', 'Sends the newsletter to all confirmed subscribers now, or schedules it with at. It cannot be taken back: only with the publishing permission and ONLY when the user explicitly asks to send it. Needs an SMTP server and a running cron (sending_problem in list_newsletters). unschedule: true turns a scheduled one back into a draft.',
                $s(['id' => $number('newsletter ID'), 'at' => $text('YYYY-MM-DD HH:MM to schedule; empty = now'), 'unschedule' => ['type' => 'boolean', 'description' => 'true = cancel the scheduled sending']], ['id'])],
            ['delete_newsletter', 'Deletes a newsletter – a draft, a scheduled or a sent one (not one being sent). Only when the user explicitly asks.', $s(['id' => $number('newsletter ID')], ['id'])],
            ['smaz_stranku', 'Přesune stránku do koše (jen na výslovný pokyn uživatele; editor nebo správce). Z koše jde 30 dní obnovit v administraci. Úvodní stránku smazat nejde.', $s(['id' => $number('ID stránky')], ['id'])],
        ];

        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky')) {
            $tools = array_filter($tools, fn (array $n): bool => !in_array($n[0], self::NEWS_TOOLS, true));
        }
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'newsletter')) {
            $tools = array_filter($tools, fn (array $n): bool => !in_array($n[0], self::NEWSLETTER_TOOLS, true));
        }
        $tools = array_filter($tools, fn (array $n): bool => ($extension = self::CONTENT_TOOLS[$n[0]] ?? '') === '' || \Kaleta\Core\Extensions::isEnabled($this->app->settings(), $extension));

        return array_values(array_map(fn (array $n): array => ['name' => $n[0], 'description' => $n[1], 'inputSchema' => $n[2]], $tools));
    }

    /** @return list<string> Czech names of all tools (including disabled extensions) */
    public function names(): array
    {
        return array_values(array_unique([...array_column($this->listAll(), 'name'), ...self::NEWS_TOOLS, ...self::NEWSLETTER_TOOLS, ...array_keys(self::CONTENT_TOOLS)]));
    }

    /** Tools of the News extension – with the extension disabled they are neither offered nor run. */
    private const array NEWS_TOOLS = ['seznam_novinek', 'nacti_novinku', 'vytvor_novinku', 'uprav_novinku', 'seznam_kategorii', 'vytvor_kategorii'];

    /** Tools of 1.6 for everything the admin can do (English names only), with the extension they need ('' = none). */
    private const array CONTENT_TOOLS = ['list_trash' => '', 'restore_from_trash' => '', 'trash_news' => 'novinky', 'delete_collection_item' => '', 'delete_collection' => '',
        'update_category' => 'novinky', 'delete_category' => 'novinky', 'delete_popup' => '', 'list_components' => '', 'save_component' => '', 'delete_component' => '',
        'save_section' => '', 'delete_section' => '', 'update_media' => '', 'delete_media' => '', 'update_enquiry' => 'poptavky', 'delete_enquiry' => 'poptavky',
        'publish_look' => '', 'discard_look' => '', 'list_look_versions' => '', 'restore_look_version' => '', 'apply_part_template' => ''];

    /** Site parts by their English names (MCP) => Czech types. */
    private const array PART_NAMES = ['header' => 'hlavicka', 'footer' => 'paticka', 'news_item' => 'novinka', 'news_list' => 'vypis', 'not_found' => 'nenalezeno'];

    /** Newsletter tools (1.5; English names only – they never had Czech ones). */
    private const array NEWSLETTER_TOOLS = ['list_newsletters', 'draft_newsletter', 'send_test_newsletter', 'send_newsletter', 'delete_newsletter'];

    /** Tools that remove or overwrite something the user may want back, or that cannot be taken back (sending). */
    private const array DESTRUCTIVE_TOOLS = ['smaz_stranku', 'zahod_koncept', 'obnov_verzi', 'send_newsletter', 'delete_newsletter', 'trash_news', 'delete_collection_item',
        'delete_collection', 'delete_category', 'delete_popup', 'delete_component', 'delete_section', 'delete_media', 'delete_enquiry', 'discard_look', 'publish_look'];

    /**
     * MCP annotations of a tool, so a client knows what to confirm with the user: reads, writes, and writes that remove
     * something or cannot be taken back. Every tool works only on this site.
     *
     * @return array{readOnlyHint: bool, destructiveHint: bool, openWorldHint: bool}
     */
    public function annotations(string $name): array
    {
        return ['readOnlyHint' => !$this->isWriteTool($name), 'destructiveHint' => in_array($name, self::DESTRUCTIVE_TOOLS, true),
            'openWorldHint' => $name === 'nahraj_soubor']; // an upload from a URL reaches outside the site
    }

    public function isWriteTool(string $name): bool
    {
        return in_array($name, ['obnov_verzi', 'zahod_koncept', 'uloz_variantu', 'vytvor_kolekci', 'uprav_kolekci', 'uloz_polozku_kolekce', 'uloz_popup', 'stavba_z_html', 'stavba_uloz', 'stavba_uprav', 'uloz_tridy', 'nahraj_soubor', 'uprav_nastaveni', 'uloz_presmerovani', 'smaz_stranku', 'vloz_sekci', 'publikuj_stavbu', 'uprav_design_system', 'vytvor_stranku', 'uprav_stranku', 'vytvor_novinku', 'uprav_novinku', 'vytvor_kategorii', 'uloz_menu', 'draft_newsletter', 'send_test_newsletter', 'send_newsletter', 'delete_newsletter',
            'restore_from_trash', 'trash_news', 'delete_collection_item', 'delete_collection', 'update_category', 'delete_category', 'delete_popup', 'save_component', 'delete_component',
            'save_section', 'delete_section', 'update_media', 'delete_media', 'update_enquiry', 'delete_enquiry', 'publish_look', 'discard_look', 'restore_look_version', 'apply_part_template'], true);
    }

    /** @param array<string, mixed> $a */
    public function call(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        if (in_array($name, self::NEWS_TOOLS, true) && !\Kaleta\Core\Extensions::isEnabled($siteSettings, 'novinky')) {
            throw new \DomainException('Novinky jsou na tomto webu vypnuté (Rozšíření).');
        }
        if (in_array($name, self::NEWSLETTER_TOOLS, true)) {
            return $this->newsletterTool($name, $a);
        }
        if (isset(self::CONTENT_TOOLS[$name])) {
            if (self::CONTENT_TOOLS[$name] !== '' && !\Kaleta\Core\Extensions::isEnabled($siteSettings, self::CONTENT_TOOLS[$name])) {
                throw new \DomainException('This tool needs an extension that is switched off on this site (Extensions).');
            }

            return $this->contentTool($name, $a);
        }
        switch ($name) {
            case 'info_o_webu':
                return [
                    'web' => $siteSettings->get('site_name'), 'adresa' => $this->app->request->origin() . $this->app->url(''), 'popis' => $siteSettings->get('site_description'),
                    'uvodni_stranka' => $siteSettings->int('home_page') ?: null, 'verze_kaleta' => KALETA_VERSION,
                    'stranek' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NULL'),
                    'novinek_vydanych' => (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL'),
                    'uzivatel' => $auth->user()['user'], 'role' => \Kaleta\Core\Auth::TYPES[(int) $auth->user()['admin']], 'smi_vydavat' => $auth->canPublish(),
                    'smi_upravovat_stranky' => $auth->hasModule('pages'),
                    // what the site has switched on, so Claude does not guess (extension keys: novinky, poptavky, newsletter…)
                    'extensions' => \Kaleta\Core\Extensions::enabled($siteSettings),
                    'languages' => ['default' => Language::defaults($siteSettings),
                        'additional' => array_map(fn (string $code): array => ['code' => $code, 'published' => in_array($code, Language::published($siteSettings, $db), true)], Language::additional($siteSettings))],
                    'cron_last_run_minutes' => $siteSettings->int('tasks_last_run') > 0 ? (int) floor((time() - $siteSettings->int('tasks_last_run')) / 60) : null,
                    'look_draft' => \Kaleta\Core\Look::summary($db, $siteSettings), // unpublished look changes (publish_look, discard_look)
                ];

            case 'seznam_stranek':
                $home = $siteSettings->int('home_page');

                // the URL of a language version has a prefix (/de/…); the translation of the home page is the root of its version (/de/)
                return array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'titulek' => $r['titulek'], 'adresa' => $this->app->request->origin()
                    . $this->app->url(($r['jazyk'] !== '' ? $r['jazyk'] . '/' : '') . ((int) $r['ids'] === $home || ($home > 0 && (int) $r['preklad_z'] === $home) ? '' : $r['seo_link'])),
                    'uvodni' => (int) $r['ids'] === $home, 'zobrazena' => (bool) $r['zobrazit'], 'v_menu' => (bool) $r['v_menu'], 'jazyk' => $r['jazyk']],
                    // without the Pages section (news author) only published pages – it can link to those, it does not see drafts
                    $db->all('SELECT ids, titulek, seo_link, zobrazit, v_menu, jazyk, preklad_z FROM {stranky} WHERE smazano IS NULL' . ($auth->hasModule('pages') ? '' : ' AND zobrazit = 1') . ' ORDER BY jazyk, poradi, titulek'));

            case 'nacti_stranku':
                $page = $this->page((int) ($a['id'] ?? 0));
                if (!$page['zobrazit'] && !$auth->hasModule('pages')) {
                    throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');
                }

                return $page;

            case 'vytvor_stranku':
            case 'uprav_stranku':
                if (!$auth->hasModule('pages')) {
                    throw new \DomainException('Stránky smí upravovat editor nebo správce.');
                }

                return $this->savePage($name === 'uprav_stranku' ? $this->page((int) ($a['id'] ?? 0)) : null, $a);

            case 'nacti_menu':
            case 'uloz_menu':
                $location = isset(\Kaleta\Core\Menu::LOCATIONS[$a['umisteni'] ?? '']) ? $a['umisteni'] : 'hlavni';
                $menuLanguage = in_array($a['jazyk'] ?? '', Language::additional($siteSettings), true) ? $a['jazyk'] : '';
                if ($name === 'uloz_menu') {
                    if (!$auth->isAdmin()) {
                        throw new \DomainException('Menu smí upravovat jen správce.');
                    }
                    // null restores the automatic menu – only when the caller really sent it, not when items are missing or cannot be read
                    if (!array_key_exists('polozky', $a) || ($a['polozky'] !== null && !is_array($a['polozky']))) {
                        throw new \InvalidArgumentException('Parametr polozky musí být seznam položek menu, nebo null pro automatické menu.');
                    }
                    \Kaleta\Core\Look::setMenu($siteSettings, $location, $menuLanguage, $a['polozky']); // to the draft look
                }
                [$inDraft, $saved] = \Kaleta\Core\Look::menuForEditing($db, $siteSettings, $location, $menuLanguage);

                return ['umisteni' => $location, 'jazyk' => $menuLanguage, 'automaticke' => $saved === null, 'polozky' => $saved ?? [],
                    'look_draft' => $inDraft ? 'the items are in the draft look – visitors see them after publish_look' : null,
                    'na_webu' => \Kaleta\Core\Menu::items($this->app, $location, $menuLanguage, $siteSettings->int('home_page')),
                    'stranky' => $db->all('SELECT ids, titulek, zobrazit FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$menuLanguage])];

            case 'stavba_schema':
                $schema = Build::schema($auth->isAdmin(), Language::defaults($siteSettings), $auth->isAdmin(), \Kaleta\Core\Extensions::enabled($siteSettings));
                if (!empty($a['_english'])) {
                    return $this->englishSchema($schema, $a);
                }
                $selected = is_array($a['prvky'] ?? null) ? array_values(array_filter($schema['prvky'], fn (array $p): bool => in_array($p['typ'], $a['prvky'], true))) : [];
                if ($selected !== [] && empty($a['uplne'])) {
                    return ['prvky' => $selected];
                }

                return (empty($a['uplne']) ? Build::overview($schema) : $schema) + [
                    'komponenty' => array_map(fn (array $k): array => ['id' => (string) $k['idm'], 'nazev' => $k['nazev'], 'vlastnosti' => $k['vlastnosti']], \Kaleta\Builder\Components::all($db))
                        + ['pozn' => 'Použití: {"typ":"komponenta","obsah":{"komponenta":"<id>","hodnoty":{"<klic>":"hodnota"}}}; prázdná hodnota = výchozí.'],
                    'casti_webu' => array_map(fn (array $t): string => $t[0] . ' – ' . $t[1], SiteParts::TYPES) + ['pozn' => 'Prvky ze skupiny „Části webu“ (logo, navigace, udaje, obsah) patří jen do částí; obálka (novinka, vypis, nenalezeno) musí obsahovat právě jeden prvek „obsah“.'],
                    'knihovna' => empty($a['uplne']) ? array_column(array_map(fn (array $k): array => ['klic' => $k['klic'], 'popis' => $k['nazev'] . ' – ' . $k['popis']], Library::listAll(\Kaleta\Core\Extensions::enabled($siteSettings))), 'popis', 'klic')
                        : Library::listAll(\Kaleta\Core\Extensions::enabled($siteSettings)),
                    'saved_sections' => array_map(fn (array $r): array => ['id' => (int) $r['idx'], 'name' => $r['nazev']], $db->all('SELECT idx, nazev FROM {sekce} ORDER BY nazev LIMIT 200'))
                        + ['note' => 'Sections saved in the builder: insert_section with saved_section: <id>.'],
                    'tridy_webu' => array_column($db->all('SELECT nazev FROM {tridy} ORDER BY nazev'), 'nazev'),
                    'design_system' => DesignSystem::load($siteSettings) + ['predvolby' => array_map(fn (array $p): string => $p[0] . ' – ' . $p[1], DesignSystem::PRESETS),
                        'pisma_titulku' => array_keys(SiteIdentity::TITLE_FONTS), 'pisma_textu' => array_keys(SiteIdentity::TEXT_FONTS)],
                    'css_tokeny' => 'V <style> a vlastním CSS používej var(--ka-barva-primarni|sekundarni|text|tlumeny|pozadi|plocha|linka|primarni-jemna|na-primarni), var(--ka-mezera-2xs…3xl), var(--ka-krok--1…5) pro velikost písma, var(--ka-zaobleni), var(--ka-stin-s|m|l), var(--ka-sirka).',
                ];

            case 'stavba_nacti':
                $target = $this->loadBuildTarget($a);

                return $this->describeTarget($target) + ['publikovana' => $target['stavba'] !== null,
                    'neulozene_zmeny' => $target['koncept'] !== null && $target['koncept'] !== $target['stavba']]
                    + (!empty($a['jen_texty']) ? ['texty' => Build::texts($this->targetBuild($target))] : ['stavba' => Build::compact($this->targetBuild($target))]);

            case 'stavba_uprav':
                $target = $this->loadBuildTarget($a);
                $operationErrors = [];
                $build = \Kaleta\Builder\Edits::apply($this->targetBuild($target), is_array($a['operace'] ?? null) ? $a['operace'] : [], $operationErrors);

                return $this->saveBuild($target, $build, !empty($a['publikovat'])) + ['chyby_operaci' => $operationErrors];

            case 'seznam_trid':
                // with the draft look: Claude works on what will be published (draft = changed in the draft look)
                $classes = \Kaleta\Core\Look::classes($db, $siteSettings, true);
                if (isset($a['nazev'])) {
                    $classes = array_intersect_key($classes, [(string) $a['nazev'] => true]);
                }

                return array_values(array_map(fn (string $name, array $c): array => ['nazev' => $name, 'styl' => $c['styl'] ?: new \stdClass(), 'css' => $c['css']]
                    + ($c['draft'] ? ['draft' => true] : []), array_keys($classes), $classes));

            case 'uloz_tridy':
                $adminOnly();
                $conversion = HtmlConverter::convert('<style>' . str_ireplace('</style', '', (string) ($a['css'] ?? '')) . '</style>', true);
                $stored = [];
                $inDraft = [];
                foreach (array_unique(array_merge(array_keys($conversion['tridy']), array_keys($conversion['tridy_styl']))) as $className) {
                    // merged: a rule only for :hover or @media keeps the class base and the other states (nahradit: true = the whole class anew)
                    $previous = empty($a['nahradit']) ? (\Kaleta\Core\Look::classes($db, $siteSettings, true)[$className] ?? null) : null; // the draft, when there is one
                    $style = ($conversion['tridy_styl'][$className] ?? []) + (array) ($previous['styl'] ?? []);
                    $css = $conversion['tridy'][$className] ?? (string) ($previous['css'] ?? '');
                    // a change of an existing class goes to the draft look, a new class is live at once (it changes nothing published)
                    \Kaleta\Core\Look::setClass($siteSettings, $className, ['styl' => $style, 'css' => $css]) ? $inDraft[] = $className : $stored[] = $className;
                }
                $deleted = [];
                foreach (is_array($a['smazat'] ?? null) ? $a['smazat'] : [] as $className) {
                    if (is_string($className) && isset(\Kaleta\Core\Look::classes($db, $siteSettings, true)[$className])) {
                        \Kaleta\Core\Look::setClass($siteSettings, $className, null);
                        $deleted[] = $className;
                    }
                }

                return ['ulozeno' => $stored, 'look_draft' => $inDraft, 'smazano' => $deleted, 'hlaseni' => $conversion['hlaseni']]
                    + ($inDraft !== [] || $deleted !== [] ? ['pozn' => 'Changes of existing classes and deletions are in the draft look – check them with preview_link site: true, publish with publish_look.'] : []);

            case 'stavba_z_html':
                $target = $this->loadBuildTarget($a, true);
                ['stavba' => $build, 'hlaseni' => $messages] = HtmlConverter::saveToSite($db, (string) ($a['html'] ?? ''), $auth->isAdmin(), $auth->isAdmin() && !empty($a['prepsat_tridy']), $siteSettings); // only the administrator changes shared classes
                if (empty($a['prepsat_tridy'])) {
                    $messages = array_map(fn (string $h): string => str_ends_with($h, 'ponechána beze změny.') ? substr($h, 0, -1) . ' (prepsat_tridy: true ji přepíše).' : $h, $messages);
                }
                if (($a['rezim'] ?? '') === 'pridat') {
                    $build['deti'] = array_merge($this->targetBuild($target)['deti'], $build['deti']);
                }

                return $this->saveBuild($target, $build, !empty($a['publikovat'])) + ['hlaseni' => $messages];

            case 'stavba_uloz':
                if (!is_array($a['stavba'] ?? null)) {
                    throw new \InvalidArgumentException('Parametr stavba musí být objekt {"v":1,"deti":[…]}.');
                }

                return $this->saveBuild($this->loadBuildTarget($a), $a['stavba'], !empty($a['publikovat']));

            case 'vloz_sekci':
                $target = $this->loadBuildTarget($a);
                if ((int) ($a['saved_section'] ?? 0) > 0) {
                    // a section someone saved in the builder ("Save as section"), with fresh element ids
                    $saved = $db->value('SELECT prvek FROM {sekce} WHERE idx = ?', [(int) $a['saved_section']]) ?? throw new \InvalidArgumentException('The saved section does not exist – saved_sections in builder_schema lists them.');
                    [$clean] = Build::sanitize(['v' => Build::VERSION, 'deti' => [\Kaleta\Builder\Library::withNewIds(json_decode((string) $saved, true) ?: [])]], $auth->isAdmin());
                    $section = ['prvek' => $clean['deti'][0] ?? throw new \InvalidArgumentException('The saved section is empty.')];
                } else {
                    $section = Library::section((string) ($a['sekce'] ?? ''), $target['jazyk']) ?? throw new \InvalidArgumentException('Sekce v knihovně není. Klíče: ' . implode(', ', array_column(Library::listAll(), 'klic')) . '.');
                    Library::createClasses($db, $section['tridy']);
                }
                $build = $this->targetBuild($target);
                $build['deti'][] = $section['prvek'];

                return $this->saveBuild($target, $build, false);

            case 'publikuj_stavbu':
                $target = $this->loadBuildTarget($a);
                if (($target['koncept'] ?? $target['stavba']) === null) {
                    throw new \InvalidArgumentException('Není co publikovat.');
                }
                if (!$auth->canPublish()) {
                    throw new \DomainException('Publikovat smí jen editor nebo správce; koncept zůstává uložený.');
                }
                $this->publishTarget($target);

                return $this->describeTarget($target) + ['stav' => 'publikováno', 'adresa' => $this->targetUrl($target)]
                    + $this->checkTarget($target, Build::fromJson($target['koncept'] ?? $target['stavba']));

            case 'stavba_verze':
                $target = $this->loadBuildTarget($a);

                return $this->describeTarget($target) + ['verze' => array_map(fn (array $r): array => ['idr' => (int) $r['idr'], 'kdy' => substr((string) $r['datum'], 0, 16), 'kdo' => $r['kdo']],
                    Publisher::listAll($db, $target['revize']))];

            case 'obnov_verzi':
                $target = $this->loadBuildTarget($a);
                $json = Publisher::load($db, $target['revize'], (int) ($a['idr'] ?? 0)) ?? throw new \InvalidArgumentException('Verze neexistuje. Použij nástroj stavba_verze.');

                return $this->saveBuild($target, Build::fromJson($json), false);

            case 'zahod_koncept':
                $target = $this->loadBuildTarget($a);
                if ($target['stavba'] === null) {
                    throw new \InvalidArgumentException('Zatím není publikovaná verze – není k čemu se vrátit.');
                }
                $r = $target['radek'];
                match ($target['druh']) {
                    'stranka' => $db->update('stranky', ['stavba_koncept' => null], ['ids' => $r['ids']]),
                    'kolekce' => \Kaleta\Builder\Collections::writeTemplate($db, $r, ['stavba_koncept' => null]),
                    'popup' => $db->update('popupy', ['stavba_koncept' => null], ['idpp' => $r['idpp']]),
                    'komponenta' => $db->update('komponenty', ['stavba_koncept' => null], ['idm' => $r['idm']]),
                    default => $db->update('casti', ['stavba_koncept' => null], ['typ' => $r['typ'], 'jazyk' => $r['jazyk'], 'varianta' => $r['varianta']]),
                };

                return $this->describeTarget($target) + ['stav' => 'koncept zahozen – platí publikovaná podoba', 'adresa' => $this->targetUrl($target)];

            case 'seznam_casti':
                $adminOnly();

                return array_map(fn (array $r): array => ['cast' => $r['typ'], 'jazyk' => $r['jazyk'], 'varianta' => $r['varianta'], 'nazev' => $r['varianta'] !== '' ? $r['nazev'] : SiteParts::TYPES[$r['typ']][0] ?? $r['typ'],
                    'stranky' => $r['varianta'] !== '' ? array_map('intval', json_decode((string) $r['stranky'], true) ?: []) : null,
                    'publikovana' => (bool) $r['publikovana'], 'neulozene_zmeny' => (bool) $r['zmeny']],
                    $db->all('SELECT typ, jazyk, varianta, nazev, stranky, stavba IS NOT NULL AS publikovana, stavba_koncept IS NOT NULL AND (stavba IS NULL OR stavba_koncept <> stavba) AS zmeny FROM {casti} ORDER BY typ, jazyk, varianta'));

            case 'uloz_variantu':
                $adminOnly();
                $type = (string) ($a['cast'] ?? '');
                if (!in_array($type, SiteParts::WITH_VARIANTS, true)) {
                    throw new \InvalidArgumentException('Varianty mají jen záhlaví a patička: ' . implode(', ', SiteParts::WITH_VARIANTS) . '.');
                }
                $language = in_array($a['jazyk'] ?? '', Language::additional($siteSettings), true) ? (string) $a['jazyk'] : '';
                $variant = (string) ($a['varianta'] ?? '');
                if (!empty($a['smazat'])) {
                    $row = $variant !== '' ? SiteParts::row($db, $type, $language, $variant) : null;
                    if ($row === null) {
                        throw new \InvalidArgumentException('Varianta neexistuje. Použij nástroj seznam_casti.');
                    }
                    Publisher::version($this->app, ['cast' => SiteParts::versionKey($type, $language, $variant)], $row['stavba'], null, $row['zmeneno']);
                    $db->delete('casti', ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant]);
                    \Kaleta\Front\Cache::clear();

                    return ['cast' => $type, 'varianta' => $variant, 'stav' => 'varianta smazána – vybrané stránky mají výchozí podobu'];
                }
                $variantName = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
                if ($variantName === '') {
                    throw new \InvalidArgumentException('Varianta musí mít název.');
                }
                $pages = array_values(array_filter(array_map('intval', is_array($a['stranky'] ?? null) ? $a['stranky'] : []),
                    fn (int $ids): bool => $db->value('SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = ? AND smazano IS NULL', [$ids, $language]) !== null));
                $variant = SiteParts::saveVariant($db, $type, $language, $variant, $variantName, $pages, Language::ofContent($siteSettings, $language));
                \Kaleta\Front\Cache::clear();

                return ['cast' => $type, 'jazyk' => $language, 'varianta' => $variant, 'nazev' => $variantName, 'stranky' => $pages,
                    'stav' => 'uloženo – stavbu varianty uprav stavba_* s parametrem varianta a publikuj; do publikování platí výchozí podoba'];

            case 'seznam_poptavek':
                if (!\Kaleta\Core\Extensions::isEnabled($siteSettings, 'poptavky') || !$auth->hasModule('enquiries')) {
                    throw new \DomainException('Poptávky smí číst jen uživatel s právem k Poptávkám (rozšíření Formuláře a poptávky musí být zapnuté).');
                }
                $whereParts = [];
                $params = [];
                $statuses = ['nove' => 0, 'prectene' => 1, 'vyrizene' => 2];
                if (isset($statuses[$a['stav'] ?? ''])) {
                    $whereParts[] = 'stav = ?';
                    $params[] = $statuses[$a['stav']];
                }
                if (($a['hledat'] ?? '') !== '') {
                    $whereParts[] = '(email LIKE ? OR data LIKE ?)';
                    $search = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
                    array_push($params, $search, $search);
                }
                $limit = max(1, min(50, (int) ($a['limit'] ?? 20)));
                $statusNames = array_flip($statuses);

                return array_map(fn (array $p): array => ['id' => (int) $p['idp'], 'datum' => substr((string) $p['datum'], 0, 16), 'formular' => $p['formular'], 'stranka' => $p['stranka'],
                    'kampan' => \Kaleta\Front\Forms::campaignText((string) $p['kampan']), 'email' => $p['email'], 'stav' => $statusNames[(int) $p['stav']] ?? '',
                    'pole' => array_map(fn (array $d): array => ['popisek' => $d[0], 'hodnota' => $d[1]], json_decode((string) $p['data'], true) ?: [])],
                    $db->all('SELECT idp, datum, formular, stranka, kampan, email, stav, data FROM {poptavky}' . ($whereParts !== [] ? ' WHERE ' . implode(' AND ', $whereParts) : '') . ' ORDER BY idp DESC LIMIT ' . $limit, $params));

            case 'uprav_design_system':
                $adminOnly();
                $ds = isset($a['predvolba']) ? (DesignSystem::preset((string) $a['predvolba']) ?? throw new \InvalidArgumentException('Předvolba neexistuje: ' . implode(', ', array_keys(DesignSystem::PRESETS)) . '.')) : \Kaleta\Core\Look::designSystem($siteSettings);
                $changes = is_array($a['ds'] ?? null) ? $a['ds'] : [];
                foreach (['barvy', 'barvy_tmave'] as $group) {
                    if (is_array($changes[$group] ?? null)) {
                        $changes[$group] += $ds[$group];
                    }
                }
                $ds = DesignSystem::sanitize($changes + $ds);
                \Kaleta\Core\Look::setDesignSystem($siteSettings, $ds); // to the draft look – publish_look publishes it

                return ['design_system' => $ds, 'citelnost' => DesignSystem::contrasts($ds), 'stav' => 'draft look – visitors see it after publish_look',
                    'nahled' => \Kaleta\Admin\Modules\Appearance::sitePreviewUrl($this->app, 60)];

            case 'seznam_popupu':
                $adminOnly();

                return array_map($this->popup(...), \Kaleta\Builder\Popups::all($db));

            case 'uloz_popup':
                $adminOnly();

                return $this->popup($this->savePopup($a), true);

            case 'seznam_kolekci':
                return array_map(fn (array $k): array => ['kolekce' => $k['seo_link'], 'nazev' => $k['nazev'], 'detail' => (bool) $k['detail'], 'pole' => $k['pole'],
                    'polozek' => (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE idk = ?', [$k['idk']])], Collections::all($db));

            case 'vytvor_kolekci':
                $adminOnly();
                $collectionName = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
                if ($collectionName === '') {
                    throw new \InvalidArgumentException('Chybí název kolekce.');
                }
                $seo = $this->availableCollectionSlug((string) ($a['adresa'] ?? '') !== '' ? (string) $a['adresa'] : $collectionName, 0);
                $field = Collections::sanitizeFields($a['pole'] ?? []);
                $db->insert('kolekce', ['nazev' => $collectionName, 'seo_link' => $seo, 'detail' => empty($a['detail']) ? 0 : 1, 'pole' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]);

                return ['kolekce' => $seo, 'pole' => $field];

            case 'uprav_kolekci':
                $adminOnly();
                $collection = $this->collection((string) ($a['kolekce'] ?? ''));
                $changes = ['zmeneno' => date('Y-m-d H:i:s')];
                if (isset($a['nazev']) && trim((string) $a['nazev']) !== '') {
                    $changes['nazev'] = mb_substr(trim((string) $a['nazev']), 0, 100);
                }
                if (isset($a['adresa']) && trim((string) $a['adresa']) !== '') {
                    $changes['seo_link'] = $this->availableCollectionSlug((string) $a['adresa'], (int) $collection['idk']);
                }
                if (array_key_exists('detail', $a)) {
                    $changes['detail'] = empty($a['detail']) ? 0 : 1;
                }
                if (is_array($a['pole'] ?? null)) {
                    $changes['pole'] = (string) json_encode(Collections::sanitizeFields($a['pole']), JSON_UNESCAPED_UNICODE);
                }
                $db->update('kolekce', $changes, ['idk' => $collection['idk']]);
                \Kaleta\Front\Cache::clear();
                $newVersion = (array) $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$collection['idk']]);

                return ['kolekce' => $newVersion['seo_link'], 'nazev' => $newVersion['nazev'], 'detail' => (bool) $newVersion['detail'], 'pole' => json_decode((string) $newVersion['pole'], true) ?: []];

            case 'seznam_polozek_kolekce':
                $collection = $this->collection((string) ($a['kolekce'] ?? ''));

                $whereParts = ['idk = ?'];
                $args = [$collection['idk']];
                if (isset($a['jazyk']) && is_string($a['jazyk'])) {
                    $whereParts[] = 'jazyk = ?';
                    $args[] = $a['jazyk'];
                }
                if (!empty($a['jen_zobrazene']) || !$auth->hasModule('collections')) {
                    $whereParts[] = 'zobrazit = 1'; // without the Collections section only published items
                }
                if (is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '') {
                    $whereParts[] = '(nazev LIKE ? OR data LIKE ?)';
                    $pattern = '%' . addcslashes(mb_substr(trim($a['hledat']), 0, 100), '%_\\') . '%';
                    array_push($args, $pattern, $pattern);
                }
                $rows = $db->all('SELECT * FROM {kolekce_polozky} WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY poradi, nazev LIMIT 5000', $args);
                if (is_string($a['pole'] ?? null) && $a['pole'] !== '') {
                    // exact match of a field value (JSON in the database – filtered here, without depending on the MySQL version)
                    $rows = array_values(array_filter($rows, fn (array $r): bool => (string) ((json_decode((string) $r['data'], true) ?: [])[$a['pole']] ?? '') === (string) ($a['hodnota'] ?? '')));
                }
                $pageNumber = max(1, (int) ($a['strana'] ?? 1));

                return ['celkem' => count($rows), 'strana' => $pageNumber, 'stran' => max(1, (int) ceil(count($rows) / 50)), 'polozky' => array_map(fn (array $r): array => ['id' => (int) $r['idp'], 'nazev' => $r['nazev'], 'seo_link' => $r['seo_link'], 'poradi' => (int) $r['poradi'], 'zobrazit' => (bool) $r['zobrazit'],
                    'jazyk' => $r['jazyk'], 'data' => json_decode((string) $r['data'], true) ?: new \stdClass()], array_slice($rows, ($pageNumber - 1) * 50, 50))];

            case 'uloz_polozku_kolekce':
                if (!$auth->hasModule('collections')) {
                    throw new \DomainException('Kolekce smí upravovat editor nebo správce.');
                }
                $collection = $this->collection((string) ($a['kolekce'] ?? ''));
                $previous = isset($a['id']) ? $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [(int) $a['id'], $collection['idk']]) : null;
                if (isset($a['id']) && $previous === null) {
                    throw new \InvalidArgumentException('Položka v kolekci není. Použij seznam_polozek_kolekce.');
                }
                // on update the name is optional – the current one stays
                $itemName = mb_substr(trim((string) ($a['nazev'] ?? $previous['nazev'] ?? '')), 0, 200);
                if ($itemName === '') {
                    throw new \InvalidArgumentException('Položka musí mít název.');
                }
                if (isset($a['data']) && !is_array($a['data'])) {
                    throw new \InvalidArgumentException('Parametr data musí být objekt {"klic":"hodnota"} podle polí kolekce.');
                }
                $errors = [];
                $data = Collections::sanitizeData($collection['pole'], (is_array($a['data'] ?? null) ? $a['data'] : []) + (json_decode((string) ($previous['data'] ?? '{}'), true) ?: []), $errors);
                $url = trim((string) ($a['adresa'] ?? ''));
                $seo = $url !== '' ? slugify($url, 150) : ($previous['seo_link'] ?? slugify($itemName, 150));
                if ($seo === '' || $seo === '_ukazka') {
                    throw new \InvalidArgumentException('Neplatná adresa položky.');
                }
                // the slug is unique within a language: an item's translation should have the same one (the language
                // switcher and hreflang find it by the slug)
                $itemLanguage = array_key_exists('jazyk', $a) ? Language::column($siteSettings, (string) $a['jazyk']) : (string) ($previous['jazyk'] ?? '');
                $seo = \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => $db->value('SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ? AND idp <> ?', [$collection['idk'], $itemLanguage, $a, (int) ($previous['idp'] ?? 0)]) !== null);
                $row = ['nazev' => $itemName, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]
                    + (array_key_exists('jazyk', $a) ? ['jazyk' => $itemLanguage] : [])
                    + (array_key_exists('poradi', $a) ? ['poradi' => max(-9999, min(9999, (int) $a['poradi']))] : [])
                    + (array_key_exists('zobrazit', $a) ? ['zobrazit' => (int) (bool) $a['zobrazit']] : []);
                if ($previous !== null) {
                    $db->update('kolekce_polozky', $row, ['idp' => $previous['idp']]);
                    $idp = (int) $previous['idp'];
                } else {
                    $idp = $db->insert('kolekce_polozky', $row + ['idk' => $collection['idk'], 'datum' => date('Y-m-d H:i:s'), 'zobrazit' => 0]);
                }

                // a key the collection does not have (a typo, „nazev“ in data instead of the parameter) would otherwise be silently dropped
                $unknownKeys = array_values(array_diff(array_keys(is_array($a['data'] ?? null) ? $a['data'] : []), array_column($collection['pole'], 'klic')));

                return ['id' => $idp, 'kolekce' => $collection['seo_link'], 'neplatna_pole' => array_keys($errors)] + ($unknownKeys !== [] ? ['nezname_klice' => $unknownKeys] : []) + [
                    'adresa' => $collection['detail'] ? $this->app->request->origin() . $this->app->url(($itemLanguage !== '' ? $itemLanguage . '/' : '') . $collection['seo_link'] . '/' . $seo) : null];

            case 'seznam_novinek':
                $where = ['c.smazano IS NULL']; // the trash is neither listed nor edited via MCP
                $p = [];
                if (($authors = $auth->managedAuthors()) !== null) {
                    $where[] = 'c.autor IN (' . implode(',', $authors) . ')';
                }
                $statuses = ['vydane' => 'c.visible = 1 AND c.datum <= NOW()', 'plan' => 'c.visible = 1 AND c.datum > NOW()', 'koncepty' => 'c.visible = 0'];
                if (isset($statuses[$a['stav'] ?? ''])) {
                    $where[] = $statuses[$a['stav']];
                }
                if (!empty($a['kategorie'])) {
                    $where[] = 'c.tema = ?';
                    $p[] = $this->category((string) $a['kategorie']);
                }
                if (!empty($a['hledat'])) {
                    $where[] = 'c.titulek LIKE ?';
                    $p[] = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
                }

                return $db->all(
                    'SELECT c.idc AS id, c.titulek, c.seo_link, t.nazev AS kategorie, c.datum, c.visible AS vydana
                     FROM {novinky} c JOIN {kategorie} t ON t.idt = c.tema WHERE ' . implode(' AND ', $where) . ' ORDER BY c.datum DESC LIMIT ?',
                    [...$p, max(1, min(50, (int) ($a['limit'] ?? 20)))],
                );

            case 'nacti_novinku':
                $c = $this->newsItem((int) ($a['id'] ?? 0));

                return array_intersect_key($c, array_flip(['idc', 'titulek', 'seo_link', 'uvod', 'text', 'obrazek', 'obrazek_popis', 'datum', 'visible', 'faq', 'seo_titulek', 'seo_popis']))
                    + ['kategorie' => $db->value('SELECT nazev FROM {kategorie} WHERE idt = ?', [$c['tema']]),
                        'stitky' => array_column($db->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
                        'adresa' => $this->app->request->origin() . $this->app->url('novinky/' . $c['seo_link'])];

            case 'vytvor_novinku':
            case 'uprav_novinku':
                if (!$auth->hasModule('news')) {
                    throw new \DomainException('K novinkám nemáš přístup (role uživatele).');
                }

                return $this->saveNewsItem($name === 'uprav_novinku' ? $this->newsItem((int) ($a['id'] ?? 0)) : null, $a);

            case 'seznam_kategorii':
                return array_map(fn (array $r): array => ['id' => (int) $r['idt'], 'nazev' => $r['nazev'], 'adresa' => $r['seo_link'], 'jazyk' => $r['jazyk'], 'novinek' => (int) $r['pocet_clanku']], Categories::listAll($db));

            case 'vytvor_kategorii':
                if (!$auth->canPublish() || !$auth->hasModule('categories')) {
                    throw new \DomainException('Kategorie smí zakládat editor nebo správce.');
                }
                $displayName = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
                if ($displayName === '') {
                    throw new \InvalidArgumentException('Chybí název kategorie.');
                }
                $seo = $this->availableSlug('kategorie', 'idt', slugify($displayName, 110));

                return ['id' => $db->insert('kategorie', ['nazev' => $displayName, 'seo_link' => $seo, 'popis' => \Kaleta\Core\Html::safe((string) ($a['popis'] ?? ''))]), 'adresa' => $seo];

            case 'seznam_medii':
                $search = is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '' ? '%' . addcslashes(trim($a['hledat']), '%_\\') . '%' : null;

                return array_map(fn (array $o): array => $this->medium($o),
                    $db->all('SELECT * FROM {media}' . ($search !== null ? ' WHERE nazev LIKE ? OR obr_poloha LIKE ?' : '') . ' ORDER BY ido DESC LIMIT ?',
                        [...($search !== null ? [$search, $search] : []), max(1, min(50, (int) ($a['limit'] ?? 20)))]));

            case 'nahraj_soubor':
                return $this->uploadFile($a);

            case 'nahled_odkaz':
                $minutes = max(1, min(10080, (int) ($a['minut'] ?? 60)));
                if (!empty($a['web'])) {
                    // the whole site with all drafts and the draft look
                    if (!$auth->hasModule('pages')) {
                        throw new \DomainException('The preview of the whole site is for editors and administrators.');
                    }

                    return ['nahled' => \Kaleta\Admin\Modules\Appearance::sitePreviewUrl($this->app, $minutes), 'plati_do' => date('Y-m-d H:i', time() + $minutes * 60),
                        'look_draft' => \Kaleta\Core\Look::summary($db, $siteSettings)];
                }
                $target = $this->loadBuildTarget($a);

                return $this->describeTarget($target) + ['nahled' => $this->targetPreviewUrl($target, $minutes), 'plati_do' => date('Y-m-d H:i', time() + $minutes * 60)];

            case 'uprav_nastaveni':
                $adminOnly();
                $changes = is_array($a['nastaveni'] ?? null) ? $a['nastaveni'] : [];
                $stored = [];
                $errors = [];
                foreach ($changes as $key => $value) {
                    // keys of 1.4.0 and older still work (nazev_webu, firma_email, nazev_webu_de…)
                    $key = (string) $key;
                    $key = \Kaleta\Core\Settings::LEGACY_KEYS[$key] ?? (preg_match('/^(nazev_webu|popis_webu)_([a-z]{2})$/', $key, $m) ? \Kaleta\Core\Settings::LEGACY_KEYS[$m[1]] . '_' . $m[2] : $key);
                    if (in_array($key, ['logo', 'favicon', 'share_image'], true)) {
                        // logo and icon: a file from Media (nahraj_soubor) or from the system (image/…); empty = no logo / icon
                        $path = ltrim(trim((string) $value), '/');
                        $ok = $path === '' || (preg_match('#^(media|image)/[A-Za-z0-9/_.-]{1,200}\.(svg|png|webp|jpe?g|avif)$#', $path) && !str_contains($path, '..') && is_file(KALETA_ROOT . '/' . $path));
                        if ($ok && $key === 'favicon' && $path !== '') {
                            // icons for phones and for installing the site (media/ikona-<n>.png) are prepared right away,
                            // as in Appearance
                            $ok = \Kaleta\Core\Images::icons(KALETA_ROOT . '/' . $path);
                        } elseif ($key === 'favicon') {
                            array_map(fn (int $n): bool => @unlink(KALETA_ROOT . '/media/ikona-' . $n . '.png'), \Kaleta\Core\Images::ICON_SIZES);
                        }
                        if (!$ok) {
                            $errors[$key] = 'Cesta k souboru z Médií (media/…) nebo ze systému (image/…); ikona musí jít převést na PNG.';
                            continue;
                        }
                        $siteSettings->set($key, $path);
                        $stored[$key] = $path;
                        continue;
                    }
                    $clean = preg_match(self::MCP_SETTINGS, $key) && is_scalar($value) ? \Kaleta\Admin\Modules\Settings::verifyValue($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value) : null;
                    if ($clean !== null && $key === 'home_page' && (int) $clean > 0
                        && $db->value('SELECT ids FROM {stranky} WHERE ids = ? AND zobrazit = 1 AND smazano IS NULL', [(int) $clean]) === null) {
                        $errors[$key] = 'Úvodní stránkou může být jen zveřejněná stránka.';
                        continue;
                    }
                    if ($clean === null) {
                        $errors[$key] = preg_match(self::MCP_SETTINGS, $key) ? 'Neplatná hodnota.' : 'Tohle nastavení přes MCP měnit nejde (jen v administraci).';
                        continue;
                    }
                    $siteSettings->set($key, $clean);
                    $stored[$key] = $clean;
                }
                if ($stored !== []) {
                    \Kaleta\Front\Cache::clear();
                }
                $current = [];
                foreach (['site_name', 'site_description', 'footer_text', 'logo', 'favicon', 'home_page', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin', 'news_per_page',
                    'share_image', 'company_name', 'company_type', 'company_id', 'company_vat_id', 'company_register', 'company_representative', 'company_street', 'company_city', 'company_postcode', 'company_country', 'company_phone', 'company_email', 'company_hours', 'company_map', 'company_gps', 'dark_mode', 'theme_switcher'] as $key) {
                    $current[$key] = $siteSettings->get($key);
                }

                return ['ulozeno' => $stored ?: new \stdClass(), 'chyby' => $errors ?: new \stdClass(), 'nastaveni' => $current];

            case 'seznam_presmerovani':
            case 'uloz_presmerovani':
                if (!\Kaleta\Core\Extensions::isEnabled($siteSettings, 'presmerovani')) {
                    throw new \DomainException('Rozšíření Přesměrování je vypnuté (Rozšíření v administraci).');
                }
                if (!$auth->hasModule('redirects')) {
                    throw new \DomainException('Přesměrování smí spravovat jen role se sekcí Přesměrování.');
                }
                if ($name === 'uloz_presmerovani') {
                    $adminOnly();
                    $z = trim((string) parse_url((string) ($a['z'] ?? ''), PHP_URL_PATH), '/ ');
                    $commandName = trim((string) ($a['na'] ?? ''));
                    if ($z === '' || !preg_match('#^[A-Za-z0-9/._~%-]{1,250}$#', $z)) {
                        throw new \InvalidArgumentException('Stará cesta musí být cesta na tomto webu, např. /stara-stranka.');
                    }
                    if (!empty($a['smazat'])) {
                        $db->delete('presmerovani', ['z_adresy' => $z]);
                    } else {
                        if (!preg_match('#^https?://[^\s]{3,240}$#i', $commandName) && !preg_match('#^/?[^\s:]{0,250}$#', $commandName)) {
                            throw new \InvalidArgumentException('Nová adresa musí být cesta (/nova) nebo https://… adresa.');
                        }
                        $commandName = preg_match('#^https?://#i', $commandName) ? $commandName : trim($commandName, '/');
                        \Kaleta\Admin\Modules\Redirects::add($db, $z, $commandName);
                        $db->run('UPDATE {presmerovani} SET typ = ? WHERE z_adresy = ?', [(int) ($a['typ'] ?? 301) === 302 ? 302 : 301, $z]);
                        $db->delete('nenalezeno', ['cesta' => $z]);
                    }
                    \Kaleta\Front\Cache::clear();
                }

                return ['presmerovani' => $db->all('SELECT z_adresy AS z, na_adresu AS na, typ, pocet FROM {presmerovani} ORDER BY z_adresy LIMIT 500'),
                    'nenalezeno' => $db->all('SELECT cesta, pocet, naposledy FROM {nenalezeno} ORDER BY pocet DESC LIMIT 30')];

            case 'smaz_stranku':
                if (!$auth->canPublish() || !$auth->hasModule('pages')) {
                    throw new \DomainException('Stránku smí smazat editor nebo správce.');
                }
                $page = $this->page((int) ($a['id'] ?? 0));
                if ((int) $page['ids'] === $siteSettings->int('home_page')) {
                    throw new \DomainException('Úvodní stránku smazat nejde – nejdřív nastav jinou (uprav_nastaveni → titulni_stranka).');
                }
                $db->run('UPDATE {stranky} SET smazano = NOW(), zobrazit = 0 WHERE ids = ? AND smazano IS NULL', [(int) $page['ids']]);
                \Kaleta\Front\Cache::clear();

                return ['id' => (int) $page['ids'], 'stav' => 'v koši – obnovit jde 30 dní v administraci (Stránky → Koš)'];
        }
        throw new \InvalidArgumentException('Neznámý nástroj: ' . $name);
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function saveNewsItem(?array $previous, array $a): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if ($previous !== null && $previous['visible'] && !$auth->canPublish()) {
            throw new \DomainException('Vydanou novinku může upravit jen editor nebo správce.');
        }
        $data = [];
        foreach (['titulek' => 255, 'uvod' => 0, 'text' => 0, 'faq' => 0, 'seo_titulek' => 255, 'seo_popis' => 320, 'obrazek' => 255, 'obrazek_popis' => 300] as $field => $max) {
            if (array_key_exists($field, $a)) {
                $data[$field] = $max > 0 ? mb_substr((string) $a[$field], 0, $max) : (string) $a[$field];
            }
        }
        foreach (['uvod', 'text'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = \Kaleta\Core\Html::forUser($data[$field], $this->app->auth());
            }
        }
        if (array_key_exists('kategorie', $a)) {
            $data['tema'] = $this->category((string) $a['kategorie']);
            // the news item takes over the category's language version – just like when saved in the administration
            $data['jazyk'] = (string) $db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$data['tema']]);
        }
        if (!empty($a['datum'])) {
            $ts = strtotime((string) $a['datum']);
            if ($ts === false) {
                throw new \InvalidArgumentException('Datum nemá platný tvar (RRRR-MM-DD HH:MM).');
            }
            $data['datum'] = date('Y-m-d H:i:s', $ts);
        }
        if (array_key_exists('vydat', $a)) {
            if ($a['vydat'] && !$auth->canPublish()) {
                throw new \DomainException('Uživatel nemá právo vydávat – novinku lze uložit jen jako koncept.');
            }
            $data['visible'] = (int) (bool) $a['vydat'];
        }
        if (($data['titulek'] ?? $previous['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Novinka musí mít titulek.');
        }
        $data['zmeneno'] = date('Y-m-d H:i:s');

        if ($previous === null) {
            if (!isset($data['tema'])) {
                throw new \InvalidArgumentException('Chybí kategorie.');
            }
            $data += ['uvod' => '', 'text' => '', 'autor' => $auth->id(), 'datum' => date('Y-m-d H:i:s'), 'visible' => 0,
                'seo_link' => $this->availableSlug('novinky', 'idc', slugify($data['titulek'], 150))];
            $id = $db->insert('novinky', $data);
        } else {
            $id = (int) $previous['idc'];
            \Kaleta\Admin\Modules\News::version($db, $previous, $auth->id()); // history is pruned the same way as in the administration
            $db->update('novinky', $data, ['idc' => $id]);
        }
        \Kaleta\Core\Search::index($db, $id);
        if (array_key_exists('stitky', $a)) {
            \Kaleta\Admin\Modules\News::tags($db, $id, (string) $a['stitky']);
        }
        $saved = $db->one('SELECT * FROM {novinky} WHERE idc = ?', [$id]);
        Media::recordUsage($db, $id, $saved['obrazek'], $saved['uvod'], $saved['text']);

        return ['id' => $id, 'stav' => !$saved['visible'] ? 'koncept' : (strtotime($saved['datum']) > time() ? 'naplánováno' : 'vydáno'),
            'nahled' => $this->app->request->origin() . $this->app->url('novinky/' . $saved['seo_link'] . '?nahled=1'),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?module=news&action=edit&id=' . $id)];
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function savePage(?array $previous, array $a): array
    {
        $db = $this->app->db();
        $data = [];
        foreach (['titulek' => 200, 'text' => 0, 'popis' => 300, 'seo_titulek' => 200, 'obrazek' => 255] as $field => $max) {
            if (array_key_exists($field, $a)) {
                $data[$field] = $max > 0 ? mb_substr((string) $a[$field], 0, $max) : (string) $a[$field];
            }
        }
        foreach (['uvod', 'text'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = \Kaleta\Core\Html::forUser($data[$field], $this->app->auth());
            }
        }
        foreach (['v_menu', 'zobrazit', 'noindex'] as $field) {
            if (array_key_exists($field, $a)) {
                $data[$field] = (int) (bool) $a[$field];
            }
        }
        if (array_key_exists('poradi', $a)) {
            $data['poradi'] = max(0, min(65535, (int) $a['poradi']));
        }
        if (!$this->app->auth()->canPublish()) {
            // without the publish permission: do not change a published page, keep a new one hidden (same as in the administration)
            if ($previous !== null && $previous['zobrazit']) {
                throw new \DomainException('Zveřejněnou stránku smí upravit jen editor nebo správce.');
            }
            unset($data['zobrazit']);
        }
        if (($data['titulek'] ?? $previous['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Stránka musí mít název.');
        }
        $siteSettings = $this->app->settings();
        if (array_key_exists('jazyk', $a)) {
            $data['jazyk'] = Language::column($siteSettings, (string) $a['jazyk']);
            if ($data['jazyk'] === '' && !in_array((string) $a['jazyk'], ['', Language::defaults($siteSettings)], true)) {
                throw new \InvalidArgumentException('Jazyková verze „' . $a['jazyk'] . '“ není zapnutá (Rozšíření → Jazykové verze, jazyky v Nastavení).');
            }
        }
        $language = $data['jazyk'] ?? (string) ($previous['jazyk'] ?? '');
        if (array_key_exists('preklad_z', $a) || array_key_exists('jazyk', $a)) {
            $data['preklad_z'] = $language === '' ? null
                : ($db->value("SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = '' AND ids <> ? AND smazano IS NULL", [(int) ($a['preklad_z'] ?? $previous['preklad_z'] ?? 0), (int) ($previous['ids'] ?? 0)]) ?: null);
        }
        // parent page: the same language, not the page itself nor its subpage (that would create a loop); the slug is
        // /parent/page
        $parent = null;
        $parentChanged = array_key_exists('nadrazena', $a) || array_key_exists('jazyk', $a);
        if ($parentChanged) {
            $parentId = (int) ($a['nadrazena'] ?? $previous['nadrazena'] ?? 0);
            $parent = $parentId > 0 ? $db->one('SELECT ids, seo_link FROM {stranky} WHERE ids = ? AND ids <> ? AND jazyk = ? AND smazano IS NULL', [$parentId, (int) ($previous['ids'] ?? 0), $language]) : null;
            if ($parentId > 0 && ($parent === null || ($previous !== null && str_starts_with($parent['seo_link'] . '/', $previous['seo_link'] . '/')))) {
                throw new \InvalidArgumentException('Nadřazená stránka musí existovat, mít stejný jazyk a nesmí to být tahle stránka ani její podstránka.');
            }
            $data['nadrazena'] = $parent !== null ? (int) $parent['ids'] : null;
        } elseif ($previous !== null && $previous['nadrazena'] !== null) {
            $parent = $db->one('SELECT ids, seo_link FROM {stranky} WHERE ids = ?', [(int) $previous['nadrazena']]);
        }
        if (array_key_exists('zverejnit_od', $a)) {
            $from = strtotime(str_replace('T', ' ', (string) $a['zverejnit_od'])) ?: null;
            if (!$this->app->auth()->canPublish()) {
                throw new \DomainException('Zveřejnění naplánuje jen editor nebo správce.');
            }
            $data['zverejnit_od'] = $from !== null && $from > time() && !($data['zobrazit'] ?? $previous['zobrazit'] ?? 0) ? date('Y-m-d H:i:s', $from) : null;
            if ($from !== null && $from <= time()) {
                throw new \InvalidArgumentException('Čas zveřejnění už proběhl – zadej budoucí čas, nebo stránku zveřejni parametrem zobrazit.');
            }
        }
        if (!empty($data['zobrazit'])) {
            $data['zverejnit_od'] = null; // a published page no longer waits for the schedule
        }
        if (array_key_exists('adresa', $a) || $previous === null || $parentChanged) {
            $base = ($a['adresa'] ?? '') !== '' ? basename(str_replace('\\', '/', (string) $a['adresa']))
                : ($previous !== null ? basename((string) $previous['seo_link']) : $data['titulek']);
            $prefix = $parent !== null ? $parent['seo_link'] . '/' : '';
            $seo = $prefix . slugify($base, max(20, 118 - strlen($prefix)));
            if ($parent === null && (in_array($seo, Pages::RESERVED_SLUGS, true) || isset(\Kaleta\Core\Language::AVAILABLE[$seo]))) {
                throw new \InvalidArgumentException('Adresu „' . $seo . '“ používá systém, zvol jinou.');
            }
            if ($db->value('SELECT ids FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$seo, (int) ($previous['ids'] ?? 0)]) !== null) {
                throw new \InvalidArgumentException('Stránka s adresou „' . $seo . '“ už existuje.');
            }
            $data['seo_link'] = $seo;
        }
        $data['zmeneno'] = date('Y-m-d H:i:s');
        if ($previous === null) {
            if (!empty($a['kopie_stavby'])) {
                // a translation starts with a copy of the original's build (the draft, otherwise the published one) – the
                // texts are then changed by stavba_uprav by id
                $original = ($data['preklad_z'] ?? null) !== null ? $db->one('SELECT stavba, stavba_koncept FROM {stranky} WHERE ids = ?', [$data['preklad_z']]) : null;
                if ($original === null) {
                    throw new \InvalidArgumentException('Kopie stavby potřebuje preklad_z – ID stránky ve výchozím jazyce, a jazyk překladu.');
                }
                $data['stavba_koncept'] = $original['stavba_koncept'] ?? $original['stavba'];
            }
            $id = $db->insert('stranky', $data + ['text' => '', 'zobrazit' => 0, 'v_menu' => 0]);
            if (!empty($data['v_menu'])) {
                \Kaleta\Core\Menu::setPage($db, $id, $language, true);
            }
        } else {
            $id = (int) $previous['ids'];
            if (($data['titulek'] ?? $previous['titulek']) !== $previous['titulek'] || (string) ($data['text'] ?? $previous['text']) !== (string) $previous['text']) {
                Pages::version($db, $id, $this->app->auth()->id(), $previous['titulek'], (string) $previous['text']); // the previous version into the history
            }
            $db->update('stranky', $data, ['ids' => $id]);
            if (isset($data['seo_link']) && $data['seo_link'] !== $previous['seo_link']) {
                Pages::move($db, $previous['seo_link'], $data['seo_link'], (bool) $previous['zobrazit']);
            }
        }
        $saved = $this->page($id);

        return ['id' => $id, 'stav' => $saved['zobrazit'] ? 'zveřejněná' : ($saved['zverejnit_od'] !== null ? 'skrytá, zveřejní se ' . substr((string) $saved['zverejnit_od'], 0, 16) : 'skrytá'),
            'adresa' => $this->app->request->origin() . $this->app->url(($saved['jazyk'] !== '' ? $saved['jazyk'] . '/' : '') . $saved['seo_link']),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?module=pages&action=edit&id=' . $id)];
    }

    /** @return array<string, mixed> */
    private function page(int $id): array
    {
        $page = $this->app->db()->one('SELECT ids, titulek, seo_link, popis, seo_titulek, obrazek, noindex, text, zobrazit, zverejnit_od, v_menu, poradi, jazyk, preklad_z, nadrazena FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]);
        if ($page === null) {
            throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');
        }

        return $page;
    }

    /** Popup for MCP output. */
    /**
     * Newsletter tools: anyone with the Newsletters section writes drafts and sends tests to themselves; sending to
     * subscribers needs the publishing permission.
     *
     * @param array<string, mixed> $a
     */
    private function newsletterTool(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'newsletter') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Kaleta\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');
        switch ($name) {
            case 'list_newsletters':
                return ['newsletters' => array_map(fn (array $n): array => $this->newsletter($n), $mailing::all($db)),
                    'confirmed_subscribers' => $mailing::confirmedCount($db), 'sending_problem' => $mailing::problem($this->app)];

            case 'draft_newsletter':
                $current = isset($a['id']) && (int) $a['id'] > 0 ? $byId() : null;
                $id = $mailing::save($this->app, array_intersect_key($a, array_flip(['subject', 'preheader', 'intro', 'news_mode', 'news_count', 'news_ids', 'button_label', 'button_url', 'language'])), (int) ($current['id'] ?? 0));
                $n = (array) $mailing::byId($db, $id);

                return $this->newsletter($n) + ['text' => str_replace($mailing::UNSUBSCRIBE, '(unsubscribe link)', $mailing::render($this->app, $n)[1]),
                    'sending_problem' => $mailing::problem($this->app)];

            case 'send_test_newsletter':
                $n = $byId();
                $email = (string) ($auth->user()['email'] ?? '');
                if ($email === '') {
                    throw new \DomainException('The connected user has no e-mail address – add one under My account in the admin.');
                }
                if (!$mailing::sendTest($this->app, $n, $email)) {
                    throw new \DomainException('The test e-mail could not be sent: ' . \Kaleta\Core\Mail::$error);
                }

                return ['sent_to' => $email];

            case 'send_newsletter':
                $n = $byId();
                if (!$auth->canPublish()) {
                    throw new \DomainException('Sending to subscribers needs the publishing permission.');
                }
                if (!empty($a['unschedule'])) {
                    $mailing::unschedule($this->app, (int) $n['id']);
                } else {
                    $mailing::send($this->app, (int) $n['id'], isset($a['at']) ? (string) $a['at'] : null);
                }

                return $this->newsletter((array) $mailing::byId($db, (int) $n['id']));

            default: // delete_newsletter
                $n = $byId();
                $mailing::delete($this->app, (int) $n['id']);

                return ['deleted' => (int) $n['id']];
        }
    }

    /**
     * Tools of 1.6: the trash, deleting and the rest of what the admin can do. Every delete checks the same permission as
     * the admin; the descriptions tell Claude to delete only when the user explicitly asks.
     *
     * @param array<string, mixed> $a
     */
    private function contentTool(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };
        $collection = function () use ($a, $db): array {
            return \Kaleta\Builder\Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        };
        switch ($name) {
            case 'list_trash':
                $out = [];
                if ($auth->hasModule('pages')) {
                    $out['pages'] = array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'title' => $r['titulek'], 'deleted_at' => substr((string) $r['smazano'], 0, 16)],
                        $db->all('SELECT ids, titulek, smazano FROM {stranky} WHERE smazano IS NOT NULL ORDER BY smazano DESC LIMIT 100'));
                }
                if ($auth->hasModule('news') && \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky')) {
                    $out['news'] = array_map(fn (array $r): array => ['id' => (int) $r['idc'], 'title' => $r['titulek'], 'deleted_at' => substr((string) $r['smazano'], 0, 16)],
                        $db->all('SELECT idc, titulek, smazano FROM {novinky} WHERE smazano IS NOT NULL' . ($auth->canPublish() ? '' : ' AND autor = ' . (int) $auth->id()) . ' ORDER BY smazano DESC LIMIT 100'));
                }
                if ($auth->hasModule('collections')) {
                    $out['collection_items'] = array_map(fn (array $r): array => ['id' => (int) $r['idp'], 'collection' => $r['kolekce'], 'name' => $r['nazev'], 'deleted_at' => substr((string) $r['smazano'], 0, 16)],
                        $db->all('SELECT p.idp, p.nazev, p.smazano, k.seo_link AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.smazano IS NOT NULL ORDER BY p.smazano DESC LIMIT 100'));
                }

                return $out;

            case 'restore_from_trash':
                $type = (string) ($a['type'] ?? '');
                if ($type === 'page') {
                    $need($auth->hasModule('pages'), 'Pages can be restored by editors and administrators.');
                    $ok = $db->run('UPDATE {stranky} SET smazano = NULL WHERE ids = ? AND smazano IS NOT NULL', [$id])->rowCount() > 0;
                } elseif ($type === 'news') {
                    $need($auth->hasModule('news'), 'News items can be restored only by users with the News section.');
                    $ok = $db->run('UPDATE {novinky} SET smazano = NULL WHERE idc = ? AND smazano IS NOT NULL' . ($auth->canPublish() ? '' : ' AND autor = ' . (int) $auth->id()), [$id])->rowCount() > 0;
                } elseif ($type === 'collection_item') {
                    $need($auth->hasModule('collections'), 'Collection items can be restored only by users with the Collections section.');
                    $ok = $db->run('UPDATE {kolekce_polozky} SET smazano = NULL WHERE idp = ? AND smazano IS NOT NULL', [$id])->rowCount() > 0;
                } else {
                    throw new \InvalidArgumentException('type must be page, news or collection_item.');
                }
                if (!$ok) {
                    throw new \InvalidArgumentException('It is not in the trash. Use list_trash.');
                }

                return ['restored' => $type, 'id' => $id, 'visible' => false];

            case 'trash_news':
                $need($auth->hasModule('news'), 'News items can be deleted only by users with the News section.');
                $item = $db->one('SELECT idc, visible, autor FROM {novinky} WHERE idc = ? AND smazano IS NULL', [$id]) ?? throw new \InvalidArgumentException('The news item does not exist. Use list_news.');
                $need($auth->canPublish() || (!$item['visible'] && (int) $item['autor'] === $auth->id()), 'A published news item or someone else’s can be deleted only with the publishing permission.');
                $db->run('UPDATE {novinky} SET smazano = NOW(), visible = 0 WHERE idc = ?', [$id]);
                \Kaleta\Front\Cache::clear();

                return ['trashed' => $id, 'restore' => 'restore_from_trash with type news within 30 days'];

            case 'delete_collection_item':
                $need($auth->hasModule('collections'), 'Collection items can be deleted only by users with the Collections section.');
                if (!\Kaleta\Admin\Modules\Collections::trashItem($db, $id, (int) $collection()['idk'])) {
                    throw new \InvalidArgumentException('The item is not in this collection (or it is already in the trash). Use list_collection_items.');
                }

                return ['trashed' => $id, 'restore' => 'restore_from_trash with type collection_item within 30 days'];

            case 'delete_collection':
                $need($auth->isAdmin(), 'Collections can be deleted only by an administrator.');
                $k = $collection();
                $db->delete('kolekce', ['idk' => $k['idk']]); // items and templates go with it (foreign keys)
                \Kaleta\Front\Cache::clear();

                return ['deleted' => $k['seo_link']];

            case 'update_category':
                $need($auth->canPublish() && $auth->hasModule('categories'), 'Categories can be changed by editors and administrators.');
                $c = $db->one('SELECT * FROM {kategorie} WHERE idt = ?', [$id]) ?? throw new \InvalidArgumentException('The category does not exist. Use list_categories.');
                $changes = [];
                if (trim((string) ($a['name'] ?? '')) !== '') {
                    $changes['nazev'] = mb_substr(trim((string) $a['name']), 0, 255);
                }
                if (isset($a['description'])) {
                    $changes['popis'] = \Kaleta\Core\Html::forUser((string) $a['description'], $auth);
                }
                if (isset($a['order'])) {
                    $changes['hodnost'] = max(0, min(65535, (int) $a['order']));
                }
                if (trim((string) ($a['slug'] ?? '')) !== '') {
                    $changes['seo_link'] = \Kaleta\Core\Slug::makeUnique(slugify((string) $a['slug'], 110), fn (string $x): bool => $db->value('SELECT idt FROM {kategorie} WHERE seo_link = ? AND idt <> ?', [$x, $id]) !== null, 120);
                }
                if ($changes !== []) {
                    $db->update('kategorie', $changes, ['idt' => $id]);
                    if (isset($changes['seo_link']) && $changes['seo_link'] !== $c['seo_link']) {
                        \Kaleta\Admin\Modules\Redirects::add($db, 'novinky/kategorie/' . $c['seo_link'], 'novinky/kategorie/' . $changes['seo_link']);
                    }
                    \Kaleta\Front\Cache::clear();
                }
                $c = (array) $db->one('SELECT * FROM {kategorie} WHERE idt = ?', [$id]);

                return ['id' => $id, 'name' => $c['nazev'], 'slug' => $c['seo_link'], 'order' => (int) $c['hodnost']];

            case 'delete_category':
                $need($auth->canPublish() && $auth->hasModule('categories'), 'Categories can be deleted by editors and administrators.');
                if ($db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$id]) === null) {
                    throw new \InvalidArgumentException('The category does not exist. Use list_categories.');
                }
                if ((int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE tema = ?', [$id]) > 0) {
                    throw new \DomainException('The category still has news items (including those in the trash) – move them to another category first.');
                }
                $db->delete('kategorie', ['idt' => $id]);

                return ['deleted' => $id];

            case 'delete_popup':
                $need($auth->isAdmin(), 'Pop-ups can be deleted only by an administrator.');
                $need($db->delete('popupy', ['idpp' => $id]) > 0, 'The pop-up does not exist. Use list_popups.');
                \Kaleta\Front\Cache::clear();

                return ['deleted' => $id];

            case 'list_components':
                return array_map(fn (array $k): array => ['id' => (int) $k['idm'], 'name' => $k['nazev'], 'properties' => $k['vlastnosti'], 'published' => $k['stavba'] !== null,
                    'unpublished_changes' => $k['stavba_koncept'] !== null], \Kaleta\Builder\Components::all($db));

            case 'save_component':
                $need($auth->isAdmin(), 'Components can be changed only by an administrator.');
                $current = $id > 0 ? (\Kaleta\Builder\Components::byId($db, $id) ?? throw new \InvalidArgumentException('The component does not exist. Use list_components.')) : null;
                $name = mb_substr(trim((string) ($a['name'] ?? ($current['nazev'] ?? ''))), 0, 100);
                if ($name === '') {
                    throw new \InvalidArgumentException('The component needs a name.');
                }
                $data = ['nazev' => $name, 'zmeneno' => date('Y-m-d H:i:s'), 'vlastnosti' => (string) json_encode(\Kaleta\Builder\Components::sanitizeProperties(
                    is_array($a['properties'] ?? null) ? $a['properties'] : ($current['vlastnosti'] ?? [])), JSON_UNESCAPED_UNICODE)];
                if ($current !== null) {
                    $db->update('komponenty', $data, ['idm' => $id]);
                } else {
                    $id = $db->insert('komponenty', $data + ['stavba_koncept' => Build::toJson(['v' => Build::VERSION, 'deti' => [Build::fresh('sekce')]])]);
                }
                \Kaleta\Front\Cache::clear();
                $k = (array) \Kaleta\Builder\Components::byId($db, $id);

                return ['id' => $id, 'name' => $k['nazev'], 'properties' => $k['vlastnosti'], 'use' => '{"typ":"komponenta","obsah":{"komponenta":"' . $id . '","hodnoty":{}}}',
                    'build' => 'edit it with get_build / save_build / edit_build and component: ' . $id . ', then publish_build'];

            case 'delete_component':
                $need($auth->isAdmin(), 'Components can be deleted only by an administrator.');
                $need($db->delete('komponenty', ['idm' => $id]) > 0, 'The component does not exist. Use list_components.');
                \Kaleta\Front\Cache::clear();

                return ['deleted' => $id];

            case 'save_section':
                $target = $this->loadBuildTarget($a);
                $element = $this->findElement($this->targetBuild($target)['deti'], (string) ($a['element'] ?? '')) ?? throw new \InvalidArgumentException('The element is not in the build. Element ids are in get_build.');
                $name = mb_substr(trim((string) ($a['name'] ?? '')), 0, 100);
                if ($name === '') {
                    throw new \InvalidArgumentException('The saved section needs a name.');
                }
                $sectionId = $db->insert('sekce', ['nazev' => $name, 'prvek' => (string) json_encode($element, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]);

                return ['id' => $sectionId, 'name' => $name, 'insert' => 'insert_section with saved_section: ' . $sectionId];

            case 'delete_section':
                $need($auth->isAdmin(), 'Saved sections can be deleted only by an administrator.');
                $need($db->delete('sekce', ['idx' => $id]) > 0, 'The saved section does not exist – saved_sections in builder_schema lists them.');

                return ['deleted' => $id];

            case 'update_media':
            case 'delete_media':
                $file = $db->one('SELECT * FROM {media} WHERE ido = ?', [$id]) ?? throw new \InvalidArgumentException('The file does not exist. Use list_media.');
                $need($auth->isAdmin() || (int) $file['vlastnik'] === $auth->id(), 'Only the owner of the file or an administrator can change it.');
                if ($name === 'update_media') {
                    $changes = array_filter(['nazev' => isset($a['alt']) ? mb_substr(trim((string) $a['alt']), 0, 150) : null, 'popis' => isset($a['caption']) ? mb_substr(trim((string) $a['caption']), 0, 500) : null,
                        'autor' => isset($a['author']) ? mb_substr(trim((string) $a['author']), 0, 120) : null], fn (?string $v): bool => $v !== null);
                    if ($changes !== []) {
                        $db->update('media', $changes, ['ido' => $id]);
                        \Kaleta\Front\Cache::clear();
                    }

                    return ['id' => $id, 'path' => $file['obr_poloha'], 'changed' => array_keys($changes)];
                }
                $usedAt = array_keys(\Kaleta\Admin\Modules\Media::findUsagesElsewhere($db)[$id] ?? []);
                if ($usedAt !== [] || $db->value('SELECT 1 FROM {media_pouziti} WHERE ido = ? LIMIT 1', [$id]) !== null) {
                    throw new \DomainException('The file is still used on the site' . ($usedAt !== [] ? ': ' . implode(', ', array_slice($usedAt, 0, 5)) : ' (in a news item)') . ' – remove it from there first.');
                }
                \Kaleta\Core\Images::delete($file['obr_poloha'], $file['nahl_poloha']);
                \Kaleta\Core\Files::delete($file['obr_poloha']);
                $db->delete('media', ['ido' => $id]);

                return ['deleted' => $id, 'path' => $file['obr_poloha']];

            case 'apply_part_template':
                $need($auth->isAdmin(), 'Site parts can be changed only by an administrator.');
                $type = self::PART_NAMES[(string) ($a['part'] ?? '')] ?? (string) ($a['part'] ?? '');
                if (!isset(SiteParts::TYPES[$type])) {
                    throw new \InvalidArgumentException('part must be header, footer, news_item, news_list or not_found.');
                }
                $language = in_array($a['language'] ?? '', Language::additional($this->app->settings()), true) ? (string) $a['language'] : '';
                $variant = (string) ($a['variant'] ?? '');
                if (!SiteParts::applyTemplate($db, $type, $language, $variant, (string) ($a['template'] ?? ''), Language::ofContent($this->app->settings(), $language), \Kaleta\Core\Extensions::enabled($this->app->settings()))) {
                    throw new \InvalidArgumentException('Unknown template or variant – builder_schema lists part_templates, list_site_parts the variants.');
                }
                $target = $this->loadBuildTarget(['cast' => $type, 'jazyk' => $language, 'varianta' => $variant]);

                return ['part' => (string) $a['part'], 'template' => (string) $a['template'], 'status' => 'draft – publish_build with the part publishes it',
                    'preview' => $this->targetPreviewUrl($target, 60)];

            case 'publish_look':
            case 'discard_look':
            case 'restore_look_version':
                $need($auth->isAdmin(), 'The look of the site can be published only by an administrator.');
                if ($name === 'discard_look') {
                    \Kaleta\Core\Look::discard($this->app->settings());

                    return ['discarded' => true];
                }
                if ($name === 'restore_look_version') {
                    \Kaleta\Core\Look::restoreVersion($this->app, $id);

                    return ['draft' => \Kaleta\Core\Look::summary($db, $this->app->settings()), 'preview' => \Kaleta\Admin\Modules\Appearance::sitePreviewUrl($this->app, 60)];
                }
                $summary = \Kaleta\Core\Look::publish($this->app);
                if ($summary === []) {
                    throw new \DomainException('There is no draft look to publish.');
                }

                return ['published' => $summary];

            case 'list_look_versions':
                return ['versions' => \Kaleta\Core\Look::versions($db), 'draft' => \Kaleta\Core\Look::summary($db, $this->app->settings())];

            case 'update_enquiry':
            case 'delete_enquiry':
                $need($auth->hasModule('enquiries'), 'Enquiries can be changed only by users with the Enquiries section.');
                $enquiry = $db->one('SELECT idp, data FROM {poptavky} WHERE idp = ?', [$id]) ?? throw new \InvalidArgumentException('The enquiry does not exist. Use list_enquiries.');
                if ($name === 'delete_enquiry') {
                    \Kaleta\Admin\Modules\Enquiries::deleteAttachments([$enquiry]);
                    $db->delete('poptavky', ['idp' => $id]);

                    return ['deleted' => $id];
                }
                $changes = [];
                if (isset($a['status'])) {
                    $status = ['new' => 0, 'read' => 1, 'resolved' => 2][(string) $a['status']] ?? throw new \InvalidArgumentException('status must be new, read or resolved.');
                    $changes['stav'] = $status;
                }
                if (isset($a['note'])) {
                    $changes['poznamka'] = mb_substr(trim((string) $a['note']), 0, 5000);
                }
                if ($changes !== []) {
                    $db->update('poptavky', $changes, ['idp' => $id]);
                }

                return ['id' => $id, 'changed' => array_keys($changes)];
        }

        throw new \InvalidArgumentException('Unknown tool.');
    }

    /** An element of a build by its id, searched through the whole tree. */
    private function findElement(array $children, string $id): ?array
    {
        foreach ($children as $p) {
            if (($p['id'] ?? null) === $id) {
                return $p;
            }
            if (is_array($p['deti'] ?? null) && ($found = $this->findElement($p['deti'], $id)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * builder_schema for the English interface: the builder vocabulary in English (Mcp\Vocabulary), the section library,
     * saved sections, components, classes and design system tokens.
     *
     * @param array<string, mixed> $schema Build::schema()
     * @param array<string, mixed> $a
     */
    private function englishSchema(array $schema, array $a): array
    {
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $only = is_array($a['prvky'] ?? null) ? array_values(array_filter($a['prvky'], 'is_string')) : [];
        $out = \Kaleta\Mcp\Vocabulary::schema($schema, $only, !empty($a['uplne']));
        if ($only !== [] && empty($a['uplne'])) {
            return $out;
        }
        $admin = fn (string $text): string => Language::runWith('en', fn (): string => t($text), 'admin-');
        $library = Library::listAll(\Kaleta\Core\Extensions::enabled($siteSettings));

        return $out + [
            'components' => array_map(fn (array $k): array => ['id' => (string) $k['idm'], 'name' => $k['nazev'], 'properties' => $k['vlastnosti']], \Kaleta\Builder\Components::all($db))
                + ['note' => 'Use: {"type":"component","content":{"component":"<id>","values":{"<key>":"value"}}}; an empty value = the default. Edit a component with the *_build tools and component: <id>.'],
            'site_parts' => ['header' => 'the header of every page', 'footer' => 'the footer of every page', 'news_item' => 'the wrapper of a news item', 'news_list' => 'the wrapper of the news list',
                'not_found' => 'the wrapper of the 404 page', 'note' => 'The elements logo, navigation, company_details and page_content belong only in site parts; a wrapper (news_item, news_list, not_found) must contain exactly one page_content element.'],
            'library' => array_column(array_map(fn (array $k): array => ['key' => $k['klic'], 'description' => $admin($k['nazev']) . ' – ' . $admin($k['popis'])], $library), 'description', 'key'),
            'saved_sections' => array_map(fn (array $r): array => ['id' => (int) $r['idx'], 'name' => $r['nazev']], $db->all('SELECT idx, nazev FROM {sekce} ORDER BY nazev LIMIT 200'))
                + ['note' => 'Sections saved in the builder: insert_section with saved_section: <id>.'],
            'part_templates' => array_map(fn (string $type): array => array_column(array_map(fn (array $t): array => ['key' => $t['klic'], 'text' => $admin($t['nazev']) . ' – ' . $admin($t['popis'])],
                \Kaleta\Builder\PartTemplates::forType($type, \Kaleta\Core\Extensions::enabled($siteSettings))), 'text', 'key'), self::PART_NAMES)
                + ['note' => 'apply_part_template puts one into the draft of the part; the look comes from the design system.'],
            'site_classes' => array_column($db->all('SELECT nazev FROM {tridy} ORDER BY nazev'), 'nazev'),
            'design_system' => DesignSystem::load($siteSettings) + ['presets' => array_map(fn (array $p): string => $admin($p[0]) . ' – ' . $admin($p[1]), DesignSystem::PRESETS),
                'heading_fonts' => array_keys(SiteIdentity::TITLE_FONTS), 'text_fonts' => array_keys(SiteIdentity::TEXT_FONTS),
                'note' => 'Keys as update_design_system takes them (barvy = colours, pismo_titulky = heading font, zaobleni = corner radius…).'],
            'css_tokens' => 'In <style> and custom CSS use var(--ka-barva-primarni|sekundarni|text|tlumeny|pozadi|plocha|linka|primarni-jemna|na-primarni) (primary, secondary, text, muted, background, surface, line, primary-soft, on-primary), var(--ka-mezera-2xs…3xl) for spacing, var(--ka-krok--1…5) for font size, var(--ka-zaobleni), var(--ka-stin-s|m|l), var(--ka-sirka).',
        ];
    }

    /** @param array<string, mixed> $n */
    private function newsletter(array $n): array
    {
        $output = ['id' => (int) $n['id'], 'subject' => $n['subject'], 'status' => $n['status']];
        foreach (['preheader', 'intro', 'news_mode', 'news_count', 'news_ids', 'button_label', 'button_url', 'language'] as $key) {
            if (array_key_exists($key, $n)) {
                $output[$key] = match ($key) {
                    'news_count' => (int) $n[$key],
                    'news_ids' => array_map('intval', array_filter(explode(',', (string) $n[$key]))),
                    default => $n[$key],
                };
            }
        }
        if ($n['status'] === 'scheduled') {
            $output['scheduled_at'] = substr((string) $n['scheduled_at'], 0, 16);
        }
        if (in_array($n['status'], ['sending', 'sent'], true)) {
            $output += ['recipients' => (int) $n['recipients'], 'sent' => (int) $n['sent_count'], 'failed' => (int) $n['failed_count'],
                'started_at' => substr((string) $n['started_at'], 0, 16), 'finished_at' => $n['finished_at'] !== null ? substr((string) $n['finished_at'], 0, 16) : null];
        }

        return $output + ['admin' => $this->app->request->origin() . $this->app->url('admin.php?module=newsletters&action=edit&id=' . (int) $n['id'])];
    }

    private function popup(array $p, bool $withPreview = false): array
    {
        $output = ['id' => $p['idpp'], 'nazev' => $p['nazev'], 'adresa' => $p['adresa'], 'odkaz' => '#popup-' . $p['adresa'], 'typ' => $p['typ'], 'spoustec' => $p['spoustec'],
            'hodnota' => $p['hodnota'], 'cetnost' => $p['cetnost'], 'dni' => $p['dni'], 'pravidla' => $p['pravidla'], 'aktivni' => (bool) $p['aktivni'],
            'publikovano' => $p['stavba'] !== null, 'zmeny' => $p['stavba_koncept'] !== null && $p['stavba_koncept'] !== $p['stavba'], 'poradi' => $p['poradi'],
            'zobrazeni' => $p['zobrazeni'], 'zavreni' => $p['zavreni'], 'konverze' => $p['konverze'],
            'stavitel' => $this->app->request->origin() . $this->app->url('admin.php?module=popups&action=builder&id=' . $p['idpp'])];
        if ($withPreview) {
            $output['nahled'] = $this->targetPreviewUrl(['druh' => 'popup', 'radek' => $p], 60);
        }

        return $output;
    }

    /** Creates a popup from a template or changes its settings; only a published one can be enabled. */
    private function savePopup(array $a): array
    {
        $db = $this->app->db();
        $popups = \Kaleta\Builder\Popups::class;
        if (isset($a['id'])) {
            $p = $popups::byId($db, (int) $a['id']) ?? throw new \InvalidArgumentException('Pop-up okno neexistuje. Použij nástroj seznam_popupu.');
        } else {
            $key = (string) ($a['vzor'] ?? 'prazdny');
            $pattern = $popups::LIBRARY[$key] ?? throw new \InvalidArgumentException('Neznámý vzor okna. Vzory: ' . implode(', ', array_keys($popups::LIBRARY)) . '.');
            $name = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100) ?: t($pattern[0]);
            $id = $db->insert('popupy', ['nazev' => $name, 'adresa' => $popups::address($db, $name), 'typ' => $pattern[2], 'spoustec' => $pattern[3], 'hodnota' => $pattern[4],
                'pravidla' => (string) json_encode($popups::defaultRules()), 'cetnost' => 'relace', 'dni' => 7, 'aktivni' => 0,
                'stavba_koncept' => Build::toJson($popups::libraryBuild($key, Language::defaults($this->app->settings()))), 'zmeneno' => date('Y-m-d H:i:s')]);
            $p = (array) $popups::byId($db, $id);
        }
        $changes = [];
        if (isset($a['nazev']) && trim((string) $a['nazev']) !== '') {
            $changes['nazev'] = mb_substr(trim((string) $a['nazev']), 0, 100);
        }
        if (isset($a['adresa']) && trim((string) $a['adresa']) !== '') {
            $url = slugify((string) $a['adresa'], 60);
            if (!preg_match($popups::ADDRESS_PATTERN, $url) || $db->value('SELECT idpp FROM {popupy} WHERE adresa = ? AND idpp <> ?', [$url, $p['idpp']]) !== null) {
                throw new \InvalidArgumentException('Tuto adresu už používá jiné okno.');
            }
            $changes['adresa'] = $url;
        }
        foreach (['typ' => $popups::TYPES, 'spoustec' => $popups::TRIGGERS, 'cetnost' => $popups::FREQUENCIES] as $field => $allowed) {
            if (isset($a[$field])) {
                $changes[$field] = isset($allowed[$a[$field]]) ? (string) $a[$field] : throw new \InvalidArgumentException('Neplatná hodnota „' . $field . '“. Povolené: ' . implode(', ', array_keys($allowed)) . '.');
            }
        }
        if (isset($a['hodnota'])) {
            $changes['hodnota'] = max(0, min(3600, (int) $a['hodnota']));
        }
        if (isset($a['dni'])) {
            $changes['dni'] = max(1, min(365, (int) $a['dni']));
        }
        if (isset($a['poradi'])) {
            $changes['poradi'] = max(-9999, min(9999, (int) $a['poradi']));
        }
        if (isset($a['pravidla'])) {
            if (!is_array($a['pravidla'])) {
                throw new \InvalidArgumentException('Parametr pravidla musí být objekt.');
            }
            $changes['pravidla'] = (string) json_encode($popups::sanitizeRules($a['pravidla'] + $p['pravidla']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('aktivni', $a)) {
            if (!empty($a['aktivni']) && $p['stavba'] === null) {
                throw new \InvalidArgumentException('Okno nejdřív publikuj (publikuj_stavbu s parametrem popup) – teprve pak ho jde zapnout.');
            }
            $changes['aktivni'] = empty($a['aktivni']) ? 0 : 1;
        }
        if ($changes !== []) {
            $db->update('popupy', $changes + ['zmeneno' => date('Y-m-d H:i:s')], ['idpp' => $p['idpp']]);
        }

        return (array) $popups::byId($db, $p['idpp']);
    }

    /**
     * Build target: a page (id; without id and with $create a new hidden page named from „titulek“) or a site part
     * (cast = type, language), which only the administrator can change. A part that does not exist yet is created with a
     * draft from the layout.
     *
     * @return array{druh: string, radek: array<string, mixed>, stavba: ?string, koncept: ?string, jazyk: string}
     */
    private function loadBuildTarget(array $a, bool $create = false): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        if (isset($a['popup']) && (int) $a['popup'] > 0) {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Pop-up okna smí měnit jen správce webu.');
            }
            $row = \Kaleta\Builder\Popups::byId($db, (int) $a['popup']) ?? throw new \InvalidArgumentException('Pop-up okno neexistuje. Použij nástroj seznam_popupu.');

            return ['druh' => 'popup', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, ''),
                'revize' => ['cast' => 'popup:' . $row['idpp']]];
        }
        if (isset($a['komponenta']) && (int) $a['komponenta'] > 0) {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Components can be changed only by an administrator.');
            }
            $row = \Kaleta\Builder\Components::byId($db, (int) $a['komponenta']) ?? throw new \InvalidArgumentException('The component does not exist. Use list_components.');

            return ['druh' => 'komponenta', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, ''),
                'revize' => ['cast' => 'komponenta:' . (int) $row['idm']]];
        }
        if (isset($a['kolekce']) && $a['kolekce'] !== '') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Šablonu detailu kolekce smí měnit jen správce webu.');
            }
            $row = \Kaleta\Builder\Collections::bySlug($db, (string) $a['kolekce']) ?? throw new \InvalidArgumentException('Kolekce neexistuje. Použij nástroj seznam_kolekci.');
            $language = (string) ($a['jazyk'] ?? '') === Language::defaults($siteSettings) ? '' : (string) ($a['jazyk'] ?? '');
            if ($language !== '' && !in_array($language, Language::additional($siteSettings), true)) {
                throw new \InvalidArgumentException('Jazyková verze „' . $language . '“ není zapnutá (Rozšíření → Jazykové verze, jazyky v Nastavení).');
            }
            $row = \Kaleta\Builder\Collections::inLanguage($db, $row, $language);
            if ($row['stavba'] === null && $row['stavba_koncept'] === null) {
                // the template the builder would show until someone edits it (another language starts with a copy of the default)
                $row['stavba_koncept'] = \Kaleta\Builder\Collections::initialTemplateDraft($db, $row);
            }

            return ['druh' => 'kolekce', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, $language),
                'revize' => ['cast' => \Kaleta\Builder\Collections::templateKey($row)]];
        }
        if (isset($a['cast']) && $a['cast'] !== '') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Části webu (záhlaví, patičku, obálky) smí měnit jen správce webu.');
            }
            $type = (string) $a['cast'];
            if (!isset(SiteParts::TYPES[$type])) {
                throw new \InvalidArgumentException('Neznámá část webu. Typy: ' . implode(', ', array_keys(SiteParts::TYPES)) . '.');
            }
            $language = in_array($a['jazyk'] ?? '', Language::additional($siteSettings), true) ? (string) $a['jazyk'] : '';
            $variant = (string) ($a['varianta'] ?? '');
            if ($variant !== '') {
                $row = in_array($type, SiteParts::WITH_VARIANTS, true) ? SiteParts::row($db, $type, $language, $variant) : null;
                if ($row === null) {
                    throw new \InvalidArgumentException('Varianta neexistuje. Varianty záhlaví a patičky vypíše seznam_casti, založí uloz_variantu.');
                }
            } else {
                // a part that does not exist yet: the draft the builder would start with – the row is created only on write
                // (reading changes nothing)
                $row = SiteParts::row($db, $type, $language) ?? ['typ' => $type, 'jazyk' => $language, 'varianta' => '', 'nazev' => '', 'stranky' => null, 'stavba' => null,
                    'stavba_koncept' => SiteParts::initialDraft($db, $type, $language, Language::ofContent($siteSettings, $language)), 'zmeneno' => null, 'nova' => true];
            }

            return ['druh' => 'cast', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, $language),
                'revize' => ['cast' => SiteParts::versionKey($type, $language, $variant)]];
        }
        if (!$auth->hasModule('pages')) {
            throw new \DomainException('Stránky smí upravovat editor nebo správce.');
        }
        if (!isset($a['id']) && $create) {
            $a['id'] = $this->savePage(null, ['titulek' => (string) ($a['titulek'] ?? '')])['id'];
        }
        $row = $db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [(int) ($a['id'] ?? 0)]) ?? throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');

        return ['druh' => 'stranka', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, $row['jazyk']),
            'revize' => ['ids' => (int) $row['ids']]];
    }

    /** The target's draft build, otherwise the published one; a text page as a build from its text. */
    private function targetBuild(array $target): array
    {
        return Build::fromJson($target['koncept'] ?? $target['stavba'])
            ?? ($target['druh'] === 'stranka' ? Build::fromText($target['radek']['titulek'], (string) $target['radek']['text']) : ['v' => Build::VERSION, 'deti' => []]);
    }

    /** @return array<string, mixed> */
    private function describeTarget(array $target): array
    {
        return match ($target['druh']) {
            'stranka' => ['id' => (int) $target['radek']['ids'], 'titulek' => $target['radek']['titulek']],
            'kolekce' => ['kolekce' => $target['radek']['seo_link'], 'titulek' => 'Detail: ' . $target['radek']['nazev'], 'detail_zapnuty' => (bool) $target['radek']['detail']]
                + ($target['radek']['sablona_jazyk'] !== '' ? ['jazyk' => $target['radek']['sablona_jazyk']] : []),
            'popup' => ['popup' => $target['radek']['idpp'], 'titulek' => 'Pop-up: ' . $target['radek']['nazev'], 'aktivni' => (bool) $target['radek']['aktivni']],
            'komponenta' => ['component' => (int) $target['radek']['idm'], 'titulek' => 'Component: ' . $target['radek']['nazev']],
            default => ['cast' => $target['radek']['typ'], 'jazyk' => $target['radek']['jazyk'], 'titulek' => SiteParts::TYPES[$target['radek']['typ']][0]]
                + ($target['radek']['varianta'] !== '' ? ['varianta' => $target['radek']['varianta']] : []),
        };
    }

    private function publishTarget(array $target): void
    {
        $db = $this->app->db();
        if ($target['druh'] === 'stranka') {
            Publisher::page($this->app, (array) $db->one('SELECT * FROM {stranky} WHERE ids = ?', [$target['radek']['ids']]));
        } elseif ($target['druh'] === 'kolekce') {
            $row = \Kaleta\Builder\Collections::inLanguage($db, (array) \Kaleta\Builder\Collections::byId($db, (int) $target['radek']['idk']), $target['radek']['sablona_jazyk']);
            // a default template nobody saved is published too (otherwise there would be nothing to publish)
            $row['stavba_koncept'] ??= $target['koncept'];
            Publisher::collection($this->app, $row);
        } elseif ($target['druh'] === 'popup') {
            Publisher::popup($this->app, (array) \Kaleta\Builder\Popups::byId($db, $target['radek']['idpp']));
        } elseif ($target['druh'] === 'komponenta') {
            Publisher::component($this->app, (array) \Kaleta\Builder\Components::byId($db, (int) $target['radek']['idm']));
        } else {
            $this->createSitePart($target['radek']);
            Publisher::part($this->app, (array) SiteParts::row($db, $target['radek']['typ'], $target['radek']['jazyk'], (string) $target['radek']['varianta']));
        }
    }

    /**
     * A site part that loadBuildTarget() only offered (not in the database yet) is created with the default draft before
     * the first write.
     */
    private function createSitePart(array $row): void
    {
        if (!empty($row['nova']) && SiteParts::row($this->app->db(), $row['typ'], $row['jazyk']) === null) {
            $this->app->db()->insert('casti', ['typ' => $row['typ'], 'jazyk' => $row['jazyk'], 'stavba_koncept' => $row['stavba_koncept'], 'zmeneno' => date('Y-m-d H:i:s')]);
        }
    }

    /** Sanitizes and saves the draft (and publishes it if asked); returns what the model needs for further work. */
    private function saveBuild(array $target, array $input, bool $publish): array
    {
        $db = $this->app->db();
        [$build, $errors] = Build::sanitize($input, $this->app->auth()->isAdmin(), Build::fromJson($target['koncept'] ?? $target['stavba']));
        $r = $target['radek'];
        if ($target['druh'] === 'stranka') {
            $db->update('stranky', ['stavba_koncept' => Build::toJson($build)], ['ids' => $r['ids']]);
        } elseif ($target['druh'] === 'kolekce') {
            \Kaleta\Builder\Collections::writeTemplate($db, $r, ['stavba_koncept' => Build::toJson($build)]);
            $target['koncept'] = Build::toJson($build);
        } elseif ($target['druh'] === 'popup') {
            $db->update('popupy', ['stavba_koncept' => Build::toJson($build)], ['idpp' => $r['idpp']]);
        } elseif ($target['druh'] === 'komponenta') {
            $db->update('komponenty', ['stavba_koncept' => Build::toJson($build)], ['idm' => $r['idm']]);
        } else {
            $this->createSitePart($r);
            $db->update('casti', ['stavba_koncept' => Build::toJson($build)], ['typ' => $r['typ'], 'jazyk' => $r['jazyk'], 'varianta' => $r['varianta']]);
        }
        if ($publish) {
            $this->publishTarget($target);
        }
        $params = match ($target['druh']) {
            'stranka' => 'module=pages&action=builder&id=' . (int) $r['ids'],
            'kolekce' => 'module=collections&action=builder&id=' . (int) $r['idk'] . ($r['sablona_jazyk'] !== '' ? '&jazyk=' . $r['sablona_jazyk'] : ''),
            'popup' => 'module=popups&action=builder&id=' . (int) $r['idpp'],
            'komponenta' => 'module=components&action=builder&id=' . (int) $r['idm'],
            default => 'module=parts&action=builder&typ=' . $r['typ'] . '&jazyk=' . $r['jazyk'],
        };

        return $this->describeTarget($target) + ['stav' => $publish ? 'publikováno' : 'koncept – na webu se ukáže po publikování', 'prvku' => $this->countElements($build['deti']),
            'chyby' => $errors, 'nahled' => $publish ? $this->targetUrl($target) : $this->targetPreviewUrl($target, 60),
            'stavitel' => $this->app->request->origin() . $this->app->url('admin.php?' . $params)] + $this->checkTarget($target, $build);
    }

    /**
     * Check before publishing as in the builder (buttons without a link, images without alt text, the page's heading
     * outline); nothing when there are no findings.
     */
    private function checkTarget(array $target, array $build): array
    {
        $findings = \Kaleta\Builder\Check::builds($build, $target['druh'] === 'stranka');

        return $findings === [] ? [] : ['kontrola' => $findings];
    }

    /** Signed link to the target's draft (valid only for this target and for a limited time). */
    private function targetPreviewUrl(array $target, int $minutes): string
    {
        $r = $target['radek'];
        if ($target['druh'] === 'komponenta') {
            return $this->targetUrl($target); // the component canvas: for a signed-in administrator only
        }
        $signature = match ($target['druh']) {
            'stranka' => 'stranka:' . (int) $r['ids'],
            'kolekce' => \Kaleta\Builder\Collections::templateKey($r),
            'popup' => 'popup:' . (int) $r['idpp'],
            default => 'cast:' . $r['typ'] . ':' . $r['jazyk'] . ($r['varianta'] !== '' ? ':' . $r['varianta'] : ''), // a link to the header would not show the variant's draft
        };
        $key = \Kaleta\Core\Preview::key($this->app->db(), $this->app->settings(), $signature, $minutes);
        if ($target['druh'] === 'popup') {
            return $this->targetUrl($target) . '?stavba=koncept&nahled_klic=' . $key;
        }

        $variant = $target['druh'] === 'cast' && $r['varianta'] !== '' ? 'varianta=' . rawurlencode($r['varianta']) . '&' : '';

        return $this->targetUrl($target) . '?' . ($target['druh'] === 'cast' ? 'cast=' . $r['typ'] . '&' : '') . $variant . 'stavba=koncept&nahled_klic=' . $key;
    }

    /**
     * Public URL where the target is visible (for a site part the home page, news item detail, listing, 404; for a
     * collection the first item).
     */
    private function targetUrl(array $target): string
    {
        $r = $target['radek'];
        if ($target['druh'] === 'popup') {
            return $this->app->request->origin() . $this->app->url('_popup/' . (int) $r['idpp']); // the popup draft over an empty site page
        }
        if ($target['druh'] === 'komponenta') {
            return $this->app->request->origin() . $this->app->url('_komponenta/' . (int) $r['idm']);
        }
        if ($target['druh'] === 'kolekce') {
            $language = $r['sablona_jazyk'];
            $item = $this->app->db()->value('SELECT seo_link FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? ORDER BY zobrazit DESC, poradi, idp LIMIT 1', [$r['idk'], $language]);

            return $this->app->request->origin() . $this->app->url(($language !== '' ? $language . '/' : '') . $r['seo_link'] . '/' . ($item ?? '_ukazka'));
        }
        if ($target['druh'] === 'stranka') {
            $home = $this->app->settings()->int('home_page') === (int) $r['ids'];
            $path = $home ? '' : $r['seo_link'];
        } elseif ($r['varianta'] !== '') {
            // the variant is shown on the first page it applies to
            $ids = (int) ((json_decode((string) $r['stranky'], true) ?: [])[0] ?? 0);
            $path = (string) $this->app->db()->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$ids]);
        } else {
            $path = match ($r['typ']) {
                'novinka', 'vypis' => 'novinky',
                'nenalezeno' => 'tahle-stranka-neexistuje',
                default => '',
            };
        }

        return $this->app->request->origin() . $this->app->url(($r['jazyk'] !== '' ? $r['jazyk'] . '/' : '') . $path);
    }

    private function countElements(array $children): int
    {
        return array_sum(array_map(fn (array $p): int => 1 + $this->countElements($p['deti'] ?? []), $children));
    }


    /** Media file for MCP output: URL for the build (media/…), dimensions and whether it is an image. */
    private function medium(array $o): array
    {
        return ['id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'adresa' => $o['obr_poloha'], 'url' => $this->app->request->origin() . $this->app->url($o['obr_poloha']),
            'obrazek' => $o['nahl_poloha'] !== '', 'rozmery' => $o['nahl_poloha'] !== '' ? $o['obr_width'] . '×' . $o['obr_height'] : null];
    }

    /** @return array<string, mixed> */
    private function uploadFile(array $a): array
    {
        $displayName = basename(str_replace('\\', '/', trim((string) ($a['nazev'] ?? ''))));
        $extension = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new \InvalidArgumentException('Název souboru musí mít příponu (např. foto.jpg, logo.svg, pismo.woff2).');
        }
        if (is_string($a['url'] ?? null) && $a['url'] !== '') {
            $url = trim($a['url']);
            if (!str_starts_with(strtolower($url), 'https://')) {
                throw new \InvalidArgumentException('Stahovat jde jen z https adresy.');
            }
            try {
                $content = (new \Kaleta\Core\ImageDownloader($url))->download($url, false);
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException('Soubor se nepodařilo stáhnout: ' . $e->getMessage());
            }
        } else {
            $content = base64_decode(preg_replace('#^data:[^,]*,#', '', (string) ($a['data'] ?? '')) ?? '', true);
            if ($content === false || $content === '') {
                throw new \InvalidArgumentException('Chybí data souboru v base64 (parametr data), nebo url.');
            }
        }
        if (strlen($content) > self::MAX_UPLOAD) {
            throw new \InvalidArgumentException('Soubor je větší než ' . (self::MAX_UPLOAD >> 20) . ' MB.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'kaleta-mcp-');
        file_put_contents($temporary, $content);
        try {
            $data = match (true) {
                $extension === 'svg' => Media::saveSvgContent($content, $displayName),
                \Kaleta\Core\Files::isAttachment($displayName) => \Kaleta\Core\Files::saveFile($temporary, $displayName),
                default => \Kaleta\Core\Images::saveFile($temporary, $displayName),
            };
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } finally {
            @unlink($temporary);
        }
        if (is_string($a['popis'] ?? null) && trim($a['popis']) !== '') {
            $data['nazev'] = mb_substr(trim($a['popis']), 0, 150);
        }
        $data['ido'] = $this->app->db()->insert('media', $data + ['vlastnik' => $this->app->auth()->id(), 'sekce' => null, 'datum' => date('Y-m-d H:i:s')]);

        return $this->medium($data) + ['pouziti' => match (true) {
            $extension === 'woff2' || $extension === 'woff' => 'uprav_design_system {"ds":{"vlastni_pisma":[{"nazev":"…","soubor":"' . $data['obr_poloha'] . '"}],"pismo_titulky":"vlastni-1"}}',
            $data['nahl_poloha'] !== '' => 'prvek obrazek {"src":"' . $data['obr_poloha'] . '"} nebo styl obrazek_pozadi',
            default => 'odkaz na soubor: /' . $data['obr_poloha'],
        }];
    }

    /** @return array<string, mixed> */
    /** A free collection slug: not a system path, a language code or the slug of another collection. */
    private function availableCollectionSlug(string $given, int $idk): string
    {
        $seo = slugify($given, 110);
        if ($seo === '' || in_array($seo, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$seo])
            || $this->app->db()->value('SELECT idk FROM {kolekce} WHERE seo_link = ? AND idk <> ?', [$seo, $idk]) !== null) {
            throw new \InvalidArgumentException('Adresu „' . $seo . '“ už používá systém nebo jiná kolekce.');
        }

        return $seo;
    }

    private function collection(string $seo): array
    {
        return Collections::bySlug($this->app->db(), $seo) ?? throw new \InvalidArgumentException('Kolekce neexistuje. Použij seznam_kolekci.');
    }

    /** @return array<string, mixed> news item the user has access to */
    private function newsItem(int $id): array
    {
        $newsItem = $this->app->db()->one('SELECT * FROM {novinky} WHERE idc = ? AND smazano IS NULL', [$id]);
        $authors = $this->app->auth()->managedAuthors();
        if ($newsItem === null || ($authors !== null && !in_array((int) $newsItem['autor'], $authors, true))) {
            throw new \InvalidArgumentException('Novinka neexistuje nebo k ní uživatel nemá přístup.');
        }

        return $newsItem;
    }

    private function category(string $nameOrSlug): int
    {
        $idt = $this->app->db()->value('SELECT idt FROM {kategorie} WHERE seo_link = ? OR nazev = ? LIMIT 1', [$nameOrSlug, $nameOrSlug]);
        if ($idt === null) {
            throw new \InvalidArgumentException('Kategorie „' . $nameOrSlug . '“ neexistuje. Použij nástroj seznam_kategorii.');
        }

        return (int) $idt;
    }

    private function availableSlug(string $table, string $key, string $seo): string
    {
        return \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => $this->app->db()->value("SELECT {$key} FROM {{$table}} WHERE seo_link = ?", [$a]) !== null, $table === 'novinky' ? 160 : 120);
    }
}
