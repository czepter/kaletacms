<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Database structure updates.
 *
 * The files system/sql/migrace/NNNN-description.sql run in ascending order, the number of the last one applied
 * is in ka_nastaveni (db_version). A new installation gets the complete schema.sql and the highest number right away.
 *
 * A data migration (2.0) is NNNN-description.php returning function (Db $db, Settings $settings): void. The code of the
 * release that runs an update request knows only the .sql files, so a PHP migration must be the highest number of its
 * release – the new code then runs it on the first request after the update.
 */
final class Migration
{
    private const string FOLDER = KALETA_SYSTEM . '/sql/migrace';

    /** MySQL errors that mean "this change is already in the database": a table, column, index or foreign key exists, a dropped column or index is missing. */
    private const array ALREADY_APPLIED = [1050, 1060, 1061, 1022, 1826, 1091];

    /** @return array<int, string> number => file, ascending */
    public static function files(): array
    {
        $files = [];
        foreach ([...(glob(self::FOLDER . '/[0-9][0-9][0-9][0-9]-*.sql') ?: []), ...(glob(self::FOLDER . '/[0-9][0-9][0-9][0-9]-*.php') ?: [])] as $file) {
            $files[(int) substr(basename($file), 0, 4)] = $file;
        }
        ksort($files);

        return $files;
    }

    public static function latest(): int
    {
        return max(1, ...array_keys(self::files()));
    }

    /** Migrations for the public site: an error is written to the log (at most once per hour) and the site keeps running. */
    public static function safe(Db $db, Settings $settings): void
    {
        try {
            self::apply($db, $settings);
        } catch (\Throwable $e) {
            self::writeError($e);
        }
    }

    public static function writeError(\Throwable $e): void
    {
        $htmlTag = KALETA_ROOT . '/storage/cache/migrace-chyba';
        if (is_file($htmlTag) && time() - (int) filemtime($htmlTag) < 3600) {
            return;
        }
        @touch($htmlTag);
        @file_put_contents(KALETA_ROOT . '/storage/log/chyby.log', sprintf("[%s] Migrace databáze se nepovedla: %s\n", date('c'), $e->getMessage()), FILE_APPEND | LOCK_EX);
    }

    /** @return list<string> names of the migrations just applied */
    public static function apply(Db $db, Settings $settings): array
    {
        // lock: both the admin and the site run migrations, two concurrent requests must not apply the same change twice
        $lock = 'kaleta_migrace_' . $db->prefix;
        if ((int) $db->value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
            return [];
        }
        $applied = [];
        try {
            $version = max(1, (int) $db->value("SELECT MAX(CAST(hodnota AS UNSIGNED)) FROM {nastaveni} WHERE promenna IN ('db_version', 'verze_db')"));
            foreach (self::files() as $number => $file) {
                if ($number <= $version) {
                    continue;
                }
                if (str_ends_with($file, '.php')) {
                    (require $file)($db, $settings); // a data migration – written to run again safely
                    $settings->set('db_version', (string) $number);
                    $applied[] = basename($file, '.php');
                    continue;
                }
                foreach (self::statements((string) file_get_contents($file), $db->prefix) as $sql) {
                    try {
                        $db->pdo()->exec($sql);
                    } catch (\PDOException $e) {
                        // a migration interrupted halfway (outage, time limit) completes on the next attempt: changes that already
                        // happened (a table, column, index or foreign key exists / is missing) are skipped instead of a permanent error 500
                        if (!in_array((int) ($e->errorInfo[1] ?? 0), self::ALREADY_APPLIED, true)) {
                            throw $e;
                        }
                    }
                }
                $settings->set('db_version', (string) $number);
                $applied[] = basename($file, '.sql');
            }
            // settings keys of 1.4.0 and older (verze_db…) written again by the release that ran the update, after migration
            // 0026 had renamed them – only once 0026 is in (older data migrations still read the old keys)
            if ((int) $db->value("SELECT MAX(CAST(hodnota AS UNSIGNED)) FROM {nastaveni} WHERE promenna IN ('db_version', 'verze_db')") >= 26) {
                OldSettingsKeys::adopt($db);
            }
        } finally {
            $db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        return $applied;
    }

    /**
     * Splits an SQL script into statements and replaces the prefix "ka_" with the installation's prefix.
     *
     * @return list<string>
     */
    public static function statements(string $sql, string $prefix): array
    {
        // constraint names must be unique in the database - they get the prefix too
        $sql = preg_replace('/\b((?:CONSTRAINT|DROP FOREIGN KEY)\s+)fk_/', '$1' . $prefix . 'fk_', $sql) ?? $sql;
        $sql = preg_replace('/\bka_(?=[a-z])/', $prefix, $sql) ?? $sql;
        // a statement ends with a semicolon at the end of a line; only a comment may follow the semicolon
        $statements = preg_split('/;[ \t]*(--[^\n]*)?(\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map(trim(...), $statements), function (string $statement): bool {
            return trim((string) preg_replace('/^\s*--.*$/m', '', $statement)) !== '';
        }));
    }
}
