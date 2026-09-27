<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Části webu z builderu (tabulka ka_casti): záhlaví a patička na všech stránkách a obálky kolem obsahu, který skládá
 * systém (detail novinky, výpis novinek, stránka 404). Část bez publikované stavby = část ze šablony (layout).
 */
final class SiteParts
{
    /** typ => [název, popis] */
    public const array TYPES = [
        'hlavicka' => ['Záhlaví', 'Logo a navigace nahoře na každé stránce.'],
        'paticka' => ['Patička', 'Kontakty, odkazy a copyright dole na každé stránce.'],
        'novinka' => ['Detail novinky', 'Obálka kolem textu novinky – třeba výzva k akci nebo další novinky pod textem.'],
        'vypis' => ['Výpis novinek', 'Obálka kolem výpisu novinek, kategorie, štítku a hledání.'],
        'nenalezeno' => ['Stránka nenalezena (404)', 'Obálka kolem hlášení, že stránka neexistuje – třeba s odkazy dál.'],
    ];

    /** Části, které mohou mít varianty pro vybrané stránky (landing page bez navigace, jiná patička…). */
    public const array WITH_VARIANTS = ['hlavicka', 'paticka'];

    public const string VARIANT_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    /** @return array<string, mixed>|null řádek části (varianta '' = výchozí podoba) */
    public static function row(Db $db, string $type, string $language, string $variant = ''): ?array
    {
        return $db->one('SELECT * FROM {casti} WHERE typ = ? AND jazyk = ? AND varianta = ?', [$type, $language, $variant]);
    }

    /**
     * Uloží variantu záhlaví nebo patičky (název a stránky, na kterých platí) a vrátí její klíč. Nová varianta začíná
     * kopií publikované výchozí podoby (jinak podoby ze šablony) jako koncept. Pro administraci i Clauda (MCP).
     *
     * @param list<int> $pages
     */
    public static function saveVariant(Db $db, string $type, string $language, string $variant, string $name, array $pages, string $contentLanguage): string
    {
        $items = (string) json_encode(array_values(array_unique(array_map('intval', $pages))));
        if (preg_match(self::VARIANT_PATTERN, $variant) && self::row($db, $type, $language, $variant) !== null) {
            $db->update('casti', ['nazev' => $name, 'stranky' => $items, 'zmeneno' => date('Y-m-d H:i:s')], ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant]);

            return $variant;
        }
        $variant = substr(slugify($name, 40), 0, 40);
        for ($i = 2, $base = $variant; self::row($db, $type, $language, $variant) !== null; $i++) {
            $variant = substr($base, 0, 36) . '-' . $i;
        }
        $defaults = self::row($db, $type, $language);
        $db->insert('casti', ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant, 'nazev' => $name, 'stranky' => $items, 'zmeneno' => date('Y-m-d H:i:s'),
            'stavba_koncept' => $defaults['stavba'] ?? Build::toJson(self::defaults($type, $contentLanguage))]);

        return $variant;
    }

    /** Publikovaná stavba části (nebo rozpracovaná pro náhled v editoru); null = část ze šablony. */
    public static function build(Db $db, string $type, string $language, bool $draft = false, string $variant = ''): ?array
    {
        $r = self::row($db, $type, $language, $variant);

        return $r === null ? null : Build::fromJson($draft ? ($r['stavba_koncept'] ?? $r['stavba']) : $r['stavba']);
    }

    /** Varianta části pro stránku (první publikovaná, která ji má v seznamu), jinak '' = výchozí. */
    public static function pageVariant(Db $db, string $type, string $language, ?int $ids): string
    {
        if ($ids === null || !in_array($type, self::WITH_VARIANTS, true)) {
            return '';
        }
        foreach ($db->all("SELECT varianta, stranky FROM {casti} WHERE typ = ? AND jazyk = ? AND varianta <> '' AND stavba IS NOT NULL ORDER BY varianta", [$type, $language]) as $r) {
            if (in_array($ids, array_map('intval', json_decode((string) $r['stranky'], true) ?: []), true)) {
                return (string) $r['varianta'];
            }
        }

        return '';
    }

    /**
     * Koncept nové části v jazyce $sloupec (JSON): další jazyk začíná kopií téže části výchozího jazyka (stejný vzhled,
     * texty se přeloží), jinak výchozí stavbou podle šablony v jazyce obsahu $jazyk.
     */
    public static function initialDraft(Db $db, string $type, string $column, string $language): string
    {
        $defaults = $column !== '' ? self::row($db, $type, '') : null;
        $json = $defaults['stavba_koncept'] ?? $defaults['stavba'] ?? null;

        return $json !== null ? (string) $json : Build::toJson(self::defaults($type, $language));
    }

    /** Stavba, se kterou se část poprvé otevře v builderu (odpovídá tomu, co dosud kreslila šablona). */
    public static function defaults(string $type, string $language): array
    {
        return \Kaleta\Core\Language::runWith($language, function () use ($type): array {
            $n = Build::fresh(...);
            $s = fn (array $p, array $style): array => ['styl' => $style] + $p;
            $z = fn (array $p, string $htmlTag): array => ['znacka' => $htmlTag] + $p;
            $children = match ($type) {
                'hlavicka' => [$s($z($n('sekce', [], [
                    $s($n('kontejner', [], [$n('logo'), $n('navigace')]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'center', 'mezera' => 'm']]),
                ]), 'header'), ['zaklad' => ['odsazeni_y' => 's', 'pozadi' => 'pozadi', 'linka_dole' => '1px solid var(--ka-barva-linka)', 'pozice' => 'sticky', 'odshora' => '0', 'vrstva' => '10']])],
                'paticka' => [$s($z($n('sekce', [], [
                    $s($n('mrizka', [], [
                        $n('kontejner', [], [$s($z($n('udaje', ['udaj' => 'nazev']), 'p'), ['zaklad' => ['tloustka_pisma' => '700']]), $n('udaje', ['udaj' => 'popis']), $n('udaje', ['udaj' => 'email'])]),
                        $n('kontejner', [], [$n('navigace', ['menu' => 'paticka', 'novinky' => false, 'mobil' => false]), $n('udaje', ['udaj' => 'site'])]), // RSS jen v <link rel="alternate">, firemní patička ho nepotřebuje
                    ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'l'], 'mobil' => ['sloupce' => '1']]),
                    $s($n('udaje', ['udaj' => 'copyright']), ['zaklad' => ['okraj_nahore' => 'l', 'velikost_pisma' => '-1', 'barva' => 'tlumeny']]),
                ]), 'footer'), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'plocha', 'linka_nahore' => '1px solid var(--ka-barva-linka)']])],
                'novinka' => [$n('obsah', [], []), Library::section('vyzva', \Kaleta\Core\Language::code())['prvek']],
                default => [$n('obsah', [], [])],
            };

            return Build::sanitize(['v' => Build::VERSION, 'deti' => $children])[0];
        });
    }

    public static function versionKey(string $type, string $language, string $variant = ''): string
    {
        return $type . ':' . $language . ($variant !== '' ? ':' . $variant : '');
    }
}
