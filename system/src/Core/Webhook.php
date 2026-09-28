<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Webhooky: po vydání novinky a po nové poptávce pošle údaje na adresy z Nastavení (Make, Zapier, IFTTT, n8n, CRM…).
 * Přes takovou službu jde novinku automaticky sdílet na sítě a poptávku založit v CRM nebo poslat do Slacku.
 */
final class Webhook
{
    /** Nová poptávka z formuláře webu → adresa z Nastavení (CRM, Make, Zapier, n8n, Slack…). */
    public static function enquiryReceived(App $app, int $idp, string $form, array $data, string $email, string $page, string $campaign = ''): void
    {
        $url = $app->settings()->get('webhook_enquiries');
        if (!preg_match('#^https://#i', $url)) {
            return;
        }
        self::deliver($url, [
            'udalost' => 'nova_poptavka', 'web' => $app->settings()->get('site_name'), 'id' => $idp, 'formular' => $form, 'email' => $email,
            'stranka' => $app->request->origin() . $page, 'prijato' => date('c'),
            'pole' => array_map(fn (array $d): array => ['popisek' => $d[0], 'hodnota' => $d[1]], $data),
        ] + ($campaign !== '' ? ['utm' => self::utm($campaign)] : []));
    }

    /** @return array<string, string> parametry utm_* bez předpony: source, medium, campaign, term, content */
    private static function utm(string $campaign): array
    {
        parse_str($campaign, $utm);
        $result = [];
        foreach ($utm as $k => $h) {
            if (is_string($k) && is_string($h) && str_starts_with($k, 'utm_')) {
                $result[substr($k, 4)] = $h;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private static function deliver(string $url, array $data): void
    {
        @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 4, 'ignore_errors' => true,
            'header' => "Content-Type: application/json; charset=utf-8\r\nUser-Agent: Kaleta/" . KALETA_VERSION . "\r\n",
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]]));
    }

    public static function articlePublished(App $app, int $idc): void
    {
        $url = $app->settings()->get('webhook_url');
        if (!preg_match('#^https://#i', $url)) {
            return;
        }
        $c = $app->db()->one(
            'SELECT c.*, t.nazev AS kategorie FROM {novinky} c JOIN {kategorie} t ON t.idt = c.tema WHERE c.idc = ? AND c.visible = 1 AND c.datum <= NOW() AND c.noindex = 0',
            [$idc],
        );
        if ($c === null) {
            return; // koncept nebo novinka naplánovaná do budoucna
        }
        $root = $app->request->origin() . $app->request->basePath() . '/'; // soubory jsou společné všem jazykům
        $data = [
            'udalost' => 'novinka_vydana', 'web' => $app->settings()->get('site_name'), 'titulek' => $c['titulek'],
            'adresa' => $app->request->origin() . $app->newsItemUrl($c['seo_link'], $c['jazyk']), 'perex' => trim(strip_tags($c['uvod'])), 'kategorie' => $c['kategorie'],
            'obrazek' => $c['obrazek'] === '' ? '' : (preg_match('#^https?://#i', $c['obrazek']) ? $c['obrazek'] : rtrim($root, '/') . '/' . ltrim($c['obrazek'], '/')),
            'stitky' => array_column($app->db()->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$idc]), 'nazev'),
            'vydano' => date('c', strtotime($c['datum'])),
        ];
        self::deliver($url, $data);
    }
}
