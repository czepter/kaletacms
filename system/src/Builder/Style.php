<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Style of an element or class: {"zaklad": {...}, "tablet": {...}, "mobil": {...}, "hover": {...}}.
 * The properties are curated (PROPERTIES) and prefer design system tokens (spacing „l“, color „primarni“, step „2“);
 * a free value works too, but only in a safe form. Editing one breakpoint never touches another.
 */
final class Style
{
    /** Breakpoints and states: key => media query or pseudo-class (empty = base). */
    public const array STATUSES = [
        'zaklad' => '',
        'tablet' => '@media (max-width: 1023px)',
        'mobil' => '@media (max-width: 767px)',
        'hover' => ':hover',     // also applies to keyboard focus (:focus-visible) – whoever does not use a mouse sees the same
        'aktivni' => ':active',  // press (button, card link)
        // state on a smaller screen: hover and press can be fine-tuned separately for tablet and mobile
        'hover_tablet' => '@media (max-width: 1023px)',
        'hover_mobil' => '@media (max-width: 767px)',
        'aktivni_tablet' => '@media (max-width: 1023px)',
        'aktivni_mobil' => '@media (max-width: 767px)',
    ];

    /** Where a state inherits a value it does not have itself (the editor shows it in grey): the nearest first. */
    public const array INHERITANCE = [
        'zaklad' => [], 'tablet' => ['zaklad'], 'mobil' => ['tablet', 'zaklad'],
        'hover' => ['zaklad'], 'hover_tablet' => ['hover', 'tablet', 'zaklad'], 'hover_mobil' => ['hover_tablet', 'hover', 'mobil', 'tablet', 'zaklad'],
        'aktivni' => ['hover', 'zaklad'], 'aktivni_tablet' => ['aktivni', 'hover_tablet', 'hover', 'tablet', 'zaklad'],
        'aktivni_mobil' => ['aktivni_tablet', 'aktivni', 'hover_mobil', 'hover_tablet', 'hover', 'mobil', 'tablet', 'zaklad'],
    ];

