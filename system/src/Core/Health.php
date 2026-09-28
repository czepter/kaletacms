<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Stav systému (health check): sada rychlých kontrol serveru, databáze, bezpečnosti a provozu.
 * Výsledek se zobrazuje v Nastavení a je dostupný i jako JSON pro monitoring (/stav.json?token=...).
 */
final class Health
{
    /**
     * @return list<array{skupina:string, nazev:string, stav:string, info:string}> stav: ok | varovani | chyba
     */
    public static function checks(App $app): array
    {
        $k = [];
        $add = function (string $group, string $name, bool|string $state, string $info) use (&$k): void {
            $k[] = ['skupina' => $group, 'nazev' => $name, 'stav' => is_bool($state) ? ($state ? 'ok' : 'chyba') : $state, 'info' => $info];
        };
        $db = $app->db();
        $siteSettings = $app->settings();

        // --- server (názvy a texty jdou přes t(); hodnoty "stav" se nepřekládají - čte je monitoring)
        $add(t('Server'), t('Verze PHP'), PHP_VERSION_ID >= 80400, PHP_VERSION_ID >= 80400 ? PHP_VERSION : t('%s - systém vyžaduje 8.4 nebo novější', PHP_VERSION));
        foreach (['pdo_mysql' => t('databáze'), 'mbstring' => t('text s diakritikou'), 'gd' => t('zpracování obrázků')] as $ext => $purpose) {
            $add(t('Server'), t('Rozšíření %s', $ext), extension_loaded($ext), extension_loaded($ext) ? $purpose : t('%s - chybí', $purpose));
        }
        foreach (['exif' => t('správné otočení fotek z mobilu'), 'intl' => t('řazení podle češtiny'), 'curl' => t('oznamování novinek vyhledávačům')] as $ext => $purpose) {
            $add(t('Server'), t('Rozšíření %s', $ext), extension_loaded($ext) ? 'ok' : 'varovani', extension_loaded($ext) ? $purpose : t('%s - doporučeno doinstalovat', $purpose));
        }
        $add(t('Server'), t('Limit nahrávaných souborů'), self::bytes((string) ini_get('upload_max_filesize')) >= 8 * 1024 * 1024 ? 'ok' : 'varovani', 'upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size'));
        $freeSpace = @disk_free_space(KALETA_ROOT);
        if ($freeSpace !== false) {
            $add(t('Server'), t('Volné místo na disku'), $freeSpace > 200 * 1024 * 1024 ? 'ok' : 'varovani', self::size((int) $freeSpace));
        }

        // --- databáze
        $add(t('Databáze'), t('Server'), 'ok', (string) $db->value('SELECT VERSION()'));
        $pending = Migration::latest() - max(1, $siteSettings->int('db_version'));
        $add(t('Databáze'), t('Struktura databáze'), $pending <= 0, $pending <= 0 ? t('aktuální (verze %d)', $siteSettings->int('db_version')) : t('čeká %d aktualizací - proběhnou při příštím načtení administrace', $pending));
        $size = (int) $db->value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', [addcslashes($db->prefix, '_%') . '%']);
        $add(t('Databáze'), t('Velikost'), 'ok', t('%s, novinek: %d', self::size($size), (int) $db->value('SELECT COUNT(*) FROM {novinky}')));

        // --- soubory a bezpečnost
        foreach (['media' => t('nahrané obrázky'), 'storage/log' => t('záznam chyb'), 'storage/cache' => t('dočasná data')] as $folder => $purpose) {
            $ok = is_dir(KALETA_ROOT . '/' . $folder) ? is_writable(KALETA_ROOT . '/' . $folder) : is_writable(KALETA_ROOT);
            $add(t('Soubory'), t('Zápis do %s/', $folder), $ok, $ok ? $purpose : t('%s - nastavte práva k zápisu', $purpose));
        }
        $add(t('Bezpečnost'), t('Instalátor'), !is_file(KALETA_ROOT . '/install.php') ? 'ok' : 'varovani', is_file(KALETA_ROOT . '/install.php') ? t('soubor install.php je stále na serveru - smažte ho') : t('install.php je odstraněn'));
        $add(t('Bezpečnost'), 'HTTPS', $app->request->isHttps() ? 'ok' : 'varovani', $app->request->isHttps() ? t('web běží na šifrovaném spojení') : t('web neběží na HTTPS - přihlašovací údaje putují nešifrovaně'));
        $add(t('Bezpečnost'), t('Ladicí režim'), !$app->debug(), $app->debug() ? t('v config.php je debug = true; na ostrém webu vypněte') : t('vypnutý'));
        $add(t('Bezpečnost'), t('Bezpečnostní hlavičky'), 'ok', t('systém odesílá X-Content-Type-Options, Referrer-Policy a X-Frame-Options; administrace navíc Content-Security-Policy a zákaz ukládání do mezipaměti'));
        $without2fa = (int) $db->value("SELECT COUNT(*) FROM {uzivatele} WHERE admin = 2 AND blokovat = 0 AND totp_tajemstvi = ''");
        $add(t('Bezpečnost'), t('Dvoufázové přihlášení administrátorů'), $without2fa === 0 ? 'ok' : 'varovani', $without2fa === 0 ? t('mají ho všichni administrátoři') : t('%d administrátor(ů) ho nemá - zapíná se v nabídce Můj účet (avatar vpravo nahoře)', $without2fa));
        $blockedCount = (int) $db->value('SELECT COUNT(*) FROM {uzivatele} WHERE blokovat = 1');
        $add(t('Bezpečnost'), t('Zablokované účty'), $blockedCount === 0 ? 'ok' : 'varovani', $blockedCount === 0 ? t('žádné') : t('%d - zablokoval je správce; odblokujete je v Uživatelích', $blockedCount));

