<?php
/**
 * Hledá češtinu v anglickém rozhraní (tools/test-english.sh). Dva režimy:
 *
 *   php tools/find-czech.php stranka.html…   viditelný text stránek (text, title, placeholder, aria-label, alt, tlačítka, data-potvrdit)
 *   php tools/find-czech.php --js            české texty ve skriptech administrace (image/*.js) bez položky ve slovníku image/jazyky/admin-en.js
 *
 * Češtinu pozná podle tří znaků: písmena s háčkem a čárkou; text, který je českým klíčem slovníku s anglickým překladem
 * (= chybí t() nebo překlad se nepoužil); častá česká slova bez diakritiky jako samostatná slova (ne adresy /novinky).
 * Vypíše nálezy (soubor: text) a skončí kódem 1, když nějaké jsou.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Česká slova bez diakritiky, která v anglickém textu nemají co dělat (malými písmeny, porovnává se celé slovo). „Seznam“ chybí schválně – je to i název služby. */
const SLOVA = ['nebo', 'jsou', 'jako', 'pokud', 'bude', 'byla', 'bylo', 'jsme', 'jste', 'nelze', 'zde', 'tento', 'tato', 'toto', 'tyto',
    'novinky', 'novinka', 'novinek', 'kontakt', 'odkaz', 'odkazu', 'soubor', 'soubory', 'nadpis', 'nadpisy', 'obsah', 'upravit', 'smazat',
    'zobrazit', 'hledat', 'hledani', 'kotva', 'heslo', 'stavba', 'stavby', 'verze', 'firma', 'adresa', 'popis', 'popisek', 'chyba', 'druh',
    'koncept', 'kategorie', 'nastavit', 'nastaveni', 'vlastnosti', 'barva', 'sekce', 'kontejner', 'galerie', 'podklad', 'odstavec',
    'titulek', 'perex', 'aktuality', 'pravidla', 'kolekce', 'komponenta', 'komponenty', 'obnovit', 'zahodit', 'odebrat', 'posunout',
    'stranka', 'stranky', 'polozka', 'polozky', 'uzivatel', 'sluzby', 'uvod', 'znacka'];

/** Slova s diakritikou, která do angličtiny patří (názvy jazyků, přejatá slova). */
const POVOLENA = ['Čeština', 'café', 'Café'];

/** Klíče slovníků, které jsou zároveň anglickým slovem (List = seznam i list stromu…), se jako klíč nehledají. */
const DVOJZNACNE = ['List', 'Reference', 'Web', 'Region', 'Standard', 'Video', 'Menu', 'Tablet', 'Logo', 'Text', 'E-mail'];

/** @return array<string, string> český text => překlad ze všech anglických slovníků */
function dictionaries(string $root): array
{
    $all = [];
    foreach (['en', 'admin-en', 'install-en'] as $s) {
        $all += require $root . '/system/jazyky/' . $s . '.php';
    }

    return $all + jsDictionary($root);
}

/** @return array<string, string> */
function jsDictionary(string $root): array
{
    preg_match('/window\.KALETA_PREKLAD = (\{.*\});/s', (string) file_get_contents($root . '/image/jazyky/admin-en.js'), $m);

    return (array) json_decode((string) preg_replace(['#^\s*//.*$#m', '/,\s*\}$/'], ['', '}'], $m[1] ?? '{}'), true);
}

function hasDiacritics(string $text): bool
{
    // názvy jazyků v nabídkách (Slovenčina, Íslenska…) jsou ve svém jazyce záměrně
    static $languages = null;
    $languages ??= array_column((function (): array { require_once dirname(__DIR__) . '/system/src/Core/Language.php'; return \Kaleta\Core\Language::AVAILABLE; })(), 0);

    return preg_match('/[ěščřžůťďňáéíóúýĚŠČŘŽŮŤĎŇÁÉÍÓÚÝ]/u', str_replace([...POVOLENA, ...$languages], '', $text)) === 1;
}

/** Česká slova v textu; slova s lomítkem, tečkou uvnitř, @, = nebo podtržítkem jsou adresy a kód, ne text. */
function czechWords(string $text): array
{
    $finding = [];
    foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
        $word = trim($word, " \t,;:!?()[]{}\"'„“”‚‘’«».…–—-");
        if ($word === '' || preg_match('#[/@=_\#.&%]#', $word)) {
            continue;
        }
        if (in_array(mb_strtolower($word), SLOVA, true)) {
            $finding[] = $word;
        }
    }

    return $finding;
}

if (($argv[1] ?? '') === '--js') {
    // každý český text skriptů administrace (i ten, který se překládá až nepřímo přes T(popisek)) musí mít položku ve slovníku
    $dictionary = jsDictionary($root);
    $findings = [];
    foreach (['admin', 'editor', 'menu', 'pomocnik', 'stavitel', 'klice', 'tema'] as $file) {
        foreach (jsStrings((string) file_get_contents($root . '/image/' . $file . '.js')) as [$line, $text, $inT]) {
            // jednoslovné řetězce bez diakritiky jsou v kódu klíče a názvy (stranka, sekce), ne texty pro člověka
            $czech = hasDiacritics($text) || preg_match('/\s/u', trim($text)) && czechWords($text) !== [];
            if (($czech || $inT) && !isset($dictionary[$text]) && preg_match('/\p{L}/u', $text) && !in_array($text, DVOJZNACNE, true)) {
                $findings[] = 'image/' . $file . '.js:' . $line . ': ' . $text;
            }
        }
    }
    echo $findings === [] ? '' : implode("\n", array_unique($findings)) . "\n";
    exit($findings === [] ? 0 : 1);
}

