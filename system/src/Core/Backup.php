<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Zálohy databáze do storage/zalohy/ (z webu nepřístupné). Bez mysqldump - funguje i na sdíleném hostingu.
 */
final class Backup
{
    public const string FOLDER = KALETA_ROOT . '/storage/zalohy';
    private const int KEEP = 10;

    /** @return string název vytvořeného souboru */
    public static function create(Db $db, string $reason = 'rucni'): string
    {
        if (!is_dir(self::FOLDER) && !mkdir(self::FOLDER, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku storage/zalohy - zkontrolujte práva k zápisu.');
        }
        $gz = function_exists('gzopen');
        $file = 'kaleta-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]/', '', $reason) . '-' . bin2hex(random_bytes(4)) . '.sql' . ($gz ? '.gz' : '');
        $path = self::FOLDER . '/' . $file;
        $f = $gz ? gzopen($path, 'wb6') : fopen($path, 'wb');
        $write = fn (string $s) => $gz ? gzwrite($f, $s) : fwrite($f, $s);

        $pdo = $db->pdo();
        $write("-- Kaleta " . KALETA_VERSION . " - záloha databáze " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = $db->run('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name', [addcslashes($db->prefix, '_%') . '%'])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            // dočasná data se nezálohují
            $structureOnly = in_array(substr($table, strlen($db->prefix)), ['stat_navstevnici', 'kontrola_ip'], true);
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM)[1];
            $write("DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            if ($structureOnly) {
                continue;
            }
            $rows = $pdo->query('SELECT * FROM `' . $table . '`', \PDO::FETCH_NUM);
            $batch = [];
            foreach ($rows as $row) {
                $batch[] = '(' . implode(',', array_map(fn ($h): string => $h === null ? 'NULL' : (is_int($h) || is_float($h) ? (string) $h : $pdo->quote((string) $h)), $row)) . ')';
                if (count($batch) >= 200) {
                    $write("INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $write("INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $write("\n");
        }
        $write("SET FOREIGN_KEY_CHECKS = 1;\n");
        $gz ? gzclose($f) : fclose($f);

        foreach (array_slice(self::listAll(), self::KEEP) as $old) {
            @unlink(self::FOLDER . '/' . $old['soubor']);
        }

        return $file;
    }

    /**
     * Obnoví databázi ze zálohy vytvořené touto třídou. Příkaz v záloze vždy končí středníkem na konci řádku
     * (hodnoty zapisuje PDO::quote, konce řádků v textech jsou v nich zakódované), takže ji lze číst po řádcích
     * bez načtení celého souboru do paměti.
     *
     * @return int počet provedených příkazů
     * @throws \RuntimeException
     */
    public static function restore(Db $db, string $file): int
    {
        $path = self::path($file);
        if ($path === null) {
            throw new \RuntimeException('Záloha neexistuje.');
        }
        $gz = str_ends_with($path, '.gz');
        if ($gz && !function_exists('gzopen')) {
            throw new \RuntimeException('Server neumí číst komprimované zálohy (chybí zlib).');
        }
        // první průchod jen kontroluje (hlavička, jen tabulky této instalace, úplný poslední příkaz) – do databáze se sahá,
        // až když je celý soubor v pořádku; poškozená nebo cizí záloha tak nenechá databázi napůl obnovenou
        $count = self::walk($path, $gz, $db->prefix, null);
        @set_time_limit(300);
        $pdo = $db->pdo();
        self::walk($path, $gz, $db->prefix, fn (string $statement): mixed => $pdo->exec($statement));
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $count;
    }

    /** @param (callable(string): mixed)|null $apply null = jen kontrola */
    private static function walk(string $path, bool $gz, string $prefix, ?callable $apply): int
    {
        $f = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        $first = (string) ($gz ? gzgets($f) : fgets($f));
        if (!str_starts_with($first, '-- Kaleta ')) {
            throw new \RuntimeException('Soubor není záloha vytvořená systémem Kaleta.');
        }
        $statement = '';
        $count = 0;
        while (($row = $gz ? gzgets($f) : fgets($f)) !== false) {
            if ($statement === '' && (trim($row) === '' || str_starts_with($row, '--'))) {
                continue;
            }
            $statement .= $row;
            if (str_ends_with(rtrim($row), ';')) {
                // záloha smí obsahovat jen tabulky této instalace
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/', $statement, $m) && !str_starts_with($m[2], $prefix)) {
                    throw new \RuntimeException('Záloha obsahuje cizí tabulku ' . $m[2] . ' – obnova byla zastavena.');
                }
                if ($apply !== null) {
                    $apply($statement);
                }
                $statement = '';
                $count++;
            }
        }
        $gz ? gzclose($f) : fclose($f);
        if (trim($statement) !== '' || $count === 0) {
            throw new \RuntimeException('Záloha je neúplná nebo poškozená (chybí konec souboru) – obnova byla zastavena.');
        }

        return $count;
    }

    /** @return list<array{soubor:string, velikost:int, cas:int}> od nejnovější */
    public static function listAll(): array
    {
        $backups = [];
        foreach (glob(self::FOLDER . '/kaleta-*.sql*') ?: [] as $path) {
            $backups[] = ['soubor' => basename($path), 'velikost' => (int) filesize($path), 'cas' => (int) filemtime($path)];
        }
        usort($backups, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $backups;
    }

    /** Cesta k existující záloze podle názvu z adresy; null = neplatný název. */
    public static function path(string $file): ?string
    {
        return preg_match('/^kaleta-[0-9a-z-]+\.sql(\.gz)?$/', $file) && is_file(self::FOLDER . '/' . $file) ? self::FOLDER . '/' . $file : null;
    }

    /** Automatická týdenní záloha - volá se při vstupu administrátora do administrace. */
    public static function createAutomatic(Db $db, Settings $settings): void
    {
        if (!$settings->bool('auto_backups')) {
            return;
        }
        $last = self::listAll()[0]['cas'] ?? 0;
        if (time() - $last > 7 * 86400) {
            try {
                $file = self::create($db, 'auto');
                RemoteBackup::upload($settings, (string) self::path($file)); // výsledek ukáže Stav systému a záložka Zálohy
            } catch (\Throwable) {
                // záloha nesmí shodit administraci; na chybějící zálohu upozorní Stav systému
            }
        }
    }
}