        $core = Integrity::check();
        $add(t('Bezpečnost'), t('Soubory jádra'), $core['stav'], $core['info']);

        // --- provoz
        $log = KALETA_ROOT . '/storage/log/chyby.log';
        $errorCount = 0;
        if (is_file($log)) {
            $from = date('c', time() - 86400);
            foreach (array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -500) as $row) {
                $errorCount += (int) (substr($row, 1, 25) >= $from);
            }
        }
        $add(t('Provoz'), t('Chyby za posledních 24 hodin'), $errorCount === 0 ? 'ok' : 'varovani', $errorCount === 0 ? t('žádné') : t('%d - podrobnosti v storage/log/chyby.log', $errorCount));
        $last = Backup::listAll()[0]['cas'] ?? 0;
        $age = $last > 0 ? (int) floor((time() - $last) / 86400) : null;
        $add(t('Provoz'), t('Záloha databáze'), $age !== null && $age <= 8 ? 'ok' : 'varovani', $age === null ? t('zatím žádná - vytvořte ji v záložce Zálohy a aktualizace') : ($age === 0 ? t('dnes') : t('před %d dny', $age)) . ', ' . ($siteSettings->bool('auto_backups') ? t('automatické zálohy zapnuté') : t('automatické zálohy vypnuté')));
        $add(t('Provoz'), t('Indexování vyhledávači'), $siteSettings->bool('indexing') ? 'ok' : 'varovani', $siteSettings->bool('indexing') ? t('povoleno') : t('zakázáno v záložce SEO a GEO - web se neobjeví ve vyhledávání'));
        $media = 0;
        if (is_dir(KALETA_ROOT . '/media')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS)) as $file) {
                $media += $file->getSize();
            }
        }
        $add(t('Provoz'), t('Velikost médií'), 'ok', self::size($media));
        $remote = explode('|', $app->settings()->get('remote_backup_status'), 2);
        if ($app->settings()->get('remote_backup') !== 'vypnuto') {
            $add(t('Provoz'), t('Zálohy mimo server'), ($remote[1] ?? '') === 'ok' ? 'ok' : 'varovani', ($remote[1] ?? '') === 'ok' ? t('poslední kopie nahrána %s', $remote[0]) : (($remote[1] ?? '') !== '' ? t('poslední pokus %s selhal: %s', $remote[0], $remote[1]) : t('zatím žádná kopie nevznikla')));
        } else {
            $add(t('Provoz'), t('Zálohy mimo server'), 'varovani', t('vypnuté – zálohy leží jen na stejném serveru jako web (Nastavení → Zálohy a aktualizace)'));
        }
        $smtp = $app->settings()->get('mail_mode') === 'smtp' && $app->settings()->get('smtp_host') !== '';
        // úlohy na pozadí (naplánované novinky, fronta pošty, push, newsletter) spouští návštěvy webu nebo cron
        $lastRun = $siteSettings->int('notification_check');
        $before = $lastRun > 0 ? (int) floor((time() - $lastRun) / 60) : null;
        $add(t('Provoz'), t('Úlohy na pozadí'), $before !== null && $before <= 30 ? 'ok' : 'varovani', $before === null
            ? t('zatím neproběhly – spustí je první návštěva webu')
            : ($before <= 30 ? t('naposledy před %d min', $before) : ($before < 120 ? t('naposledy před %d min', $before) : t('naposledy před %d h', (int) round($before / 60)))
                . ' – ' . t('na webu s malou návštěvností nastavte cron, adresu najdete níže na této stránce')));
        $update = (new Updater($siteSettings))->state();
        $add(t('Provoz'), t('Aktualizace'), !$update['nastaveno'] || $update['chyba'] !== null || $update['nova'] !== null ? 'varovani' : 'ok', match (true) {
            !$update['nastaveno'] => t('zdroj aktualizací není nastaven'),
            $update['chyba'] !== null => t('zdroj aktualizací neodpovídá: %s', (string) $update['chyba']),
            $update['nova'] !== null => t('je k dispozici verze %s (Nastavení → Zálohy a aktualizace)', (string) $update['nova']['verze']),
            default => t('systém je aktuální (%s)', KALETA_VERSION) . ($update['overeno'] > 0 ? ', ' . t('ověřeno %s', format_date((new \DateTimeImmutable())->setTimestamp((int) $update['overeno']), true)) : ''),
        });
        $add(t('Provoz'), t('Odesílání pošty'), $smtp || function_exists('mail') ? 'ok' : 'varovani', $smtp ? t('přes SMTP server %s', $app->settings()->get('smtp_host')) : (function_exists('mail') ? t('funkcí mail() serveru – spolehlivější je SMTP (Nastavení → Pošta)') : t('funkce mail() je vypnutá – nastavte SMTP (Nastavení → Pošta)')));

        return $k;
    }

    /** Souhrn pro monitoring: nejhorší nalezený stav. */
    public static function summary(array $checks): string
    {
        $statuses = array_column($checks, 'stav');

        return in_array('chyba', $statuses, true) ? 'chyba' : (in_array('varovani', $statuses, true) ? 'varovani' : 'ok');
    }

    private static function size(int $byteCount): string
    {
        foreach (['B', 'kB', 'MB', 'GB', 'TB'] as $unit) {
            if ($byteCount < 1024 || $unit === 'TB') {
                return format_number($byteCount, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $byteCount /= 1024;
        }

        return '';
    }

    private static function bytes(string $ini): int
    {
        $number = (int) $ini;

        return match (strtoupper(substr(trim($ini), -1))) {
            'G' => $number * 1024 ** 3, 'M' => $number * 1024 ** 2, 'K' => $number * 1024, default => $number,
        };
    }
}
