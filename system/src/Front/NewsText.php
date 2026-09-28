<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;

/**
 * Additions to the news item text: video embedded by URL, outline from subheadings, author bio and share links.
 * The finished HTML is inserted into the text, so site templates need not know anything about the additions; the look
 * is in image/web.css.
 */
final class NewsText
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string, mixed> $newsItem
     * @return array<string, mixed>
     */
    public function complete(array $newsItem): array
    {
        $newsItem['text'] = $this->withOutline($this->embedVideoUrls((string) $newsItem['text'])) . $this->authorHtml($newsItem) . $this->shareHtml($newsItem);

        return $newsItem;
    }

    /**
     * A paragraph containing only a video URL (YouTube, Vimeo) turns into a player on the site. Putting the URL on its own
     * line is enough.
     */
    public function embedVideoUrls(string $html): string
    {
        if (!preg_match('#youtu|vimeo\.com#i', $html)) {
            return $html;
        }

        return preg_replace_callback('#<p>\s*(?:<a\b[^>]*href="(https?://[^"]+)"[^>]*>[^<]*</a>|(https?://[^\s<]+))\s*(?:<br\s*/?>)?\s*</p>#i', function (array $m): string {
            $player = self::player(html_entity_decode($m[1] !== '' ? $m[1] : $m[2]), '', '', true);

            return $player !== '' && !str_contains($player, '<audio') && !str_contains($player, '<video') ? $player : $m[0];
        }, $html) ?? $html;
    }

    /**
     * Outline of a longer news item: from three H2 subheadings on, the headings get anchors and a table of contents is
     * inserted before the text. The anchors are useful on their own too - you can link to a specific part of the text.
     */
    public function withOutline(string $html): string
    {
        if (!$this->app->settings()->bool('article_outline') || substr_count($html, '<h2') < 3) {
            return $html;
        }
        $items = [];
        $used = [];
        $html = preg_replace_callback('#<h2\b([^>]*)>(.*?)</h2>#is', function (array $m) use (&$items, &$used): string {
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5));
            if ($text === '' || str_contains($m[1], ' id=')) {
                return $m[0];
            }
            $id = $base = slugify($text, 60);
            for ($i = 2; isset($used[$id]); $i++) {
                $id = $base . '-' . $i;
            }
            $used[$id] = true;
            $items[] = '<li><a href="#' . e($id) . '">' . e($text) . '</a></li>';

            return '<h2' . $m[1] . ' id="' . e($id) . '">' . $m[2] . '</h2>';
        }, $html) ?? $html;

        return count($items) < 3 ? $html
            : '<nav class="ka-osnova" aria-label="' . e(t('Obsah')) . '"><strong>' . e(t('Obsah')) . '</strong><ol>' . implode('', $items) . '</ol></nav>' . $html;
    }

    /**
     * Sharing a news item: plain links without third-party scripts; on a phone a system share button (image/web.js).
     *
     * @param array<string, mixed> $newsItem
     */
    public function shareHtml(array $newsItem): string
    {
        if (!$this->app->settings()->bool('share_buttons')) {
            return '';
        }
        $url = $this->app->request->origin() . $this->app->url('novinky/' . $newsItem['seo_link']);
        $u = rawurlencode($url);
        $t = rawurlencode((string) $newsItem['titulek']);
        $networks = [
            'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
            'X' => 'https://x.com/intent/post?url=' . $u . '&text=' . $t,
            'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
            'WhatsApp' => 'https://wa.me/?text=' . $t . '%20' . $u,
            'E-mail' => 'mailto:?subject=' . $t . '&body=' . $u,
        ];
        $html = '<aside class="ka-sdileni" aria-label="' . e(t('Sdílet')) . '"><span>' . e(t('Sdílet')) . '</span>'
            . '<button type="button" data-sdilet data-adresa="' . e($url) . '" data-titulek="' . e((string) $newsItem['titulek']) . '" hidden>' . e(t('Sdílet…')) . '</button>';
        foreach ($networks as $name => $link) {
            $html .= '<a href="' . e($link) . '"' . ($name === 'E-mail' ? '' : ' target="_blank" rel="noopener nofollow"') . '>' . e($name) . '</a>';
        }

        return $html . '<button type="button" data-kopirovat="' . e($url) . '" data-hotovo="' . e(t('Zkopírováno')) . '">' . e(t('Kopírovat odkaz')) . '</button></aside>';
    }

    /**
     * Author bio below the news item - only when the author has filled in a few sentences about themselves ("Můj účet", My account).
     *
     * @param array<string, mixed> $newsItem
     */
    public function authorHtml(array $newsItem): string
    {
        if (trim((string) ($newsItem['autor_bio'] ?? '')) === '' || ($newsItem['autor_jm'] ?? null) === null) {
            return '';
        }
        $photo = (string) $newsItem['autor_foto'];
        $photo = $photo === '' ? '' : (preg_match('#^(https?:)?/#i', $photo) ? $photo : $this->app->request->basePath() . '/' . $photo);

        return '<aside class="ka-autor" aria-label="' . e(t('O autorovi')) . '">'
            . ($photo !== '' ? '<img src="' . e($photo) . '" alt="" width="72" height="72" loading="lazy">' : '')
            . '<div><strong class="ka-autor-jmeno">' . e($newsItem['autor_jm']) . '</strong>'
            . ($newsItem['autor_pozice'] !== '' ? '<span>' . e($newsItem['autor_pozice']) . '</span>' : '')
            . '<p>' . nl2br(e(trim((string) $newsItem['autor_bio']))) . '</p></div></aside>';
    }

    /** Player by URL: file (audio/video), YouTube, Vimeo. Third-party players load only after a click. */
    public static function player(string $url, string $base, string $title, bool $onlyKnown = false): string
    {
        if ($url === '') {
            return '';
        }
        $mediaUrl = preg_match('#^(https?:)?/#i', $url) ? $url : $base . '/' . $url;
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($extension, ['mp3', 'm4a', 'ogg', 'oga', 'wav', 'aac'], true)) {
            return '<figure class="ka-medium ka-medium-zvuk"><audio controls preload="none" src="' . e($mediaUrl) . '"></audio></figure>';
        }
        if (in_array($extension, ['mp4', 'webm', 'm4v'], true)) {
            return '<figure class="ka-medium"><video controls preload="metadata" playsinline src="' . e($mediaUrl) . '"></video></figure>';
        }
        $embedUrl = match (true) {
            (bool) preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|live/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m) => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1',
            (bool) preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m) => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1',
            default => '',
        };
        if ($embedUrl === '' && $onlyKnown) {
            return '';
        }
        if ($embedUrl === '') {
            return '<div class="ka-medium-odkaz"><a class="ka-tl" href="' . e($mediaUrl) . '" rel="noopener">▶ ' . e(t('Přehrát')) . '</a></div>';
        }
        // a third-party player is embedded only after a click: until then nothing is sent to that service (privacy, speed)
        return '<figure class="ka-medium"><button type="button" class="ka-medium-spustit" data-vlozit="' . e($embedUrl) . '" data-titulek="' . e($title) . '">'
            . '<span aria-hidden="true">▶</span> ' . e(t('Přehrát video')) . '<small>' . e(t('Obsah se načte ze služby')) . ' ' . e((string) parse_url($embedUrl, PHP_URL_HOST)) . '</small></button></figure>';
    }
}
