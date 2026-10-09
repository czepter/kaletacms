<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Size and nesting limits checked BEFORE any parser sees HTML or SVG (3.8). Every place that parses markup from users,
 * Claude, imports or remote sites goes through fragment(), document() or xml() here – never through
 * Dom\HTMLDocument::createFromString(), \DOMDocument::loadXML()/loadHTML() or simplexml directly (tools/unit-tests.php
 * checks that).
 *
 * Why: pathological markup makes HTML parsing super-linear, and a very deep tree can crash PHP. PHP 8.4's own HTML5
 * parser (lexbor) takes 17 s for 800 KB of unclosed <div>s, runs out of 2 GB of memory on 60 KB of formatting elements
 * that it re-opens in every paragraph (<div><b id=1></div>…<p>x</p>…), and libxml frees and clones a tree recursively,
 * so 5,000 levels crash PHP with a 1 MB stack. On PHP 8.3 the compat parser (Kaleta\Compat\Html5Parser) bounds itself;
 * on 8.4+ nothing else does.
 *
 * The check is one linear pass over the bytes: a small tokenizer (comments, raw text such as <script>, <style>,
 * <textarea> and <title>, attribute values and CDATA are skipped as an HTML5 tokenizer does) with a model of the
 * parser's stack of open elements – implied end tags (<p>, <li>, <td>…), scope, foreign content (SVG, MathML) and the
 * list of active formatting elements that the parser re-opens (with the "Noah's Ark" rule), with the few places where
 * PHP 8.4's parser and the 8.3 compat parser differ. On all 51 pages of the owner's five sites it counts exactly what the
 * parser builds; where it simplifies, it keeps elements open rather than closing them, so a tree never outgrows it (tested
 * by repeating random snippets: tools/unit-tests.php).
 *
 * The limits come from real data: the largest page of the owner's five sites is 178 KB with 1,016 elements, 19 levels and
 * 14 attributes on one element; a builder Custom HTML element holds at most 20,000 characters; an SVG upload at most 2 MB.
 * The most expensive input within the limits (4.3 MB, 49,000 paragraphs) takes 0.15 s to check and 0.4 s through
 * Html::safe() on PHP 8.3, 0.1 s on 8.4. A refusal never passes the input through: a sanitizer returns '' and inside
 * guard() an HtmlTooLarge exception says which limit with the measured value (form error, MCP error, import report).
 */
final class HtmlLimits
{
    /** Bytes of one input: WebImport downloads at most 5 MB per page; the largest real page is 178 KB. */
    public const int MAX_BYTES = 5_000_000;

    /** Open elements (as in Chrome and the PHP 8.3 compat parser; real pages: 19); libxml crashes at 4,000–5,000 with a 1 MB stack. */
    public const int MAX_DEPTH = 512;

    /** Elements the parser would create (with the re-opened formatting elements); the largest real page has 1,016. */
    public const int MAX_ELEMENTS = 50_000;

    /** Attributes of one element (as the compat parser); PHP 8.4 looks for duplicates quadratically (30,000 take a second). */
    public const int MAX_ATTRIBUTES = 256;

    /** Depth of an XML document (SVG): libxml itself stops at 256 without LIBXML_PARSEHUGE. */
    public const int MAX_XML_DEPTH = 256;

    private const int HTML = 0;
    private const int SVG = 1;
    private const int MATH = 2;

    private const array VOID = ['area' => true, 'base' => true, 'basefont' => true, 'bgsound' => true, 'br' => true, 'col' => true, 'embed' => true,
        'frame' => true, 'hr' => true, 'img' => true, 'image' => true, 'input' => true, 'keygen' => true, 'link' => true, 'meta' => true, 'param' => true,
        'source' => true, 'track' => true, 'wbr' => true];

    /** Elements whose text is not markup (HTML namespace only): RAWTEXT, script data and RCDATA. */
    private const array RAW_TEXT = ['script' => true, 'style' => true, 'xmp' => true, 'iframe' => true, 'noembed' => true, 'noframes' => true,
        'textarea' => true, 'title' => true];

    private const array FORMATTING = ['a' => true, 'b' => true, 'big' => true, 'code' => true, 'em' => true, 'font' => true, 'i' => true, 'nobr' => true,
        's' => true, 'small' => true, 'strike' => true, 'strong' => true, 'tt' => true, 'u' => true];

    private const array SPECIAL = ['address' => true, 'applet' => true, 'area' => true, 'article' => true, 'aside' => true, 'base' => true,
        'basefont' => true, 'bgsound' => true, 'blockquote' => true, 'body' => true, 'br' => true, 'button' => true, 'caption' => true, 'center' => true,
        'col' => true, 'colgroup' => true, 'dd' => true, 'details' => true, 'dir' => true, 'div' => true, 'dl' => true, 'dt' => true, 'embed' => true,
        'fieldset' => true, 'figcaption' => true, 'figure' => true, 'footer' => true, 'form' => true, 'frame' => true, 'frameset' => true, 'h1' => true,
        'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true, 'head' => true, 'header' => true, 'hgroup' => true, 'hr' => true,
        'html' => true, 'iframe' => true, 'img' => true, 'input' => true, 'keygen' => true, 'li' => true, 'link' => true, 'listing' => true, 'main' => true,
        'marquee' => true, 'menu' => true, 'meta' => true, 'nav' => true, 'noembed' => true, 'noframes' => true, 'noscript' => true, 'object' => true,
        'ol' => true, 'p' => true, 'param' => true, 'plaintext' => true, 'pre' => true, 'script' => true, 'search' => true, 'section' => true,
        'select' => true, 'source' => true, 'style' => true, 'summary' => true, 'table' => true, 'tbody' => true, 'td' => true, 'template' => true,
        'textarea' => true, 'tfoot' => true, 'th' => true, 'thead' => true, 'title' => true, 'tr' => true, 'track' => true, 'ul' => true, 'wbr' => true,
        'xmp' => true];

    /** Start tags that close an open <p> first. */
    private const array CLOSES_P = ['address' => true, 'article' => true, 'aside' => true, 'blockquote' => true, 'center' => true, 'details' => true,
        'dialog' => true, 'dir' => true, 'div' => true, 'dl' => true, 'fieldset' => true, 'figcaption' => true, 'figure' => true, 'footer' => true,
        'header' => true, 'hgroup' => true, 'main' => true, 'menu' => true, 'nav' => true, 'ol' => true, 'p' => true, 'search' => true, 'section' => true,
        'summary' => true, 'ul' => true, 'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true, 'pre' => true,
        'listing' => true, 'xmp' => true, 'plaintext' => true, 'li' => true, 'dd' => true, 'dt' => true, 'table' => true, 'form' => true, 'hr' => true];

