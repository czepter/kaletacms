<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Popups as site parts: the content is built in the builder (target „popup:<id>“, versions in ka_stavba_revize), a popup has a type,
 * a trigger, display rules and a frequency.
 *
 * The rules the server knows (pages, collections, news, language, period) decide whether the popup is inserted into the page
 * at all. The rest (device, campaign, where the visitor came from, number of pages in the visit, frequency) is evaluated by
 * image/web.js in the browser – without cookies, only the visitor's sessionStorage and localStorage. The counters of views,
 * closes and conversions are incremented by POST /popup (Front\Kernel).
 */
final class Popups
{
    /** type => [name, popover] – the window and the full screen close with a click outside (auto), the panel and bars do not (manual) */
    public const array TYPES = [
        'okno' => ['Okno uprostřed', 'auto'],
        'panel' => ['Vysouvací panel v rohu', 'manual'],
        'lista-nahore' => ['Lišta nahoře', 'manual'],
        'lista-dole' => ['Lišta dole', 'manual'],
        'cela' => ['Přes celou obrazovku', 'auto'],
    ];

    /** trigger => [name, value unit ('' = without a value)] */
    public const array TRIGGERS = [
        'cas' => ['Po zadaném počtu sekund', 's'],
        'posun' => ['Po odrolování části stránky', '%'],
        'odchod' => ['Když se návštěvník chystá odejít', ''],
        'necinnost' => ['Po zadaném počtu sekund nečinnosti', 's'],
        'stranky' => ['Po zadaném počtu stránek v návštěvě', 'stránek'],
        'klik' => ['Jen kliknutím na odkaz nebo tlačítko', ''],
    ];

    public const array FREQUENCIES = [
        'relace' => 'Jednou za návštěvu',
        'dni' => 'Jednou za zadaný počet dní',
        'zavreni' => 'Dokud ho návštěvník nezavře',
        'odeslani' => 'Dokud návštěvník neodešle formulář v okně',
        'vzdy' => 'Pokaždé, když se spouštěč splní',
    ];

    public const array DEVICES = ['vse' => 'Všechna zařízení', 'pocitac' => 'Jen počítač a tablet', 'telefon' => 'Jen telefon'];

    public const string ADDRESS_PATTERN = '/^[a-z0-9][a-z0-9-]{0,59}$/';

    /** Rules of a new popup: the whole site, all languages, no restrictions. */
    public static function defaultRules(): array
    {
        return ['kde' => 'vse', 'stranky' => [], 'kolekce' => [], 'novinky' => false, 'jazyk' => '', 'od' => '', 'do' => '',
            'zarizeni' => 'vse', 'utm' => '', 'odkud' => ''];
    }

    /** @param array<string, mixed> $p */
    public static function sanitizeRules(array $p): array
    {
        $date = fn (mixed $d): string => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : '';
        $text = fn (mixed $t): string => mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._\/-]/u', '', is_string($t) ? $t : '') ?? ''), 0, 80);

