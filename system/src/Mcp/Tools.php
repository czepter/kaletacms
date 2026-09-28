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
 * Nástroje, které MCP server nabízí Claudovi. Každý nástroj respektuje práva uživatele, jehož tokenem se Claude hlásí:
 * autor pracuje jen se svými novinkami a nevydává, editor s veškerým obsahem, správce navíc se šablonami webu.
 *
 * Chyba určená Claudovi (špatný vstup, chybějící právo) se hlásí výjimkou InvalidArgumentException / DomainException.
 */
final class Tools
{
    /** Největší soubor nahraný přes MCP (base64 v jednom volání nástroje). */
    private const int MAX_UPLOAD = 12 * 1024 * 1024;

    /** Nastavení, která smí MCP měnit (ostatní – e-mail, webhooky, 2FA, pošta, zálohy – jen v administraci). */
    private const string MCP_SETTINGS = '/^(nazev_webu|popis_webu|text_paticky|titulni_stranka|soc_(facebook|instagram|x|youtube|linkedin)|pocet_clanku|sdileni|osnova_clanku|souvisejici_auto|firma_[a-z]+|tmavy_rezim|tmavy_prepinac|(nazev|popis)_webu_[a-z]{2})$/';

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array<string, mixed>> definice nástrojů pro tools/list */
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
            'popup' => $number('Místo stránky obsah pop-up okna (ID ze seznam_popupu, jen správce)')];
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
            ['vloz_sekci', 'Vloží hotovou sekci z knihovny (úvod, výhody, služby, čísla, reference, faq, výzva, novinky, kontakt) na konec konceptu stránky nebo části webu.', $s($target + ['sekce' => $text('klíč sekce ze stavba_schema → knihovna')], ['sekce'])],
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
                $s($target + ['minut' => $number('platnost v minutách, výchozí 60, nejvýš ' . \Kaleta\Core\Preview::MAX_MINUTES)])],
            ['uprav_nastaveni', 'Změní nastavení webu (správce) – hned se projeví na webu. Klíče: nazev_webu, popis_webu, text_paticky, logo_webu, favicon a og_obrazek – obrázek pro sdílení 1200×630 (cesta media/… z nahraj_soubor nebo image/…), titulni_stranka (ID úvodní stránky), soc_facebook|instagram|x|youtube|linkedin (URL), '
                . 'pocet_clanku, sdileni, osnova_clanku, souvisejici_auto (1/0), tmavy_rezim (vypnuto | auto = podle zařízení | tmavy = vždy tmavý), tmavy_prepinac (1/0 = přepínač vzhledu pro návštěvníky), údaje firmy firma_nazev, firma_typ, firma_ico, firma_dic, firma_rejstrik (zápis v rejstříku), firma_zastupce (kdo firmu zastupuje), firma_ulice, firma_mesto, firma_psc, firma_zeme (CZ), firma_telefon, firma_hodiny (den na řádek), firma_mapa, firma_gps; nazev_webu_en… pro jazykové verze. Bez parametru vrátí současné hodnoty.',
                $s(['nastaveni' => ['type' => 'object', 'description' => '{"klic":"hodnota"}']])],
            ['seznam_poptavek', 'Poptávky z formulářů webu (rozšíření Formuláře a poptávky; jen s právem k Poptávkám), nejnovější první: datum, formulář, stránka, kampaň (utm), e-mail, stav a vyplněná pole. Obsahují osobní údaje – používej je jen k tomu, oč uživatel žádá.',
                $s(['stav' => $text('nove | prectene | vyrizene | vse (výchozí)'), 'hledat' => $text('text v e-mailu nebo obsahu (nepovinné)'), 'limit' => $number('1-50, výchozí 20')])],
            ['seznam_presmerovani', 'Přesměrování starých adres (rozšíření Přesměrování) a nejčastější adresy, které skončily chybou 404.', $s([])],
            ['uloz_presmerovani', 'Přidá nebo změní přesměrování (správce): ze staré cesty na webu na novou cestu nebo https adresu. Typ 301 = natrvalo (výchozí), 302 = dočasně.',
                $s(['z' => $text('stará cesta, např. /docs nebo /o-nas'), 'na' => $text('nová cesta (/guide) nebo https://…'), 'typ' => $number('301 nebo 302'), 'smazat' => ['type' => 'boolean', 'description' => 'true = přesměrování ze staré cesty smazat']], ['z'])],
            ['smaz_stranku', 'Přesune stránku do koše (jen na výslovný pokyn uživatele; editor nebo správce). Z koše jde 30 dní obnovit v administraci. Úvodní stránku smazat nejde.', $s(['id' => $number('ID stránky')], ['id'])],
        ];

        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky')) {
            $tools = array_filter($tools, fn (array $n): bool => !in_array($n[0], self::NEWS_TOOLS, true));
        }

        return array_values(array_map(fn (array $n): array => ['name' => $n[0], 'description' => $n[1], 'inputSchema' => $n[2]], $tools));
    }

    /** @return list<string> české názvy všech nástrojů (i vypnutých rozšíření) */
    public function names(): array
    {
        return [...array_column($this->listAll(), 'name'), ...self::NEWS_TOOLS];
    }

    /** Nástroje rozšíření Novinky – s vypnutým rozšířením se nenabízejí ani nespustí. */
    private const array NEWS_TOOLS = ['seznam_novinek', 'nacti_novinku', 'vytvor_novinku', 'uprav_novinku', 'seznam_kategorii', 'vytvor_kategorii'];

    public function isWriteTool(string $name): bool
    {
        return in_array($name, ['obnov_verzi', 'zahod_koncept', 'uloz_variantu', 'vytvor_kolekci', 'uprav_kolekci', 'uloz_polozku_kolekce', 'uloz_popup', 'stavba_z_html', 'stavba_uloz', 'stavba_uprav', 'uloz_tridy', 'nahraj_soubor', 'uprav_nastaveni', 'uloz_presmerovani', 'smaz_stranku', 'vloz_sekci', 'publikuj_stavbu', 'uprav_design_system', 'vytvor_stranku', 'uprav_stranku', 'vytvor_novinku', 'uprav_novinku', 'vytvor_kategorii', 'uloz_menu'], true);
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
        switch ($name) {
            case 'info_o_webu':
                return [
                    'web' => $siteSettings->get('nazev_webu'), 'adresa' => $this->app->request->origin() . $this->app->url(''), 'popis' => $siteSettings->get('popis_webu'),
                    'sablona' => $siteSettings->get('layout'), 'uvodni_stranka' => $siteSettings->int('titulni_stranka') ?: null, 'verze_kaleta' => KALETA_VERSION,
                    'stranek' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NULL'),
                    'novinek_vydanych' => (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL'),
                    'uzivatel' => $auth->user()['user'], 'role' => \Kaleta\Core\Auth::TYPES[(int) $auth->user()['admin']], 'smi_vydavat' => $auth->canPublish(),
                    'smi_upravovat_stranky' => $auth->hasModule('pages'),
                ];

            case 'seznam_stranek':
                $home = $siteSettings->int('titulni_stranka');

                // adresa jazykové verze má předponu (/de/…); překlad úvodu je kořenem své verze (/de/)
                return array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'titulek' => $r['titulek'], 'adresa' => $this->app->request->origin()
                    . $this->app->url(($r['jazyk'] !== '' ? $r['jazyk'] . '/' : '') . ((int) $r['ids'] === $home || ($home > 0 && (int) $r['preklad_z'] === $home) ? '' : $r['seo_link'])),
                    'uvodni' => (int) $r['ids'] === $home, 'zobrazena' => (bool) $r['zobrazit'], 'v_menu' => (bool) $r['v_menu'], 'jazyk' => $r['jazyk']],
                    // bez sekce Stránky (autor novinek) jen zveřejněné stránky – na ty smí odkazovat, koncepty nevidí
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
                    // null vrátí automatické menu – jen když ho volající opravdu poslal, ne když položky chybí nebo nejdou přečíst
                    if (!array_key_exists('polozky', $a) || ($a['polozky'] !== null && !is_array($a['polozky']))) {
                        throw new \InvalidArgumentException('Parametr polozky musí být seznam položek menu, nebo null pro automatické menu.');
                    }
                    \Kaleta\Core\Menu::save($db, $location, $menuLanguage, $a['polozky']);
                    \Kaleta\Front\Cache::clear();
                }
                $saved = \Kaleta\Core\Menu::load($db, $location, $menuLanguage);

                return ['umisteni' => $location, 'jazyk' => $menuLanguage, 'automaticke' => $saved === null, 'polozky' => $saved ?? [],
                    'na_webu' => \Kaleta\Core\Menu::items($this->app, $location, $menuLanguage, $siteSettings->int('titulni_stranka')),
                    'stranky' => $db->all('SELECT ids, titulek, zobrazit FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$menuLanguage])];

            case 'stavba_schema':
                $schema = Build::schema($auth->isAdmin(), Language::defaults($siteSettings), $auth->isAdmin(), \Kaleta\Core\Extensions::enabled($siteSettings));
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
                $rows = isset($a['nazev']) ? $db->all('SELECT nazev, styl, css FROM {tridy} WHERE nazev = ?', [(string) $a['nazev']]) : $db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev');

                return array_map(fn (array $r): array => ['nazev' => $r['nazev'], 'styl' => json_decode((string) $r['styl'], true) ?: new \stdClass(), 'css' => (string) $r['css']], $rows);

            case 'uloz_tridy':
                $adminOnly();
                $conversion = HtmlConverter::convert('<style>' . str_ireplace('</style', '', (string) ($a['css'] ?? '')) . '</style>', true);
                $stored = [];
                foreach (array_unique(array_merge(array_keys($conversion['tridy']), array_keys($conversion['tridy_styl']))) as $className) {
                    // slučuje se: pravidlo jen pro :hover nebo @media nechá základ třídy a ostatní stavy (nahradit: true = celá třída znovu)
                    $previous = empty($a['nahradit']) ? $db->one('SELECT styl, css FROM {tridy} WHERE nazev = ?', [$className]) : null;
                    $style = ($conversion['tridy_styl'][$className] ?? []) + (json_decode((string) ($previous['styl'] ?? ''), true) ?: []);
                    $css = $conversion['tridy'][$className] ?? (string) ($previous['css'] ?? '');
                    $db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE styl = VALUES(styl), css = VALUES(css), zmeneno = NOW()',
                        [$className, (string) json_encode($style ?: new \stdClass(), JSON_UNESCAPED_UNICODE), $css]);
                    $stored[] = $className;
                }
                $deleted = [];
                foreach (is_array($a['smazat'] ?? null) ? $a['smazat'] : [] as $className) {
                    if (is_string($className) && $db->delete('tridy', ['nazev' => $className]) > 0) {
                        $deleted[] = $className;
                    }
                }
                \Kaleta\Front\Cache::clear();

                return ['ulozeno' => $stored, 'smazano' => $deleted, 'hlaseni' => $conversion['hlaseni']];

            case 'stavba_z_html':
                $target = $this->loadBuildTarget($a, true);
                ['stavba' => $build, 'hlaseni' => $messages] = HtmlConverter::saveToSite($db, (string) ($a['html'] ?? ''), $auth->isAdmin(), $auth->isAdmin() && !empty($a['prepsat_tridy'])); // sdílené třídy mění jen správce
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
                $section = Library::section((string) ($a['sekce'] ?? ''), $target['jazyk']) ?? throw new \InvalidArgumentException('Sekce v knihovně není. Klíče: ' . implode(', ', array_column(Library::listAll(), 'klic')) . '.');
                Library::createClasses($db, $section['tridy']);
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
                $ds = isset($a['predvolba']) ? (DesignSystem::preset((string) $a['predvolba']) ?? throw new \InvalidArgumentException('Předvolba neexistuje: ' . implode(', ', array_keys(DesignSystem::PRESETS)) . '.')) : DesignSystem::load($siteSettings);
                $changes = is_array($a['ds'] ?? null) ? $a['ds'] : [];
                foreach (['barvy', 'barvy_tmave'] as $group) {
                    if (is_array($changes[$group] ?? null)) {
                        $changes[$group] += $ds[$group];
                    }
                }
                $ds = DesignSystem::sanitize($changes + $ds);
                $siteSettings->set('design_system', (string) json_encode($ds, JSON_UNESCAPED_SLASHES));
                \Kaleta\Front\Cache::clear();

                return ['design_system' => $ds, 'citelnost' => DesignSystem::contrasts($ds), 'nahled' => $this->app->request->origin() . $this->app->url('')];

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
                    $whereParts[] = 'zobrazit = 1'; // bez sekce Kolekce jen zveřejněné položky
                }
                if (is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '') {
                    $whereParts[] = '(nazev LIKE ? OR data LIKE ?)';
                    $pattern = '%' . addcslashes(mb_substr(trim($a['hledat']), 0, 100), '%_\\') . '%';
                    array_push($args, $pattern, $pattern);
                }
                $rows = $db->all('SELECT * FROM {kolekce_polozky} WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY poradi, nazev LIMIT 5000', $args);
                if (is_string($a['pole'] ?? null) && $a['pole'] !== '') {
                    // přesná shoda hodnoty pole (JSON v databázi – filtruje se tady, bez závislosti na verzi MySQL)
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
                // při úpravě je název nepovinný – zůstane dosavadní
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
                // adresa je jedinečná v jazyce: překlad položky má mít stejnou (přepínač jazyků a hreflang ji podle ní najdou)
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

                // klíč, který kolekce nemá (překlep, „nazev“ v data místo parametru), by se jinak tiše zahodil
                $unknownKeys = array_values(array_diff(array_keys(is_array($a['data'] ?? null) ? $a['data'] : []), array_column($collection['pole'], 'klic')));

                return ['id' => $idp, 'kolekce' => $collection['seo_link'], 'neplatna_pole' => array_keys($errors)] + ($unknownKeys !== [] ? ['nezname_klice' => $unknownKeys] : []) + [
                    'adresa' => $collection['detail'] ? $this->app->request->origin() . $this->app->url(($itemLanguage !== '' ? $itemLanguage . '/' : '') . $collection['seo_link'] . '/' . $seo) : null];

            case 'seznam_novinek':
                $where = ['c.smazano IS NULL']; // koš se přes MCP nevypisuje ani needituje
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
                $target = $this->loadBuildTarget($a);
                $minutes = max(1, min(10080, (int) ($a['minut'] ?? 60)));

                return $this->describeTarget($target) + ['nahled' => $this->targetPreviewUrl($target, $minutes), 'plati_do' => date('Y-m-d H:i', time() + $minutes * 60)];

            case 'uprav_nastaveni':
                $adminOnly();
                $changes = is_array($a['nastaveni'] ?? null) ? $a['nastaveni'] : [];
                $stored = [];
                $errors = [];
                foreach ($changes as $key => $value) {
                    $key = (string) $key;
                    if (in_array($key, ['logo_webu', 'favicon', 'og_obrazek'], true)) {
                        // logo a ikona: soubor z Médií (nahraj_soubor) nebo ze systému (image/…); prázdné = bez loga / ikony
                        $path = ltrim(trim((string) $value), '/');
                        $ok = $path === '' || (preg_match('#^(media|image)/[A-Za-z0-9/_.-]{1,200}\.(svg|png|webp|jpe?g|avif)$#', $path) && !str_contains($path, '..') && is_file(KALETA_ROOT . '/' . $path));
                        if ($ok && $key === 'favicon' && $path !== '') {
                            // ikony pro telefony a instalaci webu (media/ikona-<n>.png) se připraví hned, jako ve Vzhledu
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
                    if ($clean !== null && $key === 'titulni_stranka' && (int) $clean > 0
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
                foreach (['nazev_webu', 'popis_webu', 'text_paticky', 'logo_webu', 'favicon', 'titulni_stranka', 'soc_facebook', 'soc_instagram', 'soc_x', 'soc_youtube', 'soc_linkedin', 'pocet_clanku',
                    'og_obrazek', 'firma_nazev', 'firma_typ', 'firma_ico', 'firma_dic', 'firma_rejstrik', 'firma_zastupce', 'firma_ulice', 'firma_mesto', 'firma_psc', 'firma_zeme', 'firma_telefon', 'firma_email', 'firma_hodiny', 'firma_mapa', 'firma_gps', 'tmavy_rezim', 'tmavy_prepinac'] as $key) {
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
                if ((int) $page['ids'] === $siteSettings->int('titulni_stranka')) {
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
            // novinka přebírá jazykovou verzi kategorie – stejně jako při uložení v administraci
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
            \Kaleta\Admin\Modules\News::version($db, $previous, $auth->id()); // historie se promazává stejně jako v administraci
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
            // bez práva vydávat: zveřejněnou stránku neměnit, novou nechat skrytou (stejně jako v administraci)
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
        // nadřazená stránka: stejný jazyk, ne ona sama ani její podstránka (jinak by vznikl kruh); adresa je /nadrazena/stranka
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
            $data['zverejnit_od'] = null; // zveřejněná stránka už na plán nečeká
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
                // překlad začíná kopií stavby originálu (koncept, jinak publikovaná) – texty pak změní stavba_uprav podle id
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
                Pages::version($db, $id, $this->app->auth()->id(), $previous['titulek'], (string) $previous['text']); // předchozí podoba do historie
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

    /** Pop-up okno pro výstup MCP. */
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

    /** Založí okno ze vzoru nebo změní nastavení; zapnout jde jen publikované. */
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
     * Cíl stavby: stránka (id; bez id a se $zalozit nová skrytá stránka s názvem z „titulek“) nebo část webu (cast = typ, jazyk),
     * kterou smí měnit jen správce. Část, která ještě není, se založí s konceptem podle šablony.
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
                // šablona, kterou by ukázal builder, dokud ji nikdo neupravil (další jazyk začíná kopií výchozího)
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
                // část, která ještě není: koncept, se kterým by začal builder – řádek se založí až zápisem (čtení nic nemění)
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

    /** Rozpracovaná, jinak publikovaná stavba cíle; textová stránka jako stavba z jejího textu. */
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
            // výchozí šablona, kterou nikdo neuložil, se publikuje taky (jinak by nebylo co publikovat)
            $row['stavba_koncept'] ??= $target['koncept'];
            Publisher::collection($this->app, $row);
        } elseif ($target['druh'] === 'popup') {
            Publisher::popup($this->app, (array) \Kaleta\Builder\Popups::byId($db, $target['radek']['idpp']));
        } else {
            $this->createSitePart($target['radek']);
            Publisher::part($this->app, (array) SiteParts::row($db, $target['radek']['typ'], $target['radek']['jazyk'], (string) $target['radek']['varianta']));
        }
    }

    /** Část webu, kterou cilStavby jen předložil (ještě není v databázi), se založí s výchozím konceptem před prvním zápisem. */
    private function createSitePart(array $row): void
    {
        if (!empty($row['nova']) && SiteParts::row($this->app->db(), $row['typ'], $row['jazyk']) === null) {
            $this->app->db()->insert('casti', ['typ' => $row['typ'], 'jazyk' => $row['jazyk'], 'stavba_koncept' => $row['stavba_koncept'], 'zmeneno' => date('Y-m-d H:i:s')]);
        }
    }

    /** Vyčistí a uloží koncept (případně publikuje); vrací, co model potřebuje k další práci. */
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
            default => 'module=parts&action=builder&typ=' . $r['typ'] . '&jazyk=' . $r['jazyk'],
        };

        return $this->describeTarget($target) + ['stav' => $publish ? 'publikováno' : 'koncept – na webu se ukáže po publikování', 'prvku' => $this->countElements($build['deti']),
            'chyby' => $errors, 'nahled' => $publish ? $this->targetUrl($target) : $this->targetPreviewUrl($target, 60),
            'stavitel' => $this->app->request->origin() . $this->app->url('admin.php?' . $params)] + $this->checkTarget($target, $build);
    }

    /** Kontrola před publikováním jako v builderu (tlačítka bez odkazu, obrázky bez popisu, osnova nadpisů stránky); bez nálezů nic. */
    private function checkTarget(array $target, array $build): array
    {
        $findings = \Kaleta\Builder\Check::builds($build, $target['druh'] === 'stranka');

        return $findings === [] ? [] : ['kontrola' => $findings];
    }

    /** Podepsaný odkaz na koncept cíle (platí jen pro tenhle cíl a omezenou dobu). */
    private function targetPreviewUrl(array $target, int $minutes): string
    {
        $r = $target['radek'];
        $signature = match ($target['druh']) {
            'stranka' => 'stranka:' . (int) $r['ids'],
            'kolekce' => \Kaleta\Builder\Collections::templateKey($r),
            'popup' => 'popup:' . (int) $r['idpp'],
            default => 'cast:' . $r['typ'] . ':' . $r['jazyk'] . ($r['varianta'] !== '' ? ':' . $r['varianta'] : ''), // odkaz na záhlaví neukáže koncept varianty
        };
        $key = \Kaleta\Core\Preview::key($this->app->db(), $this->app->settings(), $signature, $minutes);
        if ($target['druh'] === 'popup') {
            return $this->targetUrl($target) . '?stavba=koncept&nahled_klic=' . $key;
        }

        $variant = $target['druh'] === 'cast' && $r['varianta'] !== '' ? 'varianta=' . rawurlencode($r['varianta']) . '&' : '';

        return $this->targetUrl($target) . '?' . ($target['druh'] === 'cast' ? 'cast=' . $r['typ'] . '&' : '') . $variant . 'stavba=koncept&nahled_klic=' . $key;
    }

    /** Veřejná adresa, na které je cíl vidět (u části webu úvodní stránka, detail novinky, výpis, 404; u kolekce první položka). */
    private function targetUrl(array $target): string
    {
        $r = $target['radek'];
        if ($target['druh'] === 'popup') {
            return $this->app->request->origin() . $this->app->url('_popup/' . (int) $r['idpp']); // koncept okna přes prázdnou stránku webu
        }
        if ($target['druh'] === 'kolekce') {
            $language = $r['sablona_jazyk'];
            $item = $this->app->db()->value('SELECT seo_link FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? ORDER BY zobrazit DESC, poradi, idp LIMIT 1', [$r['idk'], $language]);

            return $this->app->request->origin() . $this->app->url(($language !== '' ? $language . '/' : '') . $r['seo_link'] . '/' . ($item ?? '_ukazka'));
        }
        if ($target['druh'] === 'stranka') {
            $home = $this->app->settings()->int('titulni_stranka') === (int) $r['ids'];
            $path = $home ? '' : $r['seo_link'];
        } elseif ($r['varianta'] !== '') {
            // varianta se ukazuje na první stránce, pro kterou platí
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


    /** Médium pro výstup MCP: adresa pro stavbu (media/…), rozměry a jestli jde o obrázek. */
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
    /** Volná adresa kolekce: ne systémová cesta, kód jazyka ani adresa jiné kolekce. */
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

    /** @return array<string, mixed> novinka, ke které má uživatel přístup */
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