    /** Start tags after which the parser does not re-open formatting elements (everything else does). */
    private const array NO_RECONSTRUCT = ['address' => true, 'article' => true, 'aside' => true, 'blockquote' => true, 'center' => true,
        'details' => true, 'dialog' => true, 'dir' => true, 'div' => true, 'dl' => true, 'fieldset' => true, 'figcaption' => true, 'figure' => true,
        'footer' => true, 'header' => true, 'hgroup' => true, 'main' => true, 'menu' => true, 'nav' => true, 'ol' => true, 'p' => true, 'search' => true,
        'section' => true, 'summary' => true, 'ul' => true, 'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'pre' => true, 'listing' => true, 'plaintext' => true, 'li' => true, 'dd' => true, 'dt' => true, 'form' => true, 'table' => true, 'hr' => true,
        'iframe' => true, 'noembed' => true, 'noframes' => true, 'param' => true, 'source' => true, 'track' => true, 'base' => true,
        'basefont' => true, 'bgsound' => true, 'link' => true, 'meta' => true, 'script' => true, 'style' => true, 'template' => true, 'title' => true,
        'textarea' => true, 'rb' => true, 'rp' => true, 'rt' => true, 'rtc' => true];

    /** End tags that close their element when it is in scope (after implied end tags). */
    private const array BLOCK_END = ['address' => true, 'article' => true, 'aside' => true, 'blockquote' => true, 'button' => true, 'center' => true,
        'details' => true, 'dialog' => true, 'dir' => true, 'div' => true, 'dl' => true, 'fieldset' => true, 'figcaption' => true, 'figure' => true,
        'footer' => true, 'header' => true, 'hgroup' => true, 'listing' => true, 'main' => true, 'menu' => true, 'nav' => true, 'ol' => true, 'pre' => true,
        'search' => true, 'section' => true, 'summary' => true, 'ul' => true, 'dd' => true, 'dt' => true];

    /** Scope boundaries (with <select>, as PHP 8.4 parses the customizable select). */
    private const array SCOPE = ['applet' => true, 'caption' => true, 'html' => true, 'table' => true, 'td' => true, 'th' => true, 'marquee' => true,
        'object' => true, 'template' => true, 'select' => true];

    /** Elements that put a marker on the list of active formatting elements. */
    private const array MARKER = ['applet' => true, 'marquee' => true, 'object' => true, 'template' => true, 'td' => true, 'th' => true, 'caption' => true];

    /** Start tags that leave SVG or MathML (<font> only with color, face or size; <sup> stays inside in PHP 8.4). */
    private const array BREAKOUT = ['b' => true, 'big' => true, 'blockquote' => true, 'body' => true, 'br' => true, 'center' => true, 'code' => true,
        'dd' => true, 'div' => true, 'dl' => true, 'dt' => true, 'em' => true, 'embed' => true, 'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true,
        'h5' => true, 'h6' => true, 'head' => true, 'hr' => true, 'i' => true, 'img' => true, 'li' => true, 'listing' => true, 'menu' => true, 'meta' => true,
        'nobr' => true, 'ol' => true, 'p' => true, 'pre' => true, 'ruby' => true, 's' => true, 'small' => true, 'span' => true, 'strong' => true,
        'strike' => true, 'sub' => true, 'table' => true, 'tt' => true, 'u' => true, 'ul' => true, 'var' => true];

    private const array TABLE_PARTS = ['caption' => true, 'colgroup' => true, 'col' => true, 'tbody' => true, 'thead' => true, 'tfoot' => true,
        'tr' => true, 'td' => true, 'th' => true];

    private const array HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const string SPACE = "\t\n\f\r ";

    /** How many guard() calls are running: inside one a refusal throws at once. */
    private static int $guards = 0;

    /* ---------- the model of the parser's state ---------- */

    private string $input = '';
    private int $length = 0;
    private int $at = 0;
    private bool $xmlMode = false;

    /** @var list<string> */
    private array $names = [];
    /** @var list<int> */
    private array $spaces = [];
    /** @var list<bool> HTML or MathML text integration points */
    private array $integration = [];
    /** @var array<string, list<int>> stack positions of each element name */
    private array $positions = [];
    /** @var list<int> positions of scope boundaries */
    private array $scope = [];
    /** @var list<int> positions of special elements */
    private array $special = [];
    /** @var list<int> positions of special elements other than address, div and p (they stop <li>, <dd> and <dt>) */
    private array $blockers = [];
    /** @var list<int> positions of HTML elements */
    private array $htmlElements = [];

    /** @var array<int, array{0: string, 1: int}|null> active formatting elements: id => [name, stack position or -1], null = a marker */
    private array $active = [];
    /** @var array<int, int|null> the entry before each entry */
    private array $before = [];
    /** @var array<int, int|null> the entry after each entry */
    private array $after = [];
    private ?int $last = null;
    private int $nextId = 0;
    /** @var array<int, bool> stack positions of open <template>s => their content is columns (a <col> came first) */
    private array $columnTemplates = [];
    /** @var array<int, int> stack position => id of its active formatting entry */
    private array $entryAt = [];
    /** @var list<array{keys: array<string, list<int>>, names: array<string, list<int>>}> the entries after each marker */
    private array $segments = [['keys' => [], 'names' => []]];
    private bool $formPointer = false;

    private int $elements = 0;
    private int $deepest = 0;
    private int $mostAttributes = 0;
    /** @var array{limit: string, value: int, max: int}|null */
    private ?array $violation = null;
    /** Past the first limit only an open-minus-closed count measures the rest of the input, for the message. */
    private int $roughDepth = 0;

    /**
     * Checks markup before any parser sees it.
     *
     * @return array{limit: string, value: int, max: int}|null null = within the limits, else the first limit passed and the measured value
     */
    public static function check(string $markup, bool $xml = false): ?array
    {
        if (strlen($markup) > self::MAX_BYTES) {
            return ['limit' => 'bytes', 'value' => strlen($markup), 'max' => self::MAX_BYTES];
        }
        $scan = new self();
        $scan->input = $markup;
        $scan->length = strlen($markup);
        $scan->xmlMode = $xml;
        $scan->run();
        if ($scan->violation === null) {
            return null;
        }
        $value = match ($scan->violation['limit']) {
            'depth' => $scan->deepest,
            'elements' => $scan->elements,
            default => $scan->mostAttributes,
        };

        return ['limit' => $scan->violation['limit'], 'value' => max($value, $scan->violation['value']), 'max' => $scan->violation['max']];
    }