        return [
            'kde' => ($p['kde'] ?? '') === 'vybrane' ? 'vybrane' : 'vse',
            'stranky' => array_values(array_unique(array_filter(array_map('intval', is_array($p['stranky'] ?? null) ? $p['stranky'] : []), fn (int $i): bool => $i > 0))),
            'kolekce' => array_values(array_unique(array_filter(is_array($p['kolekce'] ?? null) ? $p['kolekce'] : [], fn (mixed $k): bool => is_string($k) && preg_match('/^[a-z0-9-]{1,110}$/', $k) === 1))),
            'novinky' => !empty($p['novinky']),
            'jazyk' => is_string($p['jazyk'] ?? null) && preg_match('/^[a-z]{2}$/', $p['jazyk']) ? $p['jazyk'] : '',
            'od' => $date($p['od'] ?? ''),
            'do' => $date($p['do'] ?? ''),
            'zarizeni' => isset(self::DEVICES[$p['zarizeni'] ?? '']) ? $p['zarizeni'] : 'vse',
            'utm' => $text($p['utm'] ?? ''),
            'odkud' => $text($p['odkud'] ?? ''),
        ];
    }

    /**
     * Decides the rules the server knows: language and period always apply, the choice of places only with „vybrane“.
     *
     * @param array<string, mixed> $rules sanitized rules
     * @param array{ids: ?int, kolekce: ?string, novinky: bool, jazyk: string, dnes: string} $whereParts the displayed page
     */
    public static function matches(array $rules, array $whereParts): bool
    {
        if ($rules['jazyk'] !== '' && $rules['jazyk'] !== $whereParts['jazyk']) {
            return false;
        }
        if (($rules['od'] !== '' && $whereParts['dnes'] < $rules['od']) || ($rules['do'] !== '' && $whereParts['dnes'] > $rules['do'])) {
            return false;
        }
        if ($rules['kde'] === 'vse') {
            return true;
        }

        return ($whereParts['ids'] !== null && in_array($whereParts['ids'], $rules['stranky'], true))
            || ($whereParts['kolekce'] !== null && in_array($whereParts['kolekce'], $rules['kolekce'], true))
            || ($whereParts['novinky'] && $rules['novinky']);
    }

    /** A database row with the rules decoded. */
    public static function prepare(array $r): array
    {
        $r['pravidla'] = self::sanitizeRules(json_decode((string) $r['pravidla'], true) ?: []);
        foreach (['idpp', 'hodnota', 'dni', 'aktivni', 'poradi', 'zobrazeni', 'zavreni', 'konverze'] as $number) {
            $r[$number] = (int) $r[$number];
        }

        return $r;
    }

    public static function byId(Db $db, int $id): ?array
    {
        $r = $db->one('SELECT * FROM {popupy} WHERE idpp = ?', [$id]);

        return $r === null ? null : self::prepare($r);
    }

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::prepare(...), $db->all('SELECT * FROM {popupy} ORDER BY poradi, nazev'));
    }

    /**
     * Published and enabled popups for the displayed page.
     *
     * @param array{ids: ?int, kolekce: ?string, novinky: bool, jazyk: string, dnes: string} $whereParts
     * @return list<array<string, mixed>>
     */
    public static function forPage(Db $db, array $whereParts): array
    {
        return array_values(array_filter(
            array_map(self::prepare(...), $db->all('SELECT * FROM {popupy} WHERE aktivni = 1 AND stavba IS NOT NULL ORDER BY poradi, nazev')),
            fn (array $p): bool => self::matches($p['pravidla'], $whereParts),
        ));
    }

    /** A free popup slug (#popup-<slug>) derived from the text. */
    public static function address(Db $db, string $z, int $idpp = 0): string
    {
        return \Kaleta\Core\Slug::makeUnique(slugify($z, 50) ?: 'popup', fn (string $a): bool => $db->value('SELECT idpp FROM {popupy} WHERE adresa = ? AND idpp <> ?', [$a, $idpp]) !== null, 60);
    }

    /**
     * Popup wrapper on the site. The content is an already rendered build; image/web.js reads data-* (trigger, frequency, browser rules).
     * $open: preview – the popup opens right after loading, regardless of trigger and frequency.
     */
    public static function wrapper(array $p, string $content, string $counterUrl, bool $open = false): string
    {
        $type = isset(self::TYPES[$p['typ']]) ? $p['typ'] : 'okno';
        $id = 'popup-' . $p['adresa'];
        $dialog = in_array($type, ['okno', 'cela'], true);
        $data = ['popup' => (string) $p['idpp'], 'spoustec' => $p['spoustec'], 'hodnota' => (string) $p['hodnota'], 'cetnost' => $p['cetnost'],
            'dni' => (string) $p['dni'], 'zarizeni' => $p['pravidla']['zarizeni'], 'utm' => $p['pravidla']['utm'], 'odkud' => $p['pravidla']['odkud'],
            'pocitadlo' => $counterUrl] + ($open ? ['otevrit' => '1'] : []);

        return '<div id="' . e($id) . '" class="ka-popup ka-popup--' . e($type) . '" popover="' . self::TYPES[$type][1] . '" role="' . ($dialog ? 'dialog' : 'region') . '"'
            . ' aria-label="' . e($p['nazev']) . '"' . implode('', array_map(fn (string $k, string $v): string => ' data-' . $k . '="' . e($v) . '"', array_keys($data), $data)) . '>'
            . '<button type="button" class="ka-popup-zavrit" popovertarget="' . e($id) . '" popovertargetaction="hide" aria-label="' . e(t('Zavřít')) . '">×</button>'
            . '<div class="ka-popup-obsah stavba">' . $content . '</div></div>';
    }

    /** Wrapper in the builder editor: the popup stands on the canvas so that it can be edited (without popover and trigger). */
    public static function editorWrapper(array $p, string $content): string
    {
        $type = isset(self::TYPES[$p['typ']]) ? $p['typ'] : 'okno';

        return '<div class="ka-popup ka-popup--' . e($type) . ' ka-popup--editor"><div class="ka-popup-obsah stavba">' . $content . '</div></div>';
    }

    /** Ready-made popups for a new popup: key => [name, description, type, trigger, value]. */
    public const array LIBRARY = [
        'newsletter' => ['Přihlášení k newsletteru', 'Nadpis, krátký text a pole pro e-mail s potvrzením odběru.', 'okno', 'posun', 50],
        'magnet' => ['Materiál ke stažení za e-mail', 'Nabídka průvodce nebo ceníku výměnou za kontakt – formulář jde do Poptávek.', 'okno', 'odchod', 0],
        'lista' => ['Oznamovací lišta', 'Úzká lišta nahoře s krátkou zprávou a odkazem.', 'lista-nahore', 'cas', 1],
        'sleva' => ['Sleva nebo akce', 'Výrazná nabídka s kódem a tlačítkem.', 'okno', 'cas', 15],
        'udalost' => ['Pozvánka na událost', 'Panel v rohu s datem, místem a odkazem na registraci.', 'panel', 'cas', 8],
        'prazdny' => ['Prázdné okno', 'Nadpis a text – zbytek poskládáte v builderu.', 'okno', 'klik', 0],
    ];

    /** Build of a ready-made popup in the content language. */
    public static function libraryBuild(string $key, string $language = 'cs'): array
    {
        return \Kaleta\Core\Language::runWith($language, function () use ($key): array {
            $n = Build::fresh(...);
            $h = fn (string $text, string $htmlTag = 'h2'): array => ['znacka' => $htmlTag] + $n('nadpis', ['text' => $text]);
            $children = match ($key) {
                'newsletter' => [$h(t('Novinky jednou za měsíc')), $n('text', ['html' => '<p>' . e(t('Tipy a novinky z našeho oboru. Žádný spam, odhlásit se můžete jedním kliknutím.')) . '</p>']), $n('newsletter')],
                'magnet' => [$h(t('Stáhněte si průvodce zdarma')), $n('text', ['html' => '<p>' . e(t('Pošleme vám ho e-mailem. Kontakt použijeme jen k odpovědi.')) . '</p>']),
                    $n('formular', ['nazev' => t('Průvodce ke stažení'), 'dekujeme' => t('Děkujeme! Průvodce vám pošleme e-mailem.'), 'tlacitko' => t('Poslat průvodce'),
                        'pole' => [['popisek' => t('Jméno'), 'typ' => 'text', 'povinne' => false, 'moznosti' => ''], ['popisek' => t('E-mail'), 'typ' => 'email', 'povinne' => true, 'moznosti' => ''],
                            ['popisek' => t('Souhlasím se zpracováním osobních údajů za účelem vyřízení poptávky.'), 'typ' => 'souhlas', 'povinne' => true, 'moznosti' => '']]])],
                'lista' => [$n('kontejner', [], [$h(t('Nově otevíráme i v sobotu dopoledne.'), 'p'), $n('tlacitko', ['text' => t('Více informací'), 'odkaz' => '#', 'varianta' => 'odkaz'])])],
                'sleva' => [$h(t('Sleva 10 % na první objednávku')), $n('text', ['html' => '<p>' . e(t('Při objednávce zadejte kód')) . ' <strong>' . e(t('VITEJTE10')) . '</strong>.</p>']), $n('tlacitko', ['text' => t('Chci slevu'), 'odkaz' => '#'])],
                'udalost' => [$h(t('Den otevřených dveří'), 'h3'), $n('text', ['html' => '<p>' . e(t('Sobota 12. října, 10–16 h. Přijďte se podívat, jak pracujeme.')) . '</p>']), $n('tlacitko', ['text' => t('Chci přijít'), 'odkaz' => '#'])],
                default => [$h(t('Nadpis okna')), $n('text', ['html' => '<p>' . e(t('Krátký text okna.')) . '</p>'])],
            };
            if ($key === 'lista') {
                $children[0]['styl'] = ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'zarovnani' => 'center', 'rozmisteni' => 'center', 'mezera' => 's']];
            }

            return Build::sanitize(['v' => Build::VERSION, 'deti' => [$n('kontejner', [], $children)]], true)[0];
        });
    }
}