    /**
     * key => [CSS property, type, group, label, enumeration options]
     * Types: mezera | delka | barva | krok | zaobleni | stin | ramecek | vyber | cislo | obrazek | sloupce | radky | oblasti | oblast | text
     */
    public const array PROPERTIES = [
        // layout
        'zobrazeni' => ['display', 'vyber', 'rozlozeni', 'Zobrazení', ['block' => 'blok', 'flex' => 'flex (řada / sloupec)', 'grid' => 'mřížka', 'none' => 'skrýt']],
        'smer' => ['flex-direction', 'vyber', 'rozlozeni', 'Směr', ['row' => 'vedle sebe', 'column' => 'pod sebou', 'row-reverse' => 'vedle sebe obráceně', 'column-reverse' => 'pod sebou obráceně']],
        'zalamovani' => ['flex-wrap', 'vyber', 'rozlozeni', 'Zalamování', ['wrap' => 'zalamovat', 'nowrap' => 'nezalamovat']],
        'sloupce' => ['grid-template-columns', 'sloupce', 'rozlozeni', 'Sloupce mřížky', null],
        'radky' => ['grid-template-rows', 'radky', 'rozlozeni', 'Řádky mřížky', null],
        'oblasti' => ['grid-template-areas', 'oblasti', 'rozlozeni', 'Oblasti mřížky', null],
        'oblast' => ['grid-area', 'oblast', 'rozlozeni', 'Oblast v mřížce (název)', null],
        'rozpeti_sloupcu' => ['grid-column', 'vyber', 'rozlozeni', 'Přes sloupce (v mřížce)', ['span 2' => '2 sloupce', 'span 3' => '3 sloupce', 'span 4' => '4 sloupce', '1 / -1' => 'celá šířka']],
        'rozpeti_radku' => ['grid-row', 'vyber', 'rozlozeni', 'Přes řádky (v mřížce)', ['span 2' => '2 řádky', 'span 3' => '3 řádky', 'span 4' => '4 řádky']],
        'mezera' => ['gap', 'mezera', 'rozlozeni', 'Mezera mezi prvky', null],
        'zarovnani' => ['align-items', 'vyber', 'rozlozeni', 'Zarovnání (příčně)', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'stretch' => 'roztáhnout', 'baseline' => 'účaří']],
        'rozmisteni' => ['justify-content', 'vyber', 'rozlozeni', 'Rozmístění (hlavní osa)', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'space-between' => 'do krajů', 'space-around' => 'rovnoměrně']],
        'vlastni_zarovnani' => ['align-self', 'vyber', 'rozlozeni', 'Vlastní zarovnání', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'stretch' => 'roztáhnout']],
        'poradi' => ['order', 'cislo', 'rozlozeni', 'Pořadí', null],
        'rust' => ['flex', 'vyber', 'rozlozeni', 'Roztažení ve flexu', ['1 1 0%' => 'vyplnit místo', '0 0 auto' => 'podle obsahu']],
        // dimensions
        'sirka' => ['width', 'delka', 'rozmery', 'Šířka', null],
        'max_sirka' => ['max-width', 'delka', 'rozmery', 'Max. šířka', null],
        'vyska' => ['height', 'delka', 'rozmery', 'Výška', null],
        'min_vyska' => ['min-height', 'delka', 'rozmery', 'Min. výška', null],
        'pomer_stran' => ['aspect-ratio', 'vyber', 'rozmery', 'Poměr stran', ['1' => '1 : 1', '4/3' => '4 : 3', '3/2' => '3 : 2', '16/9' => '16 : 9', '21/9' => '21 : 9', '3/4' => '3 : 4']],
        'prizpusobeni' => ['object-fit', 'vyber', 'rozmery', 'Přizpůsobení obrázku', ['cover' => 'vyplnit (oříznout)', 'contain' => 'celý obrázek']],
        'na_stred' => ['margin-inline', 'vyber', 'rozmery', 'Na střed', ['auto' => 'ano']],
        // spacing
        'odsazeni_y' => ['padding-block', 'mezera', 'mezery', 'Vnitřní odsazení nahoře a dole', null],
        'odsazeni_x' => ['padding-inline', 'mezera', 'mezery', 'Vnitřní odsazení vlevo a vpravo', null],
        'okraj_nahore' => ['margin-block-start', 'mezera', 'mezery', 'Vnější okraj nahoře', null],
        'okraj_dole' => ['margin-block-end', 'mezera', 'mezery', 'Vnější okraj dole', null],
        'okraj_vlevo' => ['margin-inline-start', 'mezera', 'mezery', 'Vnější okraj vlevo', null],
        'okraj_vpravo' => ['margin-inline-end', 'mezera', 'mezery', 'Vnější okraj vpravo', null],
        // typography: first the named style from Appearance, the individual properties below it fine-tune it
        'typ_styl' => ['font', 'vyber', 'typografie', 'Typografický styl', [
            'titulek' => 'Hlavní titulek', 'nadpis-sekce' => 'Nadpis sekce', 'podnadpis' => 'Podnadpis', 'perex' => 'Perex',
            'text' => 'Běžný text', 'drobny' => 'Drobný text', 'nadtitulek' => 'Nadtitulek',
        ]],
        'velikost_pisma' => ['font-size', 'krok', 'typografie', 'Velikost písma', null],
        'tloustka_pisma' => ['font-weight', 'vyber', 'typografie', 'Tloušťka písma', ['300' => 'tenké', '400' => 'normální', '500' => 'střední', '600' => 'polotučné', '700' => 'tučné', '800' => 'extra tučné']],
        'pismo' => ['font-family', 'vyber', 'typografie', 'Písmo', ['var(--ka-pismo-text)' => 'textové', 'var(--ka-pismo-titulky)' => 'titulkové']],
        'zarovnani_textu' => ['text-align', 'vyber', 'typografie', 'Zarovnání textu', ['start' => 'vlevo', 'center' => 'na střed', 'end' => 'vpravo']],
        'radkovani' => ['line-height', 'vyber', 'typografie', 'Řádkování', ['1.1' => 'těsné', '1.3' => 'menší', '1.6' => 'běžné', '1.8' => 'volné']],
        'velka_pismena' => ['text-transform', 'vyber', 'typografie', 'Velká písmena', ['uppercase' => 'VELKÁ', 'none' => 'normální']],
        'proklad' => ['letter-spacing', 'vyber', 'typografie', 'Proklad písmen', ['-0.02em' => 'užší', '0' => 'normální', '0.06em' => 'širší', '0.12em' => 'široký']],
        'max_radek' => ['max-width', 'vyber', 'typografie', 'Délka řádku', ['var(--ka-sirka-textu)' => 'pohodlná pro čtení', '20ch' => 'krátká (titulek)', '60ch' => '60 znaků']],
        'barva' => ['color', 'barva', 'typografie', 'Barva textu', null],
        // background and border
        'pozadi' => ['background-color', 'barva', 'pozadi', 'Barva pozadí', null],
        'obrazek_pozadi' => ['background-image', 'obrazek', 'pozadi', 'Obrázek pozadí', null],
        'prechod' => ['background-image', 'vyber', 'pozadi', 'Barevný přechod', [
            'linear-gradient(135deg, var(--ka-barva-primarni), var(--ka-barva-sekundarni))' => 'hlavní → doplňková',
            'linear-gradient(180deg, var(--ka-barva-primarni-jemna), var(--ka-barva-pozadi))' => 'jemně shora',
            'linear-gradient(180deg, var(--ka-barva-pozadi), var(--ka-barva-plocha))' => 'pozadí → plocha',
            'radial-gradient(circle at 25% 15%, var(--ka-barva-primarni-jemna), transparent 60%)' => 'záře v rohu',
            'linear-gradient(180deg, transparent, rgb(0 0 0 / 0.55))' => 'ztmavení dole (na fotku)',
        ]],
        'paralaxa' => ['background-attachment', 'vyber', 'pozadi', 'Obrázek pozadí při posunu', ['fixed' => 'stojí (paralaxa)', 'scroll' => 'posouvá se s obsahem']],
        'prekryv' => ['--ka-prekryv', 'barva', 'pozadi', 'Překryv obrázku (barva)', null],
        'ramecek' => ['border', 'ramecek', 'pozadi', 'Rámeček', ['none' => 'žádný', '1px solid var(--ka-barva-linka)' => 'tenký', '2px solid currentColor' => 'výrazný', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'barva_ramecku' => ['border-color', 'barva', 'pozadi', 'Barva rámečku', null],
        'linka_nahore' => ['border-block-start', 'vyber', 'pozadi', 'Linka nahoře', ['none' => 'žádná', '1px solid var(--ka-barva-linka)' => 'tenká', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'linka_dole' => ['border-block-end', 'vyber', 'pozadi', 'Linka dole', ['none' => 'žádná', '1px solid var(--ka-barva-linka)' => 'tenká', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'zaobleni' => ['border-radius', 'zaobleni', 'pozadi', 'Zaoblení rohů', null],
        'stin' => ['box-shadow', 'stin', 'pozadi', 'Stín', null],
        'pruhlednost' => ['opacity', 'vyber', 'pozadi', 'Průhlednost', ['1' => 'žádná', '0.8' => '80 %', '0.6' => '60 %', '0.4' => '40 %']],
        'orez' => ['overflow', 'vyber', 'pozadi', 'Přesah obsahu', ['hidden' => 'oříznout', 'visible' => 'nechat']],
        'pozice' => ['position', 'vyber', 'pokrocile', 'Umístění', ['relative' => 'běžné (kotva pro vnořené)', 'sticky' => 'přilepit při posunu', 'absolute' => 'volně v nadřazeném', 'fixed' => 'pevně v okně']],
        'odshora' => ['top', 'mezera', 'pokrocile', 'Odshora', null],
        'zdola' => ['bottom', 'mezera', 'pokrocile', 'Zdola', null],
        'zleva' => ['left', 'mezera', 'pokrocile', 'Zleva', null],
        'zprava' => ['right', 'mezera', 'pokrocile', 'Zprava', null],
        'posun' => ['translate', 'vyber', 'pokrocile', 'Posun', ['0 -4px' => 'nadzvednout', '0 -0.5rem' => 'nadzvednout víc', '0 4px' => 'snížit', '-50% -50%' => 'vycentrovat (u volného umístění)']],
        'meritko' => ['scale', 'vyber', 'pokrocile', 'Měřítko', ['0.95' => '95 %', '1' => '100 %', '1.03' => '103 %', '1.05' => '105 %', '1.1' => '110 %']],
        'otoceni' => ['rotate', 'vyber', 'pokrocile', 'Otočení', ['-3deg' => '−3°', '3deg' => '3°', '-90deg' => '−90°', '90deg' => '90°', '180deg' => '180°']],
        'plynule' => ['transition', 'vyber', 'pokrocile', 'Plynulá změna (u najetí)', ['all 0.2s ease' => 'rychlá', 'all 0.4s ease' => 'pomalejší', 'none' => 'žádná']],
        'vrstva' => ['z-index', 'cislo', 'pokrocile', 'Vrstva (nad ostatním obsahem)', null],
        // reveal on scroll: an animation driven by page scrolling (CSS scroll-driven), without JavaScript; where the browser cannot do it, the element is visible right away
        'animace' => ['animation', 'vyber', 'pokrocile', 'Objevení při rolování', ['ka-objevit' => 'prolnutí', 'ka-vyjet' => 'vyjetí zdola', 'ka-priblizit' => 'přiblížení', 'none' => 'žádné']],
    ];

    public const array GROUPS = ['rozlozeni' => 'Rozložení', 'rozmery' => 'Rozměry', 'mezery' => 'Mezery', 'typografie' => 'Typografie', 'pozadi' => 'Pozadí a rámeček', 'pokrocile' => 'Pokročilé'];

    /** Safe form of a free value: numbers with units, keywords, calc/min/max/clamp, var(--ka-…). Never ; { } < > \ or url(). */
    private const string FREE_VALUE_PATTERN = '/^(?!.*(?:url|expression|javascript|@import))[-a-z0-9 .,%()#+*\/]{1,80}$/i';
    private const string LENGTH_PATTERN = '/^(auto|0|-?\d{1,5}(\.\d{1,4})?(px|rem|em|%|vw|vh|svh|dvh|ch|fr)|(min|max|clamp|calc)\([-a-z0-9 .,%+*\/()]{1,70}\)|var\(--ka-[a-z0-9-]{1,40}\)|fit-content|min-content|max-content)$/i';

    /**
     * Sanitizes a style: it knows only the states from STATUSES and the properties from PROPERTIES; an invalid value is discarded and written to $errors.
     *
     * @param array<string, string> $errors path => error text (for the editor's and MCP's message)
     * @return array<string, array<string, string>>
     */
    public static function sanitize(mixed $style, string $path = '', array &$errors = []): array
    {
        $clean = [];
        foreach (is_array($style) ? $style : [] as $state => $properties) {
            if (!isset(self::STATUSES[$state]) || !is_array($properties)) {
                $errors[$path . '.' . $state] = 'Neznámý breakpoint nebo stav (povolené: ' . implode(', ', array_keys(self::STATUSES)) . ').';
                continue;
            }
            foreach ($properties as $key => $value) {
                if (!isset(self::PROPERTIES[$key])) {
                    $errors[$path . '.' . $state . '.' . $key] = 'Neznámá vlastnost stylu.';
                    continue;
                }
                $value = is_scalar($value) ? trim((string) $value) : '';
                if ($value === '') {
                    continue;
                }
                if (self::value($key, $value) === null) {
                    $errors[$path . '.' . $state . '.' . $key] = 'Neplatná hodnota „' . mb_substr($value, 0, 40) . '“.';
                    continue;
                }
                $clean[$state][$key] = $value;
            }
        }

        return $clean;
    }

    /** CSS value for a stored property value; null = invalid. */
    public static function value(string $key, string $value): ?string
    {
        [, $type, , , $options] = self::PROPERTIES[$key];

        return match ($type) {
            'vyber' => isset($options[$value]) ? $value : null,
            'mezera' => isset(DesignSystem::SPACES[$value]) ? 'var(--ka-mezera-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'delka' => preg_match(self::LENGTH_PATTERN, $value) ? $value : null,
            'krok' => in_array($value, DesignSystem::STEPS, true) ? 'var(--ka-krok-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'barva' => self::color($value),
            'zaobleni' => isset(DesignSystem::RADII[$value]) ? 'var(--ka-zaobleni-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'stin' => isset(DesignSystem::SHADOWS[$value]) ? 'var(--ka-stin-' . $value . ')' : ($value === 'none' ? 'none' : self::shadow($value)),
            'ramecek' => isset($options[$value]) ? $value : self::border($value),
            'radky' => preg_match('/^([1-9]|1[0-2])$/', $value) ? 'repeat(' . $value . ', auto)' : (preg_match('/^((\d{1,2}(\.\d)?fr|auto|min-content|max-content|\d{1,4}(px|rem))\s?){1,8}$/', $value) ? trim($value) : null),
            'oblasti' => self::areas($value),
            'oblast' => preg_match('/^[a-z][a-z0-9-]{0,20}$/', $value) ? $value : null,
            'cislo' => preg_match('/^-?\d{1,3}$/', $value) ? $value : null,
            'sloupce' => self::columns($value),
            'obrazek' => preg_match('#^(https://[^\s"\'()<>\\\\]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $value) ? $value : null,
            default => preg_match(self::FREE_VALUE_PATTERN, $value) ? $value : null,
        };
    }

    /**
     * A CSS declaration as style properties (converting <style> from HTML to class states – breakpoints and hover). Tokens are returned
     * as keys ("var(--ka-mezera-l)" → "l"), the padding/margin shorthands are expanded. What has no counterpart in the style returns null.
     *
     * @return array<string, string>|null style key => value
     */
    public static function fromCss(string $property, string $value): ?array
    {
        $property = strtolower(trim($property));
        $value = trim((string) preg_replace('/\s*!important$/i', '', trim($value)));
        // common notations the builder knows under a logical name: margin-top → margin-block-start, flex-start → start
        $property = ['margin-top' => 'margin-block-start', 'margin-bottom' => 'margin-block-end', 'margin-left' => 'margin-inline-start', 'margin-right' => 'margin-inline-end'][$property] ?? $property;
        // the background shorthand with only a color (background: #EFECE5) is the background color
        if ($property === 'background' && preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([^()]*\)|var\(--ka-barva-[a-z0-9-]+\)|[a-z]+)$/i', $value)) {
            $property = 'background-color';
        }
        if (in_array($property, ['align-items', 'align-self', 'justify-content'], true)) {
            $value = ['flex-start' => 'start', 'flex-end' => 'end'][$value] ?? $value;
        }
        if ($property === 'text-align') {
            $value = ['left' => 'start', 'right' => 'end'][$value] ?? $value;
        }
        $token = static fn (string $h): string => (string) preg_replace_callback('/var\(--ka-(mezera|krok|zaobleni|stin|barva)-([a-z0-9-]{1,20})\)/',
            static fn (array $m): string => match ($m[1]) {
                'mezera' => isset(DesignSystem::SPACES[$m[2]]) ? $m[2] : $m[0],
                'krok' => in_array($m[2], DesignSystem::STEPS, true) ? $m[2] : $m[0],
                'zaobleni' => isset(DesignSystem::RADII[$m[2]]) ? (string) $m[2] : $m[0],
                'stin' => isset(DesignSystem::SHADOWS[$m[2]]) ? $m[2] : $m[0],
                default => isset(DesignSystem::COLOR_TOKENS[$m[2]]) ? $m[2] : $m[0],
            }, $h);
        $pairs = static function (string $h): ?array {
            $parts = preg_split('/\s+/', trim($h)) ?: [];

            return match (count($parts)) {
                1 => [$parts[0], $parts[0]],
                2 => [$parts[0], $parts[1]],
                3 => $parts[0] === $parts[2] ? [$parts[0], $parts[1]] : null,
                4 => $parts[0] === $parts[2] && $parts[1] === $parts[3] ? [$parts[0], $parts[1]] : null,
                default => null,
            };
        };
        if (in_array($property, ['padding', 'margin'], true)) {
            $args = $pairs($token($value));
            if ($args === null) {
                return null;
            }
            $keys = $property === 'padding' ? ['odsazeni_y', 'odsazeni_x'] : null;
            if ($keys === null) {
                $result = [];
                foreach (['okraj_nahore' => $args[0], 'okraj_dole' => $args[0], 'okraj_vlevo' => $args[1], 'okraj_vpravo' => $args[1]] as $k => $h) {
                    if ($h === 'auto' && str_starts_with($k, 'okraj_v')) {
                        $result['na_stred'] = 'auto';
                        continue;
                    }
                    if (self::value($k, $h) === null) {
                        return null;
                    }
                    $result[$k] = $h;
                }

                return $result;
            }

            return self::value($keys[0], $args[0]) !== null && self::value($keys[1], $args[1]) !== null ? [$keys[0] => $args[0], $keys[1] => $args[1]] : null;
        }
        $value = $token($value);
        if ($property === 'transform') {
            // shift and enlargement (hover effect) have their own properties translate and scale in the style
            $value = match (true) {
                (bool) preg_match('/^translateY\(([^()]+)\)$/', $value, $m) => '0 ' . trim($m[1]),
                (bool) preg_match('/^translate\(([^(),]+),\s*([^(),]+)\)$/', $value, $m) => trim($m[1]) . ' ' . trim($m[2]),
                (bool) preg_match('/^scale\(([\d.]+)\)$/', $value, $m) => $m[1],
                default => '',
            };
            $property = str_contains($value, ' ') ? 'translate' : 'scale';
        }
        if ($property === 'grid-template-columns') {
            if (preg_match('/^repeat\(\s*([1-9]|1[0-2])\s*,\s*(minmax\(0,\s*1fr\)|1fr)\s*\)$/', $value, $m)) {
                $value = $m[1];
            } elseif (preg_match('/^repeat\(\s*auto-(fit|fill)\s*,\s*minmax\(\s*(?:min\(100%,\s*)?(\d{1,3}(?:\.\d{1,2})?(?:rem|px|ch))\)?\s*,\s*1fr\s*\)\s*\)$/', $value, $m)) {
                $value = 'auto:' . $m[2];
            } elseif (preg_match('/^1fr$/', $value)) {
                $value = '1';
            }
        }
        foreach (self::PROPERTIES as $key => [$css]) {
            if ($css === $property && self::value($key, $value) !== null) {
                return [$key => $value];
            }
        }

        return null;
    }

    /** Color: a design system token, or a safely written custom color. */
    public static function color(string $value): ?string
    {
        if (isset(DesignSystem::COLOR_TOKENS[$value])) {
            return 'var(--ka-barva-' . $value . ')';
        }

        return preg_match('/^(#[0-9a-f]{3,8}|transparent|currentColor|(rgba?|hsla?|oklch|oklab|lab|lch|hwb)\([0-9., %\/+-]{3,60}\)|var\(--ka-barva-[a-z-]{1,30}\))$/i', $value) ? $value : null;
    }

    /**
     * Custom shadow from the shadow editor: up to three layers "[inset] x y [blur] [spread] color" (the color also as a token, e.g. "0 8px 24px primarni").
     */
    private static function shadow(string $value): ?string
    {
        $layers = preg_split('/,(?![^(]*\))/', $value) ?: [];
        if (count($layers) > 3) {
            return null;
        }
        $css = [];
        foreach ($layers as $layer) {
            if (!preg_match('/^\s*(inset\s+)?((?:-?\d{1,3}(?:\.\d{1,2})?(?:px|rem|em)?\s+){2,4})(\S.*?)\s*$/i', $layer, $m) || ($color = self::color($m[3])) === null) {
                return null;
            }
            $css[] = $m[1] . trim($m[2]) . ' ' . $color;
        }

        return $css === [] ? null : implode(', ', $css);
    }

    /** Custom border: "2px dashed primarni" – width, line style and color (token or custom). */
    private static function border(string $value): ?string
    {
        if (!preg_match('/^(\d{1,2}(?:\.\d)?px)\s+(solid|dashed|dotted|double)\s+(\S+)$/', $value, $m) || ($color = self::color($m[3])) === null) {
            return null;
        }

        return $m[1] . ' ' . $m[2] . ' ' . $color;
    }

    /** Grid areas: rows separated by "/", area names in a row (a dot = an empty cell); all rows of the same length. */
    private static function areas(string $value): ?string
    {
        $rows = array_map(fn (string $r): array => preg_split('/\s+/', trim($r)) ?: [], explode('/', $value));
        if (count($rows) > 8 || count(array_unique(array_map('count', $rows))) !== 1 || count($rows[0]) > 12) {
            return null;
        }
        foreach ($rows as $row) {
            foreach ($row as $name) {
                if (!preg_match('/^([a-z][a-z0-9-]{0,20}|\.)$/', $name)) {
                    return null;
                }
            }
        }

        return implode(' ', array_map(fn (array $r): string => '"' . implode(' ', $r) . '"', $rows));
    }

    /** Grid columns: "3" = three equal ones, "auto:16rem" = as many as fit at min. 16rem, "2fr 1fr" = a custom ratio. */
    private static function columns(string $value): ?string
    {
        if (preg_match('/^([1-9]|1[0-2])$/', $value)) {
            return 'repeat(' . $value . ', minmax(0, 1fr))';
        }
        if (preg_match('/^auto:(\d{1,3}(\.\d{1,2})?)(rem|px|ch)$/', $value, $m)) {
            return 'repeat(auto-fit, minmax(min(100%, ' . $m[1] . $m[3] . '), 1fr))';
        }

        return preg_match('/^((\d{1,2}(\.\d)?fr|auto|\d{1,4}(px|rem))\s?){1,6}$/', $value) ? trim($value) : null;
    }

    /**
     * CSS for a selector from the style: base, :hover, then breakpoints (from larger to smaller, so that the smaller wins).
     *
     * @param array<string, array<string, string>> $style
     */
    public static function css(string $selector, array $style, string $customCss = '', string $base = ''): string
    {
        $css = '';
        $declarations = function (array $properties) use ($base): string {
            $rows = [];
            $image = null;
            if (isset($properties['typ_styl'])) {
                // the typography style first: a size or weight set separately fine-tunes it (the later declaration wins)
                $properties = ['typ_styl' => $properties['typ_styl']] + $properties;
            }
            foreach ($properties as $key => $value) {
                $css = self::value($key, (string) $value);
                if ($css === null) {
                    continue;
                }
                [$property, $type] = self::PROPERTIES[$key];
                if ($key === 'typ_styl') {
                    $rows[] = 'font: var(--ka-typ-' . $css . ')';
                    if ($css === 'nadtitulek') {
                        array_push($rows, 'text-transform: uppercase', 'letter-spacing: 0.08em');
                    }
                    continue;
                }
                if ($key === 'animace') {
                    if ($css !== 'none') {
                        array_push($rows, 'animation: ' . $css . ' linear both', 'animation-timeline: view()', 'animation-range: entry 0% cover 28%');
                    }
                    continue;
                }
                if ($type === 'obrazek') {
                    $image = $css;
                    continue;
                }
                $rows[] = $property . ': ' . $css;
                if ($key === 'pozadi' && ($value === 'bila' || $value === 'cerna') && !isset($properties['barva'])) {
                    // white and black do not change in dark mode: the text and derived shades inside adapt to them (otherwise light text on white)
                    $text = $value === 'bila' ? 'var(--ka-barva-text-svetle)' : 'var(--ka-barva-text-tmave)';
                    $surface = $value === 'bila' ? '#ffffff' : '#000000';
                    array_push($rows, '--ka-barva-text: ' . $text, 'color: ' . $text,
                        '--ka-barva-tlumeny: color-mix(in oklch, ' . $text . ' 64%, ' . $surface . ')', '--ka-barva-linka: color-mix(in oklch, ' . $text . ' 14%, ' . $surface . ')');
                }
            }
            if ($image !== null) {
                // the site's media always from the installation root – a relative url() would be looked up elsewhere on /en/… or /kolekce/polozka
                if (!str_starts_with($image, 'https://') && !str_starts_with($image, '/')) {
                    $image = $base . '/' . $image;
                }
                // the background image always covers the area; an optional overlay (--ka-prekryv) goes over it for text legibility
                $rows[] = 'background-image: linear-gradient(var(--ka-prekryv, transparent), var(--ka-prekryv, transparent)), url("' . $image . '")';
                $rows[] = 'background-size: cover';
                $rows[] = 'background-position: center';
            }

            return $rows === [] ? '' : implode('; ', $rows) . ';';
        };
        $base = $declarations($style['zaklad'] ?? []) . ($customCss !== '' ? ' ' . $customCss : '');
        if (trim($base) !== '') {
            $css .= $selector . ' { ' . trim($base) . " }\n";
        }
        if (($hover = $declarations($style['hover'] ?? [])) !== '') {
            $css .= $selector . ':is(:hover, :focus-visible) { ' . $hover . " }\n";
        }
        if (($active = $declarations($style['aktivni'] ?? [])) !== '') {
            $css .= $selector . ':active { ' . $active . " }\n";
        }
        foreach (['tablet', 'mobil'] as $state) {
            $block = '';
            if (($d = $declarations($style[$state] ?? [])) !== '') {
                $block .= $selector . ' { ' . $d . ' } ';
            }
            if (($d = $declarations($style['hover_' . $state] ?? [])) !== '') {
                $block .= $selector . ':is(:hover, :focus-visible) { ' . $d . ' } ';
            }
            if (($d = $declarations($style['aktivni_' . $state] ?? [])) !== '') {
                $block .= $selector . ':active { ' . $d . ' } ';
            }
            if ($block !== '') {
                $css .= self::STATUSES[$state] . ' { ' . trim($block) . " }\n";
            }
        }

        return $css;
    }

    /**
     * Custom CSS of a class (entered by the administrator, or created by converting HTML from AI): only "property: value;" declarations
     * without blocks, url(), imports and script constructs. Disallowed lines are discarded.
     */
    public static function customCss(string $css, array &$discarded = []): string
    {
        $output = [];
        foreach (preg_split('/;(?![^(]*\))/', str_replace(["\r", "\n"], ' ', $css)) ?: [] as $declarations) {
            $declarations = trim($declarations);
            if ($declarations === '') {
                continue;
            }
            // forbidden: loading external resources (url, image-set, image, src), comments and unclosed quotes – they would break the CSS of the rest of the page
            if (preg_match('/^(--ka-[a-z0-9-]{1,40}|-?[a-z][a-z-]{1,40})\s*:\s*([^;{}<>\\\\@]{1,200})$/i', $declarations, $m)
                && !preg_match('/url\s*\(|image-set|image\s*\(|src\s*\(|cross-fade|element\s*\(|expression|javascript|behavior|-moz-binding|\/\*|\*\//i', $m[2])
                && substr_count($m[2], '"') % 2 === 0 && substr_count($m[2], "'") % 2 === 0) {
                $output[] = strtolower($m[1]) . ': ' . trim($m[2]) . ';';
            } else {
                $discarded[] = $declarations;
            }
        }

        return implode(' ', $output);
    }
}
