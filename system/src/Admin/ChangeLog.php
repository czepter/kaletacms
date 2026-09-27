<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;

/**
 * Protokol změn: kdo, kdy a co v administraci udělal. Záznamy starší než půl roku se mažou.
 */
final class ChangeLog
{
    public static function write(App $app, string $module, string $action, string $description = ''): void
    {
        try {
            $user = $app->auth()->user();
            $app->db()->insert('protokol', [
                'cas' => date('Y-m-d H:i:s'), 'kdo' => $user['idu'] ?? null, 'jmeno' => (string) ($user['jmeno'] ?? '') ?: (string) ($user['user'] ?? ''),
                'modul' => mb_substr($module, 0, 30), 'akce' => mb_substr($action, 0, 40), 'popis' => mb_substr($description, 0, 255),
            ]);
            if (random_int(1, 100) === 1) {
                $app->db()->run('DELETE FROM {protokol} WHERE cas < NOW() - INTERVAL 180 DAY');
            }
        } catch (\Throwable) {
            // protokol nesmí rozbít akci, kterou zaznamenává (např. před provedením migrace tabulka ještě neexistuje)
        }
    }
}
