<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Stats: own measurement without cookies (visits, views, most-read news, traffic sources).
 */
final class Stats extends Module
{
    public const string IDENT = 'stats';
    public const string NAME = 'Statistika';
    public const string GROUP = 'Správa';
    public const string ICON = 'statistika';
    public const string EXTENSION = 'statistika';

    protected function actionList(): Response
    {
        $days = in_array($this->request->getInt('dni'), [7, 30, 90], true) ? $this->request->getInt('dni') : 30;
        $rows = $this->db->pairs('SELECT den, CONCAT(navstevy, ":", zobrazeni) FROM {stat_dny} WHERE den > CURDATE() - INTERVAL ? DAY', [$days]);
        $chart = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            [$n, $z] = array_map(intval(...), explode(':', $rows[$day] ?? '0:0'));
            $chart[$day] = ['navstevy' => $n, 'zobrazeni' => $z];
        }

        return $this->view('list', 'Statistika', [
            'days' => $days,
            'chart' => $chart,
            'isEnabled' => $this->app->settings()->bool('stats'),
            'newsItems' => $this->db->all(
                'SELECT c.idc, c.titulek, c.seo_link, SUM(s.pocet) AS pocet FROM {stat_novinky} s JOIN {novinky} c ON c.idc = s.idc
                 WHERE s.den > CURDATE() - INTERVAL ? DAY GROUP BY c.idc, c.titulek, c.seo_link ORDER BY pocet DESC LIMIT 15',
                [$days],
            ),
            'pages' => $this->db->all('SELECT cesta, SUM(pocet) AS pocet FROM {stat_stranky} WHERE den > CURDATE() - INTERVAL ? DAY GROUP BY cesta ORDER BY pocet DESC LIMIT 20', [$days]),
            'sources' => $this->db->all('SELECT zdroj, SUM(pocet) AS pocet FROM {stat_zdroje} WHERE den > CURDATE() - INTERVAL ? DAY GROUP BY zdroj ORDER BY pocet DESC LIMIT 15', [$days]),
        ]);
    }
}
