<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Převod obsahu příspěvku z WordPressu na HTML, jaké píše editor novinek Kalety. Jen převádí text – nic nečte ani nezapisuje.
 *
 * Pořadí kroků: bloky Gutenbergu → zkratky v hranatých závorkách ([caption], [gallery]…) → vložená videa → odstavce
 * (starý „klasický“ editor je odděluje jen prázdným řádkem) → propuštění jen povolených značek a atributů.
 * Obsah exportu se bere jako nedůvěryhodný: co není na seznamu POVOLENE, do článku se nedostane (skripty, rámce, styly, onclick…).
 */
final class WpContent
{
    /** Stejná podmnožina HTML, jakou propouští editor článků při vkládání (image/editor.js, POVOLENE) – jen bez IFRAME. */
    private const array ALLOWED = [
        'p' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'mark' => [], 'br' => [], 'hr' => [],
        'a' => ['href', 'title', 'target'], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'code' => [], 'pre' => [],
        'figure' => ['class'], 'figcaption' => [], 'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
    ];

    /** Značky, které editor nezná, ale mají blízkou náhradu. */
    private const array TAG_REPLACEMENTS = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    /** Tyhle značky mizí i s obsahem (u ostatních nepovolených zůstává aspoň jejich text). */
    private const array DISCARD = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'noscript', 'svg', 'math', 'template', 'head', 'title', 'meta', 'link', 'base',
        'input', 'button', 'select', 'textarea', 'video', 'audio', 'canvas', 'frame', 'frameset'];

    /** Značky, které tvoří vlastní odstavec – kolem nich se <p> nedělá. */
    private const string BLOCK_TAGS = 'table|thead|tfoot|tbody|tr|td|th|caption|div|dl|dd|dt|ul|ol|li|pre|blockquote|figure|figcaption|h[1-6]|hr|p|address|section|article|aside|header|footer|nav|details|summary';

    /** Zkratky, které převádíme sami, a proto na ně náhled neupozorňuje. */
    private const array KNOWN_SHORTCODES = ['caption', 'wp_caption', 'gallery', 'embed'];

    /** Zkratky WordPressu bez atributů a párové značky, které by jinak vypadaly jako běžný text v závorce. */
    private const array ALWAYS_SHORTCODES = ['audio', 'video', 'playlist', 'more', 'toc', 'contact-form-7', 'contact-form', 'sitemap', 'products', 'recent_posts'];

    /**
     * @param array<int|string, string> $attachments čísla příloh WordPressu => adresa souboru (pro [gallery ids="…"])
     */
    public static function sanitize(string $content, array $attachments = []): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $content);
        $blockEditor = str_contains($html, '<!-- wp:'); // Gutenberg má odstavce hotové, klasický editor ne
        $html = self::blocks($html);
        $html = self::outsideCode($html, fn (string $part): string => self::shortcodesToHtml($part, $attachments));
        $html = self::embeddedVideos($html);
        if (!$blockEditor) {
            $html = self::paragraphs($html);
        }

        return self::allowedHtml($html);
    }

    /** Text pro builder (prvek Text, odpovědi FAQ…): stejný povolovací seznam značek jako editor, bez převodů z WordPressu. */
    public static function safeHtml(string $html): string
    {
        return self::allowedHtml($html);
    }

    /**
     * Perex a text článku. Perex je ruční výtah z WordPressu; když chybí, vezme se část před značkou „Číst dál“ (<!--more-->),
     * a když není ani ta, první odstavec textu – ten se pak v textu neopakuje.
     *
     * @param array<int|string, string> $attachments
     * @return array{0:string, 1:string} [perex, text]
     */
    public static function introAndText(string $intro, string $content, array $attachments = []): array
    {
        $intro = trim(html_entity_decode(strip_tags($intro), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($intro !== '') {
            return ['<p>' . nl2br(e($intro), false) . '</p>', self::sanitize($content, $attachments)];
        }
        $parts = preg_split('/<!--\s*more\b.*?-->/s', $content, 2) ?: [$content];
        if (count($parts) === 2 && trim(strip_tags($parts[0])) !== '') {
            return [self::sanitize($parts[0], $attachments), self::sanitize($parts[1], $attachments)];
        }
        $text = self::sanitize($content, $attachments);
        // první odstavec, ve kterém je skutečný text
        if (preg_match('#<p>((?:(?!</?p>).)*?\p{L}(?:(?!</?p>).)*?)</p>\s*#su', $text, $m, PREG_OFFSET_CAPTURE)) {
            return ['<p>' . $m[1][0] . '</p>', trim(substr($text, 0, $m[0][1]) . substr($text, $m[0][1] + strlen($m[0][0])))];
        }

        return ['', $text];
    }

    /**
     * Zkratky doplňků (plug-inů), které v obsahu jsou a které import neumí převést – pro varování v náhledu.
     *
     * @return list<string> názvy zkratek
     */
    public static function unknownShortcodes(string $content): array
    {
        $found = [];
        $content = (string) preg_replace('#<pre\b.*?</pre>|<code\b.*?</code>#is', '', $content); // v ukázkách kódu jsou závorky běžný text
        preg_match_all('#\[/([a-zA-Z][\w-]*)\]#', $content, $paired);
        if (preg_match_all('#(?<!\[)\[([a-zA-Z][\w-]*)((?:\s[^\]]*)?)/?\]#', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $z) {
                if (!in_array(strtolower($z[1]), self::KNOWN_SHORTCODES, true) && self::isShortcode($z[1], $z[2], $paired[1])) {
                    $found[strtolower($z[1])] = true;
                }
            }
        }

        return array_keys($found);
    }

    /* ---------- bloky Gutenbergu ---------- */

    private static function blocks(string $html): string
    {
        // fotogalerie: z bloku zůstanou jen obrázky, složené do naší galerie
        $html = preg_replace_callback('#<!--\s*wp:gallery\b.*?-->(.*?)<!--\s*/wp:gallery\s*-->#s', function (array $m): string {
            preg_match_all('#<img\b[^>]*>#i', $m[1], $images);

            return self::gallery($images[0]);
        }, $html) ?? $html;
        // vložené video či příspěvek: adresa je v nastavení bloku; u nás stačí adresa na samostatném řádku (Front\TextNovinky)
        $html = preg_replace_callback('#<!--\s*wp:(?:core-embed/[\w-]+|embed)\s+(\{.*?\})\s*-->.*?<!--\s*/wp:(?:core-embed/[\w-]+|embed)\s*-->#s', function (array $m): string {
            $url = (string) (json_decode($m[1], true)['url'] ?? '');

            return preg_match('#^https?://[^\s<>"]+$#i', $url) ? "\n<p>" . e($url) . "</p>\n" : '';
        }, $html) ?? $html;

        // ostatní komentáře (hranice bloků, <!--more-->, <!--nextpage-->) už nic neznamenají
        return preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    }

    /** @param list<string> $imgTags hotové značky <img …> */
    private static function gallery(array $imgTags): string
    {
        return $imgTags === [] ? '' : "\n" . '<figure class="galerie">' . implode('', $imgTags) . '</figure>' . "\n";
    }

    /* ---------- zkratky v hranatých závorkách ---------- */

    /** @param array<int|string, string> $attachments */
    private static function shortcodesToHtml(string $html, array $attachments): string
    {
        // [caption]<img> Popisek[/caption] → obrázek s popiskem
        $html = preg_replace_callback('#\[(?:wp_)?caption\b([^\]]*)\](.*?)\[/(?:wp_)?caption\]#s', function (array $m): string {
            if (!preg_match('#<img\b[^>]*>#i', $m[2], $img)) {
                return $m[2];
            }
            $labelText = preg_match('#\bcaption="([^"]*)"#', $m[1], $a) ? $a[1] : trim((string) preg_replace('#<a\b[^>]*>\s*</a>#i', '', str_replace($img[0], '', $m[2])));

            return "\n\n<figure>" . $img[0] . ($labelText !== '' ? '<figcaption>' . $labelText . '</figcaption>' : '') . "</figure>\n\n";
        }, $html) ?? $html;

        // [gallery ids="1,2,3"] → naše galerie; obrázky se dohledají mezi přílohami exportu
        $html = preg_replace_callback('#\[gallery\b([^\]]*)\]#', function (array $m) use ($attachments): string {
            $images = [];
            foreach (preg_match('#\bids="([\d,\s]+)"#', $m[1], $a) ? explode(',', $a[1]) : [] as $id) {
                $url = $attachments[(int) $id] ?? '';
                if (preg_match('#\.(jpe?g|png|gif|webp)$#i', (string) parse_url($url, PHP_URL_PATH))) {
                    $images[] = '<img src="' . e($url) . '" alt="">';
                }
            }

            return "\n\n" . self::gallery($images) . "\n\n";
        }, $html) ?? $html;

        // [embed]adresa[/embed] → adresa na samostatném řádku
        $html = preg_replace('#\[embed[^\]]*\]\s*(https?://[^\s\[]+)\s*\[/embed\]#i', "\n\n$1\n\n", $html) ?? $html;

        // zbylé zkratky doplňků: značka zmizí, text uvnitř zůstává
        preg_match_all('#\[/([a-zA-Z][\w-]*)\]#', $html, $paired);

        return preg_replace_callback('#(?<!\[)\[(/?)([a-zA-Z][\w-]*)((?:\s[^\]]*)?)/?\]#', function (array $z) use ($paired): string {
            return $z[1] === '/' || self::isShortcode($z[2], $z[3], $paired[1]) ? '' : $z[0];
        }, $html) ?? $html;
    }

    /**
     * Pozná zkratku od běžného textu v závorce ([sic], [1]): zkratka má atributy, uzavírací značku, podtržítko či pomlčku v názvu,
     * nebo je na seznamu známých.
     *
     * @param list<string> $paired názvy, ke kterým v textu existuje uzavírací [/název]
     */
    private static function isShortcode(string $name, string $attributes, array $paired): bool
    {
        return str_contains($attributes, '=') || in_array($name, $paired, true) || preg_match('/[_-]/', $name) === 1
            || in_array(strtolower($name), self::ALWAYS_SHORTCODES, true) || in_array(strtolower($name), self::KNOWN_SHORTCODES, true);
    }

    /** Přehrávače vložené jako <iframe> se mění na adresu videa na samostatném řádku; ostatní rámce později zmizí. */
    private static function embeddedVideos(string $html): string
    {
        return preg_replace_callback('#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>.*?</iframe>#is', function (array $m): string {
            $url = match (true) {
                (bool) preg_match('#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})#', $m[1], $v) => 'https://www.youtube.com/watch?v=' . $v[1],
                (bool) preg_match('#player\.vimeo\.com/video/(\d+)#', $m[1], $v) => 'https://vimeo.com/' . $v[1],
                (bool) preg_match('#open\.spotify\.com/embed/(episode|show|track)/([A-Za-z0-9]+)#', $m[1], $v) => 'https://open.spotify.com/' . $v[1] . '/' . $v[2],
                default => '',
            };

            return $url === '' ? '' : "\n\n<p>" . $url . "</p>\n\n";
        }, $html) ?? $html;
    }

    /* ---------- odstavce klasického editoru ---------- */

    /**
     * Klasický editor WordPressu ukládá odstavce jen jako text oddělený prázdným řádkem; značky <p> doplňuje až při zobrazení.
     * Tady se doplní natrvalo: prázdný řádek = nový odstavec, jednoduchý konec řádku = <br>. Blokové značky se neobalují.
     */
    public static function paragraphs(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        // <pre> se nesmí dotknout nic – schová se a na konci vrátí
        $hidden = [];
        $text = preg_replace_callback('#<pre\b.*?</pre>#is', function (array $m) use (&$hidden): string {
            $key = "\x02PRE" . count($hidden) . "\x03";
            $hidden[$key] = $m[0];

            return "\n\n" . $key . "\n\n";
        }, $text) ?? $text;
        // adresa sama na řádku (video z YouTube…) má být vlastním odstavcem, aby ji web poznal
        $text = preg_replace('#^[ \t]*(https?://[^\s<>"]+)[ \t]*$#m', "\n\n$1\n\n", $text) ?? $text;
        $text = preg_replace('#<br\s*/?>\s*<br\s*/?>#i', "\n\n", $text) ?? $text;
        $text = preg_replace('#(<(?:' . self::BLOCK_TAGS . ')(?:\s[^>]*)?/?>)#i', "\n\n$1", $text) ?? $text;
        $text = preg_replace('#(</(?:' . self::BLOCK_TAGS . ')>)#i', "$1\n\n", $text) ?? $text;

        $html = '';
        foreach (preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || isset($hidden[$part]) || preg_match('#^</?(?:' . self::BLOCK_TAGS . ')\b#i', $part)) {
                $html .= $part . "\n";
            } elseif (preg_match('#^(.*?)((?:</(?:' . self::BLOCK_TAGS . ')>\s*)+)$#is', $part, $m)) {
                $html .= '<p>' . self::lineBreaks($m[1]) . '</p>' . $m[2] . "\n"; // text těsně před koncem bloku: „…text</div>“
            } else {
                $html .= '<p>' . self::lineBreaks($part) . "</p>\n";
            }
        }

        return strtr($html, $hidden);
    }

    private static function lineBreaks(string $text): string
    {
        return (string) preg_replace('#(?<!<br>)\n#', "<br>\n", trim((string) preg_replace('#<br\s*/?>[ \t]*\n?#i', "<br>\n", $text)));
    }

    /* ---------- jen povolené značky ---------- */

    /** Propustí jen značky a atributy ze seznamu POVOLENE; HTML čte skutečný analyzátor HTML5, ne regulární výrazy. */
    private static function allowedHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if ($body === null) {
            return '';
        }
        self::sanitizeNode($doc, $body);
        self::normalizeRoot($doc, $body);
        $output = '';
        foreach ($body->childNodes as $n) {
            $output .= $doc->saveHtml($n) . ($n instanceof \Dom\Element ? "\n" : '');
        }
        $output = str_replace(['&nbsp;', "\u{00A0}"], ' ', $output);

        // úklid mimo ukázky kódu: zalomení na kraji odstavce a prázdné řádky nic neznamenají
        return trim(self::outsideCode($output, fn (string $part): string => (string) preg_replace(['#<p>(?:\s*<br>)+\s*#', '#(?:\s*<br>)+\s*</p>#', "/\n{2,}/"], ['<p>', '</p>', "\n"], $part)));
    }

    private static function sanitizeNode(\Dom\HTMLDocument $doc, \Dom\Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $n) {
            if (!$n instanceof \Dom\Element) {
                // komentáře a instrukce pryč; stejně tak prázdné řádky mezi obrázkem a popiskem
                if (!$n instanceof \Dom\Text || ($node instanceof \Dom\Element && $node->localName === 'figure' && trim($n->data) === '')) {
                    $n->parentNode?->removeChild($n);
                }
                continue;
            }
            $tag = strtolower($n->localName);
            if (in_array($tag, self::DISCARD, true)) {
                $n->remove();
                continue;
            }
            self::sanitizeNode($doc, $n);
            if ($tag === 'a' && self::isLinkToOwnImage($n)) {
                self::extract($n); // náhled odkazující na velký obrázek: prohlížečku fotek má web vlastní
                continue;
            }
            if ($tag === 'div' || isset(self::TAG_REPLACEMENTS[$tag])) {
                // <div> s bloky uvnitř jen zmizí, <div> s textem je odstavec
                $new = $tag === 'div' ? (self::hasBlockChild($n) ? null : 'p') : self::TAG_REPLACEMENTS[$tag];
                if ($new === null) {
                    self::extract($n);
                    continue;
                }
                $n = self::rename($doc, $n, $new);
                $tag = $new;
            }
            if (!isset(self::ALLOWED[$tag])) {
                self::extract($n);
                continue;
            }
            self::sanitizeAttributes($n, $tag);
            if ($tag === 'a' && !$n->hasAttribute('href')) {
                self::extract($n); // odkaz, kterému nezbyla bezpečná adresa, je jen text
            }
        }
    }

    private static function sanitizeAttributes(\Dom\Element $n, string $tag): void
    {
        if ($tag === 'img') {
            // doplňky pro líné načítání dávají skutečnou adresu do data-src
            $url = $n->getAttribute('data-src') ?? $n->getAttribute('data-lazy-src') ?? $n->getAttribute('src') ?? '';
            if (!self::isSafeUrl($url) || str_starts_with(strtolower(trim($url)), 'data:')) {
                $n->remove();

                return;
            }
            $n->setAttribute('src', trim($url));
        }
        foreach (iterator_to_array($n->attributes) as $a) {
            $name = strtolower($a->name);
            $ok = in_array($name, self::ALLOWED[$tag], true) && match ($name) {
                'href', 'src' => self::isSafeUrl($a->value),
                'width', 'height', 'colspan', 'rowspan' => ctype_digit($a->value),
                'target' => $a->value === '_blank',
                'class' => preg_match('/(^|\s)galerie(\s|$)/', $a->value) === 1,
                default => true,
            };
            if (!$ok) {
                $n->removeAttribute($a->name);
            }
        }
        if ($tag === 'figure' && $n->hasAttribute('class')) {
            $n->setAttribute('class', 'galerie');
        }
        if ($tag === 'a' && $n->hasAttribute('target')) {
            $n->setAttribute('rel', 'noopener');
        }
        if ($tag === 'img') {
            $n->setAttribute('alt', (string) $n->getAttribute('alt'));
            $n->setAttribute('loading', 'lazy');
        }
    }

    /** Adresa smí být http(s), mailto, tel nebo místní; javascript:, data:, vbscript: a podobné ne (ani s vloženými mezerami a tabulátory). */
    public static function isSafeUrl(string $url): bool
    {
        $url = (string) preg_replace('/[\x00-\x20]+/', '', $url);

        return $url !== '' && (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || preg_match('#^(https?|mailto|tel):#i', $url) === 1);
    }

    /** Po vyčištění nesmí na nejvyšší úrovni zůstat volný text ani samotný obrázek: text patří do <p>, obrázek do <figure>. */
    private static function normalizeRoot(\Dom\HTMLDocument $doc, \Dom\Element $body): void
    {
        $paragraph = null;
        foreach (iterator_to_array($body->childNodes) as $n) {
            $isInline = $n instanceof \Dom\Text || ($n instanceof \Dom\Element && in_array(strtolower($n->localName), ['a', 'strong', 'em', 'b', 'i', 'u', 's', 'sub', 'sup', 'br', 'code'], true));
            if ($n instanceof \Dom\Element && strtolower($n->localName) === 'img') {
                $wrapper = $doc->createElement('figure');
                $body->replaceChild($wrapper, $n);
                $wrapper->appendChild($n);
                $paragraph = null;
            } elseif ($isInline) {
                if ($paragraph === null) {
                    if ($n instanceof \Dom\Text && trim($n->data) === '') {
                        $n->remove();
                        continue;
                    }
                    $paragraph = $doc->createElement('p');
                    $body->insertBefore($paragraph, $n);
                }
                $paragraph->appendChild($n);
            } else {
                $paragraph = null;
            }
        }
        foreach (iterator_to_array($body->childNodes) as $n) {
            if (!$n instanceof \Dom\Element || strtolower($n->localName) !== 'p') {
                continue;
            }
            $images = [];
            foreach ($n->childNodes as $child) {
                if ($child instanceof \Dom\Element && strtolower($child->localName) === 'img') {
                    $images[] = $child;
                }
            }
            $text = trim(str_replace("\u{00A0}", ' ', (string) $n->textContent));
            if ($text === '' && $images === []) {
                $n->remove(); // prázdný odstavec
            } elseif ($text === '' && count($images) === 1) {
                $wrapper = $doc->createElement('figure'); // odstavec jen s obrázkem = obrázek, jak ho vkládá editor
                $wrapper->appendChild($images[0]);
                $body->replaceChild($wrapper, $n);
            }
        }
    }

    private static function isLinkToOwnImage(\Dom\Element $a): bool
    {
        $children = array_values(array_filter(iterator_to_array($a->childNodes), fn (\Dom\Node $n): bool => !($n instanceof \Dom\Text && trim($n->data) === '')));

        return count($children) === 1 && $children[0] instanceof \Dom\Element && strtolower($children[0]->localName) === 'img'
            && preg_match('#\.(jpe?g|png|gif|webp)$#i', (string) parse_url((string) $a->getAttribute('href'), PHP_URL_PATH)) === 1;
    }

    private static function hasBlockChild(\Dom\Element $n): bool
    {
        foreach ($n->childNodes as $child) {
            if ($child instanceof \Dom\Element && preg_match('#^(?:' . self::BLOCK_TAGS . ')$#i', $child->localName)) {
                return true;
            }
        }

        return false;
    }

    /** Odstraní značku, její obsah nechá na místě. */
    private static function extract(\Dom\Element $n): void
    {
        while ($n->firstChild !== null) {
            $n->parentNode?->insertBefore($n->firstChild, $n);
        }
        $n->remove();
    }

    private static function rename(\Dom\HTMLDocument $doc, \Dom\Element $n, string $tag): \Dom\Element
    {
        $new = $doc->createElement($tag);
        while ($n->firstChild !== null) {
            $new->appendChild($n->firstChild);
        }
        $n->parentNode?->replaceChild($new, $n);

        return $new;
    }

    /**
     * Zavolá funkci jen na části HTML mimo <pre> a <code> – v ukázkách kódu jsou hranaté závorky běžný text.
     *
     * @param callable(string): string $callback
     */
    private static function outsideCode(string $html, callable $callback): string
    {
        $parts = preg_split('#(<pre\b.*?</pre>|<code\b.*?</code>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $parts[$i] = $callback($part);
            }
        }

        return implode('', $parts);
    }
}
