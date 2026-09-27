<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Db;

/**
 * Publikování konceptu stavby (stránka, část webu, kolekce, komponenta nebo pop-up) – z editoru i z MCP. Předchozí publikovaná verze jde do historie
 * (ka_stavba_revize, 20 posledních pro každý cíl), cache webu se vymaže.
 */
final class Publisher
{
    public const int VERSIONS_KEPT = 20;

    /** Stránka: do textu se uloží obsah bez rozložení – z něj čerpá hledání, llms.txt, API i návrat k textu. */
    public static function page(App $app, array $page): void
    {
        $new = $page['stavba_koncept'] ?? $page['stavba'];
        self::version($app, ['ids' => $page['ids']], $page['stavba'], $new, $page['zmeneno'] ?? null);
        $text = Build::asText(Build::fromJson($new) ?? []);
        $app->db()->update('stranky', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')] + ($text !== '' ? ['text' => $text] : []), ['ids' => $page['ids']]);
        \Kaleta\Front\Cache::clear();
    }

    public static function part(App $app, array $row): void
    {
        $new = $row['stavba_koncept'] ?? $row['stavba'];
        $variant = (string) ($row['varianta'] ?? '');
        self::version($app, ['cast' => SiteParts::versionKey($row['typ'], $row['jazyk'], $variant)], $row['stavba'], $new, $row['zmeneno'] ?? null);
        $app->db()->update('casti', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['typ' => $row['typ'], 'jazyk' => $row['jazyk'], 'varianta' => $variant]);
        \Kaleta\Front\Cache::clear();
    }

    /** Šablona detailu položek kolekce v jazyce z Kolekce::vJazyce (verze pod klíčem „kolekce:<idk>“, u dalšího jazyka „kolekce:<idk>:<jazyk>“). */
    public static function collection(App $app, array $collection): void
    {
        $new = $collection['stavba_koncept'] ?? $collection['stavba'];
        self::version($app, ['cast' => Collections::templateKey($collection)], $collection['stavba'], $new, $collection['zmeneno'] ?? null);
        Collections::writeTemplate($app->db(), $collection, ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')]);
        \Kaleta\Front\Cache::clear();
    }

    /** Pop-up okno (verze pod klíčem „popup:<idpp>“). */
    public static function popup(App $app, array $popup): void
    {
        $new = $popup['stavba_koncept'] ?? $popup['stavba'];
        self::version($app, ['cast' => 'popup:' . (int) $popup['idpp']], $popup['stavba'], $new, $popup['zmeneno'] ?? null);
        $app->db()->update('popupy', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['idpp' => $popup['idpp']]);
        \Kaleta\Front\Cache::clear();
    }

    /** Komponenta (verze pod klíčem „komponenta:<idm>“) – změna se projeví na všech stránkách, kde je použitá. */
    public static function component(App $app, array $component): void
    {
        $new = $component['stavba_koncept'] ?? $component['stavba'];
        self::version($app, ['cast' => 'komponenta:' . (int) $component['idm']], $component['stavba'], $new, $component['zmeneno'] ?? null);
        $app->db()->update('komponenty', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['idm' => $component['idm']]);
        \Kaleta\Front\Cache::clear();
    }

    /** Uloží předchozí publikovanou verzi do historie. @param array{ids?: int|string, cast?: string} $cil */
    public static function version(App $app, array $target, ?string $old, ?string $newVersion, ?string $date): void
    {
        if ($old === null || $old === $newVersion) {
            return;
        }
        $db = $app->db();
        $db->insert('stavba_revize', $target + ['datum' => $date ?? date('Y-m-d H:i:s'), 'kdo' => $app->auth()->id() ?: null, 'stavba' => $old]);
        [$whereParts, $value] = self::whereClause($target);
        $boundary = $db->value('SELECT idr FROM {stavba_revize} WHERE ' . $whereParts . ' ORDER BY idr DESC LIMIT 1 OFFSET ' . self::VERSIONS_KEPT, [$value]);
        if ($boundary !== null) {
            $db->run('DELETE FROM {stavba_revize} WHERE ' . $whereParts . ' AND idr <= ?', [$value, $boundary]);
        }
    }

    /** @param array{ids?: int|string, cast?: string} $target @return list<array<string, mixed>> */
    public static function listAll(Db $db, array $target): array
    {
        [$whereParts, $value] = self::whereClause($target);

        return $db->all("SELECT r.idr, r.datum, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo FROM {stavba_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r." . $whereParts . ' ORDER BY r.idr DESC', [$value]);
    }

    /** @param array{ids?: int|string, cast?: string} $target */
    public static function load(Db $db, array $target, int $idr): ?string
    {
        [$whereParts, $value] = self::whereClause($target);
        $build = $db->value('SELECT stavba FROM {stavba_revize} WHERE idr = ? AND ' . $whereParts, [$idr, $value]);

        return $build === null ? null : (string) $build;
    }

    /** @return array{0: string, 1: int|string} */
    private static function whereClause(array $target): array
    {
        return isset($target['ids']) ? ['ids = ?', (int) $target['ids']] : ['cast = ?', (string) $target['cast']];
    }
}
