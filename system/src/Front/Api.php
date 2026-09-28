<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\Response;

/**
 * Public read-only API (JSON). Returns only what is visible on the site too: published news, categories and shown pages.
 *
 *   GET /api/novinky?strana=1&kategorie=<slug>     list of news
 *   GET /api/novinky/<slug>                        full news item
 *   GET /api/kategorie                             news categories
 *   GET /api/stranky                               site pages (without content)
 *
 * Deprecated since 1.8 (the Deprecation header, RFC 9745) and removed in 2.0: the JSON Feed (/feed.json) covers the news
 * and the MCP connection covers building and editing the site.
 */
final class Api
{
    /** 28 September 2026 – Kaleta 1.8 (the Deprecation header carries it as @<Unix time>). */
    public const int DEPRECATED_AT = 1790553600;

    public function __construct(private readonly App $app, private readonly NewsRepository $news)
    {
    }

    public function handle(string $path): Response
    {
        $root = $this->app->request->origin() . $this->app->url('');
        $summary = fn (array $c): array => [
            'id' => (int) $c['idc'], 'titulek' => $c['titulek'], 'adresa' => $root . 'novinky/' . $c['seo_link'], 'api' => $root . 'api/novinky/' . $c['seo_link'],
            'perex' => trim(strip_tags($c['uvod'])), 'obrazek' => $c['obrazek'] === '' ? null : (preg_match('#^https?://#i', $c['obrazek']) ? $c['obrazek'] : $this->app->request->origin() . $c['obrazek']),
            'kategorie' => ['nazev' => $c['tema_jm'], 'adresa' => $c['tema_seo']], 'autor' => $c['autor_jm'], 'vydano' => date('c', strtotime($c['datum'])),
        ];

        if ((str_starts_with($path, '/api/novinky') || $path === '/api/kategorie') && !\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky')) {
            return $this->json(['chyba' => t('News is switched off on this website.')], 404);
        }
        if ($path === '/api/novinky') {
            $pageNumber = max(1, $this->app->request->getInt('strana', 1));
            $category = $this->app->request->get('kategorie');
            $idt = $category === '' ? null : $this->app->db()->value('SELECT idt FROM {kategorie} WHERE seo_link = ?', [$category]);
            if ($category !== '' && $idt === null) {
                return $this->json(['chyba' => t('The category does not exist.')], 404);
            }
            [$news, $total] = $idt === null ? $this->news->listPublished($pageNumber) : $this->news->inCategory((int) $idt, $pageNumber);

            return $this->json(['celkem' => $total, 'strana' => $pageNumber, 'na_stranku' => $this->news->perPage(), 'novinky' => array_map($summary, $news)]);
        }
        if (preg_match('#^/api/novinky/([a-z0-9-]+)$#', $path, $m)) {
            $c = $this->news->bySlug($m[1]);

            return $c === null ? $this->json(['chyba' => t('News item does not exist.')], 404) : $this->json($summary($c) + [
                'uvod_html' => $c['uvod'], 'text_html' => $c['text'], 'aktualizovano' => $c['aktualizovano'] ? date('c', strtotime($c['aktualizovano'])) : null,
                'stitky' => array_column($this->app->db()->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
            ]);
        }
        if ($path === '/api/kategorie') {
            return $this->json(array_map(
                fn (array $r): array => ['nazev' => $r['nazev'], 'adresa' => $r['seo_link'], 'novinek' => (int) $r['pocet_clanku']],
                \Kaleta\Admin\Modules\Categories::listAll($this->app->db(), Language::siteColumn()),
            ));
        }
        if ($path === '/api/stranky') {
            return $this->json(array_map(
                fn (array $s): array => ['titulek' => $s['titulek'], 'adresa' => $root . $s['seo_link'], 'popis' => $s['popis']],
                $this->app->db()->all('SELECT titulek, seo_link, popis FROM {stranky} WHERE zobrazit = 1 AND jazyk = ? ORDER BY poradi, titulek', [Language::siteColumn()]),
            ));
        }

        return $this->json(['chyba' => t('Unknown API address.')], 404);
    }

    private function json(mixed $data, int $status = 200): Response
    {
        return new Response((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, [
            'Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=60',
            'Deprecation' => '@' . self::DEPRECATED_AT, 'Link' => '<' . $this->app->request->origin() . $this->app->url('feed.json') . '>; rel="alternate"; type="application/feed+json"',
        ]);
    }
}