/**
 * Řetězce skriptu bez komentářů a regulárních výrazů: [řádek, text, je-li přímo v T(…)].
 * Řetězce skládané s proměnnou (`…${x}`) se berou po částech.
 *
 * @return list<array{0:int, 1:string, 2:bool}>
 */
function jsStrings(string $js): array
{
    $result = [];
    $n = strlen($js);
    $line = 1;
    for ($i = 0; $i < $n; $i++) {
        $c = $js[$i];
        $next = $js[$i + 1] ?? '';
        if ($c === "\n") {
            $line++;
        } elseif ($c === '/' && $next === '/') {
            $i = (strpos($js, "\n", $i) ?: $n) - 1;
        } elseif ($c === '/' && $next === '*') {
            $end = strpos($js, '*/', $i + 2) ?: $n;
            $line += substr_count(substr($js, $i, $end - $i), "\n");
            $i = $end + 1;
        } elseif ($c === '/' && preg_match('/[(,=:\[!&|?{};]\s*$/', substr($js, max(0, $i - 20), min(20, $i)))) {
            // regulární výraz: přeskočit až po neescapované lomítko mimo [třídu]
            for ($j = $i + 1, $className = false; $j < $n && $js[$j] !== "\n"; $j++) {
                if ($js[$j] === '\\') {
                    $j++;
                } elseif ($js[$j] === '[') {
                    $className = true;
                } elseif ($js[$j] === ']') {
                    $className = false;
                } elseif ($js[$j] === '/' && !$className) {
                    break;
                }
            }
            $i = $j;
        } elseif ($c === "'" || $c === '"' || $c === '`') {
            for ($j = $i + 1; $j < $n && $js[$j] !== $c; $j++) {
                $j += $js[$j] === '\\' ? 1 : 0;
            }
            $raw = substr($js, $i + 1, $j - $i - 1);
            $inT = (bool) preg_match('/\bT\(\s*$/', substr($js, max(0, $i - 10), min(10, $i)));
            $text = $c === '`' ? $raw : (string) json_decode('"' . str_replace(['\\\'', '"'], ["'", '\\"'], $raw) . '"');
            foreach ($c === '`' ? preg_split('/\$\{[^}]*\}/', $text) ?: [] : [$text] as $part) {
                $result[] = [$line, $part, $inT];
            }
            $line += substr_count($raw, "\n");
            $i = $j;
        }
    }

    return $result;
}

// ---------- viditelný text stránek ----------

$keys = [];
$patterns = [];
foreach (dictionaries($root) as $czech => $translation) {
    $czech = (string) $czech;
    if ($czech === $translation || !preg_match('/\p{L}{3}/u', $czech) || in_array($czech, DVOJZNACNE, true) || preg_match('/^[a-z_]+$/', $czech) && str_contains($czech, '_')) {
        continue;
    }
    if (preg_match('/%(\d\$)?[sd]/', $czech)) {
        // text s doplněnou hodnotou (Nalezeno: %s): hledá se jako vzor, jen když má kolem hodnoty dost textu
        $fixed = trim((string) preg_replace('/%(\d\$)?[sd]/', ' ', $czech));
        if (preg_match('/\p{L}{4}/u', $fixed)) {
            $patterns[] = '/^' . implode('.+?', array_map(fn (string $x): string => preg_quote($x, '/'), preg_split('/%(?:\d\$)?[sd]/', $czech) ?: [])) . '$/u';
        }
        continue;
    }
    $keys[$czech] = true;
}

$findings = 0;
foreach (array_slice($argv, 1) as $file) {
    $html = (string) file_get_contents($file);
    if (trim($html) === '') {
        continue;
    }
    $dom = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR | Dom\HTML_NO_DEFAULT_NS);
    $xp = new Dom\XPath($dom);
    $texts = [];
    foreach ($xp->query('//text()[not(ancestor::script or ancestor::style)]') as $node) {
        $texts[] = $node->textContent;
    }
    foreach ($xp->query('//@title | //@placeholder | //@aria-label | //@alt | //@data-potvrdit | //button/@value | //input[@type="submit" or @type="button" or @type="reset"]/@value | //meta[@name="description"]/@content | //optgroup/@label') as $attribute) {
        $texts[] = $attribute->value;
    }
    $printed = [];
    foreach ($texts as $text) {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || isset($printed[$text])) {
            continue;
        }
        $reason = match (true) {
            hasDiacritics($text) => 'diakritika',
            isset($keys[$text]) || isset($keys[rtrim($text, ':')]) => 'český klíč slovníku',
            (bool) array_filter($patterns, fn (string $v): bool => preg_match($v, $text) === 1) => 'český klíč slovníku',
            ($words = czechWords($text)) !== [] => 'české slovo „' . implode('“, „', $words) . '“',
            default => '',
        };
        if ($reason !== '') {
            $printed[$text] = true;
            echo '         ', mb_strimwidth($text, 0, 160, '…'), '   [', $reason, "]\n";
            $findings++;
        }
    }
}
exit($findings > 0 ? 1 : 0);
