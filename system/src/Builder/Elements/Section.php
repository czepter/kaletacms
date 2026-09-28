<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** A full-width band of the page; an inner wrapper keeps the content at the site width (or narrow for text, or none). */
final class Section extends Element
{
    public const string TYPE = 'sekce';
    public const string NAME = 'Sekce';
    public const string DESCRIPTION = 'A full-width band with centred content.';
    public const string ICON = 'sekce';
    public const string GROUP = 'Rozložení';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['section', 'header', 'footer', 'aside', 'article', 'div'];

    public static function properties(): array
    {
        return [
            'sirka' => ['typ' => 'vyber', 'popisek' => 'Content width', 'vychozi' => 'obsah', 'moznosti' => ['obsah' => 'site width', 'uzka' => 'narrow (text)', 'plna' => 'full width']],
            'video' => ['typ' => 'odkaz', 'popisek' => 'Background video (MP4 or WebM from Media, no sound)', 'vychozi' => '', 'media' => 'video'], // editor: a pick from Media, not a link
        ];
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['odsazeni_y' => 'xl']];
    }

    public static function baseCss(): string
    {
        return '.ka-obal { width: min(100% - 2 * var(--ka-okraj, var(--ka-mezera-m)), var(--ka-sirka)); margin-inline: auto; }
.ka-obal--uzka { width: min(100% - 2 * var(--ka-okraj, var(--ka-mezera-m)), var(--ka-sirka-textu)); }
:where(.stavba) a:focus-visible { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }
.ka-obal > * + * { margin-block-start: var(--ka-mezera-m); }
.ka-s-videem { position: relative; isolation: isolate; overflow: hidden; }
.ka-video-pozadi { position: absolute; inset: 0; z-index: -1; width: 100%; height: 100%; object-fit: cover; }
@media (prefers-reduced-motion: reduce) { .ka-video-pozadi { display: none; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $width = $p['obsah']['sirka'] ?? 'obsah';
        $content = $width === 'plna' ? $children : '<div class="ka-obal' . ($width === 'uzka' ? ' ka-obal--uzka' : '') . '">' . $children . '</div>';

        // background video: only a file from Media (a third-party player would send data without consent); muted, looped, hidden from screen readers
        $video = (string) ($p['obsah']['video'] ?? '');
        if (preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,300}\.(mp4|webm))$#i', $video, $m) && !str_contains($m[1], '..')) {
            $content = '<video class="ka-video-pozadi" src="' . e($k->app->request->basePath() . '/' . $m[1]) . '" autoplay muted loop playsinline preload="metadata" aria-hidden="true"></video>' . $content;
            $a = Text::withClass($a, 'ka-s-videem');
        }

        return '<' . $p['znacka'] . $a . '>' . $content . '</' . $p['znacka'] . '>';
    }
}