    /**
     * What the model measured (for tests and the limits' documentation).
     *
     * @return array{depth: int, elements: int, attributes: int}
     */
    public static function measure(string $markup, bool $xml = false): array
    {
        $scan = new self();
        $scan->input = $markup;
        $scan->length = strlen($markup);
        $scan->xmlMode = $xml;
        $scan->run();

        return ['depth' => $scan->deepest, 'elements' => $scan->elements, 'attributes' => $scan->mostAttributes];
    }

    /**
     * An HTML fragment (the content of <body>) as a document, or null when it is over a limit (inside guard(): HtmlTooLarge).
     * $encoding as in Dom\HTMLDocument::createFromString(): 'UTF-8' for text from forms and databases, null to let a <meta> decide.
     */
    public static function fragment(string $html, ?string $encoding = 'UTF-8'): ?\Dom\HTMLDocument
    {
        if (self::refuse(self::check($html))) {
            return null;
        }

        return \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, $encoding);
    }

    /**
     * Like fragment(), for callers that cannot go on without a document (a conversion, an MCP tool).
     *
     * @throws HtmlTooLarge
     */
    public static function fragmentOrFail(string $html, ?string $encoding = 'UTF-8'): \Dom\HTMLDocument
    {
        $violation = self::check($html);
        if ($violation !== null) {
            throw new HtmlTooLarge($violation);
        }

        return \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, $encoding);
    }

    /**
     * A whole page from another site (the encoding from its BOM or <meta>).
     *
     * @throws HtmlTooLarge when it is over a limit (the import skips the page with the reason)
     */
    public static function document(string $html): \Dom\HTMLDocument
    {
        // UTF-16 and UTF-32 hide their markup from a byte scan: the check reads the page as the parser will decode it
        $scanned = strlen($html) <= self::MAX_BYTES && str_contains($html, "\0") ? \Kaleta\Compat\Html5Parser::utf8($html) : $html;
        $violation = self::check($scanned);
        if ($violation !== null) {
            throw new HtmlTooLarge($violation);
        }

        return \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    /**
     * An XML document (an SVG upload, a sitemap) into $dom; false when it is not well-formed.
     *
     * @throws HtmlTooLarge when it is over a limit (bytes, depth or attributes – libxml reads XML in linear time otherwise)
     */
    public static function xml(\DOMDocument $dom, string $xml, int $options): bool
    {
        $violation = self::check($xml, true);
        if ($violation !== null) {
            throw new HtmlTooLarge($violation);
        }

        return $xml !== '' && $dom->loadXML($xml, $options);
    }

    /**
     * Runs $work (a sanitizer, a conversion, one import item) and throws HtmlTooLarge as soon as markup in it is refused –
     * so a save never goes on with the empty result a refusing sanitizer returns, and nothing after the refusal runs.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws HtmlTooLarge
     */
    public static function guard(callable $work): mixed
    {
        self::$guards++;
        try {
            return $work();
        } finally {
            self::$guards--;
        }
    }

    /**
     * A refusal – also one a caller found itself (an administrator's text is stored without parsing, but checked). True =
     * refused: the caller returns an empty result; inside guard() it throws HtmlTooLarge right away instead.
     *
     * @param array{limit: string, value: int, max: int}|null $violation
     * @throws HtmlTooLarge inside guard()
     */
    public static function refuse(?array $violation): bool
    {
        if ($violation === null) {
            return false;
        }
        if (self::$guards > 0) {
            throw new HtmlTooLarge($violation);
        }

        return true;
    }

    /**
     * The violation in English (MCP, logs): "The markup is nested 40,000 levels deep; the limit is 512." (HTML or SVG)
     *
     * @param array{limit: string, value: int, max: int} $v
     */
    public static function english(array $v): string
    {
        return sprintf(match ($v['limit']) {
            'bytes' => 'The markup is %s bytes long; the limit is %s.',
            'depth' => 'The markup is nested %s levels deep; the limit is %s.',
            'attributes' => 'An element in the markup has %s attributes; the limit is %s.',
            default => 'The markup has %s elements; the limit is %s.',
        }, number_format($v['value']), number_format($v['max']));
    }

    /**
     * The same in the administration's language (forms, import reports).
     *
     * @param array{limit: string, value: int, max: int} $v
     */
    public static function message(array $v): string
    {
        $value = number_format($v['value'], 0, '', ' ');
        $max = number_format($v['max'], 0, '', ' ');

        return match ($v['limit']) {
            'bytes' => t('The markup is %s bytes long; the limit is %s.', $value, $max),
            'depth' => t('The markup is nested %s levels deep; the limit is %s.', $value, $max),
            'attributes' => t('An element in the markup has %s attributes; the limit is %s.', $value, $max),
            default => t('The markup has %s elements; the limit is %s.', $value, $max),
        };
    }

    /* ---------- the scan ---------- */

    private function run(): void
    {
        while ($this->at < $this->length) {
            $lt = strpos($this->input, '<', $this->at);
            if ($lt === false) {
                $this->text();
                return;
            }
            if ($lt > $this->at) {
                $this->text();
            }
            $this->at = $lt;
            $next = $this->input[$lt + 1] ?? '';
            if ($next === '!') {
                $this->markupDeclaration();
            } elseif ($next === '?') {
                $this->skipPast('>', $lt + 2); // a processing instruction (XML) or a bogus comment (HTML)
            } elseif ($next === '/') {
                $this->endTag();
            } elseif (ctype_alpha($next)) {
                if (!$this->startTag()) {
                    return; // the input ends inside a tag: the parser drops the rest
                }
            } else {
                $this->text();
                $this->at = $lt + 1;
            }
        }
    }

    /** Text: the parser re-opens closed formatting elements before it. */
    private function text(): void
    {
        if (!$this->xmlMode && !$this->inForeignContent()) {
            $this->reconstruct();
        }
    }

    private function markupDeclaration(): void
    {
        $start = $this->at;
        if (substr($this->input, $start, 4) === '<!--') {
            $body = $start + 4;
            if (($this->input[$body] ?? '') === '>') {
                $this->at = $body + 1; // <!--> is an empty comment
                return;
            }
            if (substr($this->input, $body, 2) === '->') {
                $this->at = $body + 2; // and so is <!--->
                return;
            }
            $end = strpos($this->input, '-->', $body);
            $bang = strpos($this->input, '--!>', $body);
            if ($bang !== false && ($end === false || $bang < $end)) {
                $this->at = $bang + 4;
            } else {
                $this->at = $end === false ? $this->length : $end + 3;
            }
            return;
        }
        if (substr($this->input, $start, 9) === '<![CDATA[' && ($this->xmlMode || $this->inForeignContent())) {
            $end = strpos($this->input, ']]>', $start + 9);
            $this->at = $end === false ? $this->length : $end + 3;
            return;
        }
        if ($this->xmlMode && strtoupper(substr($this->input, $start, 9)) === '<!DOCTYPE') {
            // an internal subset in [ ] may hold > characters
            $bracket = $start + strcspn($this->input, '[>', $start);
            if (($this->input[$bracket] ?? '') === '[') {
                $this->skipPast(']', $bracket);
            }
        }
        $this->skipPast('>', max($this->at, $start + 2)); // a DOCTYPE or a bogus comment
    }

    private function skipPast(string $char, int $from): void
    {
        $end = $from >= $this->length ? false : strpos($this->input, $char, $from);
        $this->at = $end === false ? $this->length : $end + 1;
    }

    /**
     * Reads a tag name and its attributes (from just after "<" or "</"); null = the input ends inside the tag.
     *
     * @return array{name: string, attributes: array<string, string>, selfClosing: bool}|null
     */
    private function readTag(int $nameStart): ?array
    {
        $nameLength = strcspn($this->input, self::SPACE . '/>', $nameStart);
        $name = strtolower(substr($this->input, $nameStart, $nameLength));
        $at = $nameStart + $nameLength;
        $attributes = [];
        $count = 0;
        $selfClosing = false;
        $keep = isset(self::FORMATTING[$name]) || $name === 'annotation-xml';
        while (true) {
            if ($at < $this->length) {
                $at += strspn($this->input, self::SPACE, $at);
            }
            if ($at >= $this->length) {
                return null;
            }
            $c = $this->input[$at];
            if ($c === '>') {
                break;
            }
            if ($c === '/') {
                $at++;
                if (($this->input[$at] ?? '') === '>') {
                    $selfClosing = true;
                    break;
                }
                continue;
            }
            // an attribute name: its first character may be "=", then anything up to a space, "/", ">" or "="
            $nameLength = $at + 1 < $this->length ? 1 + strcspn($this->input, self::SPACE . '/>=', $at + 1) : 1;
            $attribute = strtolower(substr($this->input, $at, $nameLength));
            $at += $nameLength;
            $count++;
            $value = '';
            if ($at < $this->length) {
                $at += strspn($this->input, self::SPACE, $at);
            }
            if (($this->input[$at] ?? '') === '=') {
                $at++;
                if ($at < $this->length) {
                    $at += strspn($this->input, self::SPACE, $at);
                }
                $quote = $this->input[$at] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $end = strpos($this->input, $quote, $at + 1);
                    if ($end === false) {
                        return null;
                    }
                    $value = substr($this->input, $at + 1, $end - $at - 1);
                    $at = $end + 1;
                } elseif ($at < $this->length) {
                    $valueLength = strcspn($this->input, self::SPACE . '>', $at);
                    $value = substr($this->input, $at, $valueLength);
                    $at += $valueLength;
                }
            }
            if ($keep) {
                $attributes[$attribute] ??= $value; // a repeated attribute is dropped by the parser
            }
        }
        $this->at = $at + 1;
        $this->mostAttributes = max($this->mostAttributes, $count);
        if ($count > self::MAX_ATTRIBUTES) {
            $this->fail('attributes', $count, self::MAX_ATTRIBUTES);
        }

        return ['name' => $name, 'attributes' => $attributes, 'selfClosing' => $selfClosing];
    }

    private function startTag(): bool
    {
        $tag = $this->readTag($this->at + 1);
        if ($tag === null) {
            $this->leaf(); // the markup around a fragment ("</body></html>") may still close the tag: one more element
            return false;
        }
        ['name' => $name, 'attributes' => $attributes, 'selfClosing' => $selfClosing] = $tag;
        if ($this->violation !== null) {
            if (!$selfClosing && !isset(self::VOID[$name])) {
                $this->push($name, self::HTML, []); // only counted now
            }
            $this->skipRawText($name, self::HTML);
            return true;
        }
        if ($this->xmlMode) {
            $this->push($name, self::SVG, $attributes);
            if ($selfClosing) {
                $this->pop();
            }
            return true;
        }
        if ($this->inForeignContent() && !$this->htmlStartTagInForeign($name, $attributes)) {
            // in the namespace of the current element (an <svg> in MathML is a MathML element), but <svg> in <annotation-xml> is SVG
            $current = count($this->names) - 1;
            $space = $name === 'svg' && $this->names[$current] === 'annotation-xml' ? self::SVG : $this->spaces[$current];
            $this->push($name, $space, $attributes);
            if ($selfClosing) {
                $this->pop();
            }
            return true;
        }
        $this->htmlStartTag($name, $attributes, $selfClosing);

        return true;
    }

    /**
     * In SVG or MathML: true when the start tag goes by the HTML rules (it breaks out, or it is in an integration point).
     *
     * @param array<string, string> $attributes
     */
    private function htmlStartTagInForeign(string $name, array $attributes): bool
    {
        $top = count($this->names) - 1;
        if ($this->integration[$top] && !($this->spaces[$top] === self::MATH && ($name === 'mglyph' || $name === 'malignmark'))) {
            return true;
        }
        if (isset(self::BREAKOUT[$name]) || ($name === 'font' && (isset($attributes['color']) || isset($attributes['face']) || isset($attributes['size'])))) {
            $this->leaveForeignContent(true);
            return true;
        }

        return false;
    }

    /**
     * A breakout: pops SVG and MathML elements until an HTML element or an integration point. For a start tag PHP 8.4's parser
     * also stops at an element named as a MathML text integration point in SVG (<svg><mtext>); for </p> and </br>, and in the
     * PHP 8.3 compat parser, it follows the standard.
     */
    private function leaveForeignContent(bool $startTag = false): void
    {
        $names = ['mi', 'mo', 'mn', 'ms', 'mtext'];
        while ($this->inForeignContent() && (!$startTag || PHP_VERSION_ID < 80400 || !in_array($this->names[count($this->names) - 1], $names, true))) {
            $this->pop();
        }
    }

    /** @param array<string, string> $attributes */
    private function htmlStartTag(string $name, array $attributes, bool $selfClosing): void
    {
        $current = count($this->names) - 1;
        if ($current >= 0 && $this->names[$current] === 'template') {
            // the first start tag in a <template> decides what it holds: after a <col> only columns, every other start tag is
            // ignored (also <textarea> or <xmp>, so their text is markup)
            $this->columnTemplates[$current] ??= $name === 'col';
            if ($this->columnTemplates[$current] && $name !== 'col' && $name !== 'template') {
                return;
            }
        }
        if ($name === 'html' || $name === 'body' || $name === 'head' || $name === 'frameset' || $name === 'frame') {
            return; // merged into the existing element, or ignored
        }
        if (isset(self::TABLE_PARTS[$name])) {
            $this->tablePart($name);
            return;
        }
        if ($name === 'form') {
            $inTemplate = $this->topOf('template') >= 0;
            if ($this->formPointer && !$inTemplate) {
                return; // a form inside a form is ignored (inside a <template> it is not)
            }
            $this->formPointer = $this->formPointer || !$inTemplate;
        } elseif ($name === 'table' && $this->topOf('table') > max($this->topOf('td'), $this->topOf('th'), $this->topOf('caption'), $this->topOf('template'))) {
            $this->popTo($this->topOf('table')); // a table directly in a table closes the first one (not inside a <template> in it)
        } elseif ($name === 'li') {
            $this->closeListItem(['li']);
        } elseif ($name === 'dd' || $name === 'dt') {
            $this->closeListItem(['dd', 'dt']);
        } elseif ($name === 'button' && $this->inScope($this->topOf('button'))) {
            $this->popTo($this->topOf('button'));
        } elseif ($name === 'a') {
            if ($this->lastEntry('a') !== null) {
                $this->adoptionAgency('a', true);
            }
        } elseif ($name === 'nobr') {
            $this->reconstruct();
            if ($this->inScope($this->topOf('nobr'))) {
                $this->adoptionAgency('nobr', true);
            }
        } elseif (($name === 'input' || $name === 'select') && $this->selectInScope()) {
            $this->popTo($this->topOf('select')); // an <input> closes an open <select>; a <select> in one only closes it
            if ($name === 'select') {
                return;
            }
        } elseif ($name === 'option' || $name === 'optgroup') {
            if ($this->selectInScope()) {
                // implied end tags (an <optgroup> stays open: counting it as open never counts less)
                while ($this->names !== [] && in_array($this->names[count($this->names) - 1], ['dd', 'dt', 'li', 'option', 'p', 'rb', 'rp', 'rt', 'rtc'], true)) {
                    $this->pop();
                }
            } else {
                $this->popIfCurrent('option');
            }
        } elseif (in_array($name, ['rb', 'rtc', 'rp', 'rt'], true) && $this->inScope($this->topOf('ruby'))) {
            $implied = ['dd', 'dt', 'li', 'optgroup', 'option', 'p', 'rb', 'rp', 'rt'];
            if ($name === 'rb' || $name === 'rtc') {
                $implied[] = 'rtc';
            }
            while ($this->names !== [] && in_array($this->names[count($this->names) - 1], $implied, true)) {
                $this->pop();
            }
        }
        if (isset(self::CLOSES_P[$name])) {
            $this->closeParagraph();
        }
        if (in_array($name, self::HEADINGS, true) && $this->names !== [] && in_array($this->names[count($this->names) - 1], self::HEADINGS, true)) {
            $this->pop();
        }
        if (!isset(self::NO_RECONSTRUCT[$name])) {
            $this->reconstruct();
        }
        if (isset(self::VOID[$name])) {
            $this->leaf();
            return;
        }
        $space = $name === 'svg' ? self::SVG : ($name === 'math' ? self::MATH : self::HTML);
        $this->push($name, $space, $attributes);
        if ($space !== self::HTML && $selfClosing) {
            $this->pop();
            return;
        }
        if (isset(self::FORMATTING[$name])) {
            $this->addFormatting($name, $attributes);
        }
        if ($name === 'plaintext') {
            $this->reconstruct();
            $this->at = $this->length; // the rest is text
            return;
        }
        $this->skipRawText($name, $space);
    }

    /**
     * After the start tag of a raw text element: jumps to its end tag, which the main loop then reads. PHP 8.4 re-opens
     * closed formatting elements inside it for its text, so the model does too.
     */
    private function skipRawText(string $name, int $space): void
    {
        if ($this->xmlMode || $space !== self::HTML || !isset(self::RAW_TEXT[$name])) {
            return;
        }
        $this->reconstruct();
        if ($name !== 'script') {
            $this->at = $this->findEndTag($name, $this->at);
            return;
        }
        // script data: <!-- <script> … </script> --> hides an end tag (the escaped states of the tokenizer); each search goes
        // forward and is remembered until it is passed, so the skip stays linear
        $next = ['end' => -1, 'open' => -1, 'close' => -1, 'inner' => -1];
        $find = function (string $what, int $from) use (&$next): int {
            if ($next[$what] < $from) {
                $found = match ($what) {
                    'end' => $this->findEndTag('script', $from),
                    'inner' => $this->findStartTag('script', $from),
                    default => $from >= $this->length ? false : strpos($this->input, $what === 'open' ? '<!--' : '-->', $from),
                };
                $next[$what] = $found === false ? $this->length : $found;
            }

            return $next[$what];
        };
        $at = $this->at;
        $state = 0; // 0 data, 1 escaped, 2 double escaped
        while (true) {
            $end = $find('end', $at);
            $dash = $find($state === 0 ? 'open' : 'close', $at);
            $inner = $state === 1 ? $find('inner', $at) : $this->length;
            if ($dash < $end && $dash <= $inner) {
                // the dashes of "<!--" can end it again at once: "<!-->" and "<!--->" go back to script data
                $at = $dash + ($state === 0 ? 2 : 3);
                $state = $state === 0 ? 1 : 0;
                continue;
            }
            if ($inner < $end) {
                $state = 2;
                $at = $inner + 7;
                continue;
            }
            if ($state === 2 && $end < $this->length) {
                $state = 1;
                $at = $end + 8;
                continue;
            }
            $this->at = $end;
            return;
        }
    }

    /** Position of "</name" followed by a space, "/" or ">" (case-insensitive), or the end of the input. */
    private function findEndTag(string $name, int $from): int
    {
        $length = strlen($name) + 2;
        while ($from < $this->length && ($found = stripos($this->input, '</' . $name, $from)) !== false) {
            $after = $this->input[$found + $length] ?? '';
            if ($after === '' || str_contains(self::SPACE . '/>', $after)) {
                return $found;
            }
            $from = $found + $length;
        }

        return $this->length;
    }

    /** Position of "<name" followed by a space, "/" or ">" (case-insensitive), or the end of the input. */
    private function findStartTag(string $name, int $from): int
    {
        $length = strlen($name) + 1;
        while ($from < $this->length && ($found = stripos($this->input, '<' . $name, $from)) !== false) {
            $after = $this->input[$found + $length] ?? '';
            if ($after !== '' && str_contains(self::SPACE . '/>', $after)) {
                return $found;
            }
            $from = $found + $length;
        }

        return $this->length;
    }

    private function endTag(): void
    {
        if (!ctype_alpha($this->input[$this->at + 2] ?? '')) {
            $this->skipPast('>', $this->at + 2); // "</>" is ignored, "</3…>" is a bogus comment
            return;
        }
        $tag = $this->readTag($this->at + 2);
        if ($tag === null) {
            $this->at = $this->length;
            return;
        }
        $name = $tag['name'];
        if ($this->violation !== null || $this->xmlMode) {
            $this->pop(); // XML: well-formed input closes the current element (libxml refuses anything else)
            return;
        }
        if ($this->names !== [] && $this->spaces[count($this->spaces) - 1] !== self::HTML) {
            if ($name === 'br' || $name === 'p') {
                $this->leaveForeignContent();
            } else {
                $match = $this->topOf($name);
                if ($match > $this->top($this->htmlElements)) {
                    $this->popTo($match); // an element in the foreign part of the stack
                    return;
                }
            }
        }
        $top = $this->topOf($name);
        if ($top >= 0 && $this->spaces[$top] !== self::HTML) {
            return; // the HTML rules look for an HTML element only: closing nothing never counts less
        }
        $this->htmlEndTag($name);
    }

    private function htmlEndTag(string $name): void
    {
        if ($name === 'p') {
            $p = $this->topOf('p');
            if ($p >= 0 && $p > max($this->scopeTop(), $this->topOf('button'))) {
                $this->popTo($p);
            } else {
                $this->leaf(); // an empty <p> is created
            }
        } elseif ($name === 'br') {
            $this->reconstruct();
            $this->leaf();
        } elseif ($name === 'li') {
            $li = $this->topOf('li');
            if ($li >= 0 && $li > max($this->scopeTop(), $this->topOf('ol'), $this->topOf('ul'))) {
                $this->popTo($li);
            }
        } elseif (isset(self::BLOCK_END[$name])) {
            if ($this->inScope($this->topOf($name))) {
                $this->popTo($this->topOf($name));
            }
        } elseif (in_array($name, self::HEADINGS, true)) {
            $heading = max(array_map($this->topOf(...), self::HEADINGS));
            if ($this->inScope($heading)) {
                $this->popTo($heading);
            }
        } elseif ($name === 'form') {
            $this->formPointer = false;
            $this->popIfCurrent('form'); // otherwise the parser takes the form out of the stack and leaves what is above it (counted as open)
        } elseif ($name === 'applet' || $name === 'marquee' || $name === 'object' || $name === 'template') {
            $top = $this->topOf($name);
            if ($top >= 0 && $top >= $this->scopeTop()) {
                $this->popTo($top);
                $this->clearToMarker();
            }
        } elseif ($name === 'select') {
            if ($this->selectInScope()) {
                $this->popTo($this->topOf('select'));
            }
        } elseif ($name === 'table') {
            $top = $this->topOf($name);
            if ($top >= 0 && $top >= $this->topOf('template')) {
                $this->popTo($top, true);
            }
        } elseif (isset(self::TABLE_PARTS[$name]) && $name !== 'col' && $name !== 'colgroup') {
            $top = $this->topOf($name);
            if ($this->topOf('table') >= 0 && $top > max($this->topOf('table'), $this->topOf('template'))) { // outside a table (or in a <template> in it) ignored
                $this->popTo($top, true);
            }
        } elseif ($name === 'colgroup') {
            $this->popIfCurrent($name);
        } elseif ($name === 'body' || $name === 'html' || $name === 'col') {
            return;
        } elseif (isset(self::FORMATTING[$name])) {
            $this->adoptionAgency($name);
        } else {
            $this->anyOtherEndTag($name);
        }
    }

    /** "Any other end tag": closes the element unless a special element is above it. */
    private function anyOtherEndTag(string $name): void
    {
        $top = $this->topOf($name);
        if ($top >= 0 && $top >= $this->top($this->special)) {
            $this->popTo($top);
        }
    }

    /**
     * The adoption agency for the end tag of a formatting element, simplified so that it never counts less than the parser:
     * without a special element above the formatting element it pops to it; with one, the stack stays as it is (the parser
     * moves that block and copies the formatting element – counted as two more elements).
     */
    /** @param bool $startTag for the start tag of <a> or <nobr>: the parser then takes the old element out of the list and the stack */
    private function adoptionAgency(string $name, bool $startTag = false): void
    {
        $id = $this->lastEntry($name);
        if ($id === null) {
            $this->anyOtherEndTag($name);
            return;
        }
        $at = $this->active[$id][1] ?? -1;
        if ($at < 0) {
            $this->removeEntry($id); // closed earlier: it is no longer re-opened
            return;
        }
        if (!$this->inScope($at)) {
            if ($startTag) {
                $this->removeEntry($id);
                $this->forget($name, $at);
            }
            return;
        }
        $this->removeEntry($id);
        if ($this->top($this->special) > $at) {
            // up to 8 rounds, one per block below, each copies the formatting element and up to 3 others in between; the
            // formatting elements left above may be closed and re-opened once more
            $open = count($this->entryAt);
            $this->created(min(8, count($this->special)) * (1 + min(3, $open)) + $open);
            $this->forget($name, $at);
            return;
        }
        $this->popTo($at);
    }

    /**
     * The parser took the element at $at out of the middle of the stack: it stays counted as open (counting more never counts
     * less), but no end tag or scope check finds it any more – closing it would close everything above it too.
     */
    private function forget(string $name, int $at): void
    {
        $list = $this->positions[$name] ?? [];
        $index = array_search($at, $list, true);
        if (is_int($index)) {
            array_splice($list, $index, 1);
            $this->positions[$name] = $list;
        }
    }

    /** The table modes: implied <tbody> and <tr>, and closing of cells, rows and sections. Outside a table these tags are ignored. */
    private function tablePart(string $name): void
    {
        $table = $this->topOf('table');
        $inTemplate = $this->topOf('template') > $table;
        $inIntegrationPoint = $this->names !== [] && $this->spaces[count($this->spaces) - 1] !== self::HTML;
        if ($table < 0 || $inTemplate || $inIntegrationPoint) {
            // outside a table the parser ignores these – except in a <template> or an integration point of SVG or MathML,
            // where it inserts them: there they count as ordinary elements
            if ($inTemplate || $inIntegrationPoint) {
                $current = $this->names === [] ? '' : $this->names[count($this->names) - 1];
                // a cell in a section gets its row and a row its section, as in a table (a <colgroup> is then left open: counted)
                if (($name === 'td' || $name === 'th' || $name === 'tr') && ($current === 'table' || $current === 'colgroup')) {
                    $this->push('tbody', self::HTML, []);
                    $current = 'tbody';
                }
                if (($name === 'td' || $name === 'th') && in_array($current, ['tbody', 'thead', 'tfoot'], true)) {
                    $this->push('tr', self::HTML, []);
                }
                if ($name === 'col') {
                    $this->leaf();
                } else {
                    $this->push($name, self::HTML, []);
                }
            }
            return;
        }
        $cell = max($this->topOf('td'), $this->topOf('th'));
        if ($cell > $table) {
            $this->popTo($cell, true); // a table part inside a cell closes the cell
        }
        if ($name === 'td' || $name === 'th' || $name === 'tr') {
            $row = $this->topOf('tr');
            if ($row > $table && $name === 'tr') {
                $this->popTo($row, true);
                $row = -1;
            }
            if ($row > $table) {
                $this->popTo($row + 1, true);
            } else {
                $section = max($this->topOf('tbody'), $this->topOf('thead'), $this->topOf('tfoot'));
                if ($section > $table) {
                    $this->popTo($section + 1, true);
                } else {
                    $this->popTo($table + 1, true);
                    $this->push('tbody', self::HTML, []);
                }
                $this->push('tr', self::HTML, []);
                if ($name === 'tr') {
                    return;
                }
            }
            $this->push($name, self::HTML, []);
            return;
        }
        $this->popTo($table + 1, true);
        if ($name === 'col') {
            $this->push('colgroup', self::HTML, []);
            $this->leaf();
            return;
        }
        $this->push($name, self::HTML, []);
    }

    private function closeParagraph(): void
    {
        $p = $this->topOf('p');
        if ($p >= 0 && $p > max($this->scopeTop(), $this->topOf('button'))) {
            $this->popTo($p);
        }
    }

    /** @param list<string> $names li, or dd and dt: closes the nearest one unless a special element (not address, div, p) is above it */
    private function closeListItem(array $names): void
    {
        $item = max(array_map($this->topOf(...), $names));
        if ($item >= 0 && $item >= $this->top($this->blockers)) { // the item itself is one
            $this->popTo($item);
        }
    }

    /* ---------- the stack of open elements ---------- */

    /** @param array<string, string> $attributes */
    private function push(string $name, int $space, array $attributes): void
    {
        if ($this->violation !== null) {
            $this->created(1);
            $this->deepest = max($this->deepest, ++$this->roughDepth);
            return;
        }
        $at = count($this->names);
        $this->names[] = $name;
        $this->spaces[] = $space;
        $this->positions[$name][] = $at;
        $integration = $space === self::SVG ? in_array($name, ['foreignobject', 'desc', 'title'], true)
            : ($space === self::MATH && (in_array($name, ['mi', 'mo', 'mn', 'ms', 'mtext'], true)
                || ($name === 'annotation-xml' && in_array(strtolower($attributes['encoding'] ?? ''), ['text/html', 'application/xhtml+xml'], true))));
        $this->integration[] = $integration;
        if ($space === self::HTML) {
            $this->htmlElements[] = $at;
        }
        // in SVG and MathML the integration points are boundaries; PHP 8.4 also takes a foreign element with the name of an
        // HTML scope boundary (<object>, <table>…) for one
        $foreignBoundary = $space !== self::HTML && ($integration || $name === 'annotation-xml');
        if (isset(self::SCOPE[$name]) || $foreignBoundary) {
            $this->scope[] = $at;
        }
        if (($space === self::HTML && isset(self::SPECIAL[$name])) || $foreignBoundary) {
            $this->special[] = $at;
            if ($name !== 'address' && $name !== 'div' && $name !== 'p') {
                $this->blockers[] = $at;
            }
        }
        if ($space === self::HTML && isset(self::MARKER[$name])) {
            $this->append(null);
            $this->segments[] = ['keys' => [], 'names' => []];
        }
        $this->created(1);
        if ($at + 1 > $this->deepest) {
            $this->deepest = $at + 1;
            $max = $this->xmlMode ? self::MAX_XML_DEPTH : self::MAX_DEPTH;
            if ($this->deepest > $max) {
                $this->fail('depth', $this->deepest, $max);
            }
        }
    }

    /** @param bool $closeCell a cell or caption that is popped is closed (its formatting elements go) */
    private function pop(bool $closeCell = false): void
    {
        if ($this->violation !== null) {
            $this->roughDepth = max(0, $this->roughDepth - 1);
            return;
        }
        $at = count($this->names) - 1;
        if ($at < 0) {
            return;
        }
        $name = $this->names[$at];
        $space = $this->spaces[$at];
        array_pop($this->names);
        array_pop($this->spaces);
        array_pop($this->integration);
        $positions = $this->positions[$name] ?? [];
        self::dropTop($positions, $at); // a forgotten element is not among them any more
        $this->positions[$name] = $positions;
        self::dropTop($this->scope, $at);
        self::dropTop($this->special, $at);
        self::dropTop($this->blockers, $at);
        self::dropTop($this->htmlElements, $at);
        unset($this->columnTemplates[$at]);
        if (isset($this->entryAt[$at])) {
            $id = $this->entryAt[$at];
            unset($this->entryAt[$at]);
            if (isset($this->active[$id])) {
                $this->active[$id][1] = -1; // closed but still on the list: the parser re-opens it before the next text
            }
        }
        if ($closeCell && $space === self::HTML && ($name === 'td' || $name === 'th' || $name === 'caption')) {
            $this->clearToMarker(); // closing a cell or caption clears its formatting elements (popping an <object> on the way does not)
        }
    }

    /** @param list<int> $list */
    private static function dropTop(array &$list, int $at): void
    {
        if ($list !== [] && $list[count($list) - 1] === $at) {
            array_pop($list);
        }
    }

    /** Pops until the stack has $at elements: the element at position $at and everything above it go. */
    private function popTo(int $at, bool $closeCells = false): void
    {
        $at = max(0, $at);
        while ($this->violation === null && count($this->names) > $at) {
            $this->pop($closeCells);
        }
    }

    private function popIfCurrent(string $name): void
    {
        if ($this->names !== [] && $this->names[count($this->names) - 1] === $name) {
            $this->pop();
        }
    }

    /** The topmost stack position of an element name, -1 when none is open. */
    private function topOf(string $name): int
    {
        return $this->top($this->positions[$name] ?? []);
    }

    /** @param list<int> $list */
    private function top(array $list): int
    {
        return $list === [] ? -1 : $list[count($list) - 1];
    }

    private function scopeTop(): int
    {
        return $this->top($this->scope);
    }

    /** A <select> is in scope (PHP 8.4 parses the customizable select: it holds any element, and is a scope boundary itself). */
    private function selectInScope(): bool
    {
        return $this->inScope($this->topOf('select'));
    }

    /** The element at stack position $at is in scope: open, with no scope boundary above it (it may be one itself). */
    private function inScope(int $at): bool
    {
        return $at >= 0 && $at >= $this->scopeTop();
    }

    private function inForeignContent(): bool
    {
        $top = count($this->spaces) - 1;

        return $top >= 0 && $this->spaces[$top] !== self::HTML && !$this->integration[$top];
    }

    /* ---------- the list of active formatting elements ---------- */

    /** @param array<string, string> $attributes */
    private function addFormatting(string $name, array $attributes): void
    {
        if ($this->violation !== null) {
            return;
        }
        ksort($attributes);
        $key = $name . "\0" . http_build_query($attributes);
        $segment = count($this->segments) - 1;
        $same = array_values(array_filter($this->segments[$segment]['keys'][$key] ?? [], fn (int $id): bool => isset($this->active[$id])));
        if (count($same) >= 3) {
            $this->removeEntry($same[0]); // Noah's Ark: at most three identical formatting elements after the last marker
            array_shift($same);
        }
        $at = count($this->names) - 1;
        $id = $this->append([$name, $at]);
        $this->entryAt[$at] = $id;
        $same[] = $id;
        $this->segments[$segment]['keys'][$key] = $same;
        $this->segments[$segment]['names'][$name][] = $id;
    }

    /** The last entry with this name after the last marker. */
    private function lastEntry(string $name): ?int
    {
        $segment = count($this->segments) - 1;
        while (($ids = $this->segments[$segment]['names'][$name] ?? []) !== []) {
            $id = $ids[count($ids) - 1];
            if (isset($this->active[$id])) {
                return $id;
            }
            array_pop($this->segments[$segment]['names'][$name]); // removed earlier
        }

        return null;
    }

    /**
     * Adds an entry (a formatting element, or null = a marker) at the end of the list – a linked list, so removing from
     * the middle and walking back from the end cost nothing per step.
     *
     * @param array{0: string, 1: int}|null $entry
     */
    private function append(?array $entry): int
    {
        $id = $this->nextId++;
        $this->active[$id] = $entry;
        $this->before[$id] = $this->last;
        $this->after[$id] = null;
        if ($this->last !== null) {
            $this->after[$this->last] = $id;
        }
        $this->last = $id;

        return $id;
    }

    private function removeEntry(int $id): void
    {
        if (!array_key_exists($id, $this->active)) {
            return;
        }
        $at = $this->active[$id][1] ?? -1;
        if ($at >= 0 && ($this->entryAt[$at] ?? null) === $id) {
            unset($this->entryAt[$at]);
        }
        $before = $this->before[$id] ?? null;
        $after = $this->after[$id] ?? null;
        if ($before !== null) {
            $this->after[$before] = $after;
        }
        if ($after !== null) {
            $this->before[$after] = $before;
        } else {
            $this->last = $before;
        }
        unset($this->active[$id], $this->before[$id], $this->after[$id]);
    }

    private function clearToMarker(): void
    {
        while ($this->last !== null) {
            $id = $this->last;
            $marker = $this->active[$id] === null;
            $this->removeEntry($id);
            if ($marker) {
                break;
            }
        }
        if (count($this->segments) > 1) {
            array_pop($this->segments);
        }
    }

    /** Re-opens the formatting elements closed since the last marker (the parser does so before text and most start tags). */
    private function reconstruct(): void
    {
        if ($this->violation !== null) {
            return;
        }
        $reopen = [];
        for ($id = $this->last; $id !== null; $id = $this->before[$id] ?? null) {
            $entry = $this->active[$id] ?? null;
            if ($entry === null || $entry[1] >= 0) {
                break;
            }
            $reopen[] = $id;
        }
        foreach (array_reverse($reopen) as $id) {
            $entry = $this->active[$id] ?? null;
            if ($entry === null || $this->violation !== null) {
                return;
            }
            $this->push($entry[0], self::HTML, []);
            if ($this->violation === null) {
                $at = count($this->names) - 1;
                $this->active[$id] = [$entry[0], $at];
                $this->entryAt[$at] = $id;
            }
        }
    }

    /** A void element (or an empty one the parser creates): one more element, one level below the current node. */
    private function leaf(): void
    {
        $this->created(1);
        if ($this->violation === null && count($this->names) + 1 > $this->deepest) {
            $this->deepest = count($this->names) + 1;
            if ($this->deepest > self::MAX_DEPTH) {
                $this->fail('depth', $this->deepest, self::MAX_DEPTH);
            }
        }
    }

    private function created(int $elements): void
    {
        $this->elements += $elements;
        // XML (SVG, sitemaps) has no element limit: libxml parses it in linear time, and a sitemap may list 50,000 addresses
        if ($this->elements > self::MAX_ELEMENTS && !$this->xmlMode) {
            $this->fail('elements', $this->elements, self::MAX_ELEMENTS);
        }
    }

    /** The first limit passed; past it the rest of the input is only counted roughly (open minus closed tags), for the message. */
    private function fail(string $limit, int $value, int $max): void
    {
        if ($this->violation === null) {
            $this->violation = ['limit' => $limit, 'value' => $value, 'max' => $max];
            $this->roughDepth = count($this->names);
            $this->names = $this->spaces = $this->scope = $this->special = $this->blockers = $this->htmlElements = [];
            $this->integration = [];
            $this->positions = $this->active = $this->entryAt = $this->before = $this->after = [];
            $this->last = null;
        }
    }
}
