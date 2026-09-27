<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\WpContent;

/**
 * Stavba stránky: strom prvků {"v": 1, "deti": [{"id", "typ", "znacka", "obsah", "styl", "tridy", "kotva", "deti"}]}.
 *
 * Jediný validátor pro editor, MCP i API (vycisti) a jediný vykreslovač v PHP (vykresli) – co editor ukáže, je přesně web.
 * Neplatná část stromu se při čištění opraví nebo zahodí a nahlásí; na veřejném webu se nikdy nevyhazuje výjimka.
 */
final class Build
{
    public const int VERSION = 1;
    public const int MAX_ELEMENTS = 800;
    public const int MAX_DEPTH = 12;
    public const string CLASS_PATTERN = '/^[a-z][a-z0-9-]{0,40}(__[a-z0-9-]{1,30})?(--[a-z0-9-]{1,30})?$/';

    /** Vlastní atributy prvku: jen neškodné (žádné on…, style, href, src, ani háčky skriptů webu jako data-vlozit – ty by šly zneužít). */
    public const string ATTRIBUTE_PATTERN = '/^(data-(?!ka-|(?:adresa|cast|formular|hotovo|karusel|konec|kopirovat|krok|obnovit|odeslano|odpocet|pocitadlo|samo|sdilet|tema|texty|titulek|vlozit|zalozky|zapnuto|zavrit|znovu)$)[a-z0-9-]{1,30}|aria-[a-z]{2,20}|title|lang|role|rel)$/i';

    /** Id, která používá šablona webu (skok na obsah, navigace, cookie lišta) – kotva prvku je nesmí zopakovat. */
    public const array RESERVED_ANCHORS = ['obsah', 'navigace', 'cookies-lista', 'cookies-nadpis', 'cookies-znovu'];

    /** Registr typů prvků (pořadí = pořadí v panelu Přidat). @var list<class-string<Prvek>> */
    public const array ELEMENTS = [
        Elements\Section::class, Elements\Container::class, Elements\Grid::class,
        Elements\Heading::class, Elements\Text::class, Elements\Image::class, Elements\Button::class, Elements\BulletList::class,
        Elements\Quote::class, Elements\Faq::class, Elements\Video::class, Elements\Divider::class,
        Elements\Icon::class, Elements\Gallery::class, Elements\Tabs::class, Elements\Carousel::class, Elements\Map::class, Elements\Modal::class, Elements\Breadcrumbs::class,
        Elements\Counter::class, Elements\Progress::class, Elements\Rating::class, Elements\Countdown::class, Elements\SocialLinks::class, Elements\Search::class,
        Elements\News::class, Elements\CollectionList::class, Elements\Form::class, Elements\Newsletter::class, Elements\Component::class, Elements\Html::class, Elements\BackToTop::class,
        Elements\Logo::class, Elements\Navigation::class, Elements\LanguageSwitcher::class, Elements\CompanyDetails::class, Elements\PageContent::class,
    ];

    /** @return class-string<Element>|null */
    public static function className(string $type): ?string
    {
        foreach (self::ELEMENTS as $className) {
            if ($className::TYPE === $type) {
                return $className;
            }
        }

        return null;
    }

