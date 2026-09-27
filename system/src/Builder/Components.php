<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Komponenty – znovupoužitelné bloky builderu (tabulka ka_komponenty). Uvnitř komponenty jsou {{vlastnosti}} – stejné
 * značky jako u kolekcí (Kolekce::dosad) – a každé použití na stránce (prvek „komponenta“) jim dá vlastní hodnoty.
 */
final class Components
{
    /** Typy vlastností (podmnožina polí kolekcí). */
    public const array TYPES = ['text' => 'krátký text', 'radky' => 'delší text', 'html' => 'formátovaný text', 'obrazek' => 'obrázek', 'odkaz' => 'odkaz'];

    /** Nejvyšší zanoření komponent do sebe (komponenta v komponentě…). */
    public const int MAX_NESTING = 4;

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::extract(...), $db->all('SELECT * FROM {komponenty} ORDER BY nazev'));
    }

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $idm): ?array
    {
        $r = $db->one('SELECT * FROM {komponenty} WHERE idm = ?', [$idm]);

        return $r === null ? null : self::extract($r);
    }

    private static function extract(array $r): array
    {
        $r['vlastnosti'] = json_decode((string) $r['vlastnosti'], true) ?: [];

        return $r;
    }

    /**
     * Definice vlastností z formuláře nebo od AI: klíč, popisek, typ a výchozí hodnota (zkontrolovaná podle typu).
     *
     * @return list<array{klic: string, popisek: string, typ: string, vychozi: string}>
     */
    public static function sanitizeProperties(mixed $input): array
    {
        // jen řádky s popiskem; výchozí hodnota jde ruku v ruce s polem, pořadí se nesmí rozejít
        $rows = array_values(array_filter(is_array($input) ? $input : [], fn (mixed $v): bool => is_array($v) && trim(strip_tags((string) ($v['popisek'] ?? ''))) !== ''));
        $rows = array_slice($rows, 0, 30);
        $field = Collections::sanitizeFields(array_map(fn (array $v): array => ['typ' => isset(self::TYPES[$v['typ'] ?? '']) ? $v['typ'] : 'text'] + $v, $rows));
        $defaults = Collections::sanitizeData($field, array_combine(array_column($field, 'klic'), array_map(fn (array $v): string => is_scalar($v['vychozi'] ?? null) ? (string) $v['vychozi'] : '', $rows)));

        return array_map(fn (array $p): array => $p + ['vychozi' => $defaults[$p['klic']] ?? ''], $field);
    }

    /**
     * Hodnoty pro {{značky}}: zadané u použití, jinak výchozí. Zkontrolují se podle typu vlastnosti (obrázek, odkaz, HTML).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(array $component, array $given): array
    {
        $clean = Collections::sanitizeData($component['vlastnosti'], $given);
        $h = [];
        foreach ($component['vlastnosti'] as $v) {
            $h[$v['klic']] = [($clean[$v['klic']] ?? '') !== '' ? $clean[$v['klic']] : (string) $v['vychozi'], $v['typ']];
        }

        return $h;
    }
}
