<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Kontrola stavby před publikováním pro Clauda (MCP) – stejná pravidla jako v builderu (image/stavitel.js, kontrola()):
 * tlačítka bez odkazu, obrázky bez souboru či popisu a u stránek osnova nadpisů. Kontrast textu tu chybí, ten potřebuje
 * vykreslenou stránku a hlídá ho builder v prohlížeči.
 */
final class Check
{
    public const int MAX = 12;

    /**
     * @param array<string, mixed> $build vyčištěná stavba
     * @param bool $headings hlídat osnovu nadpisů (stránka má mít jeden h1 a nepřeskakovat úrovně)
     * @return list<array{id: ?string, zprava: string}>
     */
    public static function builds(array $build, bool $headings): array
    {
        $findings = [];
        $outline = [];
        $tags = static fn (mixed $x): bool => is_string($x) && str_contains($x, '{{');
        $walk = static function (array $children) use (&$walk, &$findings, &$outline, $tags): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $o = is_array($p['obsah'] ?? null) ? $p['obsah'] : [];
                $id = isset($p['id']) ? (string) $p['id'] : null;
                $type = $p['typ'] ?? '';
                if ($type === 'tlacitko' && in_array($o['odkaz'] ?? '', ['', '#'], true)) {
                    $findings[] = ['id' => $id, 'zprava' => t('Tlačítko „%s“ nikam nevede – doplňte odkaz.', self::text($o['text'] ?? ''))];
                }
                if ($type === 'obrazek' && ($o['src'] ?? '') === '') {
                    $findings[] = ['id' => $id, 'zprava' => t('Obrázek není vybraný – na webu se nezobrazí.')];
                } elseif ($type === 'obrazek' && ($o['alt'] ?? '') === '' && !$tags($o['src'])) {
                    $findings[] = ['id' => $id, 'zprava' => t('Obrázek nemá popis pro nevidomé (alt).')];
                }
                // nadpis se značkou p (velké číslo, štítek) do osnovy nepatří
                if ($type === 'nadpis' && preg_match('/^h([1-6])$/', (string) ($p['znacka'] ?? 'h2'), $m)) {
                    $outline[] = [$id, (int) $m[1], self::text($o['text'] ?? '')];
                }
                if (is_array($p['deti'] ?? null)) {
                    $walk($p['deti']);
                }
            }
        };
        $walk(is_array($build['deti'] ?? null) ? $build['deti'] : []);
        if ($headings) {
            $h1 = array_values(array_filter($outline, static fn (array $n): bool => $n[1] === 1));
            if ($h1 === []) {
                $findings[] = ['id' => $outline[0][0] ?? null, 'zprava' => t('Stránka nemá hlavní nadpis (h1) – vyhledávače i čtečky podle něj poznají, o čem je.')];
            }
            if (count($h1) > 1) {
                $findings[] = ['id' => $h1[1][0], 'zprava' => t('Stránka má víc hlavních nadpisů (h1) – nechte jen jeden.')];
            }
            foreach ($outline as $i => $n) {
                if ($i > 0 && $n[1] > $outline[$i - 1][1] + 1) {
                    $findings[] = ['id' => $n[0], 'zprava' => t('Nadpis „%s“ přeskakuje úroveň (h%d → h%d).', mb_substr($n[2], 0, 40), $outline[$i - 1][1], $n[1])];
                }
            }
        }

        return array_slice($findings, 0, self::MAX);
    }

    private static function text(mixed $html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5));
    }
}
