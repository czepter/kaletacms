<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Oznámení o vydání novinky: webhook a IndexNow. Volá se hned po vydání v administraci a také po návštěvách
 * webu (nejvýše jednou za minutu) - díky tomu se oznámí i novinky naplánované do budoucna a novinky vydané
 * přes Claude (MCP), jakmile jejich čas nastane. Na pozadí se přitom odešle i fronta pošty a zkontrolují odkazy.
 */
final class Notifications
{
    /** Po odeslání stránky návštěvníkovi (index.php). */
    public static function runInBackground(App $app): void
    {
        $s = $app->settings();
        if (time() - $s->int('notification_check') < 60) {
            return;
        }
        $s->set('notification_check', (string) time());
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            self::process($app);
            Mail::processQueue($s);
            Newsletter::processQueue($app);
            Links::runInBackground($app);
            self::purgePersonalData($app);
        } catch (\Throwable) {
            // oznámení nesmí shodit web; další pokus proběhne při příští návštěvě
        }
    }

    /**
     * Jednou denně: poptávky po nastavené době uchování a nepotvrzené přihlášky k odběru starší 30 dní (GDPR – bez souhlasu
     * se adresa nedrží). Běží bez ohledu na to, jestli je rozšíření zapnuté: data z doby, kdy zapnuté bylo, se mažou také.
     */
    public static function purgePersonalData(App $app, bool $immediately = false): void
    {
        $s = $app->settings();
        if (!$immediately && time() - $s->int('data_cleanup') < 86400) {
            return;
        }
        $s->set('data_cleanup', (string) time());
        \Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $s);
        if ($s->int('cookies_log_months') > 0) {
            // záznamy o souhlasech s cookies nemají ležet věčně
            $app->db()->run('DELETE FROM {souhlasy} WHERE cas < NOW() - INTERVAL ? MONTH', [$s->int('cookies_log_months')]);
        }
        $app->db()->run('DELETE FROM {odberatele} WHERE stav = 0 AND datum < NOW() - INTERVAL 30 DAY');
    }

    /** Oznámí všechny vydané a dosud neoznámené novinky (nejvýš 2 dny staré, aby se po výpadku nerozeslal archiv). */
    public static function process(App $app): void
    {
        $db = $app->db();
        // naplánované stránky: skrytá stránka se v zadaný čas sama zveřejní
        if ($db->run('UPDATE {stranky} SET zobrazit = 1, zverejnit_od = NULL WHERE zverejnit_od IS NOT NULL AND zverejnit_od <= NOW() AND smazano IS NULL')->rowCount() > 0) {
            \Kaleta\Front\Cache::clear();
        }
        $newsItems = $db->all('SELECT idc, seo_link, jazyk, noindex FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND oznameno IS NULL ORDER BY datum LIMIT 5');
        foreach ($newsItems as $c) {
            // nejdřív označit: kdyby oznámení spadlo, nesmí se opakovat donekonečna
            if ($db->run('UPDATE {novinky} SET oznameno = NOW() WHERE idc = ? AND oznameno IS NULL', [$c['idc']])->rowCount() === 0) {
                continue;
            }
            \Kaleta\Front\Cache::clear(); // naplánovaná novinka právě vyšla - výpis z cache ji ještě nezná
            if ($c['noindex'] || (int) $db->value('SELECT datum < NOW() - INTERVAL 2 DAY FROM {novinky} WHERE idc = ?', [$c['idc']]) === 1) {
                continue;
            }
            Webhook::articlePublished($app, (int) $c['idc']);
            (new \Kaleta\Front\Seo($app))->indexNow($app->newsItemUrl($c['seo_link'], $c['jazyk']));
        }
    }
}
