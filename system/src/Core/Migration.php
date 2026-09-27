<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Aktualizace struktury databáze.
 *
 * Soubory system/sql/migrace/NNNN-popis.sql se provedou vzestupně, číslo poslední provedené
 * je v ka_nastaveni (verze_db). Nová instalace dostane rovnou úplné schema.sql a nejvyšší číslo.
 */
final class Migration
{
    private const string FOLDER = KALETA_SYSTEM . '/sql/migrace';

    /** Chyby MySQL, které znamenají „tahle změna už v databázi je“: tabulka, sloupec, index, cizí klíč existuje, rušený sloupec či index chybí. */
    private const array ALREADY_APPLIED = [1050, 1060, 1061, 1022, 1826, 1091];

    /** @return array<int, string> číslo => soubor, vzestupně */
    public static function files(): array
    {
        $files = [];
        foreach (glob(self::FOLDER . '/[0-9][0-9][0-9][0-9]-*.sql') ?: [] as $file) {
            $files[(int) substr(basename($file), 0, 4)] = $file;
        }
        ksort($files);

        return $files;
    }

    public static function latest(): int
    {
        return max(1, ...array_keys(self::files()));
    }

    /** Migrace pro veřejný web: chyba se zapíše do protokolu (nejvýš jednou za hodinu) a web běží dál. */
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

    /** @return list<string> názvy právě provedených migrací */
    public static function apply(Db $db, Settings $settings): array
    {
        // zámek: migrace spouští administrace i web, dva souběžné požadavky nesmí tutéž změnu provést dvakrát
        $lock = 'kaleta_migrace_' . $db->prefix;
        if ((int) $db->value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
            return [];
        }
        $applied = [];
        try {
            $version = max(1, (int) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'verze_db'"));
            foreach (self::files() as $number => $file) {
                if ($number <= $version) {
                    continue;
                }
                foreach (self::statements((string) file_get_contents($file), $db->prefix) as $sql) {
                    try {
                        $db->pdo()->exec($sql);
                    } catch (\PDOException $e) {
                        // migrace přerušená uprostřed (výpadek, časový limit) se při dalším pokusu dokončí: změny, které už
                        // proběhly (tabulka, sloupec, index či cizí klíč existuje / chybí), se přeskočí místo chyby 500 napořád
                        if (!in_array((int) ($e->errorInfo[1] ?? 0), self::ALREADY_APPLIED, true)) {
                            throw $e;
                        }
                    }
                }
                $settings->set('verze_db', (string) $number);
                $applied[] = basename($file, '.sql');
            }
        } finally {
            $db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        return $applied;
    }

    /**
     * Rozdělí SQL skript na příkazy a nahradí předponu "ka_" předponou instalace.
     *
     * @return list<string>
     */
    public static function statements(string $sql, string $prefix): array
    {
        // názvy omezení musí být v databázi jedinečné - dostanou předponu také
        $sql = preg_replace('/\b((?:CONSTRAINT|DROP FOREIGN KEY)\s+)fk_/', '$1' . $prefix . 'fk_', $sql) ?? $sql;
        $sql = preg_replace('/\bka_(?=[a-z])/', $prefix, $sql) ?? $sql;
        // příkaz končí středníkem na konci řádku; za středníkem smí být už jen komentář
        $statements = preg_split('/;[ \t]*(--[^\n]*)?(\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map(trim(...), $statements), function (string $statement): bool {
            return trim((string) preg_replace('/^\s*--.*$/m', '', $statement)) !== '';
        }));
    }
}
