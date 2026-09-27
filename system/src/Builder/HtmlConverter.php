<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Převod HTML na stavbu: jazykový model (nebo import) napíše běžné sémantické HTML s blokem <style> a z něj vznikne
 * čistá stavba – jeden prvek za jednu značku, vzhled ve sdílených třídách; <form> se stane prvkem Formulář. Co převést
 * nejde (skripty, vložené styly, složité selektory), se vynechá a nahlásí, aby autor věděl, co doplnit v builderu.
 *
 * Třída je čistá (bez databáze): vrací stavbu, třídy z <style> a hlášení. Ukládání a kontrolu práv dělá volající.
 */
final class HtmlConverter
{
    /** Značky, které se převádějí na obsah prvku Text (souvislý tok textu se slučuje do jednoho prvku). */
    private const array TEXT_TAGS = ['p', 'ul', 'ol', 'table', 'pre', 'dl', 'address'];

    /** Obalové značky bez vlastního významu pro stavbu – převádí se jen jejich obsah. */
    private const array UNWRAP = ['html', 'body', 'main'];

    /** Značky, které nemají ve stavbě obdobu a vynechají se vždy. */
    private const array SKIP = ['script', 'noscript', 'style', 'link', 'meta', 'template', 'input', 'select', 'textarea', 'button', 'label', 'canvas', 'object', 'embed'];

    /** @var list<string> */
    private array $messages = [];

    /** @var array<string, string> třída => bezpečné deklarace */
    private array $classes = [];

    /** @var array<string, array<string, array<string, string>>> třída => stav (tablet, mobil, hover…) => vlastnosti stylu */
    private array $classStyles = [];

    private function __construct(private readonly bool $admin)
    {
    }

    /**
     * @param bool $admin smí vzniknout prvek Vlastní HTML (pro SVG a vložené mapy)
     * @return array{stavba: array<string, mixed>, tridy: array<string, string>, tridy_styl: array<string, array<string, array<string, string>>>, hlaseni: list<string>}
     */
    public static function convert(string $html, bool $admin = false): array
    {
        $conversion = new self($admin);
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        foreach ($document->querySelectorAll('style') as $style) {
            $conversion->styles($style->textContent);
        }
        $elements = $conversion->children($document->body);

        // nejvyšší úroveň stavby tvoří sekce: souvislé řady jiných prvků se zabalí do jedné sekce
        $root = [];
        $sequence = [];
        foreach ($elements as $p) {
            if ($p['typ'] === 'sekce') {
                if ($sequence !== []) {
                    $root[] = Build::fresh('sekce', [], $sequence);
                    $sequence = [];
                }
                $root[] = $p;
            } else {
                $sequence[] = $p;
            }
        }
        if ($sequence !== []) {
            $root[] = Build::fresh('sekce', [], $sequence);
        }

        return ['stavba' => ['v' => Build::VERSION, 'deti' => $root], 'tridy' => $conversion->classes, 'tridy_styl' => $conversion->classStyles,
            'hlaseni' => array_values(array_unique($conversion->messages))];
    }

    /**
     * HTML z jazykového modelu (MCP, asistent v builderu) do webu: převod, uložení nových tříd z <style> (existující třída
     * webu se přepíše jen s $prepsat) a odebrání tříd bez stylu.
     *
     * @return array{stavba: array<string, mixed>, hlaseni: list<string>}
     */
    public static function saveToSite(\Kaleta\Core\Db $db, string $html, bool $admin, bool $overwrite = false): array
    {
        $conversion = self::convert($html, $admin);
        $messages = $conversion['hlaseni'];
        $existing = array_column($db->all('SELECT nazev FROM {tridy}'), 'nazev');
        foreach (array_unique(array_merge(array_keys($conversion['tridy']), array_keys($conversion['tridy_styl']))) as $className) {
            if (in_array($className, $existing, true) && !$overwrite) {
                $messages[] = 'Třída .' . $className . ' už na webu je – ponechána beze změny.';
                continue;
            }
            $style = (string) json_encode($conversion['tridy_styl'][$className] ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
            $db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE styl = VALUES(styl), css = VALUES(css), zmeneno = NOW()',
                [$className, $style, $conversion['tridy'][$className] ?? '']);
        }
        $skipped = [];
        $build = self::withoutClasses($conversion['stavba'], array_merge($existing, array_keys($conversion['tridy']), array_keys($conversion['tridy_styl'])), $skipped);
        if ($skipped !== []) {
            $messages[] = 'Třídy bez stylu vynechány: ' . implode(', ', array_unique($skipped)) . '.';
        }

        return ['stavba' => $build, 'hlaseni' => $messages];
    }