    public static function newId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 7);
    }

    /** Nový prvek daného typu s výchozím obsahem a stylem (pro editor, knihovnu i převod HTML). */
    public static function fresh(string $type, array $content = [], array $children = []): array
    {
        $className = self::className($type) ?? throw new \InvalidArgumentException('Neznámý typ prvku ' . $type);
        $defaults = array_map(fn (array $field): mixed => $field['vychozi'] ?? '', $className::properties());

        return ['id' => self::newId(), 'typ' => $type, 'znacka' => $className::HTML_TAGS[0], 'obsah' => $content + $defaults, 'styl' => $className::defaultStyle(), 'tridy' => [], 'deti' => $children];
    }

    /**
     * Vyčistí strom od kohokoli (editor, AI, import). Neznámé typy, vlastnosti a hodnoty zahodí, chybějící doplní výchozími,
     * duplicitní nebo chybějící id vytvoří znovu.
     *
     * @param bool $admin smí měnit prvky JEN_SPRAVCE (vlastní HTML); ostatním se jejich obsah převezme z $previous
     * @param array<string, mixed>|null $previous dosavadní stavba (kvůli prvkům JEN_SPRAVCE)
     * @return array{0: array<string, mixed>, 1: array<string, string>} [stavba, chyby cesta => text]
     */
    public static function sanitize(mixed $input, bool $admin = true, ?array $previous = null): array
    {
        $errors = [];
        $used = [];
        $count = 0;
        $protected = $admin || $previous === null ? [] : self::elementsOfType($previous, fn (string $className): bool => $className::ADMIN_ONLY);
        $children = is_array($input) && is_array($input['deti'] ?? null) ? $input['deti'] : (is_array($input) && array_is_list($input) ? $input : []);
        if (!is_array($input)) {
            $errors['stavba'] = 'Stavba musí být objekt {"v": 1, "deti": [...]}.';
        }
        $build = ['v' => self::VERSION, 'deti' => self::sanitizeChildren($children, 'deti', 1, $errors, $used, $count, $admin, $protected)];

        return [$build, $errors];
    }

    /** @param array<string, array<string, mixed>> $protected */
    private static function sanitizeChildren(array $children, string $path, int $depth, array &$errors, array &$used, int &$count, bool $admin, array $protected): array
    {
        $output = [];
        foreach (array_values($children) as $i => $p) {
            $place = $path . '[' . $i . ']';
            if (!is_array($p) || ($className = self::className((string) ($p['typ'] ?? ''))) === null) {
                $errors[$place] = 'Neznámý typ prvku „' . mb_substr((string) (is_array($p) ? ($p['typ'] ?? '') : ''), 0, 30) . '“ – vynechán. Typy: ' . implode(', ', array_map(fn (string $t): string => $t::TYPE, self::ELEMENTS)) . '.';
                continue;
            }
            if (++$count > self::MAX_ELEMENTS) {
                $errors[$place] = 'Stavba má víc než ' . self::MAX_ELEMENTS . ' prvků – zbytek vynechán.';
                break;
            }
            $id = is_string($p['id'] ?? null) && preg_match('/^[a-z0-9]{3,16}$/', $p['id']) && !isset($used[$p['id']]) ? $p['id'] : self::newId();
            $used[$id] = true;
            if ($className::ADMIN_ONLY && !$admin) {
                if (!isset($protected[$id])) {
                    $errors[$place] = 'Prvek „' . $className::NAME . '“ smí vložit jen správce webu – vynechán.';
                    continue;
                }
                $output[] = $protected[$id]; // obsah vlastního HTML se nemění, jen může zůstat na místě
                continue;
            }
            $htmlTag = in_array($p['znacka'] ?? null, $className::HTML_TAGS, true) ? $p['znacka'] : $className::HTML_TAGS[0];
            $clean = ['id' => $id, 'typ' => $className::TYPE, 'znacka' => $htmlTag, 'obsah' => self::sanitizeContent($className::properties(), is_array($p['obsah'] ?? null) ? $p['obsah'] : [], $place . '.obsah', $errors)];
            $style = Style::sanitize($p['styl'] ?? [], $place . '.styl', $errors);
            if ($style !== []) {
                $clean['styl'] = $style;
            }
            $classes = array_values(array_unique(array_filter(is_array($p['tridy'] ?? null) ? $p['tridy'] : [], fn (mixed $t): bool => is_string($t) && preg_match(self::CLASS_PATTERN, $t) === 1)));
            if ($classes !== []) {
                $clean['tridy'] = array_slice($classes, 0, 8);
            }
            if (is_string($p['kotva'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $p['kotva'])) {
                // kotva = id na stránce: musí být jedinečná a nesmí se srazit s id šablony ani stylem jiného prvku (s-…)
                if (isset($used['kotva:' . $p['kotva']]) || in_array($p['kotva'], self::RESERVED_ANCHORS, true) || preg_match('/^(s|ka)-/', $p['kotva'])) {
                    $errors[$place . '.kotva'] = 'Kotvu „' . $p['kotva'] . '“ už na stránce používá jiný prvek nebo šablona – vynechána.';
                } else {
                    $clean['kotva'] = $p['kotva'];
                    $used['kotva:' . $p['kotva']] = true;
                }
            }
            if (is_string($p['popis'] ?? null) && trim($p['popis']) !== '') {
                $clean['popis'] = mb_substr(trim(strip_tags($p['popis'])), 0, 60); // jméno prvku ve stromu editoru
            }
            // vlastní CSS prvku: jen bezpečné deklarace (jako u tříd)
            if (is_string($p['css'] ?? null) && trim($p['css']) !== '') {
                $discarded = [];
                $css = Style::customCss(mb_substr($p['css'], 0, 2000), $discarded);
                if ($css !== '') {
                    $clean['css'] = $css;
                }
                foreach ($discarded as $d) {
                    $errors[$place . '.css'] = 'Nepovolená deklarace: ' . mb_substr($d, 0, 60);
                }
            }
            // vlastní atributy: data-*, aria-*, title, lang, role, rel – hodnoty se escapují při vykreslení
            if (is_array($p['atributy'] ?? null)) {
                $attributes = [];
                foreach (array_slice($p['atributy'], 0, 10, true) as $name => $value) {
                    if (is_string($name) && is_scalar($value) && preg_match(self::ATTRIBUTE_PATTERN, $name)) {
                        $attributes[strtolower($name)] = mb_substr((string) $value, 0, 200);
                    } else {
                        $errors[$place . '.atributy'] = 'Atribut může být jen data-…, aria-…, title, lang, role nebo rel.';
                    }
                }
                if ($attributes !== []) {
                    $clean['atributy'] = $attributes;
                }
            }
            // podmínky zobrazení: jen pro přihlášené / nepřihlášené, od a do data (včetně)
            if (is_array($p['podminky'] ?? null)) {
                $conditions = [];
                if (in_array($p['podminky']['prihlaseni'] ?? '', ['ano', 'ne'], true)) {
                    $conditions['prihlaseni'] = $p['podminky']['prihlaseni'];
                }
                foreach (['od', 'do'] as $bound) {
                    $date = (string) ($p['podminky'][$bound] ?? '');
                    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false) {
                        $conditions[$bound] = $date;
                    } elseif ($date !== '') {
                        $errors[$place . '.podminky'] = 'Datum podmínky zobrazení musí být ve tvaru RRRR-MM-DD.';
                    }
                }
                if ($conditions !== []) {
                    $clean['podminky'] = $conditions;
                }
            }
            if (($p['zamek'] ?? false) === true) {
                $clean['zamek'] = true; // v editoru nejde na plátně vybrat ani přetáhnout
            }
            if ($className::CONTAINER) {
                if ($depth >= self::MAX_DEPTH) {
                    $errors[$place . '.deti'] = 'Příliš hluboké vnoření – vnořené prvky vynechány.';
                    $clean['deti'] = [];
                } else {
                    $clean['deti'] = self::sanitizeChildren(is_array($p['deti'] ?? null) ? $p['deti'] : [], $place . '.deti', $depth + 1, $errors, $used, $count, $admin, $protected);
                }
            } elseif (!empty($p['deti'])) {
                $errors[$place . '.deti'] = 'Prvek „' . $className::NAME . '“ nemůže obsahovat další prvky – vynechány.';
            }
            $output[] = $clean;
        }

        return $output;
    }

    /** @param array<string, array<string, mixed>> $field */
    private static function sanitizeContent(array $field, array $content, string $path, array &$errors): array
    {
        $clean = [];
        foreach ($field as $key => $def) {
            $value = $content[$key] ?? $def['vychozi'] ?? '';
            $max = (int) ($def['max'] ?? 5000);
            $clean[$key] = match ($def['typ']) {
                'text' => mb_substr(trim(strip_tags(is_scalar($value) ? (string) $value : '')), 0, $max),
                'radky' => mb_substr(strip_tags(is_scalar($value) ? (string) $value : ''), 0, $max),
                'inline' => self::inline(is_scalar($value) ? (string) $value : '', $max),
                'html' => WpContent::safeHtml(is_scalar($value) ? mb_substr((string) $value, 0, 200000) : ''),
                'kod' => self::code(is_scalar($value) ? mb_substr((string) $value, 0, $max) : ''),
                'odkaz' => self::link(is_scalar($value) ? (string) $value : '', $path . '.' . $key, $errors),
                'obrazek' => is_string($value) && preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300}|\{\{[a-z][a-z0-9_]{0,30}\}\})$#', $value) ? $value : '',
                'vyber' => is_scalar($value) && isset($def['moznosti'][(string) $value]) ? (string) $value : (string) $def['vychozi'],
                'cislo' => is_numeric($value) ? max((int) ($def['min'] ?? 0), min((int) ($def['max'] ?? 100), (int) $value)) : (int) $def['vychozi'],
                'prepinac' => (bool) $value,
                // hodnoty vlastností komponenty: jen klíč => text; podle typu vlastnosti se zkontrolují při vykreslení
                'hodnoty' => array_slice(array_filter(
                    array_map(fn (mixed $v): ?string => is_scalar($v) ? mb_substr((string) $v, 0, 20000) : null, is_array($value) ? $value : []),
                    fn (?string $v, int|string $k): bool => $v !== null && is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,30}$/', $k) === 1,
                    ARRAY_FILTER_USE_BOTH,
                ), 0, 30, true),
                'polozky' => array_slice(array_values(array_map(
                    fn (mixed $item): array => self::sanitizeContent($def['pole'], is_array($item) ? $item : [], $path . '.' . $key, $errors),
                    is_array($value) ? $value : [],
                )), 0, (int) ($def['max'] ?? 30)),
                default => '',
            };
            if ($def['typ'] === 'obrazek' && $value !== '' && $clean[$key] === '') {
                $errors[$path . '.' . $key] = 'Obrázek musí být z Médií (media/…) nebo na adrese https://.';
            }
        }

        return $clean;
    }

    /** Krátký text s tučným písmem, kurzívou, zvýrazněním (mark = doplňková barva, např. tečka za titulkem), zalomením a odkazem – nic dalšího. */
    private static function inline(string $html, int $max): string
    {
        $clean = WpContent::safeHtml('<p>' . mb_substr($html, 0, $max * 2) . '</p>');
        $clean = (string) preg_replace('#^<p>|</p>$#', '', trim($clean));
        $clean = strip_tags($clean, '<strong><b><em><i><mark><br><a><s><sub><sup>');

        return trim(str_replace(['</p>', '<p>'], ['<br>', ''], $clean));
    }

    /**
     * Vlastní HTML správce (prvek jen pro správce): bez skriptů, obsluh událostí a odkazů javascript:. Vložené mapy a formuláře
     * služeb jsou <iframe>, ty zůstávají – není to čistič pro obsah od jiných rolí (na ten je Core\Html::bezpecne).
     */
    public static function code(string $html): string
    {
        // opakovat, dokud se něco mění: vnořené <scr<script></script>ipt> by se po jednom průchodu složilo znovu
        do {
            $before = $html;
            $html = (string) preg_replace(['#<script\b[^>]*>.*?</script\s*>#is', '#<script\b[^>]*>#i', '#</script\s*>#i'], '', $html);
            $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
            $html = (string) preg_replace('#(href|src|action|formaction|srcdoc)\s*=\s*(["\'])\s*(javascript|vbscript|data:text/html)[^"\']*\2#i', '$1="#"', $html);
        } while ($html !== $before);

        return $html;
    }

    private static function link(string $url, string $path, array &$errors): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#' || preg_match('/^\{\{[a-z][a-z0-9_]{0,30}\}\}$/', $url)) {
            return $url; // {{url}} a další pole kolekce: dosadí se a zkontrolují při vykreslení
        }
        if (WpContent::isSafeUrl($url) && !preg_match('/[\s"<>]/', $url)) {
            return mb_substr($url, 0, 500);
        }
        $errors[$path] = 'Odkaz může být jen https://…, mailto:, tel:, #kotva nebo adresa na webu (/…).';

        return '';
    }

    /** @return array<string, array<string, mixed>> id => prvek pro všechny prvky, jejichž třída splní podmínku */
    private static function elementsOfType(array $build, callable $condition): array
    {
        $found = [];
        $walk = function (array $children) use (&$walk, &$found, $condition): void {
            foreach ($children as $p) {
                $className = is_array($p) ? self::className((string) ($p['typ'] ?? '')) : null;
                if ($className !== null && $condition($className) && isset($p['id'])) {
                    $found[(string) $p['id']] = $p;
                }
                if (is_array($p['deti'] ?? null)) {
                    $walk($p['deti']);
                }
            }
        };
        $walk($build['deti'] ?? []);

        return $found;
    }

    /**
     * Vykreslí stavbu: HTML a CSS jen toho, co stránka používá (základ typů, použité třídy, styl prvků) ve vrstvách kaskády.
     * V režimu editoru dostane každý prvek data-ka-id, aby šel na plátně vybrat.
     *
     * @return array{html:string, css:string, faq:list<array{0:string, 1:string}>}
     */
    public static function render(App $app, array $build, bool $editor = false): array
    {
        $k = new Context($app, $editor);
        $html = self::html($build, $k);

        return ['html' => $html, 'css' => self::css($app->db(), $k), 'faq' => $k->faq];
    }

    /** HTML stavby ve sdíleném kontextu stránky (web tak skládá stránku, záhlaví a patičku a CSS vypíše jednou přes css()). */
    public static function html(array $build, Context $k): string
    {
        return self::renderChildren($build['deti'] ?? [], $k);
    }

    private static function renderChildren(array $children, Context $k): string
    {
        $html = '';
        foreach ($children as $p) {
            try {
                $html .= self::renderElement($p, $k);
            } catch (\Throwable $e) {
                // „doktor“: vadný prvek se na webu vynechá, v editoru se ukáže hláška
                error_log('Builder: prvek ' . ($p['id'] ?? '?') . ' – ' . $e->getMessage());
                $html .= $k->editor ? '<div data-ka-id="' . e((string) ($p['id'] ?? '')) . '" style="padding:1rem;border:2px dashed #b3261e;color:#b3261e">' . e(t('Prvek se nepodařilo vykreslit.')) . '</div>' : '';
            }
        }

        return $html;
    }

    /** @param array{prihlaseni?: string, od?: string, do?: string} $conditions */
    public static function meetsConditions(array $conditions, Context $k): bool
    {
        $today = date('Y-m-d');
        if (isset($conditions['od']) && $today < $conditions['od'] || isset($conditions['do']) && $today > $conditions['do']) {
            return false;
        }
        if (isset($conditions['prihlaseni'])) {
            $signedIn = $k->app->auth()->user() !== null;

            return $conditions['prihlaseni'] === 'ano' ? $signedIn : !$signedIn;
        }

        return true;
    }

    private static function renderElement(array $p, Context $k): string
    {
        $className = self::className((string) $p['typ']);
        if ($className === null) {
            return '';
        }
        if (($p['podminky'] ?? []) !== [] && !$k->editor) {
            $k->withoutCache = true;
            if (!self::meetsConditions($p['podminky'], $k)) {
                return '';
            }
        }
        if ($className::EXTENSION !== '' && !\Kaleta\Core\Extensions::isEnabled($k->app->settings(), $className::EXTENSION)) {
            // prvek vypnutého rozšíření (novinky, formulář): na webu nic, v editoru upozornění – stavba zůstává, po zapnutí se vrátí
            return $k->editor ? '<div data-ka-id="' . e((string) ($p['id'] ?? '')) . '" data-ka-typ="' . e($className::TYPE) . '" style="padding:1rem;border:2px dashed currentColor;opacity:.6">'
                . e(t('%s – rozšíření je vypnuté, na webu se nezobrazí.', t($className::NAME))) . '</div>' : '';
        }
        // stavba uložená starší verzí nemusí mít vlastnosti, které prvek dostal později – doplní se výchozí hodnotou
        $p['obsah'] = (is_array($p['obsah'] ?? null) ? $p['obsah'] : []) + array_map(fn (array $field): mixed => $field['vychozi'] ?? '', $className::properties());
        $k->types[$className::TYPE] = true;
        if ($k->item !== null) {
            $p['obsah'] = self::fillItem($className::properties(), $p['obsah'] ?? [], $k->item);
        }
        $children = match (true) {
            $className === Elements\CollectionList::class => Elements\CollectionList::repeat($p, $k, fn (): string => self::renderChildren($p['deti'] ?? [], $k)),
            $className === Elements\Component::class => Elements\Component::inner($p, $k, fn (array $build): string => self::renderChildren($build['deti'] ?? [], $k)),
            $className::CONTAINER => self::renderChildren($p['deti'] ?? [], $k),
            default => '',
        };
        $style = $p['styl'] ?? [];
        $customCss = (string) ($p['css'] ?? '');
        $hasStyle = $style !== [] || $customCss !== '';
        // uvnitř Výpisu kolekce se prvek opakuje: styl přes třídu s-<id>, ne přes id (id musí být na stránce jen jednou)
        $isRepeated = $k->inLoop > 0;
        if ($className === Elements\Modal::class && empty($p['kotva']) && !$isRepeated) {
            $p['kotva'] = 'okno-' . $p['id']; // okno má stálou adresu #okno-…, i když později dostane styl (tlačítka na ni odkazují)
        }
        $id = $isRepeated ? null : ($p['kotva'] ?? ($hasStyle ? 's-' . $p['id'] : null));
        $classes = array_merge($isRepeated && $hasStyle ? ['s-' . $p['id']] : [], $p['tridy'] ?? []);
        if ($hasStyle && !isset($k->styles[$p['id']])) {
            $k->styles[$p['id']] = true;
            $k->css .= Style::css($isRepeated ? '.s-' . $p['id'] : '#' . $id, $style, $customCss, $k->app->request->basePath());
        }
        foreach ($p['tridy'] ?? [] as $t) {
            $k->classes[$t] = true;
        }
        $a = ($id !== null ? ' id="' . e($id) . '"' : '')
            . ($classes !== [] ? ' class="' . e(implode(' ', $classes)) . '"' : '')
            . implode('', array_map(fn (string $n, string $h): string => ' ' . $n . '="' . e($h) . '"', array_keys($p['atributy'] ?? []), $p['atributy'] ?? []))
            . ($k->editor ? ' data-ka-id="' . e((string) $p['id']) . '" data-ka-typ="' . e($className::TYPE) . '"' . (!empty($p['zamek']) ? ' data-ka-zamek' : '') : '');

        return $className::render($p, $a, $children, $k);
    }

    /**
     * Hodnoty položky kolekce do polí obsahu podle jejich typu ({{nazev}} v nadpisu, {{foto}} v obrázku, {{url}} v odkazu…).
     *
     * @param array<string, array<string, mixed>> $properties
     * @param array<string, array{0: string, 1: string}> $values
     */
    private static function fillItem(array $properties, array $content, array $values): array
    {
        foreach ($properties as $key => $def) {
            if (is_string($content[$key] ?? null)) {
                $content[$key] = Collections::fill($content[$key], $def['typ'], $values);
            } elseif ($def['typ'] === 'hodnoty' && is_array($content[$key] ?? null)) {
                // komponenta ve výpisu kolekce: {{pole}} položky v hodnotách vlastností (zkontrolují se až podle typu vlastnosti)
                $content[$key] = array_map(fn (mixed $v): mixed => is_string($v) ? Collections::fill($v, 'text', $values) : $v, $content[$key]);
            } elseif ($def['typ'] === 'polozky' && is_array($content[$key] ?? null)) {
                $content[$key] = array_map(fn (array $item): array => self::fillItem($def['pole'], $item, $values), $content[$key]);
            }
        }

        return $content;
    }

    /** CSS stránky: základ použitých typů, použité třídy (z ka_tridy) a styl jednotlivých prvků – každé ve své vrstvě. */
    public static function css(Db $db, Context $k): string
    {
        // ve stavbě řídí rozestupy mezery kontejnerů (gap), ne okraje nadpisů a odstavců ze šablony; text uvnitř prvku Text je má
        $base = ':where(.stavba) :where(h1, h2, h3, h4, h5, h6, p, ul, ol, blockquote, figure, hr) { margin-block: 0; }' . "\n"
            . ':where(.stavba) :where(.ka-text) > * + * { margin-block-start: 1em; }' . "\n";
        foreach (self::ELEMENTS as $className) {
            if (isset($k->types[$className::TYPE]) && $className::baseCss() !== '') {
                $base .= $className::baseCss() . "\n";
            }
        }
        $classes = '';
        if ($k->classes !== []) {
            $names = array_keys($k->classes);
            foreach ($db->all('SELECT nazev, styl, css FROM {tridy} WHERE nazev IN (' . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY nazev', $names) as $r) {
                $classes .= Style::css('.' . $r['nazev'], json_decode((string) $r['styl'], true) ?: [], Style::customCss((string) $r['css']), $k->app->request->basePath());
            }
        }
        if (preg_match('/animation: ka-(objevit|vyjet|priblizit)/', $k->css . $classes)) {
            // animace „Objevení při rolování“; kdo nechce pohyb (nastavení systému), vidí prvky rovnou
            $base .= '@keyframes ka-objevit { from { opacity: 0; } }' . "\n"
                . '@keyframes ka-vyjet { from { opacity: 0; translate: 0 2.5rem; } }' . "\n"
                . '@keyframes ka-priblizit { from { opacity: 0; scale: 0.92; } }' . "\n"
                . '@media (prefers-reduced-motion: reduce) { :where(.stavba) * { animation: none !important; } }' . "\n";
        }
        $css = DesignSystem::LAYERS . "\n";
        foreach (['stavitel' => $base, 'tridy' => $classes, 'prvky' => $k->css] as $layer => $content) {
            if (trim($content) !== '') {
                $css .= '@layer ' . $layer . " {\n" . $content . "}\n";
            }
        }

        return $css;
    }

    /** Stavba ze stránky: publikovaná, nebo koncept (editor, náhled). Neplatný JSON = null. */
    public static function fromJson(?string $json): ?array
    {
        $data = $json === null ? null : json_decode($json, true);

        return is_array($data) && isset($data['deti']) ? $data : null;
    }

    public static function toJson(array $build): string
    {
        return (string) json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Obsah stavby jako prosté sémantické HTML bez rozložení a stylu (nadpisy, odstavce, seznamy, odkazy, obrázky).
     * Při publikování se ukládá do sloupce text – z něj čerpá hledání, llms.txt, verze .md, API, MCP i export, a je to
     * i obsah stránky, kdyby se vrátila k textu.
     */
    public static function asText(array $build): string
    {
        $html = '';
        $walk = function (array $children) use (&$walk, &$html): void {
            foreach ($children as $p) {
                $o = $p['obsah'] ?? [];
                $z = preg_match('/^h[1-6]$/', $p['znacka'] ?? '') ? $p['znacka'] : 'p';
                $html .= match ($p['typ'] ?? '') {
                    'nadpis' => "<{$z}>" . ($o['text'] ?? '') . "</{$z}>\n",
                    'text' => ($o['html'] ?? '') . "\n",
                    'obrazek' => ($o['src'] ?? '') !== '' ? '<figure><img src="' . e($o['src']) . '" alt="' . e($o['alt'] ?? '') . '">' . (($o['popisek'] ?? '') !== '' ? '<figcaption>' . e($o['popisek']) . '</figcaption>' : '') . "</figure>\n" : '',
                    'tlacitko' => ($o['text'] ?? '') !== '' ? '<p>' . (($o['odkaz'] ?? '') !== '' ? '<a href="' . e($o['odkaz']) . '">' . e($o['text']) . '</a>' : e($o['text'])) . "</p>\n" : '',
                    'seznam' => ($rows = array_filter(array_map('trim', explode("\n", (string) ($o['polozky'] ?? ''))))) !== []
                        ? "<{$p['znacka']}>" . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $rows)) . "</{$p['znacka']}>\n" : '',
                    'citat' => '<blockquote><p>' . ($o['text'] ?? '') . '</p>' . (($o['autor'] ?? '') !== '' ? '<p>– ' . e($o['autor']) . (($o['pozice'] ?? '') !== '' ? ', ' . e($o['pozice']) : '') . '</p>' : '') . "</blockquote>\n",
                    'faq' => implode('', array_map(fn (array $f): string => '<h3>' . e($f['otazka'] ?? '') . '</h3>' . ($f['odpoved'] ?? '') . "\n", $o['polozky'] ?? [])),
                    'video' => ($o['url'] ?? '') !== '' ? '<p><a href="' . e($o['url']) . '">' . e(($o['titulek'] ?? '') !== '' ? $o['titulek'] : $o['url']) . "</a></p>\n" : '',
                    'oddelovac' => "<hr>\n",
                    default => '',
                };
                // vnitřek Výpisu kolekce je vzor se {{značkami}}, ne obsah stránky
                if (is_array($p['deti'] ?? null) && ($p['typ'] ?? '') !== 'kolekce') {
                    $walk($p['deti']);
                }
            }
        };
        $walk($build['deti'] ?? []);

        return trim($html);
    }

    /** Textová stránka převedená na stavbu: jedna úzká sekce s nadpisem a textem (zpět jde přes verze). */
    public static function fromText(string $title, string $html): array
    {
        return ['v' => self::VERSION, 'deti' => [self::fresh('sekce', ['sirka' => 'uzka'], [
            ['znacka' => 'h1'] + self::fresh('nadpis', ['text' => e($title)]),
            self::fresh('text', ['html' => $html !== '' ? $html : '<p></p>']),
        ])]];
    }

    /**
     * Popis schématu pro editor a pro jazykové modely (MCP stavba_schema): typy prvků s poli, vlastnosti stylu a tokeny.
     *
     * @return array<string, mixed>
     */
    /** @param list<string>|null $extensions zapnutá rozšíření (null = všechna) – prvky vypnutých se nenabízejí */
    public static function schema(bool $admin = true, string $language = 'cs', bool $parts = false, ?array $extensions = null): array
    {
        // výchozí obsah nových prvků je v jazyce stránky, popisky polí překládá editor do jazyka administrace
        return \Kaleta\Core\Language::runWith($language, fn (): array => self::buildSchema($admin, $parts, $extensions));
    }

    /**
     * Schéma ve zkratce pro jazykový model (MCP): prvek i vlastnost stylu na jednom řádku. Výčty mají výchozí hodnotu
     * označenou hvězdičkou, položky (polozky) vypíšou svá pole v hranatých závorkách. Úplné definice vybraných prvků
     * (popisky, výchozí děti) vrací schéma s parametrem prvky.
     *
     * @param array<string, mixed> $schema výstup schema()
     * @return array<string, mixed>
     */
    public static function overview(array $schema): array
    {
        $field = function (array $properties) use (&$field): string {
            $parts = [];
            foreach ($properties as $key => $v) {
                $description = $key . ':' . $v['typ'];
                if (isset($v['moznosti']) && is_array($v['moznosti'])) {
                    $description .= '(' . implode('|', array_map(fn (string|int $m): string => (string) $m . ((string) $m === (string) ($v['vychozi'] ?? '') ? '*' : ''), array_keys($v['moznosti']))) . ')';
                } elseif (isset($v['pole']) && is_array($v['pole'])) {
                    $description .= '[' . $field($v['pole']) . ']';
                } elseif (in_array($v['typ'], ['prepinac', 'cislo'], true) && isset($v['vychozi'])) {
                    $description .= '=' . var_export($v['vychozi'], true);
                }
                $parts[] = $description;
            }

            return implode('; ', $parts);
        };
        $elements = [];
        foreach ($schema['prvky'] as $p) {
            $style = [];
            foreach ((array) $p['vychozi_styl'] as $state => $properties) {
                foreach ((array) $properties as $k => $h) {
                    $style[] = ($state === 'zaklad' ? '' : $state . '.') . $k . '=' . $h;
                }
            }
            $elements[$p['typ']] = $p['nazev'] . ' – ' . $p['popis'] . ($p['kontejner'] ? ' [KONTEJNER]' : '') . ' | značky: ' . implode(',', $p['znacky'])
                . (is_array($p['vlastnosti']) && $p['vlastnosti'] !== [] ? ' | obsah: ' . $field($p['vlastnosti']) : '')
                . ($style !== [] ? ' | styl nového prvku v builderu: ' . implode(', ', $style) . ' (v JSON ho uveď sám, jinak ho prvek nemá)' : '');
        }
        $style = [];
        foreach ($schema['styl'] as $key => $v) {
            $style[$key] = $v['css'] . ': ' . (isset($v['moznosti']) ? implode('|', array_keys($v['moznosti'])) : $v['typ']);
        }

        return ['verze' => $schema['verze'], 'prvky' => $elements, 'styl' => $style, 'stavy' => $schema['stavy'], 'tokeny' => $schema['tokeny'],
            'typy_hodnot' => [
                'mezera' => 'token ' . implode('|', $schema['tokeny']['mezery']) . ' nebo délka (1.5rem)', 'krok' => 'velikost písma: token ' . implode('|', $schema['tokeny']['kroky']) . ' nebo délka',
                'barva' => 'token (' . implode('|', array_keys($schema['tokeny']['barvy'])) . ') nebo #hex', 'zaobleni' => 'token ' . implode('|', array_map('strval', $schema['tokeny']['zaobleni'])) . ' nebo délka',
                'stin' => 'token s|m|l, none nebo „x y rozostření barva“', 'ramecek' => 'výčet nebo „2px solid barva“', 'delka' => 'px, rem, %, vw, fr, auto, min()/max()/clamp()/calc()',
                'sloupce' => 'číslo 1–12, „auto:16rem“ (kolik se vejde) nebo „2fr 1fr“', 'radky' => 'číslo nebo „auto 1fr“', 'oblasti' => 'řádky oddělené /, např. „a a / b c“',
            ],
            'uzel' => '{"id":"(nepovinné, zachovej při úpravách)","typ":"…","znacka":"(jedna ze značek; výchozí první)","obsah":{…},"styl":{"zaklad":{…},"tablet":{…},"mobil":{…},"hover":{…}},"tridy":["…"],"kotva":"id-pro-odkaz","deti":[…]}; prázdná pole a výchozí hodnoty vynech',
            'pravidla' => $schema['pravidla']];
    }

    /**
     * Stavba bez toho, co doplní vycisti(): výchozí obsah, výchozí značka, prázdný styl, třídy a děti. Styl zůstává celý –
     * výchozí styl typu (flex kontejneru) dostane jen nový prvek z builderu, uložený prvek bez stylu by ho ztratil. Pro výstup do MCP –
     * model čte i posílá jen to, co je nastavené (stejná stavba má zhruba třetinovou délku).
     *
     * @param array<string, mixed> $build
     * @return array<string, mixed>
     */
    public static function compact(array $build): array
    {
        $node = function (array $p) use (&$node): array {
            $className = self::className((string) ($p['typ'] ?? ''));
            if ($className !== null) {
                foreach ($className::properties() as $key => $v) {
                    if (array_key_exists($key, $p['obsah'] ?? []) && $p['obsah'][$key] === ($v['vychozi'] ?? null)) {
                        unset($p['obsah'][$key]);
                    }
                }
                if (($p['znacka'] ?? null) === $className::HTML_TAGS[0]) {
                    unset($p['znacka']);
                }
            }
            foreach (['obsah', 'styl', 'tridy', 'deti', 'podminky', 'atributy'] as $key) {
                if (array_key_exists($key, $p) && ($p[$key] === [] || $p[$key] === null || $p[$key] === '')) {
                    unset($p[$key]);
                }
            }
            if (isset($p['deti'])) {
                $p['deti'] = array_map($node, $p['deti']);
            }

            return $p;
        };

        return ['v' => $build['v'] ?? self::VERSION, 'deti' => array_map($node, $build['deti'] ?? [])];
    }

    /** Typy vlastností prvku, které nesou text pro návštěvníka nebo odkaz (překlad stavby). */
    private const array TEXT_PROPERTIES = ['text', 'odkaz', 'radky', 'html', 'inline', 'textarea', 'polozky', 'souhlas'];

    /** Textové vlastnosti, které jsou nastavení, ne text pro návštěvníka (klíče kolekcí a polí, e-mail, datum, číslo hodnocení). */
    private const array TECHNICAL_PROPERTIES = ['kolekce', 'razeni_pole', 'filtr_pole', 'komponenta', 'kategorie', 'prijemce', 'cil', 'hodnota'];

    /**
     * Texty stavby pro překlad: prvky s id a jen ty vlastnosti obsahu, které nesou text nebo odkaz (bez stylů a struktury),
     * a popisky pro čtečky v atributech. Změny se vrací operací „uprav“ podle id (Upravy).
     *
     * @param array<string, mixed> $build
     * @return list<array{id: string, typ: string, obsah?: array<string, mixed>, atributy?: array<string, string>}>
     */
    public static function texts(array $build): array
    {
        $result = [];
        $walk = function (array $elements) use (&$walk, &$result): void {
            foreach ($elements as $p) {
                $className = self::className((string) ($p['typ'] ?? ''));
                $content = [];
                foreach ($className !== null ? $className::properties() : [] as $key => $v) {
                    $value = $p['obsah'][$key] ?? null;
                    if (in_array($v['typ'] ?? '', self::TEXT_PROPERTIES, true) && !in_array($key, self::TECHNICAL_PROPERTIES, true) && $value !== null && $value !== '' && $value !== []) {
                        $content[$key] = $value;
                    }
                }
                $attributes = array_intersect_key(is_array($p['atributy'] ?? null) ? $p['atributy'] : [], ['aria-label' => 1, 'title' => 1]);
                if (($content !== [] || $attributes !== []) && isset($p['id'])) {
                    $result[] = ['id' => (string) $p['id'], 'typ' => (string) $p['typ']] + ($content !== [] ? ['obsah' => $content] : []) + ($attributes !== [] ? ['atributy' => $attributes] : []);
                }
                $walk(is_array($p['deti'] ?? null) ? $p['deti'] : []);
            }
        };
        $walk($build['deti'] ?? []);

        return $result;
    }

    /** @param list<string>|null $extensions */
    public static function disabledTypes(?array $extensions): array
    {
        $types = [];
        foreach (self::ELEMENTS as $className) {
            if ($extensions !== null && $className::EXTENSION !== '' && !in_array($className::EXTENSION, $extensions, true)) {
                $types[] = $className::TYPE;
            }
        }

        return $types;
    }

    /** @return array<string, mixed> */
    private static function buildSchema(bool $admin, bool $parts, ?array $extensions): array
    {
        $elements = [];
        $disabled = self::disabledTypes($extensions);
        foreach (self::ELEMENTS as $className) {
            if (($className::ADMIN_ONLY && !$admin) || ($className::PARTS_ONLY && !$parts) || in_array($className::TYPE, $disabled, true)) {
                continue;
            }
            $elements[] = [
                'typ' => $className::TYPE, 'nazev' => $className::NAME, 'popis' => $className::DESCRIPTION, 'ikona' => $className::ICON, 'skupina' => $className::GROUP,
                'kontejner' => $className::CONTAINER, 'znacky' => $className::HTML_TAGS, 'vlastnosti' => $className::properties(), 'vychozi_styl' => $className::defaultStyle() ?: new \stdClass(),
                'vychozi_deti' => $className::defaultChildren(),
            ];
        }
        $style = [];
        foreach (Style::PROPERTIES as $key => [$css, $type, $group, $labelText, $options]) {
            $style[$key] = ['css' => $css, 'typ' => $type, 'skupina' => $group, 'popisek' => $labelText] + ($options !== null ? ['moznosti' => $options] : []);
        }

        return [
            'verze' => self::VERSION, 'prvky' => $elements, 'styl' => $style, 'skupiny_stylu' => Style::GROUPS, 'stavy' => array_keys(Style::STATUSES),
            'tokeny' => ['barvy' => DesignSystem::COLOR_TOKENS, 'mezery' => array_keys(DesignSystem::SPACES), 'kroky' => DesignSystem::STEPS,
                'zaobleni' => array_keys(DesignSystem::RADII), 'stiny' => array_keys(DesignSystem::SHADOWS)],
            'pravidla' => [
                'Jeden prvek = jedna HTML značka; sekce má nejvýš jeden vnitřní obal. Obsah stránky skládej ze sekcí.',
                'Styl ber z tokenů (mezera „l“, barva „primarni“, krok „2“); volnou hodnotu jen když token nestačí.',
                'Styl má stavy zaklad, tablet (do 1023 px), mobil (do 767 px), hover; úprava jednoho stavu nemění ostatní.',
                'Opakovaný vzhled (karty, štítky) dej do třídy, ne do stylu každého prvku.',
                'Sloupce mřížky: číslo („3“), „auto:16rem“ (kolik se vejde) nebo poměr („2fr 1fr“).',
            ],
        ];
    }
}
