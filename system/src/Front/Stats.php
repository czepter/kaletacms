<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;

/**
 * Vlastní měření návštěvnosti bez cookies.
 * Návštěvník = otisk (IP + prohlížeč + sůl platná jeden den); samotná IP se nikam neukládá
 * a otisky starší než dva dny se mažou, takže čtenáře nejde sledovat v čase.
 */
final class Stats
{
    private const string BOTS = '/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|java\/|http|scan|check|feed|lighthouse|headless/i';

    public static function record(App $app, ?int $idc): void
    {
        $server = $_SERVER;
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (!\Kaleta\Core\Extensions::isEnabled($app->settings(), 'statistika') || !$app->settings()->bool('statistika') || $ua === '' || preg_match(self::BOTS, $ua) || $app->request->get('nahled') !== '') {
            return;
        }
        $db = $app->db();
        $today = date('Y-m-d');
        $salt = (new Antispam($db, $app->settings()))->key() . $today;
        $hash = substr(hash('sha256', $salt . '|' . $app->request->ip() . '|' . $ua), 0, 32);

        $new = $db->run('INSERT IGNORE INTO {stat_navstevnici} (den, otisk) VALUES (?, ?)', [$today, $hash])->rowCount() === 1;
        $db->run('INSERT INTO {stat_dny} (den, navstevy, zobrazeni) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE navstevy = navstevy + VALUES(navstevy), zobrazeni = zobrazeni + 1', [$today, (int) $new]);
        if ($idc !== null) {
            $db->run('INSERT INTO {stat_novinky} (den, idc, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, $idc]);
        }
        // zobrazení po adresách (i s jazykovou verzí) – nejčtenější stránky v administraci
        $path = mb_substr((string) parse_url($app->url(ltrim($app->request->path(), '/')), PHP_URL_PATH), 0, 255);
        $db->run('INSERT INTO {stat_stranky} (den, cesta, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, $path]);
        $source = strtolower((string) parse_url((string) ($server['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
        $source = preg_replace('/^www\./', '', $source) ?? '';
        // vlastní web je nastavená adresa webu, ne hlavička Host – tu si může klient napsat, jak chce
        $custom = preg_replace('/^www\./', '', strtolower((string) parse_url($app->request->origin(), PHP_URL_HOST))) ?? '';
        if ($new && $source !== '' && $source !== $custom) {
            $db->run('INSERT INTO {stat_zdroje} (den, zdroj, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, mb_substr($source, 0, 100)]);
        }
        if (random_int(1, 200) === 1) {
            $db->run('DELETE FROM {stat_navstevnici} WHERE den < CURDATE() - INTERVAL 1 DAY');
            $db->run('DELETE FROM {stat_stranky} WHERE den < CURDATE() - INTERVAL 400 DAY');
        }
    }
}
