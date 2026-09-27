<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;
use Kaleta\Core\WpContent;

/**
 * Kolekce – vlastní typy obsahu (reference, tým, produkty, pobočky…): definice polí, položky a hodnoty pro builder.
 *
 * V builderu je vypisuje prvek Výpis kolekce: jeho vnitřek se zopakuje pro každou položku a zástupné značky {{pole}}
 * v textech, obrázcích a odkazech se nahradí hodnotami položky. Vždy jsou k dispozici {{nazev}}, {{url}} (detail) a {{datum}}.
 */
final class Collections
{
    /** Typy polí (klíč => popisek). */
    public const array FIELD_TYPES = ['text' => 'krátký text', 'radky' => 'delší text', 'html' => 'formátovaný text', 'obrazek' => 'obrázek', 'odkaz' => 'odkaz', 'cislo' => 'číslo', 'datum' => 'datum'];

    /** Vestavěné hodnoty každé položky – vlastní pole je mít nesmí. */
    public const array BUILT_IN = ['nazev', 'url', 'datum', 'seo'];

    public const string PLACEHOLDER_PATTERN = '/\{\{([a-z][a-z0-9_]{0,30})\}\}/';

    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,30}$/';

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::extract(...), $db->all('SELECT * FROM {kolekce} ORDER BY nazev'));
    }

    /** @return array<string, mixed>|null */
    public static function bySlug(Db $db, string $seo): ?array
    {
        $r = $db->one('SELECT * FROM {kolekce} WHERE seo_link = ?', [$seo]);

        return $r === null ? null : self::extract($r);
    }

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $idk): ?array
    {
        $r = $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$idk]);

        return $r === null ? null : self::extract($r);
    }

    /**
     * Šablona detailu v jazykové verzi: kolekce, jejíž stavba a koncept patří jazyku (sablona_jazyk). Výchozí jazyk ('')
     * má šablonu v ka_kolekce, další jazyky v ka_kolekce_sablony; jazyk bez vlastní šablony má stavbu i koncept null.
     *
     * @param array<string, mixed> $collection @return array<string, mixed>
     */
    public static function inLanguage(Db $db, array $collection, string $language): array
    {
        $collection['sablona_jazyk'] = $language;
        if ($language === '') {
            return $collection;
        }
        $r = $db->one('SELECT stavba, stavba_koncept, zmeneno FROM {kolekce_sablony} WHERE idk = ? AND jazyk = ?', [$collection['idk'], $language]);

        return ['stavba' => $r['stavba'] ?? null, 'stavba_koncept' => $r['stavba_koncept'] ?? null, 'zmeneno' => $r['zmeneno'] ?? null] + $collection;
    }

    /** Zapíše sloupce šablony jazyka, kterou vrátil vJazyce (výchozí do ka_kolekce, další jazyk založí řádek). @param array<string, mixed> $sloupce */
    public static function writeTemplate(Db $db, array $collection, array $columns): void
    {
        $language = (string) ($collection['sablona_jazyk'] ?? '');
        if ($language === '') {
            $db->update('kolekce', $columns, ['idk' => $collection['idk']]);
        } elseif ($db->value('SELECT 1 FROM {kolekce_sablony} WHERE idk = ? AND jazyk = ?', [$collection['idk'], $language]) !== null) {
            $db->update('kolekce_sablony', $columns, ['idk' => $collection['idk'], 'jazyk' => $language]);
        } else {
            $db->insert('kolekce_sablony', $columns + ['idk' => $collection['idk'], 'jazyk' => $language]);
        }
    }

    /** Klíč verzí a podepsaného náhledu šablony: kolekce:<idk>, u dalšího jazyka kolekce:<idk>:<jazyk>. */
    public static function templateKey(array $collection): string
    {
        $language = (string) ($collection['sablona_jazyk'] ?? '');

        return 'kolekce:' . (int) $collection['idk'] . ($language !== '' ? ':' . $language : '');
    }

    /**
     * Koncept, se kterým builder šablonu jazyka otevře, dokud ji nikdo neuložil: další jazyk začíná kopií šablony
     * výchozího jazyka, výchozí jazyk šablonou poskládanou z polí kolekce.
     */
    public static function initialTemplateDraft(Db $db, array $collection): string
    {
        if (($collection['sablona_jazyk'] ?? '') !== '') {
            $defaults = (array) self::byId($db, (int) $collection['idk']);
            if (($defaults['stavba_koncept'] ?? $defaults['stavba'] ?? null) !== null) {
                return (string) ($defaults['stavba_koncept'] ?? $defaults['stavba']);
            }
        }

        return Build::toJson(self::defaultTemplate($collection));
    }

    private static function extract(array $r): array
    {
        $r['pole'] = json_decode((string) $r['pole'], true) ?: [];

        return $r;
    }

    /**
     * Definice polí z formuláře nebo od AI: klíč jen malá písmena, číslice a podtržítko (vznikne z popisku), známý typ.
     *
     * @return list<array{klic: string, popisek: string, typ: string}>
     */
    public static function sanitizeFields(mixed $input): array
    {
        $field = [];
        $keys = [];
        foreach (is_array($input) ? $input : [] as $p) {
            $labelText = mb_substr(trim(strip_tags((string) ($p['popisek'] ?? ''))), 0, 80);
            if ($labelText === '') {
                continue;
            }
            $key = (string) ($p['klic'] ?? '');
            $key = preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key) ? $key : substr(str_replace('-', '_', slugify($labelText, 30)), 0, 30);
            if (!preg_match('/^[a-z]/', $key)) {
                $key = 'pole_' . $key;
            }
            while (in_array($key, self::BUILT_IN, true) || isset($keys[$key])) {
                $key .= '_2';
            }
            $keys[$key] = true;
            $field[] = ['klic' => $key, 'popisek' => $labelText, 'typ' => isset(self::FIELD_TYPES[$p['typ'] ?? '']) ? $p['typ'] : 'text'];
        }

        return array_slice($field, 0, 30);
    }

    /**
     * Hodnoty položky podle definice polí. Neplatná hodnota se zahodí a nahlásí.
     *
     * @param list<array{klic: string, popisek: string, typ: string}> $field
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    public static function sanitizeData(array $field, array $input, array &$errors = []): array
    {
        $data = [];
        foreach ($field as $p) {
            $h = trim((string) (is_scalar($input[$p['klic']] ?? null) ? $input[$p['klic']] : ''));
            $clean = match ($p['typ']) {
                'text' => mb_substr(strip_tags(str_replace(["\r", "\n"], ' ', $h)), 0, 500),
                'radky' => mb_substr(strip_tags(str_replace("\r\n", "\n", $h)), 0, 5000),
                'html' => WpContent::safeHtml(mb_substr($h, 0, 100000)),
                'obrazek' => $h === '' || preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $h) ? $h : null,
                'odkaz' => $h === '' || (WpContent::isSafeUrl($h) && !preg_match('/[\s"<>]/', $h)) ? mb_substr($h, 0, 500) : null,
                'cislo' => $h === '' || is_numeric(str_replace([' ', ','], ['', '.'], $h)) ? str_replace(' ', '', $h) : null,
                'datum' => $h === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $h) && strtotime($h) !== false) ? $h : null,
                default => '',
            };
            if ($clean === null) {
                $errors[$p['klic']] = $p['popisek'];
                $clean = '';
            }
            $data[$p['klic']] = $clean;
        }

        return $data;
    }

    /**
     * Viditelné položky kolekce v jazyce webu: filtr podle hodnoty pole, řazení (i podle vlastního pole) a stránkování.
     *
     * @param array{0: string, 1: string}|null $filter [klíč pole, hodnota]
     * @return array{0: list<array<string, mixed>>, 1: int} [položky, celkem]
     */
    public static function items(Db $db, int $idk, string $language, int $count, string $sort = 'poradi', ?array $filter = null, int $pageNumber = 1, string $sortField = ''): array
    {
        $field = fn (string $key): string => "JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "'))"; // klíč prošel VZOR_KLICE
        $whereParts = 'idk = ? AND zobrazit = 1 AND jazyk = ?';
        $params = [$idk, $language];
        if ($filter !== null && preg_match(self::KEY_PATTERN, $filter[0]) && $filter[1] !== '') {
            $whereParts .= ' AND ' . $field($filter[0]) . ' = ?';
            $params[] = $filter[1];
        }
        $byField = preg_match(self::KEY_PATTERN, $sortField) === 1;
        $order = match (true) {
            $sort === 'nazev' => 'nazev',
            $sort === 'nejnovejsi' => 'datum DESC, idp DESC',
            // čísla se řadí jako čísla, ostatní jako text
            $sort === 'pole' && $byField => '(' . $field($sortField) . ' + 0) ASC, ' . $field($sortField) . ' ASC, nazev',
            $sort === 'pole_sestupne' && $byField => '(' . $field($sortField) . ' + 0) DESC, ' . $field($sortField) . ' DESC, nazev',
            default => 'poradi, nazev',
        };
        $count = max(1, min(100, $count));
        $total = (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE ' . $whereParts, $params);
        $items = array_map(function (array $r): array {
            $r['data'] = json_decode((string) $r['data'], true) ?: [];

            return $r;
        }, $db->all('SELECT * FROM {kolekce_polozky} WHERE ' . $whereParts . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [...$params, $count, (max(1, $pageNumber) - 1) * $count]));

        return [$items, $total];
    }

    /** Různé hodnoty pole mezi viditelnými položkami (tlačítka filtru ve výpisu). @return list<string> */
    public static function fieldValues(Db $db, int $idk, string $language, string $key): array
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', array_column($db->all(
            "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "')) AS h FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND jazyk = ? ORDER BY h LIMIT 30",
            [$idk, $language],
        ), 'h')), fn (string $h): bool => $h !== '' && $h !== 'null'));
    }

    /**
     * Hodnoty pro zástupné značky: klíč => [hodnota, typ].
     *
     * @param callable(string): string $url adresa uvnitř webu
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(array $collection, array $item, callable $url): array
    {
        $h = [
            'nazev' => [(string) $item['nazev'], 'text'],
            'url' => [$collection['detail'] ? $url($collection['seo_link'] . '/' . $item['seo_link']) : '', 'odkaz'],
            'datum' => [format_date((string) $item['datum']), 'text'],
            'seo' => [(string) $item['seo_link'], 'text'],
        ];
        foreach ($collection['pole'] as $p) {
            $h[$p['klic']] = [(string) ($item['data'][$p['klic']] ?? ''), $p['typ']];
        }

        return $h;
    }

    /** Ukázkové hodnoty pro editor, když kolekce ještě nemá položky: popisky polí v hranatých závorkách. */
    public static function sample(array $collection): array
    {
        $h = ['nazev' => ['[' . t('Název') . ']', 'text'], 'url' => ['#', 'odkaz'], 'datum' => [format_date(date('Y-m-d H:i:s')), 'text'], 'seo' => ['', 'text']];
        foreach ($collection['pole'] as $p) {
            $h[$p['klic']] = [in_array($p['typ'], ['obrazek', 'odkaz'], true) ? '' : '[' . $p['popisek'] . ']', $p['typ']];
        }

        return $h;
    }

    /**
     * Dosadí hodnoty do pole obsahu prvku podle typu cílového pole (text se escapuje až při vykreslení, inline a html hned).
     *
     * @param array<string, array{0: string, 1: string}> $values
     */
    public static function fill(string $text, string $target, array $values): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        // jeden průchod: dosazená hodnota se už znovu neprochází (značky {{…}} napsané v textu pole zůstanou textem)
        $htmlTag = substr(self::PLACEHOLDER_PATTERN, 1, -1);
        $pattern = $target === 'html' ? '#<p>\s*' . $htmlTag . '\s*</p>|' . $htmlTag . '#' : self::PLACEHOLDER_PATTERN;
        $result = (string) preg_replace_callback($pattern, function (array $m) use ($target, $values): string {
            $key = ($m[1] ?? '') !== '' ? $m[1] : $m[2];
            [$h, $type] = $values[$key] ?? ['', 'text'];
            if ($target === 'html' && ($m[1] ?? '') !== '') {
                // odstavec jen se značkou formátovaného nebo delšího textu se nahradí celý (jinak by vzniklo <p><p>…</p></p>)
                return match ($type) {
                    'html' => $h,
                    'radky' => $h === '' ? '' : '<p>' . nl2br(e($h), false) . '</p>',
                    default => $h === '' ? '' : '<p>' . e($h) . '</p>',
                };
            }
            $plain = $type === 'html' ? trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)) : $h;

            return match ($target) {
                'html' => $type === 'html' ? $h : ($type === 'radky' ? nl2br(e($h), false) : e($h)),
                'inline' => $type === 'radky' ? nl2br(e($h), false) : e($plain),
                // Vlastní HTML se vypisuje, jak je (filtr kódu proběhl při uložení, dosazení až teď): hodnota nesmí přinést značky
                'kod' => $type === 'html' ? \Kaleta\Core\Html::safe($h) : ($type === 'radky' ? nl2br(e($h), false) : e($h)),
                default => $plain,
            };
        }, $text);
        if ($target === 'odkaz' && $result !== '' && !WpContent::isSafeUrl($result)) {
            return '';
        }
        if ($target === 'obrazek' && $result !== '' && !preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $result)) {
            return '';
        }

        return $result;
    }

    /** Šablona detailu položky, dokud ji správce neupraví v builderu: nadpis, obrázek a všechna pole pod sebou. */
    public static function defaultTemplate(array $collection): array
    {
        $n = Build::fresh(...);
        $children = [['znacka' => 'h1'] + $n('nadpis', ['text' => '{{nazev}}'])];
        foreach ($collection['pole'] as $p) {
            $children[] = match ($p['typ']) {
                'obrazek' => $n('obrazek', ['src' => '{{' . $p['klic'] . '}}', 'alt' => '{{nazev}}']),
                'odkaz' => $n('tlacitko', ['text' => $p['popisek'], 'odkaz' => '{{' . $p['klic'] . '}}', 'varianta' => 'obrys']),
                'html', 'radky' => $n('text', ['html' => '{{' . $p['klic'] . '}}']),
                default => $n('text', ['html' => '<p><strong>' . e($p['popisek']) . ':</strong> {{' . $p['klic'] . '}}</p>']),
            };
        }

        return Build::sanitize(['v' => Build::VERSION, 'deti' => [$n('sekce', ['sirka' => 'uzka'], [
            ['styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]] + $n('kontejner', [], $children),
        ])]])[0];
    }
}
