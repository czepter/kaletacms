<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Safe HTML from users without administrator permission (author and editor of news and pages, MCP with their token).
 * Unlike the conversion from WordPress (WpContent) it keeps the structure and the classes from the editor and removes only what can run code
 * or take over an account: scripts, frames, forms, event handlers, styles, the site's script hooks (data-…) and javascript:/data: URLs.
 * An administrator can insert custom HTML (the HTML element, layouts), so their text is not sanitized.
 */
final class Html
{
    private const array DISCARD = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea',
        'template', 'noscript', 'svg', 'math', 'link', 'meta', 'base', 'head', 'title', 'dialog', 'portal'];

    /** Allowed attributes (besides aria-*); the others – mainly on…, style, data-…, formaction, srcdoc – are removed. */
    private const array ATTRIBUTES = ['href', 'src', 'alt', 'title', 'class', 'id', 'width', 'height', 'colspan', 'rowspan', 'scope', 'target', 'rel', 'lang', 'dir',
        'loading', 'decoding', 'srcset', 'sizes', 'start', 'reversed', 'type', 'cite', 'datetime', 'controls', 'poster', 'preload', 'playsinline', 'muted', 'loop'];

    public static function safe(string $html): string
    {
        if (trim($html) === '' || !preg_match('/<|&/', $html)) {
            return $html;
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;
        if ($body === null) {
            return '';
        }
        self::node($body);
        $output = '';
        foreach ($body->childNodes as $n) {
            $output .= $doc->saveHtml($n);
        }

        return $output;
    }

    /** Sanitizes only for users without administrator permission. */
    public static function forUser(string $html, Auth $auth): string
    {
        return $auth->isAdmin() ? $html : self::safe($html);
    }

    private static function node(\Dom\Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $n) {
            if ($n instanceof \Dom\Comment) {
                $n->remove();
                continue;
            }
            if (!$n instanceof \Dom\Element) {
                continue;
            }
            if (in_array(strtolower($n->localName), self::DISCARD, true)) {
                $n->remove();
                continue;
            }
            foreach (iterator_to_array($n->attributes) as $a) {
                $name = strtolower($a->name);
                $ok = (in_array($name, self::ATTRIBUTES, true) || preg_match('/^aria-[a-z]{2,20}$/', $name))
                    && (!in_array($name, ['href', 'src', 'poster', 'cite'], true) || WpContent::isSafeUrl($a->value))
                    && ($name !== 'srcset' || !preg_match('/(javascript|data|vbscript):/i', $a->value))
                    && ($name !== 'target' || $a->value === '_blank');
                if (!$ok) {
                    $n->removeAttribute($a->name);
                }
            }
            if (strtolower($n->localName) === 'a' && $n->getAttribute('target') === '_blank') {
                $n->setAttribute('rel', 'noopener');
            }
            self::node($n);
        }
    }
}