    /**
     * Odebere třídy, které nemají styl (z cizích CSS frameworků, WordPressu…) – jen by zabíraly místo.
     *
     * @param list<string> $known třídy, které styl mají
     * @param list<string> $skipped sem se zapíšou odebrané
     */
    public static function withoutClasses(array $node, array $known, array &$skipped = []): array
    {
        foreach ($node['deti'] ?? [] as $i => $p) {
            if (isset($p['tridy'])) {
                $skipped = array_merge($skipped, array_diff($p['tridy'], $known));
                $p['tridy'] = array_values(array_intersect($p['tridy'], $known));
                if ($p['tridy'] === []) {
                    unset($p['tridy']);
                }
            }
            $node['deti'][$i] = self::withoutClasses($p, $known, $skipped);
        }

        return $node;
    }

    /** @return list<array<string, mixed>> */
    private function children(Node $parent, int $depth = 0): array
    {
        $output = [];
        $flow = '';          // souvislý text (odstavce, seznamy) čekající na sloučení do jednoho prvku Text
        $questions = [];       // souvislé <details> čekající na sloučení do prvku Otázky a odpovědi
        $flushFlow = function () use (&$output, &$flow, &$questions): void {
            if (trim(strip_tags($flow, '<img>')) !== '') {
                $output[] = Build::fresh('text', ['html' => $flow]);
            }
            $flow = '';
            if ($questions !== []) {
                $output[] = Build::fresh('faq', ['polozky' => $questions]);
                $questions = [];
            }
        };

        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                if (trim($node->textContent) !== '') {
                    $flow .= '<p>' . e(trim($node->textContent)) . '</p>';
                }
                continue;
            }
            if (!$node instanceof Element) {
                continue;
            }
            $htmlTag = strtolower($node->localName);
            if ($htmlTag === 'details' && ($question = $node->querySelector('summary')) !== null) {
                if ($flow !== '') {
                    $output[] = Build::fresh('text', ['html' => $flow]);
                    $flow = '';
                }
                $response = $node->cloneNode(true);
                $response->querySelector('summary')?->remove();
                $questions[] = ['otazka' => trim($question->textContent), 'odpoved' => trim($response->innerHTML)];
                continue;
            }
            // odstavec bez třídy se připojí k souvislému textu; s třídou je samostatný prvek, aby třída měla kam
            if (in_array($htmlTag, self::TEXT_TAGS, true) && $this->classesOf($node) === [] && !$this->hasBlocks($node)) {
                if ($questions !== []) {
                    $flushFlow();
                }
                $flow .= self::html($node);
                continue;
            }
            $flushFlow();
            foreach ($this->element($node, $depth) as $p) {
                $output[] = $p;
            }
        }
        $flushFlow();

        return $output;
    }

    /** @return list<array<string, mixed>> jeden prvek, víc prvků (rozbalený obal) nebo nic */
    private function element(Element $el, int $depth): array
    {
        $htmlTag = strtolower($el->localName);
        if (in_array($htmlTag, self::UNWRAP, true)) {
            return $this->children($el, $depth);
        }
        if (in_array($htmlTag, self::SKIP, true)) {
            if ($htmlTag !== 'style') {
                $this->messages[] = 'Značka <' . $htmlTag . '> mimo formulář nemá ve stavbě obdobu – vynechána.';
            }

            return [];
        }
        if ($el->hasAttribute('style')) {
            $this->messages[] = 'Vložené styly (atribut style) se nepřevádějí – vzhled patří do tříd v <style>.';
        }
        if ($depth >= Build::MAX_DEPTH - 1) {
            $this->messages[] = 'Příliš hluboké vnoření – nejhlubší část převedena jako text.';

            return [Build::fresh('text', ['html' => self::html($el)])];
        }

        $p = match (true) {
            in_array($htmlTag, ['section', 'header', 'footer', 'aside', 'article', 'nav', 'div', 'li'], true) => $this->wrapper($el, $htmlTag, $depth),
            (bool) preg_match('/^h[1-6]$/', $htmlTag) => ['znacka' => $htmlTag] + Build::fresh('nadpis', ['text' => trim($el->innerHTML)]),
            in_array($htmlTag, self::TEXT_TAGS, true) => $this->textOrWrapper($el, $htmlTag, $depth),
            $htmlTag === 'img' => Build::fresh('obrazek', ['src' => $el->getAttribute('src') ?? '', 'alt' => $el->getAttribute('alt') ?? '']),
            $htmlTag === 'figure' => $this->figure($el, $depth),
            $htmlTag === 'a' => $this->link($el, $depth),
            $htmlTag === 'blockquote' => $this->quote($el),
            $htmlTag === 'hr' => Build::fresh('oddelovac'),
            $htmlTag === 'form' => $this->form($el),
            in_array($htmlTag, ['iframe', 'video'], true) && ($video = $this->video($el)) !== null => $video,
            in_array($htmlTag, ['svg', 'iframe', 'video', 'picture', 'audio'], true) => $this->customHtml($el),
            default => Build::fresh('text', ['html' => '<p>' . trim($el->innerHTML) . '</p>']),
        };
        if ($p === null) {
            return [];
        }
        if (($classes = $this->classesOf($el)) !== []) {
            $p['tridy'] = $classes;
            if (array_intersect($classes, array_keys($this->classes + $this->classStyles)) !== []) {
                // vzhled dává třída z <style>: výchozí styl prvku (flex kontejneru, odsazení sekce) by ji přebil – vrstva prvků je v kaskádě až za třídami
                $p['styl'] = [];
            }
        }
        if (($id = $el->getAttribute('id')) !== null && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $id)) {
            $p['kotva'] = $id;
        }

        return [$p];
    }

    /** section/div/… – na nejvyšší úrovni sekce (section, header, footer), jinde kontejner nebo mřížka. */
    private function wrapper(Element $el, string $htmlTag, int $depth): array
    {
        $children = $this->children($el, $depth + 1);
        if ($depth === 0 && in_array($htmlTag, ['section', 'header', 'footer', 'aside', 'article'], true)) {
            // vnitřní obal webu (.container, .wrapper) je u sekce zbytečný – sekce má vlastní; zůstane, jen když jeho třída má styl
            if (count($children) === 1 && $children[0]['typ'] === 'kontejner' && array_intersect($children[0]['tridy'] ?? [], array_keys($this->classes)) === []) {
                $children = $children[0]['deti'];
            }

            return ['znacka' => $htmlTag] + Build::fresh('sekce', [], $children);
        }
        $htmlTag = in_array($htmlTag, Elements\Container::HTML_TAGS, true) ? $htmlTag : 'div';

        return ['znacka' => $htmlTag] + Build::fresh('kontejner', [], $children);
    }

    /** Seznam s třídou nebo se složitými položkami (karty v <ul>) je kontejner, jednoduchý seznam s třídou je prvek Seznam. */
    private function textOrWrapper(Element $el, string $htmlTag, int $depth): array
    {
        if (in_array($htmlTag, ['ul', 'ol'], true)) {
            if (!$this->hasBlocks($el)) {
                $items = [];
                foreach ($el->children as $li) {
                    $items[] = trim($li->textContent);
                }

                return ['znacka' => $htmlTag] + Build::fresh('seznam', ['polozky' => implode("\n", $items)]);
            }

            return ['znacka' => 'ul'] + Build::fresh('kontejner', [], array_map(
                fn (array $p): array => $p['typ'] === 'kontejner' ? ['znacka' => 'li'] + $p : ['znacka' => 'li'] + Build::fresh('kontejner', [], [$p]),
                $this->children($el, $depth + 1),
            ));
        }

        return Build::fresh('text', ['html' => self::html($el)]);
    }

    private function figure(Element $el, int $depth): ?array
    {
        $image = $el->querySelector('img');
        if ($image === null) {
            return ['znacka' => 'div'] + Build::fresh('kontejner', [], $this->children($el, $depth + 1));
        }

        return Build::fresh('obrazek', ['src' => $image->getAttribute('src') ?? '', 'alt' => $image->getAttribute('alt') ?? '', 'popisek' => trim($el->querySelector('figcaption')?->textContent ?? '')]);
    }

    /** Odkaz s blokovým obsahem (karta) je kontejner-odkaz, samostatný textový odkaz je tlačítko. */
    private function link(Element $el, int $depth): array
    {
        $url = $el->getAttribute('href') ?? '';
        if ($this->hasBlocks($el) || $el->querySelector('img') !== null) {
            return ['znacka' => 'div'] + Build::fresh('kontejner', ['odkaz' => $url], $this->children($el, $depth + 1));
        }
        $className = strtolower((string) $el->getAttribute('class'));
        $variant = match (true) {
            (bool) preg_match('/outline|obrys|ghost|secondary|sekundar/', $className) => 'obrys',
            (bool) preg_match('/\blink\b|odkaz/', $className) => 'odkaz',
            default => 'primarni',
        };

        return Build::fresh('tlacitko', ['text' => trim($el->textContent), 'odkaz' => $url, 'varianta' => $variant, 'nove_okno' => $el->getAttribute('target') === '_blank']);
    }

    /** Formulář → prvek Formulář: pole podle ovládacích prvků a jejich popisků; odesílá se vždy do Poptávek webu. */
    private function form(Element $el): array
    {
        $field = [];
        $radios = []; // skupiny <input type="radio"> podle name → jedno pole výběru
        foreach ($el->querySelectorAll('input, select, textarea') as $input) {
            $type = strtolower((string) ($input->getAttribute('type') ?? 'text'));
            if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'image', 'file', 'password'], true)) {
                if (in_array($type, ['file', 'password'], true)) {
                    $this->messages[] = 'Pole typu ' . $type . ' formulář nepodporuje – vynecháno.';
                }
                continue;
            }
            $labelText = $this->fieldLabel($el, $input);
            $required = $input->hasAttribute('required');
            if ($type === 'radio') {
                $displayName = (string) $input->getAttribute('name');
                if (!isset($radios[$displayName])) {
                    $radios[$displayName] = count($field);
                    $group = $input->closest('fieldset')?->querySelector('legend')?->textContent;
                    $field[] = ['popisek' => trim($group ?? $displayName), 'typ' => 'vyber', 'povinne' => $required, 'moznosti' => ''];
                }
                $field[$radios[$displayName]]['moznosti'] = ltrim($field[$radios[$displayName]]['moznosti'] . "\n" . $labelText);
                continue;
            }
            $field[] = match (true) {
                strtolower($input->localName) === 'textarea' => ['popisek' => $labelText, 'typ' => 'textarea', 'povinne' => $required, 'moznosti' => ''],
                strtolower($input->localName) === 'select' => ['popisek' => $labelText, 'typ' => 'vyber', 'povinne' => $required, 'moznosti' => implode("\n", array_filter(array_map(
                    fn (Element $o): string => ($o->getAttribute('value') ?? 'x') === '' ? '' : trim($o->textContent), iterator_to_array($input->querySelectorAll('option')),
                )))],
                $type === 'checkbox' => ['popisek' => $labelText, 'typ' => 'souhlas', 'povinne' => $required, 'moznosti' => ''],
                default => ['popisek' => $labelText, 'typ' => in_array($type, ['email', 'tel'], true) ? $type : 'text', 'povinne' => $required, 'moznosti' => ''],
            };
        }
        $button = $el->querySelector('button:not([type="button"]):not([type="reset"]), input[type="submit"]');
        $text = trim($button === null ? '' : ($button->localName === 'input' ? (string) $button->getAttribute('value') : $button->textContent));
        $this->messages[] = 'Formulář převeden na prvek Formulář: odesílá se do Poptávek webu a e-mailem (adresa v action se nepoužije).';

        return Build::fresh('formular', array_filter(['pole' => array_slice($field, 0, 20), 'tlacitko' => $text], fn (mixed $v): bool => $v !== '' && $v !== []));
    }

    private function fieldLabel(Element $form, Element $input): string
    {
        $id = $input->getAttribute('id');
        $label = $id !== null && $id !== '' ? $form->querySelector('label[for="' . addcslashes($id, '"\\') . '"]') : null;
        $label ??= $input->closest('label');
        if ($label !== null) {
            $copy = $label->cloneNode(true);
            foreach ($copy->querySelectorAll('input, select, textarea') as $v) {
                $v->remove();
            }
            $text = trim((string) preg_replace('/\s+/', ' ', $copy->textContent));
            if ($text !== '') {
                return rtrim($text, ' *:');
            }
        }

        $firstOption = strtolower($input->localName) === 'select' ? $input->querySelector('option[value=""]')?->textContent : null;

        return trim((string) ($input->getAttribute('placeholder') ?? $input->getAttribute('aria-label') ?? $firstOption ?? $input->getAttribute('name') ?? ''));
    }

    private function quote(Element $el): array
    {
        $signature = $el->querySelector('footer, cite, figcaption');
        $author = trim($signature?->textContent ?? '');
        $signature?->remove();
        $text = trim(preg_replace('#</?p[^>]*>#', ' ', $el->innerHTML) ?? '');

        return Build::fresh('citat', ['text' => $text, 'autor' => ltrim($author, "—–- \t")]);
    }

    private function video(Element $el): ?array
    {
        $src = $el->getAttribute('src') ?? $el->querySelector('source')?->getAttribute('src') ?? '';
        if (preg_match('#(youtube\.com/embed/|youtube-nocookie\.com/embed/)([\w-]{6,})#', $src, $m)) {
            return Build::fresh('video', ['url' => 'https://www.youtube.com/watch?v=' . $m[2], 'titulek' => $el->getAttribute('title') ?? '']);
        }
        if (preg_match('#player\.vimeo\.com/video/(\d+)#', $src, $m)) {
            return Build::fresh('video', ['url' => 'https://vimeo.com/' . $m[1], 'titulek' => $el->getAttribute('title') ?? '']);
        }

        return null;
    }

    private function customHtml(Element $el): ?array
    {
        if (!$this->admin) {
            $this->messages[] = 'Značka <' . strtolower($el->localName) . '> jde vložit jen jako Vlastní HTML, a to smí jen správce webu – vynechána.';

            return null;
        }

        return Build::fresh('html', ['kod' => self::html($el)]);
    }

    /** Obsahuje prvek blokové značky (pak nejde o prostý text, ale o strukturu)? */
    private function hasBlocks(Element $el): bool
    {
        return $el->querySelector('div, section, article, header, footer, aside, nav, h1, h2, h3, h4, h5, h6, figure, img, blockquote, details, a.btn, a.button, a[class*="tlacitko"]') !== null;
    }

    /** @return list<string> třídy ve tvaru, který stavba přijme */
    private function classesOf(Element $el): array
    {
        $classes = preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [];

        return array_values(array_filter($classes, fn (string $t): bool => preg_match(Build::CLASS_PATTERN, $t) === 1));
    }

    /**
     * Pravidla „.trida { … }“ z <style> se stanou sdílenými třídami. „.trida:hover“ a @media (max-width: …) se převedou na stavy
     * třídy (najetí, tablet do 1023 px, mobil do 767 px) – deklarace, které mají ve stylu builderu obdobu. Ostatní se nahlásí.
     */
    private function styles(string $css): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        // @media s max-width = breakpoint builderu; blok se zpracuje a z CSS odstraní
        $css = (string) preg_replace_callback('/@media\s*([^{]*)\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/i', function (array $m): string {
            $state = $this->stateFromMedia($m[1]);
            if ($state === null) {
                $this->messages[] = 'Pravidlo @media ' . trim(mb_substr($m[1], 0, 60)) . ' se nepřevádí – builder má breakpointy @media (max-width: 1023px) = tablet a (max-width: 767px) = mobil; stylujte od desktopu dolů.';

                return '';
            }
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $m[2], $rules, PREG_SET_ORDER);
            foreach ($rules as [, $selectors, $declarations]) {
                foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                    if (preg_match('/^\.([a-z][a-z0-9_-]*)(:hover|:focus-visible)?$/', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                        $this->addClassState($t[1], isset($t[2]) ? 'hover_' . $state : $state, $declarations);
                    } elseif ($selector !== '') {
                        $this->messages[] = 'V @media se převádějí jen selektory jedné třídy; vynecháno: ' . mb_substr($selector, 0, 60) . '.';
                    }
                }
            }

            return '';
        }, $css);
        if (preg_match_all('/@(media|supports|container|keyframes|font-face|import|layer)\b/i', $css, $m)) {
            $this->messages[] = 'Pravidla @' . implode(', @', array_unique(array_map('strtolower', $m[1]))) . ' se nepřevádějí – nastavte je ve stylu prvku nebo třídy v builderu.';
            // vnořené bloky se odstraní, aby nepřevzaly deklarace do nesprávných tříd
            do {
                $css = (string) preg_replace('/@[a-z-]+[^{;]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|@[a-z-]+[^{;]*;/i', '', $css, -1, $count);
            } while ($count > 0);
        }
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $other = [];
        foreach ($rules as [, $selectors, $declarations]) {
            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                if (preg_match('/^\.([a-z][a-z0-9_-]*)$/', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                    $discarded = [];
                    $safe = Style::customCss($declarations, $discarded);
                    $this->classes[$t[1]] = trim(($this->classes[$t[1]] ?? '') . ' ' . $safe);
                    foreach ($discarded as $d) {
                        $this->messages[] = 'Třída .' . $t[1] . ': nepovolená deklarace „' . mb_substr($d, 0, 60) . '“ vynechána.';
                    }
                } elseif (preg_match('/^\.([a-z][a-z0-9_-]*)(:hover|:focus-visible|:active)$/', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                    $this->addClassState($t[1], $t[2] === ':active' ? 'aktivni' : 'hover', $declarations);
                } elseif ($selector !== '') {
                    $other[] = $selector;
                }
            }
        }
        if ($other !== []) {
            $this->messages[] = 'Převádějí se jen selektory jedné třídy (.karta, .karta:hover); vynecháno: ' . mb_substr(implode(', ', array_unique($other)), 0, 200) . '.';
        }
    }

    /** Breakpoint builderu podle podmínky @media: max-width do 767 px = mobil, do 1023 px = tablet; jiná podmínka = null. */
    private function stateFromMedia(string $condition): ?string
    {
        if (!preg_match('/^\s*(?:screen\s+and\s+)?\(\s*max-width\s*:\s*(\d+(?:\.\d+)?)(px|rem|em)\s*\)\s*$/i', $condition, $m)) {
            return null;
        }
        $px = (float) $m[1] * (strtolower($m[2]) === 'px' ? 1 : 16);

        return match (true) {
            $px >= 600 && $px < 900 => 'mobil',
            $px >= 900 && $px <= 1280 => 'tablet',
            default => null,
        };
    }

    /** Deklarace do stavu třídy (hover, tablet…) jako vlastnosti stylu; co převést nejde, se nahlásí. */
    private function addClassState(string $className, string $state, string $declarations): void
    {
        if (!isset(Style::STATUSES[$state])) {
            return;
        }
        foreach (preg_split('/;(?![^(]*\))/', $declarations) ?: [] as $d) {
            if (!str_contains($d, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $d, 2));
            $properties = Style::fromCss($property, $value);
            if ($properties === null) {
                $this->messages[] = 'Třída .' . $className . ' (' . $state . '): deklaraci „' . mb_substr(trim($d), 0, 60) . '“ builder ve stavu neumí – vynechána.';
                continue;
            }
            $this->classStyles[$className][$state] = array_merge($this->classStyles[$className][$state] ?? [], $properties);
        }
    }

    /** Celé HTML prvku včetně značky. Vlastnost outerHTML má Dom\Element až od PHP 8.5 – Kaleta běží i na 8.4. */
    private static function html(\Dom\Element $el): string
    {
        return $el->ownerDocument->saveHtml($el);
    }
}
