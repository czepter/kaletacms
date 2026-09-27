<?php
/**
 * Jednotkové testy jádra Kalety - bez frameworku a bez databáze: php tools/unit-tests.php
 *
 * Hlídají to, co kouřový test (tools/test.sh) nepozná: kryptografii, parsování a převody textu.
 * Nový test = další volání over('popis', $skutecne, $ocekavane).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\Asistent;
use Kaleta\Core\Hledani;
use Kaleta\Core\Migrace;
use Kaleta\Core\Soubory;
use Kaleta\Core\Totp;
use Kaleta\Front\Seo;
use Kaleta\Front\TextNovinky;

$errors = 0;
$total = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $errors, $total;
    $total++;
    if ($actual === $expected) {
        return;
    }
    $errors++;
    echo "  CHYBA  {$label}\n         čekal jsem: " . var_export($expected, true) . "\n         dostal jsem: " . var_export($actual, true) . "\n";
}

/* ---------- převody textu ---------- */
check('slugify: diakritika a mezery', slugify('Příliš žluťoučký kůň!'), 'prilis-zlutoucky-kun');
check('slugify: prázdný vstup', slugify('***'), 'n-a');
check('slugify: délka', strlen(slugify(str_repeat('abc ', 100), 20)) <= 20, true);
check('bez_diakritiky', bez_diakritiky('Ďábelské ÓDY – Straße'), 'Dabelske ODY – Strasse');
check('e(): uvozovky a značky', e('<a href="x">\'</a>'), '&lt;a href=&quot;x&quot;&gt;&#039;&lt;/a&gt;');
check('datum', datum('2026-09-05 07:03:00', true), '5. 9. 2026 07:03');

/* ---------- hledání ---------- */
check('Hledani::normalizuj', Hledani::normalizuj('<p>Nábřeží&nbsp;<b>Vltavy</b></p><h2>Proměna!</h2>'), 'nabrezi vltavy promena');
check('Hledani::dotaz: krátká slova vypadnou', Hledani::dotaz('co je na Nábřeží'), '+nabrezi*');
check('Hledani::dotaz: operátory fulltextu se neprosadí', Hledani::dotaz('+tajne -verejne "fraze" (x) ~y*'), '+tajne* +verejne* +fraze*');
check('Hledani::dotaz: nejvýš 8 slov', substr_count(Hledani::dotaz('aaa bbb ccc ddd eee fff ggg hhh iii jjj'), '+'), 8);

/* ---------- TOTP (RFC 6238, tajemství "12345678901234567890") ---------- */
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
check('TOTP: vektor T=59', Totp::kod($secret, intdiv(59, 30)), '287082');
check('TOTP: vektor T=1111111109', Totp::kod($secret, intdiv(1111111109, 30)), '081804');
check('TOTP: vektor T=2000000000', Totp::kod($secret, intdiv(2000000000, 30)), '279037');
check('TOTP: platný kód projde', Totp::over($secret, '287082', 59), true);
check('TOTP: sousední okno projde', Totp::over($secret, '287082', 59 + 30), true);
check('TOTP: starý kód neprojde', Totp::over($secret, '287082', 59 + 300), false);
check('TOTP: nesmysl neprojde', Totp::over($secret, 'abcdef', 59), false);
check('TOTP: nové tajemství má 160 bitů', strlen(Totp::noveTajemstvi()), 32);

/* ---------- migrace: dělení SQL na příkazy ---------- */
$sql = "-- komentář\nALTER TABLE ka_novinky ADD COLUMN x INT;   -- poznámka za příkazem\nCREATE TABLE ka_nova (\n  a VARCHAR(10) DEFAULT ';'\n);\nALTER TABLE ka_a ADD CONSTRAINT fk_a FOREIGN KEY (b) REFERENCES ka_b (id);\n";
$statements = Migrace::prikazy($sql, 'web_');
check('Migrace::prikazy: počet', count($statements), 3);
check('Migrace::prikazy: předpona tabulek', str_contains($statements[1], 'CREATE TABLE web_nova'), true);
check('Migrace::prikazy: středník v hodnotě příkaz nerozdělí', str_contains($statements[1], "DEFAULT ';'"), true);
check('Migrace::prikazy: předpona omezení', str_contains($statements[2], 'CONSTRAINT web_fk_a') && str_contains($statements[2], 'REFERENCES web_b'), true);
check('Migrace: KALETA_VERZE_DB odpovídá souborům', KALETA_VERZE_DB, Migrace::posledni());

/* ---------- přílohy ---------- */
check('Soubory: PDF je příloha', Soubory::jePriloha('Zpráva.PDF'), true);
check('Soubory: PHP není příloha', Soubory::jePriloha('shell.php'), false);
check('Soubory: dvojitá přípona', Soubory::jePriloha('shell.pdf.php'), false);
check('Soubory: SVG a HTML ne', Soubory::jePriloha('x.svg') || Soubory::jePriloha('x.html'), false);
check('Soubory: velikost', Soubory::velikost(1536), '2 kB');
check('Soubory: velikost v MB', Soubory::velikost(5 * 1048576), '5,0 MB');

/* ---------- přehrávač a vložené adresy ---------- */
check('prehravac: YouTube bez cookies', str_contains(TextNovinky::prehravac('https://www.youtube.com/watch?v=dQw4w9WgXcQ', '', 'T'), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), true);
check('prehravac: youtu.be', str_contains(TextNovinky::prehravac('https://youtu.be/dQw4w9WgXcQ', '', 'T'), 'embed/dQw4w9WgXcQ'), true);
check('prehravac: MP3 je <audio>', str_contains(TextNovinky::prehravac('media/2026/09/epizoda.mp3', '/magazin', 'T'), '<audio controls preload="none" src="/magazin/media/2026/09/epizoda.mp3">'), true);
check('prehravac: neznámá adresa v režimu jenZname', TextNovinky::prehravac('https://example.com/video', '', 'T', true), '');
check('prehravac: titulek se escapuje', str_contains(TextNovinky::prehravac('https://vimeo.com/123', '', '"><script>'), '<script>'), false);
$types = (new ReflectionClass(TextNovinky::class))->newInstanceWithoutConstructor();
$html = $types->vlozeneAdresy('<p>Úvod</p><p>https://youtu.be/dQw4w9WgXcQ</p><p>Viz https://youtu.be/dQw4w9WgXcQ v textu.</p>');
check('vlozeneAdresy: jen samostatný řádek', [substr_count($html, 'data-vlozit'), substr_count($html, 'Viz https://youtu.be')], [1, 1]);

/* ---------- šablony webu ---------- */
check('Šablony: výchozí šablona existuje a je i výchozí hodnotou nastavení', [is_file(dirname(__DIR__) . '/layout/' . \Kaleta\Front\Layouty::VYCHOZI . '/base.php'), \Kaleta\Core\Settings::DEFAULTS['layout']], [true, \Kaleta\Front\Layouty::VYCHOZI]);
check('Šablony: zrušená šablona „default“ se nevrátila', is_dir(dirname(__DIR__) . '/layout/default'), false);

/* ---------- anglický slovník pokrývá texty webu i administrace ---------- */
$missingTranslation = static function (string $dictionary, array $patterns): array {
    $translations = require dirname(__DIR__) . '/system/jazyky/' . $dictionary;
    $missing = [];
    foreach ($patterns as $pattern) {
        foreach (glob(dirname(__DIR__) . '/' . $pattern) ?: [] as $file) {
            preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)+)'/u", (string) file_get_contents($file), $m);
            foreach ($m[1] as $k) {
                $k = stripslashes($k);
                if (!isset($translations[$k]) && preg_match('/[áčďéěíňóřšťúůýž]/iu', $k)) {
                    $missing[] = basename($file) . ': ' . $k;
                }
            }
        }
    }

    return array_values(array_unique($missing));
};
check('Slovník en.php: texty webu mají anglický překlad', $missingTranslation('en.php', ['system/views/front/*.php', 'layout/*/*.php', 'system/src/Front/*.php', 'system/src/Stavitel/*.php', 'system/src/Stavitel/Prvky/*.php']), []);
check('Slovník admin-en.php: E-mail webu', isset((require dirname(__DIR__) . '/system/jazyky/admin-en.php')['E-mail webu']), true);

check('Stavba::kod: vnořený skript se nesloží znovu', [str_contains(Kaleta\Stavitel\Stavba::kod('<scr<script>x</script>ipt>alert(1)</scr<script>y</script>ipt>'), '<script'), Kaleta\Stavitel\Stavba::kod('<iframe src="https://mapy.cz/x"></iframe>')], [false, '<iframe src="https://mapy.cz/x"></iframe>']);
check('Stavba::kod: obsluhy událostí a javascript: zmizí', Kaleta\Stavitel\Stavba::kod('<a href="javascript:alert(1)" onclick="x()">A</a><iframe srcdoc="data:text/html,x"></iframe>'), '<a href="#">A</a><iframe srcdoc="#"></iframe>');
/* ---------- skripty administrace a webu bez systémových dialogů (nejdou nastylovat, přeložit a prohlížeče je potlačují) ---------- */
$nativeDialogs = [];
foreach (glob(dirname(__DIR__) . '/image/*.js') ?: [] as $file) {
    $code = preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//.*$#m'], ['', '$1'], (string) file_get_contents($file)); // bez komentářů
    if (preg_match_all('/\b(alert|prompt|confirm)\s*\(/', (string) $code, $m)) {
        $nativeDialogs[] = basename($file) . ': ' . implode(', ', array_unique($m[1]));
    }
}
check('Skripty bez window.alert/prompt/confirm', $nativeDialogs, []);

/* ---------- QR kód (dvoufázové přihlášení): vlastní kodér bez knihovny ---------- */
// Reed–Solomon: známý vektor „HELLO WORLD“ verze 1-M z návodu k normě (thonky.com, QR Code Tutorial)
check('Qr: opravné kódy Reed–Solomon (známý vektor)', Kaleta\Core\Qr::opravneKody([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10), [196, 35, 39, 119, 235, 215, 231, 226, 93, 23]);
$qrRows = fn (array $m): array => array_map(fn (array $r): string => implode('', array_map(fn (bool $b): string => $b ? '#' : '.', $r)), $m);
$qr = $qrRows(Kaleta\Core\Qr::matice('Kaleta', 2));
// formátové bity čtené z matice (sloupec 8 a řádek 8 u levého horního rohu) = tabulka normy pro úroveň M, masku 2: 101111001111100
$qrFormat = '';
foreach ([[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]] as [$y, $x]) {
    $qrFormat = ($qr[$y][$x] === '#' ? '1' : '0') . $qrFormat;
}
check('Qr: formátové bity M/maska 2 podle tabulky normy', $qrFormat, '101111001111100');
check('Qr: verze 1 = 21 × 21 s hledacím vzorem', [count($qr), $qr[0], $qr[6]], [21, '#######..##.#.#######', '#######.#.#.#.#######']);
// matice ověřené nezávislou čtečkou (Chrome BarcodeDetector) – hlídá, že se kodér nerozbije
$qrHash = fn (string $text): string => sha1(implode("\n", array_map(fn (array $r): string => implode('', array_map('intval', $r)), Kaleta\Core\Qr::matice($text))));
check('Qr: adresa otpauth (verze 6) odpovídá ověřené matici', $qrHash('otpauth://totp/Acme%3Aadmin?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Acme&digits=6&period=30'), '21b4e92a0dd7dfcfe3d26161ea6baf9459312669');
check('Qr: verze 12 (verzní bity, víc bloků) odpovídá ověřené matici', $qrHash(str_repeat('Kaleta QR 0123456789 ', 12)), '12f9c4c80e2de66f316614d576e6dde5464482c8');
$qrSvg = Kaleta\Core\Qr::svg('otpauth://totp/x?secret=AB', 'QR <kód>');
check('Qr: SVG s popisem, bez skriptu', str_contains($qrSvg, 'role="img" aria-label="QR &lt;kód&gt;"') && !str_contains($qrSvg, '<script'), true);

/* ---------- velikosti obrázků: vysoké snímky se měří šířkou, srcset nese skutečné šířky ---------- */
check('Obrazky::pomer: fotka na šířku i na výšku podle delší strany', [Kaleta\Core\Obrazky::pomer(4000, 3000, 2000), Kaleta\Core\Obrazky::pomer(3000, 4000, 2000)], [0.5, 0.5]);
check('Obrazky::pomer: celostránkový snímek podle šířky, výška nejvýš trojnásobek', [Kaleta\Core\Obrazky::pomer(1440, 5000, 2000), round(Kaleta\Core\Obrazky::pomer(1440, 5000, 1200), 3), Kaleta\Core\Obrazky::pomer(1000, 9000, 2000)], [1.0, 0.72, 6000 / 9000]);
$imageFolder = dirname(__DIR__) . '/media/' . date('Y/m');
@mkdir($imageFolder, 0775, true);
$imageTmp = tempnam(sys_get_temp_dir(), 'obr');
$imagePng = imagecreatetruecolor(1440, 5000);
imagepng($imagePng, $imageTmp);
$imageSaved = Kaleta\Core\Obrazky::ulozSoubor($imageTmp, 'celostrankovy-snimek.png');
$imageSrcset = Kaleta\Core\Obrazky::srcset($imageSaved['obr_poloha'], '');
check('Obrazky: vysoký snímek si nechá šířku, srcset má skutečné šířky variant', [$imageSaved['obr_width'], $imageSaved['obr_height'], (bool) preg_match('/-nahled\.png 553w, .*-1200\.png 1037w, .*\.png 1440w$/', $imageSrcset)], [1440, 5000, true]);
Kaleta\Core\Obrazky::smaz($imageSaved['obr_poloha'], $imageSaved['nahl_poloha']);
@unlink($imageTmp);

check('Styl::zCss: barva rámečku (i pro stav hover)', Kaleta\Stavitel\Styl::zCss('border-color', '#F6F4EE'), ['barva_ramecku' => '#F6F4EE']);
check('Styl::zCss: zkratka background jen s barvou', Kaleta\Stavitel\Styl::zCss('background', '#EFECE5'), Kaleta\Stavitel\Styl::zCss('background-color', '#EFECE5'));
check('Styl::zCss: background s obrázkem zůstane mimo', Kaleta\Stavitel\Styl::zCss('background', 'url(a.png) no-repeat'), null);
$buildCheck = Kaleta\Stavitel\Kontrola::stavby(['v' => 1, 'deti' => [['id' => 's', 'typ' => 'sekce', 'deti' => [
    ['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Služby']],
    ['id' => 'b', 'typ' => 'nadpis', 'znacka' => 'h4', 'obsah' => ['text' => 'Detail']],
    ['id' => 'c', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Poptat', 'odkaz' => '#']],
    ['id' => 'd', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/a.jpg', 'alt' => '']],
    ['id' => 'e', 'typ' => 'obrazek', 'obsah' => ['src' => '{{foto}}', 'alt' => '']],
    ['id' => 'f', 'typ' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => '01']],
]]]], true);
check('Kontrola stavby: tlačítko bez odkazu, obrázek bez popisu, chybějící h1 a přeskočená úroveň', array_column($buildCheck, 'id'), ['c', 'd', 'a', 'b']);
check('Kontrola stavby: části webu osnovu nadpisů nehlídají', Kaleta\Stavitel\Kontrola::stavby(['v' => 1, 'deti' => [['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h3', 'obsah' => ['text' => 'Kontakt']]]], false), []);
check('Poptávka: kampaň z utm_* adresy stránky s formulářem', Kaleta\Front\Formulare::kampan('https://example.com/akce?utm_source=google&utm_medium=cpc&utm_campaign=jaro&gclid=x&utm_term[]=a', 'https://example.com'), 'utm_source=google&utm_medium=cpc&utm_campaign=jaro');
check('Poptávka: kampaň jen z vlastního webu', Kaleta\Front\Formulare::kampan('https://jiny.cz/?utm_source=x', 'https://example.com'), '');
check('Poptávka: kampaň pro člověka', Kaleta\Front\Formulare::kampanText('utm_source=google&utm_medium=cpc&utm_campaign=jaro'), 'google / cpc / jaro');
// MCP anglicky: každý nástroj má anglický název, každé pevné hlášení překlad, parametry a výsledky se převádějí
$mcpSource = (string) file_get_contents(KALETA_ROOT . '/system/src/Mcp/Nastroje.php');
preg_match_all("/^\s+\['([a-z_]+)', '/m", substr($mcpSource, 0, (int) strpos($mcpSource, 'public function zavolej')), $mcpTools);
check('MCP: každý nástroj má anglický název', array_values(array_diff($mcpTools[1], Kaleta\Mcp\Anglicky::ceske())), []);
preg_match_all("/Exception\('((?:[^'\\\\]|\\\\.)*)'\)/", $mcpSource . file_get_contents(KALETA_ROOT . '/system/src/Stavitel/Upravy.php'), $mcpMessages);
check('MCP: pevná hlášení mají anglický překlad', array_values(array_filter(array_map('stripslashes', $mcpMessages[1]), fn (string $z): bool => Kaleta\Mcp\Anglicky::zprava($z) === $z)), []);
check('MCP anglicky: parametry, hodnoty a položky menu', Kaleta\Mcp\Anglicky::argumenty('save_menu', ['location' => 'footer', 'items' => [['type' => 'page', 'page_id' => 2, 'children' => [['type' => 'link', 'url' => '/x', 'new_window' => true]]]]]),
    ['umisteni' => 'paticka', 'polozky' => [['typ' => 'stranka', 'ids' => 2, 'deti' => [['typ' => 'odkaz', 'url' => '/x', 'nove_okno' => true]]]]]);
check('MCP anglicky: typy polí kolekce a nastavení', [Kaleta\Mcp\Anglicky::argumenty('create_collection', ['fields' => [['label' => 'Foto', 'type' => 'image']]]), Kaleta\Mcp\Anglicky::argumenty('update_settings', ['settings' => ['site_name_de' => 'X', 'company_email' => 'a@b.c', 'nazev_webu' => 'Y']])],
    [['pole' => [['popisek' => 'Foto', 'typ' => 'obrazek']]], ['nastaveni' => ['nazev_webu_de' => 'X', 'firma_email' => 'a@b.c', 'nazev_webu' => 'Y']]]);
check('MCP anglicky: výsledek s anglickými klíči, stavba beze změny', Kaleta\Mcp\Anglicky::vysledek('save_build', ['id' => 3, 'stav' => 'publikováno', 'stavba' => ['v' => 1, 'deti' => [['typ' => 'nadpis', 'stav' => 'x']]], 'kontrola' => [['id' => 'a', 'zprava' => 'z']]]),
    ['id' => 3, 'status' => 'published', 'build' => ['v' => 1, 'deti' => [['typ' => 'nadpis', 'stav' => 'x']]], 'check' => [['id' => 'a', 'message' => 'z']]]);
$mcpList = [['name' => 'save_collection_item', 'inputSchema' => ['properties' => ['data' => ['type' => 'object'], 'name' => ['type' => 'string'], 'fields' => ['type' => 'array']]]]];
check('MCP: objekt a pole poslané jako text JSON se rozbalí podle schématu, text zůstane textem', Kaleta\Mcp\Server::rozbalJson($mcpList, 'save_collection_item', ['data' => '{"a":"b"}', 'name' => '{"x":1}', 'fields' => '[1,2]']),
    ['data' => ['a' => 'b'], 'name' => '{"x":1}', 'fields' => [1, 2]]);
check('MCP: neplatný JSON nebo pole místo objektu se nerozbalí', Kaleta\Mcp\Server::rozbalJson($mcpList, 'save_collection_item', ['data' => '{nic', 'fields' => '{"a":1}']), ['data' => '{nic', 'fields' => '{"a":1}']);
check('MCP: logická hodnota poslaná jako text („false“ nezveřejní skrytou stránku)', Kaleta\Mcp\Server::rozbalJson([['name' => 'create_page', 'inputSchema' => ['properties' => ['visible' => ['type' => 'boolean'], 'title' => ['type' => 'string']]]]], 'create_page', ['visible' => 'false', 'title' => 'false']), ['visible' => false, 'title' => 'false']);
check('MCP: logická hodnota „true“ a „1“ jako text', array_values(array_map(fn (string $h): mixed => Kaleta\Mcp\Server::rozbalJson([['name' => 't', 'inputSchema' => ['properties' => ['v' => ['type' => 'boolean']]]]], 't', ['v' => $h])['v'], ['true', '1', '0', 'ano'])), [true, true, false, 'ano']);
check('MCP: text JSON u parametru typu [array, null] se rozbalí', Kaleta\Mcp\Server::rozbalJson([['name' => 'save_menu', 'inputSchema' => ['properties' => ['items' => ['type' => ['array', 'null']]]]]], 'save_menu', ['items' => '[{"type":"page"}]']), ['items' => [['type' => 'page']]]);
check('MCP: neznámé parametry se vyjmenují', Kaleta\Mcp\Server::nezname($mcpList, 'save_collection_item', ['data' => '{}', 'classes' => [], 'name' => 'x']), ['classes']);
// pop-up okna: pravidla serveru (místa, jazyk, období) a anglické parametry MCP
$popupWhere = fn (array $x): array => $x + ['ids' => null, 'kolekce' => null, 'novinky' => false, 'jazyk' => 'cs', 'dnes' => '2026-09-25'];
$popupSelected = Kaleta\Stavitel\Popupy::vycistiPravidla(['kde' => 'vybrane', 'stranky' => ['4', 'x', 4], 'kolekce' => ['tym', 'Ne platna'], 'novinky' => 1, 'od' => '2026-02-30', 'utm' => 'jaro<b>']);
check('Pop-up: vyčištěná pravidla', [$popupSelected['stranky'], $popupSelected['kolekce'], $popupSelected['novinky'], $popupSelected['od'], $popupSelected['utm'], $popupSelected['zarizeni']], [[4], ['tym'], true, '', 'jarob', 'vse']);
check('Pop-up: vybraná místa – stránka, kolekce, novinky, jinde ne', [
    Kaleta\Stavitel\Popupy::odpovida($popupSelected, $popupWhere(['ids' => 4])), Kaleta\Stavitel\Popupy::odpovida($popupSelected, $popupWhere(['kolekce' => 'tym'])),
    Kaleta\Stavitel\Popupy::odpovida($popupSelected, $popupWhere(['novinky' => true])), Kaleta\Stavitel\Popupy::odpovida($popupSelected, $popupWhere(['ids' => 5])),
], [true, true, true, false]);
$popupPeriod = Kaleta\Stavitel\Popupy::vycistiPravidla(['od' => '2026-10-01', 'do' => '2026-10-31', 'jazyk' => 'en']);
check('Pop-up: období a jazyk platí i pro celý web', [
    Kaleta\Stavitel\Popupy::odpovida($popupPeriod, $popupWhere(['jazyk' => 'en'])), Kaleta\Stavitel\Popupy::odpovida($popupPeriod, $popupWhere(['jazyk' => 'en', 'dnes' => '2026-10-15'])),
    Kaleta\Stavitel\Popupy::odpovida($popupPeriod, $popupWhere(['jazyk' => 'cs', 'dnes' => '2026-10-15'])), Kaleta\Stavitel\Popupy::odpovida($popupPeriod, $popupWhere(['jazyk' => 'en', 'dnes' => '2026-11-01'])),
], [false, true, false, false]);
$navCss = Kaleta\Stavitel\Prvky\Navigace::zakladniCss();
check('Kolekce: hodnota v Vlastním HTML je escapovaná, formátovaný text vyčištěný', [
    Kaleta\Stavitel\Kolekce::dosad('<div title="{{nazev}}">{{nazev}}</div>', 'kod', ['nazev' => ['<img src=x onerror=alert(1)>"', 'text']]),
    Kaleta\Stavitel\Kolekce::dosad('<div>{{telo}}</div>', 'kod', ['telo' => ['<p>Ahoj</p><img src=x onerror=alert(1)>', 'html']]),
], ['<div title="&lt;img src=x onerror=alert(1)&gt;&quot;">&lt;img src=x onerror=alert(1)&gt;&quot;</div>', '<div><p>Ahoj</p><img src="x"></div>']);
check('Navigace: menu na telefonu se dá posouvat (dlouhé menu se skupinami)', (bool) preg_match('/@media \\(max-width: 767px\\).*?\\.ka-nav-menu\\[popover\\] \\{[^}]*max-height:[^}]*overflow-y: auto/s', $navCss), true);
check('MCP anglicky: pop-up okno – hodnoty a pravidla', Kaleta\Mcp\Anglicky::argumenty('save_popup', ['type' => 'slide_in', 'trigger' => 'exit', 'frequency' => 'until_closed', 'template' => 'lead_magnet',
    'rules' => ['where' => 'selected', 'pages' => [2], 'device' => 'phone', 'campaign' => 'jaro']]),
    ['typ' => 'panel', 'spoustec' => 'odchod', 'cetnost' => 'zavreni', 'vzor' => 'magnet', 'pravidla' => ['kde' => 'vybrane', 'stranky' => [2], 'zarizeni' => 'telefon', 'utm' => 'jaro']]);
check('MCP anglicky: výsledek pop-up okna', Kaleta\Mcp\Anglicky::vysledek('save_popup', ['id' => 3, 'nazev' => 'X', 'adresa' => 'x', 'typ' => 'lista-dole', 'spoustec' => 'stranky', 'cetnost' => 'dni',
    'pravidla' => ['kde' => 'vse', 'zarizeni' => 'pocitac', 'od' => ''], 'aktivni' => true, 'zobrazeni' => 5]),
    ['id' => 3, 'name' => 'X', 'slug' => 'x', 'type' => 'bottom_bar', 'trigger' => 'pages', 'frequency' => 'days', 'rules' => ['where' => 'all', 'device' => 'desktop', 'from' => ''], 'active' => true, 'views' => 5]);
check('Pop-up: každý vzor z knihovny se sestaví', array_map(fn (string $k): bool => count(Kaleta\Stavitel\Popupy::stavbaZKnihovny($k, 'en')['deti']) === 1, array_keys(Kaleta\Stavitel\Popupy::KNIHOVNA)), array_fill(0, count(Kaleta\Stavitel\Popupy::KNIHOVNA), true));
check('MCP anglicky: hlášení s proměnnou částí', Kaleta\Mcp\Anglicky::zprava('Kategorie „Akce“ neexistuje. Použij nástroj seznam_kategorii.'), 'The category “Akce” does not exist. Use list_categories.');
use Kaleta\Core\Cesty;
check('Cesty: systémové adresy v jazyce verze', [Cesty::verejna('novinky/kategorie/akce', 'en', null), Cesty::verejna('novinky/stitek/x', 'de', null), Cesty::verejna('hledani?q=a', 'fr', null),
    Cesty::verejna('novinky/kategorie/akce', 'cs', null), Cesty::verejna('novinky-akce', 'en', null), Cesty::verejna('novinky', 'en', null)],
    ['news/category/akce', 'news/tag/x', 'search?q=a', 'novinky/kategorie/akce', 'novinky-akce', 'news']);
check('Cesty: požadavek na vnitřní cestu a kanonickou podobu', [Cesty::vnitrni('/news/tag/x', 'en', null), Cesty::vnitrni('/novinky/x', 'en', null), Cesty::vnitrni('/news', 'cs', null), Cesty::vnitrni('/o-nas', 'en', null)],
    [['/novinky/stitek/x', '/news/tag/x'], ['/novinky/x', '/news/x'], ['/novinky', '/novinky'], ['/o-nas', '/o-nas']]);
// slovníky dalších jazyků webu: jen klíče anglického slovníku (a anglické názvy dnů a měsíců pro datum slovy), stejné %s a značky
$enDictionary = require KALETA_ROOT . '/system/jazyky/en.php';
$dataNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$brokenDictionaries = [];
foreach (glob(KALETA_ROOT . '/system/jazyky/[a-z][a-z].php') ?: [] as $file) {
    $code = basename($file, '.php');
    if ($code === 'en') {
        continue;
    }
    $dictionary = require $file;
    if (!isset(Kaleta\Core\Jazyk::DOSTUPNE[$code])) {
        $brokenDictionaries[] = $code . ': jazyk není v Jazyk::DOSTUPNE';
    }
    foreach ($dictionary as $key => $translation) {
        if (!isset($enDictionary[$key]) && !in_array($key, $dataNames, true) && $key !== 'datum_format') {
            $brokenDictionaries[] = $code . ': navíc „' . $key . '“';
        } elseif (isset($enDictionary[$key]) && (preg_match_all('/%(?:\d+\$)?[sd]/', $enDictionary[$key]) !== preg_match_all('/%(?:\d+\$)?[sd]/', $translation) || substr_count($enDictionary[$key], '<') !== substr_count($translation, '<'))) {
            $brokenDictionaries[] = $code . ': zástupné znaky nebo značky v „' . $key . '“';
        }
    }
}
check('Slovníky jazyků webu: klíče z en.php, stejné %s a HTML', $brokenDictionaries, []);

/* ---------- porovnání verzí ---------- */
$r = Kaleta\Core\Rozdil::html('<p>Radnice schválila plán.</p><p>Druhý odstavec.</p>', '<p>Radnice včera schválila nový plán.</p><p>Druhý odstavec.</p><p>Třetí.</p>');
check('Rozdil: slova ve změněném odstavci', str_contains($r['html'], '<ins>včera </ins>') && str_contains($r['html'], '<ins>nový </ins>'), true);
check('Rozdil: nezměněný odstavec bez značek', str_contains($r['html'], '<p>Druhý odstavec.</p>'), true);
check('Rozdil: nový odstavec', str_contains($r['html'], '<p><ins>Třetí.</ins></p>'), true);
check('Rozdil: HTML ve vstupu se escapuje', str_contains(Kaleta\Core\Rozdil::html('', '<p>a &lt;script&gt; b</p>')['html'], '<script>'), false);
check('Rozdil: shodné texty', Kaleta\Core\Rozdil::html('<p>Stejné</p>', '<p>Stejné</p>')['pridano'], 0);

/* ---------- FAQ ---------- */
check('Seo::faq', Seo::faq("Kdy to začne?\nV pondělí.\n\nKolik to stojí?\nNic."), [['Kdy to začne?', 'V pondělí.'], ['Kolik to stojí?', 'Nic.']]);
check('Seo::faq: prázdný vstup', Seo::faq(null), []);

/* ---------- zálohy do S3: podpis AWS Signature V4 (hodnota ověřená nezávislým výpočtem) ---------- */
$h = Kaleta\Core\VzdalenaZaloha::podpisS3('PUT', 's3.eu-central-1.amazonaws.com', '/muj-bucket/kaleta-zaloha.sql.gz', hash('sha256', 'obsah'), 'eu-central-1', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 1789900000);
check('S3: rozsah a podepsané hlavičky', str_contains($h['Authorization'], 'Credential=AKIDEXAMPLE/20260920/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature='), true);
check('S3: podpis má 64 šestnáctkových znaků', (bool) preg_match('/Signature=[0-9a-f]{64}$/', $h['Authorization']), true);

/* ---------- kontrola odkazů: jen veřejné adresy (ochrana před ohledáváním vnitřní sítě) ---------- */
check('Odkazy: výběr odkazů z HTML', Kaleta\Core\Odkazy::odkazy('<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="#kotva">k</a> <a class="x" href="/clanek/muj">c</a> <a href="https://example.com/a?x=1&amp;y=2">znovu</a></p>'), ['https://example.com/a?x=1&y=2', '/clanek/muj']);
foreach (['http://127.0.0.1/', 'http://localhost/', 'http://10.0.0.5/admin', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'ftp://example.com/', 'https://example.com:8443/', 'file:///etc/passwd', 'gopher://x/'] as $internal) {
    check('Odkazy: nekontroluje se ' . $internal, Kaleta\Core\Odkazy::jeVerejna($internal), false);
}
check('Odkazy: veřejná adresa se kontroluje', Kaleta\Core\Odkazy::jeVerejna('https://93.184.216.34/stranka'), true);

/* ---------- asistent: překlad článku (kostra HTML z originálu, texty od modelu) ---------- */
$articleHtml = '<h2>Nadpis oddílu</h2><p>První <strong>tučný</strong> a <a href="/x?a=1&amp;b=2">odkaz</a>.</p><figure><img src="a.jpg" alt="x"><figcaption>Popisek fotky</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>';
$r = Kaleta\Core\Asistent::rozloz($articleHtml);
check('Asistent::rozloz: úseky k překladu', $r['useky'], ['Nadpis oddílu', 'První [[0]]tučný[[1]] a [[2]]odkaz[[3]].', 'Popisek fotky']);
check('Asistent::sloz: beze změny textu vrátí původní HTML', Kaleta\Core\Asistent::sloz($r['kostra'], $r['useky']), $articleHtml);
check('Asistent::sloz: HTML od modelu se vypíše jako text', str_contains(Kaleta\Core\Asistent::sloz($r['kostra'], ['<script>alert(1)</script>', 'x', '<img src=x onerror=alert(1)>']), '<script>alert(1)') || str_contains(Kaleta\Core\Asistent::sloz($r['kostra'], ['a', 'b', '<img src=x onerror=alert(1)>']), '<img src=x'), false);
check('Asistent::sloz: chybějící symbol = úsek bez formátování', Kaleta\Core\Asistent::sloz($r['kostra'], ['N', 'First [[0]]bold[[1]] and link.', 'P']), '<h2>N</h2><p>First bold and link.</p><figure><img src="a.jpg" alt="x"><figcaption>P</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>');
check('Asistent::sloz: špatně vnořené symboly = úsek bez formátování', str_contains(Kaleta\Core\Asistent::sloz($r['kostra'], ['N', '[[1]]bold[[0]] [[2]]link[[3]]', 'P']), '<strong>'), false);
check('Asistent::sloz: přeházené pořadí slov formátování zachová', str_contains(Kaleta\Core\Asistent::sloz($r['kostra'], ['N', 'A [[2]]link[[3]] and [[0]]bold[[1]] first.', 'P']), '<p>A <a href="/x?a=1&amp;b=2">link</a> and <strong>bold</strong> first.</p>'), true);

$settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($settings, ['nazev_webu' => 'Test', 'ai_klic' => 'x']);
$fake = new class($settings) extends Kaleta\Core\Asistent {
    public int $calls = 0;

    protected function zavolej(array $body): array
    {
        $this->calls++;
        preg_match('#<useky>\n(.*)\n</useky>#s', $body['messages'][0]['content'], $m);

        return ['content' => [['type' => 'text', 'text' => json_encode(['preklady' => array_map(mb_strtoupper(...), json_decode($m[1], true))], JSON_UNESCAPED_UNICODE)]]];
    }
};
$translated = $fake->preloz(['titulek' => 'Tom & Jerry „znovu“ ve městě, tentokrát úplně jinak než kdy dřív', 'text' => '<p>Krátký <em>text</em> článku, který má aspoň pár desítek znaků.</p>', 'seo_popis' => ''], 'en', ['titulek', 'seo_popis']);
check('Asistent::preloz: prostý text se neescapuje dvakrát', $translated['titulek'], 'TOM & JERRY „ZNOVU“ VE MĚSTĚ, TENTOKRÁT ÚPLNĚ JINAK NEŽ KDY DŘÍV');
check('Asistent::preloz: HTML pole drží kostru', $translated['text'], '<p>KRÁTKÝ <em>TEXT</em> ČLÁNKU, KTERÝ MÁ ASPOŇ PÁR DESÍTEK ZNAKŮ.</p>');
check('Asistent::preloz: prázdné pole zůstane prázdné', $translated['seo_popis'], '');
$fake->calls = 0;
$long = $fake->preloz(['text' => str_repeat('<p>' . str_repeat('Věta o něčem. ', 100) . '</p>', 9)], 'en');
check('Asistent::preloz: dlouhý článek jde po dávkách', [$fake->calls > 1, substr_count($long['text'], '<p>')], [true, 9]);
try {
    $fake->preloz(['text' => '<p>nic</p>'], 'xx');
    check('Asistent::preloz: neznámý jazyk odmítne', 'prošlo', 'výjimka');
} catch (RuntimeException) {
    check('Asistent::preloz: neznámý jazyk odmítne', 'výjimka', 'výjimka');
}

/* ---------- dočasné přepnutí jazyka (e-maily v jazyce příjemce) ---------- */
Kaleta\Core\Jazyk::nastav('cs');
check('Jazyk::docasne: uvnitř platí cizí jazyk', Kaleta\Core\Jazyk::docasne('en', fn (): string => Kaleta\Core\Jazyk::kod() . '|' . t('Číst článek →')), 'en|Read article →');
check('Jazyk::docasne: potom se jazyk vrátí', Kaleta\Core\Jazyk::kod() . '|' . t('Číst článek →'), 'cs|Číst článek →');
try {
    Kaleta\Core\Jazyk::docasne('en', function (): never { throw new RuntimeException('x'); });
} catch (RuntimeException) {
}
check('Jazyk::docasne: jazyk se vrátí i po výjimce', Kaleta\Core\Jazyk::kod(), 'cs');

/* ---------- převládající barva obrázku ---------- */
if (function_exists('imagecreatetruecolor')) {
    $temporary = tempnam(sys_get_temp_dir(), 'rs') . '.png';
    $canvas = imagecreatetruecolor(40, 20);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 200, 30, 60));
    imagepng($canvas, $temporary);
    check('Obrazky::barva: jednobarevný obrázek', Kaleta\Core\Obrazky::barva($temporary), '#c81e3c');
    unlink($temporary);
    check('Obrazky::barva: chybějící soubor', Kaleta\Core\Obrazky::barva($temporary), null);
}

/* ---------- skripty: nesmí hledat prvek (data-atribut), který nikde nevzniká – tak se rozbil dialog Médií ---------- */
$whereCreated = [
    'image/editor.js' => ['system/views/admin'], 'image/admin.js' => ['system/views/admin', 'system/src/Admin'], 'image/pomocnik.js' => ['system/views/admin'],
    'image/web.js' => ['system/views/front', 'system/src/Front', 'system/src/Stavitel/Prvky', 'layout'],
];
foreach ($whereCreated as $script => $folders) {
    $source = (string) file_get_contents(KALETA_ROOT . '/' . $script);
    preg_match_all('/querySelector(?:All)?\(\'\[(data-[a-z0-9-]+)\]\'\)/', $source, $links);
    $withoutSearch = (string) preg_replace('/(querySelector(All)?|closest|matches)\([^)]*\)/', '', $source);
    $missing = [];
    foreach (array_unique($links[1]) as $attribute) {
        $inTemplates = false;
        foreach ($folders as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_ROOT . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
                $inTemplates = $inTemplates || str_contains((string) file_get_contents($file->getPathname()), $attribute);
            }
        }
        if (!$inTemplates && !preg_match('/[\s"\']' . preg_quote($attribute, '/') . '[\s>="\']/', $withoutSearch) && !str_contains($withoutSearch, "setAttribute('" . $attribute . "'")) {
            $missing[] = $attribute;
        }
    }
    check($script . ': každý hledaný data-atribut někde vzniká', $missing, []);
}

/* ---------- administrace má Content-Security-Policy bez 'unsafe-inline': žádné inline skripty ani obsluhy událostí ---------- */
$inline = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_ROOT . '/system/views/admin', FilesystemIterator::SKIP_DOTS)) as $file) {
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>|\son(?:click|change|input|submit|load|error|key\w+|mouse\w+)="#i', $source)) {
        $inline[] = substr($file->getPathname(), strlen(KALETA_ROOT) + 1);
    }
}
check('šablony administrace neobsahují inline skripty (CSP)', $inline, []);

/* ---------- podpisy vydavatele: víc klíčů, výměna a odvolání klíče ---------- */
if (function_exists('sodium_crypto_sign_keypair')) {
    $pair = fn (): array => (fn (string $p): array => [sodium_crypto_sign_secretkey($p), sodium_crypto_sign_publickey($p)])(sodium_crypto_sign_keypair());
    [[$skPrimary, $pkPrimary], [$skBackup, $pkBackup], [$skNew, $pkNew], [$skForeign]] = [$pair(), $pair(), $pair(), $pair()];
    $sign = fn (string $message, string $sk): string => base64_encode(sodium_crypto_sign_detached($message, $sk));
    $pub = tempnam(sys_get_temp_dir(), 'rs');
    file_put_contents($pub, "# poznámka\n" . base64_encode($pkPrimary) . " provozni\n\nnesmysl-ktery-neni-klic\n" . base64_encode($pkBackup) . " zalozni 2026-09-20\n");
    $message = Kaleta\Core\Podpis::zpravaBalicku('3.0.1', str_repeat('A', 64), false);
    check('Podpis::klice: dva platné klíče, poznámky a nesmysly se přeskočí', count(Kaleta\Core\Podpis::klice($pub)), 2);
    check('Podpis: provozní klíč platí', Kaleta\Core\Podpis::plati($message, $sign($message, $skPrimary), $pub), true);
    check('Podpis: záložní klíč platí také', Kaleta\Core\Podpis::plati($message, $sign($message, $skBackup), $pub), true);
    check('Podpis: cizí klíč neplatí', Kaleta\Core\Podpis::plati($message, $sign($message, $skForeign), $pub), false);
    check('Podpis: poškozený podpis neplatí', Kaleta\Core\Podpis::plati($message, 'AAAA', $pub), false);
    check('Podpis: běžné vydání nejde prohlásit za bezpečnostní', Kaleta\Core\Podpis::plati(Kaleta\Core\Podpis::zpravaBalicku('3.0.1', str_repeat('A', 64), true), $sign($message, $skPrimary), $pub), false);
    check('Podpis: otisk balíčku se porovnává bez ohledu na velikost písmen', Kaleta\Core\Podpis::zpravaBalicku('3.0.1', 'ABC', false), '3.0.1|abc|bezne');
    // únik provozního klíče: vydání podepsané záložním přinese soubor bez něj a s novým provozním
    file_put_contents($pub, base64_encode($pkNew) . " provozni\n" . base64_encode($pkBackup) . " zalozni\n");
    check('výměna klíče: odvolaný klíč už neplatí', Kaleta\Core\Podpis::plati($message, $sign($message, $skPrimary), $pub), false);
    check('výměna klíče: nový provozní klíč platí', Kaleta\Core\Podpis::plati($message, $sign($message, $skNew), $pub), true);
    file_put_contents($pub, '');
    check('Podpis: bez klíčů neplatí nic', Kaleta\Core\Podpis::plati($message, $sign($message, $skNew), $pub), false);
    unlink($pub);
}
// Klíče vydavatele Kalety vzniknou až před prvním vydáním (docs/RELEASING.md); do té doby smí být soubor bez klíče, ale musí jít přečíst.
check('system/aktualizace.pub jde přečíst', is_array(Kaleta\Core\Podpis::klice(KALETA_ROOT . '/system/aktualizace.pub')), true);

/* ---------- instalátor: každý text má překlad ve všech jazycích ---------- */
$keys = [];
foreach (['system/views/install/formular.php', 'system/views/install/hotovo.php', 'system/src/Install/Installer.php'] as $file) {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(KALETA_ROOT . '/' . $file), $found);
    foreach ($found[1] as $text) {
        $keys[stripslashes($text)] = true;
    }
}
// karty rozšíření a ukázkových webů vypisuje instalátor přes t() z konstant
foreach ([...array_values(Kaleta\Core\Rozsireni::SEZNAM), ...array_values(Kaleta\Stavitel\Knihovna::WEBY)] as $card) {
    $keys[$card['nazev'] ?? $card[0]] = true;
    $keys[$card['popis'] ?? $card[1]] = true;
}
foreach (['en'] as $code) {
    $dictionary = require KALETA_ROOT . '/system/jazyky/install-' . $code . '.php';
    // mezinárodní slova se nepřekládají (nástroj na slovníky shodné položky nezapisuje)
    $missing = array_values(array_diff(array_keys($keys), array_keys($dictionary), ['Server', 'Port', 'E-mail', 'Newsletter']));
    check('instalátor: úplný slovník ' . $code, $missing, []);
}

/* ---------- čísla podle jazyka ---------- */
check('pocet: česky mezera jako oddělovač tisíců', Kaleta\Core\Jazyk::docasne('cs', fn () => pocet(1234567)), "1\u{00A0}234\u{00A0}567");
check('pocet: anglicky čárka a desetinná tečka', Kaleta\Core\Jazyk::docasne('en', fn () => pocet(12345.678, 2)), '12,345.68');
check('Soubory::velikost: anglicky desetinná tečka', Kaleta\Core\Jazyk::docasne('en', fn () => Kaleta\Core\Soubory::velikost(3 * 1048576 + 524288)), '3.5 MB');

/* ---------- marketingové kódy a souhlas ---------- */
check('Seo::cekaNaSouhlas: bez lišty beze změny', Kaleta\Front\Seo::cekaNaSouhlas('<script src="x.js"></script>', 'zadna'), '<script src="x.js"></script>');
check('Seo::cekaNaSouhlas: vestavěná lišta balí do <template>', Kaleta\Front\Seo::cekaNaSouhlas('<ins></ins><script>a()</script>', 'vestavena'), '<template data-souhlas="marketing"><ins></ins><script>a()</script></template>');
check('Seo::cekaNaSouhlas: externí služba dostane značené skripty', Kaleta\Front\Seo::cekaNaSouhlas('<ins></ins><SCRIPT async src="x.js"></script><script type="application/json">{}</script>', 'externi'),
    '<ins></ins><script type="text/plain" data-cookieconsent="marketing" async src="x.js"></script><script type="application/json">{}</script>');

/* ---------- aktualizace: úklid souborů, které nové vydání už neobsahuje ---------- */
$cleanup = sys_get_temp_dir() . '/kaleta-uklid-' . bin2hex(random_bytes(4));
mkdir($cleanup . '/system/stare', 0775, true);
mkdir($cleanup . '/media', 0775, true);
foreach (['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', 'vlastni.php'] as $f) {
    file_put_contents($cleanup . '/' . $f, 'x');
}
$deleted = Kaleta\Core\Aktualizace::uklidZastarale($cleanup, ['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', '../mimo.php'], ['index.php', 'system/zustava.php']);
check('Aktualizace: smaže jen soubor zrušený novým vydáním', $deleted, 1);
check('Aktualizace: zrušený soubor i jeho prázdná složka jsou pryč', is_dir($cleanup . '/system/stare'), false);
check('Aktualizace: chráněné cesty a vlastní soubory zůstávají', [is_file($cleanup . '/media/foto.jpg'), is_file($cleanup . '/config.php'), is_file($cleanup . '/vlastni.php'), is_file($cleanup . '/system/zustava.php')], [true, true, true, true]);
exec('rm -rf ' . escapeshellarg($cleanup));

/* ---------- přihlašovací klíče (WebAuthn): softwarový autentikátor proti ověřovacímu jádru ---------- */
$pkRp = 'redakce.example'; $pkOrigin = 'https://redakce.example';
$pkKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pkDescription = openssl_pkey_get_details($pkKey);
$pkDer = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkDescription['key']));
$pkX = str_pad($pkDescription['ec']['x'], 32, "\0", STR_PAD_LEFT); $pkY = str_pad($pkDescription['ec']['y'], 32, "\0", STR_PAD_LEFT);
$pkCose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $pkX . "\x22\x58\x20" . $pkY;
$pkId = random_bytes(20);
$pkClient = static fn (string $type, string $challenge, string $origin): string => Kaleta\Core\Passkey::b64((string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES));
$pkRegData = static fn (string $rp, int $flags = 0x45): string => hash('sha256', $rp, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($pkId)) . $pkId . $pkCose;
$pkChallenge = Kaleta\Core\Passkey::vyzva();
$pkReg = ['clientDataJSON' => $pkClient('webauthn.create', $pkChallenge, $pkOrigin), 'authenticatorData' => Kaleta\Core\Passkey::b64($pkRegData($pkRp)), 'publicKey' => Kaleta\Core\Passkey::b64($pkDer), 'publicKeyAlgorithm' => -7];
$pkSaved = Kaleta\Core\Passkey::overRegistraci($pkReg, $pkChallenge, $pkOrigin, $pkRp);
check('Passkey: registrace vrátí id klíče', $pkSaved['id'], Kaleta\Core\Passkey::b64($pkId));
check('Passkey: registrace vrátí veřejný klíč v PEM', str_contains($pkSaved['klic'], 'BEGIN PUBLIC KEY'), true);
$pkRejects = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException) { return true; } };
check('Passkey: registrace s cizí výzvou neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overRegistraci($pkReg, Kaleta\Core\Passkey::vyzva(), $pkOrigin, $pkRp)), true);
check('Passkey: registrace z jiného původu neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overRegistraci($pkReg, $pkChallenge, 'https://podvrh.example', $pkRp)), true);
check('Passkey: registrace pro jinou doménu neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overRegistraci($pkReg, $pkChallenge, $pkOrigin, 'jina.example')), true);
$pkForeign = openssl_pkey_get_details(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
check('Passkey: podstrčený veřejný klíč neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overRegistraci(['publicKey' => Kaleta\Core\Passkey::b64(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkForeign['key'])))] + $pkReg, $pkChallenge, $pkOrigin, $pkRp)), true);
check('Passkey: odpověď z přihlášení nejde použít k registraci', $pkRejects(fn () => Kaleta\Core\Passkey::overRegistraci(['clientDataJSON' => $pkClient('webauthn.get', $pkChallenge, $pkOrigin)] + $pkReg, $pkChallenge, $pkOrigin, $pkRp)), true);
$pkSignIn = static function (string $challenge, int $counter, string $rp = 'redakce.example', string $origin = 'https://redakce.example', int $flags = 0x05) use ($pkKey, $pkClient): array {
    $data = hash('sha256', $rp, true) . chr($flags) . pack('N', $counter);
    $client = $pkClient('webauthn.get', $challenge, $origin);
    openssl_sign($data . hash('sha256', Kaleta\Core\Passkey::zB64($client), true), $signature, $pkKey, OPENSSL_ALGO_SHA256);

    return ['clientDataJSON' => $client, 'authenticatorData' => Kaleta\Core\Passkey::b64($data), 'signature' => Kaleta\Core\Passkey::b64($signature)];
};
$pkV2 = Kaleta\Core\Passkey::vyzva();
check('Passkey: platné přihlášení vrátí nové počitadlo', Kaleta\Core\Passkey::overPrihlaseni($pkSignIn($pkV2, 5), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 4), 5);
check('Passkey: synchronizovaný klíč s nulovým počitadlem projde', Kaleta\Core\Passkey::overPrihlaseni($pkSignIn($pkV2, 0), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 0), 0);
check('Passkey: přehraná odpověď (jiná výzva) neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkSignIn($pkV2, 6), Kaleta\Core\Passkey::vyzva(), $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: počitadlo, které neroste, neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkSignIn($pkV2, 5), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: podpis jiným klíčem neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkSignIn($pkV2, 6), $pkV2, $pkOrigin, $pkRp, $pkForeign['key'], 5)), true);
check('Passkey: odpověď z podvržené domény neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example.podvrh.cz'), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: klíč jiné domény neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkSignIn($pkV2, 6, 'jina.example'), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: bez potvrzení přítomnosti uživatele neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example', 0x00), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
$pkChanged = $pkSignIn($pkV2, 6); $pkChanged['authenticatorData'] = Kaleta\Core\Passkey::b64(Kaleta\Core\Passkey::zB64($pkChanged['authenticatorData']) . 'x');
check('Passkey: pozměněná data zařízení neprojdou', $pkRejects(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkChanged, $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: původ a doména z adresy webu', [Kaleta\Core\Passkey::puvod('https://WWW.Web.cz/'), Kaleta\Core\Passkey::puvod('http://localhost:8080'), Kaleta\Core\Passkey::rpId('https://www.web.cz:8443/x')], ['https://www.web.cz', 'http://localhost:8080', 'www.web.cz']);

/* ---------- .htaccess: cíle přepisů jsou adresy, ne relativní cesty ---------- */
// Relativní cíl (RewriteRule ^ index.php) skončí na hostinzích, které mapují subdomény do složky mimo kořen webu, smyčkou a chybou 500.
$htaccess = (string) file_get_contents(KALETA_ROOT . '/.htaccess');
preg_match_all('/^\s*RewriteRule\s+\S+\s+(\S+)/m', $htaccess, $targets);
check('.htaccess: žádný přepis nemá relativní cíl', array_values(array_filter($targets[1], static fn (string $c): bool => $c !== '-' && !str_starts_with($c, '%{ENV:BASE}/'))), []);
check('.htaccess: složka webu se počítá z adresy požadavku', str_contains($htaccess, 'E=BASE:%1'), true);

/* ---------- cesty v nabídce jako odkazy (hlášky, Stav systému, nápovědy) ---------- */
Kaleta\Core\Jazyk::nastav('cs', 'admin-');
$routesHtml = Kaleta\Admin\Cesty::odkazy('/admin.php', 'Je k dispozici nová verze 3.0.1 – nainstalujete ji v Nastavení → Zálohy a aktualizace. <b>', ['config']);
check('Cesty: známá cesta je odkaz', str_contains($routesHtml, '<a href="/admin.php?modul=config&amp;zalozka=zalohy">Nastavení → Zálohy a aktualizace</a>'), true);
check('Cesty: zbytek textu zůstává escapovaný', str_contains($routesHtml, '&lt;b&gt;'), true);
check('Cesty: delší cesta má přednost a odkaz se nevnořuje', substr_count($routesHtml, '<a '), 1);
check('Cesty: bez práva k modulu žádný odkaz', str_contains(Kaleta\Admin\Cesty::odkazy('/admin.php', 'Nastavení → Pošta', []), '<a '), false);
Kaleta\Core\Jazyk::nastav('en', 'admin-');
check('Cesty: v angličtině se odkazuje přeložená cesta', str_contains(Kaleta\Admin\Cesty::odkazy('/admin.php', t('Je k dispozici nová verze %s – nainstalujete ji v Nastavení → Zálohy a aktualizace.', '3.0.1'), ['config']), '>Settings → Backups and updates</a>'), true);
Kaleta\Core\Jazyk::nastav('cs', 'admin-');

/* ---------- antispam: otisk IP ---------- */
check('Antispam::otisk: není to IP adresa', str_contains(Kaleta\Core\Antispam::otisk('203.0.113.7'), '203'), false);
check('Antispam::otisk: stejná adresa = stejný otisk', Kaleta\Core\Antispam::otisk('203.0.113.7'), Kaleta\Core\Antispam::otisk('203.0.113.7'));

/* ---------- import z WordPressu: čtení exportu (tools/fixtures/wordpress-sample.xml), náhled, bezpečné XML ---------- */
$wpPath = KALETA_ROOT . '/tools/fixtures/wordpress-sample.xml';
$wpRejects = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException) { return true; } };
$wp = new Kaleta\Core\WpSoubor($wpPath);
check('WpSoubor: ukázkový export projde ověřením', $wpRejects(fn () => $wp->over()), false);
$wpHeader = $wp->hlavicka();
check('WpSoubor: starý web z <channel><link>', [$wpHeader['nazev'], $wpHeader['adresa']], ['Podhorský zpravodaj', 'https://www.podhorsky-zpravodaj.example']);
check('WpSoubor: autoři jako přihlašovací jméno => zobrazované jméno', $wpHeader['autori'], ['redakce' => 'Redakce Zpravodaje', 'bhorakova' => 'Běla Horáková']);
check('WpSoubor: rubriky s hierarchií', $wpHeader['rubriky'], ['zpravy' => ['nazev' => 'Zprávy', 'predek' => ''], 'z-radnice' => ['nazev' => 'Z radnice', 'predek' => 'zpravy']]);
check('WpSoubor: tři štítky', array_keys($wpHeader['stitky']), ['most', 'doprava', 'slavnosti']);
$wpItems = iterator_to_array($wp->polozky());
check('WpSoubor: devět položek, typy v pořadí souboru', array_column($wpItems, 'typ'), ['post', 'post', 'post', 'post', 'page', 'nav_menu_item', 'attachment', 'attachment', 'attachment']);
check('WpSoubor: přeskočení už zpracovaných položek drží pořadí', array_keys(iterator_to_array($wp->polozky(7))), [7, 8]);
check('WpSoubor: první příspěvek', [$wpItems[0]['id'], $wpItems[0]['stav'], $wpItems[0]['pripnuty'], $wpItems[0]['nahled'], $wpItems[0]['rubriky'], array_keys($wpItems[0]['stitky'])], [101, 'publish', true, 201, ['z-radnice' => 'Z radnice'], ['most', 'doprava']]);
check('WpSoubor: komentáře se nečtou', array_key_exists('komentare', $wpItems[0]), false);
check('WpSoubor: e-mail ani IP se z exportu nikam nedostanou', (bool) preg_match('/posta\.example|198\.51\.100|203\.0\.113/', (string) json_encode($wpItems)), false);
$wpState = Kaleta\Core\WpImport::novyStav('wordpress-sample.xml');
Kaleta\Core\WpImport::analyzuj($wpState, 30, $wpPath);
check('WpImport náhled: fáze a počet položek', [$wpState['faze'], $wpState['celkem'], $wpState['pozice']], ['nahled', 9, 0]);
check('WpImport náhled: příspěvky podle stavu a stránky', [$wpState['prehled']['clanky'], $wpState['prehled']['stranky']], [['publish' => 3, 'draft' => 1], ['publish' => 1]]);
check('WpImport náhled: kategorie, štítky, autoři, přílohy', [$wpState['prehled']['rubriky'], $wpState['prehled']['stitky'], $wpState['prehled']['autori'], $wpState['prehled']['prilohy']], [2, 3, 2, 3]);
check('WpImport náhled: upozorní na cizí typ obsahu a zkratku doplňku', [$wpState['prehled']['jine'], $wpState['prehled']['zkratky']], [['nav_menu_item' => 1], ['kontaktni-formular' => 1]]);
check('WpImport náhled: adresy příloh pro galerie a hlavní obrázky', $wpState['prilohy'][202] ?? '', 'https://www.podhorsky-zpravodaj.example/wp-content/uploads/2026/05/pohled.jpg');

$wpTmp = sys_get_temp_dir() . '/kaleta-wp-' . bin2hex(random_bytes(4));
mkdir($wpTmp);
$wpHead = '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title><link>https://stary.example</link>';
file_put_contents($wpTmp . '/tajne.txt', 'TAJNY-OBSAH-SERVERU');
$wpMalicious = [
    'vnější entita (XXE)' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://' . $wpTmp . '/tajne.txt">]>' . $wpHead . '<item><title>&xxe;</title><content:encoded>&xxe;</content:encoded></item></channel></rss>',
    'miliarda smíchů' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "haha"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;">]>' . $wpHead . '<item><title>&c;</title></item></channel></rss>',
    'vnější DTD' => '<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://127.0.0.1:1/zly.dtd">' . $wpHead . '</channel></rss>',
    'nedefinovaná entita' => '<?xml version="1.0"?>' . $wpHead . '<item><title>&neexistuje;</title></item></channel></rss>',
    'jiné XML než export WordPressu' => '<?xml version="1.0"?><rss version="2.0"><channel><title>Obyčejné RSS</title><item><title>x</title></item></channel></rss>',
    'poškozené XML' => '<?xml version="1.0"?>' . $wpHead . '<item><title>neuzavřeno</item>',
    'HTML místo XML' => '<html><body>xmlns:wp="http://wordpress.org/export/1.2/"</body></html>',
];
foreach ($wpMalicious as $label => $xml) {
    file_put_contents($wpTmp . '/zly.xml', $xml);
    $read = '';
    $rejected = $wpRejects(function () use ($wpTmp, &$read): void {
        $bad = new Kaleta\Core\WpSoubor($wpTmp . '/zly.xml');
        $bad->over();
        $read = (string) json_encode([$bad->hlavicka(), iterator_to_array($bad->polozky())]);
    });
    check('WpSoubor odmítne: ' . $label, [$rejected, str_contains($read, 'TAJNY-OBSAH') || str_contains($read, 'hahahaha')], [true, false]);
}
file_put_contents($wpTmp . '/dobry.xml', '<?xml version="1.0"?>' . $wpHead . '<item><title>A &amp; B</title></item></channel></rss>');
check('WpSoubor: běžné entity (&amp;) jsou v pořádku', iterator_to_array((new Kaleta\Core\WpSoubor($wpTmp . '/dobry.xml'))->polozky())[0]['titulek'], 'A & B');
exec('rm -rf ' . escapeshellarg($wpTmp));
foreach (['export.xml' => true, 'Můj web.WordPress.2026-09-21.XML' => true, '../config.xml' => false, 'slozka/export.xml' => false, '.skryty.xml' => false, 'export.php' => false, 'export.xml.php' => false, "export\0.xml" => false, '' => false] as $name => $expectedResult) {
    check('WpSoubor::platnyNazev ' . json_encode((string) $name), Kaleta\Core\WpSoubor::platnyNazev((string) $name), $expectedResult);
}
check('WpSoubor: název nahraného souboru bez diakritiky a vždy .xml', Kaleta\Core\WpSoubor::nazevProNahrani('Můj web.WordPress.2026-09-21.xml'), 'muj-web-wordpress-2026-09-21.xml');

/* ---------- import z WordPressu: čištění obsahu ---------- */
$wpClean = Kaleta\Core\WpObsah::vycisti(...);
check('WpObsah: klasický editor – odstavce z prázdných řádků, <br> z konců řádků', $wpClean("První řádek\ndruhý řádek\n\nDruhý odstavec"), "<p>První řádek<br>\ndruhý řádek</p>\n<p>Druhý odstavec</p>");
check('WpObsah: blokové značky se do <p> nebalí', $wpClean("Úvod\n\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>"), "<p>Úvod</p>\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>");
check('WpObsah: komentáře Gutenbergu mizí, odstavce zůstávají', $wpClean("<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Nadpis</h1>\n<!-- /wp:heading -->"), "<p>Text</p>\n<h2>Nadpis</h2>");
check('WpObsah: [caption] → figure s popiskem, odkaz na velký obrázek mizí', $wpClean('[caption id="attachment_5" align="alignnone" width="300"]<a href="https://stary.example/wp-content/uploads/most.jpg"><img class="size-medium" src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" /></a> Most přes řeku[/caption]'), '<figure><img src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" loading="lazy"><figcaption>Most přes řeku</figcaption></figure>');
check('WpObsah: [gallery ids] → naše galerie jen ze známých obrázků', $wpClean('[gallery ids="5,6,7,99" columns="2"]', [5 => 'https://stary.example/a.jpg', 6 => 'https://stary.example/b.png', 7 => 'https://stary.example/dokument.pdf']), '<figure class="galerie"><img src="https://stary.example/a.jpg" alt="" loading="lazy"><img src="https://stary.example/b.png" alt="" loading="lazy"></figure>');
check('WpObsah: blok galerie Gutenbergu → naše galerie', $wpClean('<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://stary.example/a.jpg" alt="A" class="wp-image-5"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->'), '<figure class="galerie"><img src="https://stary.example/a.jpg" alt="A" loading="lazy"></figure>');
check('WpObsah: adresa YouTube na samostatném řádku je vlastní odstavec', $wpClean("Text před\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nText po"), "<p>Text před</p>\n<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>\n<p>Text po</p>");
check('WpObsah: takový odstavec web promění v přehrávač', str_contains((new ReflectionClass(TextNovinky::class))->newInstanceWithoutConstructor()->vlozeneAdresy($wpClean("https://www.youtube.com/watch?v=dQw4w9WgXcQ")), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), true);
check('WpObsah: blok embed → adresa v odstavci', $wpClean('<!-- wp:embed {"url":"https://vimeo.com/76979871","type":"video"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/76979871</div></figure><!-- /wp:embed -->'), '<p>https://vimeo.com/76979871</p>');
check('WpObsah: iframe YouTube → adresa, cizí iframe pryč', $wpClean('<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560"></iframe><iframe src="https://zly.example/"></iframe>'), '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>');
check('WpObsah: zkratky doplňků mizí, jejich text a [sic] zůstávají', $wpClean('[vc_row][vc_column width="1/2"]Text uvnitř[/vc_column][/vc_row] [contact-form-7 id="1"] citace [sic] a [[ukázka]]'), '<p>Text uvnitř  citace [sic] a [[ukázka]]</p>');
check('WpObsah: v ukázce kódu se závorky nemění', $wpClean("<pre>pole[muj_klic] = 1;\n\nkonec</pre>"), "<pre>pole[muj_klic] = 1;\n\nkonec</pre>");
$wpUnsafe = $wpClean('<p onclick="x()" style="color:red">Klik <a href="java&#9;script:alert(1)" onmouseover="x()">odkaz</a> <a href="https://dobry.example/" target="_blank">ven</a></p><script>alert(1)</script><style>p{}</style><img src="data:image/svg+xml;base64,AAAA"><img src="https://stary.example/a.jpg" onerror="alert(1)" srcset="x 2x"><svg onload="alert(1)"><circle/></svg><form action="/x"><input name="a"></form><object data="x"></object><div class="obal"><span>Text v divu</span></div>');
check('WpObsah: skripty, styly, obsluhy událostí, javascript: a data: adresy neprojdou', $wpUnsafe, "<p>Klik odkaz <a href=\"https://dobry.example/\" target=\"_blank\" rel=\"noopener\">ven</a></p>\n<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"></figure>\n<p>Text v divu</p>");
check('WpObsah: po čištění nezbyde nic nebezpečného', (bool) preg_match('/<script|<style|<svg|<form|<iframe|<object|\son[a-z]+=|javascript:|data:|style=|srcset=/i', $wpUnsafe), false);
check('WpObsah::bezpecnaAdresa', array_map(Kaleta\Core\WpObsah::bezpecnaAdresa(...), ['https://a.cz/', '/clanek/x', '#kotva', 'mailto:a@b.cz', "java\nscript:alert(1)", ' JAVASCRIPT:alert(1)', 'data:text/html,x', 'vbscript:x', '']), [true, true, true, true, false, false, false, false, false]);
check('WpObsah: perex z výtahu WordPressu, text celý', Kaleta\Core\WpObsah::perexAText('Ruční <b>výtah</b> &amp; spol.', "Odstavec jedna\n\nOdstavec dva"), ['<p>Ruční výtah &amp; spol.</p>', "<p>Odstavec jedna</p>\n<p>Odstavec dva</p>"]);
check('WpObsah: bez výtahu je perexem první odstavec a v textu se neopakuje', Kaleta\Core\WpObsah::perexAText('', "[caption]<img src=\"https://stary.example/a.jpg\" alt=\"\"> Popisek[/caption]\n\nOdstavec jedna\n\nOdstavec dva"), ['<p>Odstavec jedna</p>', "<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"><figcaption>Popisek</figcaption></figure>\n<p>Odstavec dva</p>"]);
check('WpObsah: značka „Číst dál“ dělí perex a text', Kaleta\Core\WpObsah::perexAText('', "Před značkou\n<!--more-->\nZa značkou"), ['<p>Před značkou</p>', '<p>Za značkou</p>']);
check('WpObsah: cizí zkratky pro varování v náhledu', Kaleta\Core\WpObsah::ciziZkratky('[gallery ids="1"] [caption]x[/caption] [et_pb_section]a[/et_pb_section] [sic] <code>[muj_klic]</code>'), ['et_pb_section']);
[$wpIntro, $wpText] = Kaleta\Core\WpObsah::perexAText($wpItems[0]['perex'], $wpItems[0]['obsah'], $wpState['prilohy']);
check('WpObsah: ukázkový příspěvek – perex, obrázek s popiskem, video, galerie, bez skriptu a zkratky', [str_starts_with($wpIntro, '<p>Po dvanácti měsících'), substr_count($wpText, '<figcaption>'), str_contains($wpText, '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>'), substr_count($wpText, 'class="galerie"'), (bool) preg_match('/script|onclick|kontaktni-formular|javascript/i', $wpText)], [true, 1, true, 1, false]);

/* ---------- import z WordPressu: stav, datum, adresy ---------- */
$wpStatuses = [];
foreach (['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'nesmysl'] as $wpS) {
    $wpM = Kaleta\Core\WpImport::stavClanku($wpS);
    $wpStatuses[$wpS] = $wpM === null ? 'vynechat' : ($wpM['visible'] ? 'vydany' : 'koncept');
}
check('WpImport::stavClanku', $wpStatuses, ['publish' => 'vydany', 'future' => 'vydany', 'draft' => 'koncept', 'pending' => 'koncept', 'private' => 'vynechat', 'trash' => 'vynechat', 'auto-draft' => 'vynechat', 'inherit' => 'vynechat', 'nesmysl' => 'vynechat']);
check('WpImport::stavClanku: příspěvek chráněný heslem se nezveřejní', Kaleta\Core\WpImport::stavClanku('publish', true), ['visible' => 0]);
check('WpImport::datum: místní čas starého webu', Kaleta\Core\WpImport::datum(['datum' => '2026-05-12 09:30:00', 'datum_gmt' => '2026-05-12 07:30:00']), '2026-05-12 09:30:00');
check('WpImport::datum: koncept s nulovým datem dostane dnešek', Kaleta\Core\WpImport::datum(['datum' => '0000-00-00 00:00:00', 'datum_gmt' => '0000-00-00 00:00:00', 'vydano' => ''], 1789000000), date('Y-m-d H:i:s', 1789000000));
$wpTaken = ['lavka', 'lavka-2'];
check('WpImport::volnaAdresa: obsazená adresa dostane číslo', Kaleta\Core\WpImport::volnaAdresa('lavka', fn (string $a): bool => in_array($a, $wpTaken, true)), 'lavka-3');
check('WpImport::volnaAdresa: volná zůstává', Kaleta\Core\WpImport::volnaAdresa('most', fn (string $a): bool => in_array($a, $wpTaken, true)), 'most');
check('WpImport::staraCesta', array_map(Kaleta\Core\WpImport::staraCesta(...), ['https://stary.example/2026/05/lavka/', 'https://stary.example/?p=104', 'https://stary.example/blog/p%C5%99%C3%ADklad/', 'https://stary.example/' . str_repeat('x', 300)]), ['2026/05/lavka', '', 'blog/příklad', '']);
check('WpImport::bezRozmeru', array_map(Kaleta\Core\WpImport::bezRozmeru(...), ['https://s.example/u/foto-300x200.jpg', 'https://s.example/u/foto-1024x683.JPG?ver=2', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']), ['https://s.example/u/foto.jpg', 'https://s.example/u/foto.JPG', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']);
check('WpImport::zdroj: doména starého webu, nejvýš 40 znaků', [Kaleta\Core\WpImport::zdroj('https://WWW.Stary.example/blog'), Kaleta\Core\WpImport::zdroj(''), strlen(Kaleta\Core\WpImport::zdroj('https://' . str_repeat('a', 60) . '.example'))], ['wp:stary.example', 'wp', 40]);
check('ExportWebu::cesta: jen názvy exportů, nic mimo složku', [Kaleta\Core\ExportWebu::cesta('../config.php'), Kaleta\Core\ExportWebu::cesta('export-20260921-101500.zip/../../config.php'), Kaleta\Core\ExportWebu::cesta('kaleta-20260918-130917-rucni-7d777965.sql.gz')], [null, null, null]);

/* ---------- import z WordPressu: stahování obrázků jen ze starého webu a jen z veřejných adres (ochrana před SSRF) ---------- */
$wpDownload = new Kaleta\Core\StahovaniObrazku('https://www.stary-web.example/blog/');
check('StahovaniObrazku: doména starého webu bez www', $wpDownload->domena(), 'stary-web.example');
foreach ([
    'https://www.stary-web.example/wp-content/uploads/a.jpg' => true,
    'http://stary-web.example/a.png' => true,
    'https://STARY-WEB.example./a.png' => true,
    'https://stary-web.example:443/a.png' => true,
    'https://stary-web.example:8443/a.png' => false,                 // jiný port
    'http://stary-web.example:22/a.png' => false,
    'https://cdn.stary-web.example/a.png' => false,                  // jiná (pod)doména
    'https://stary-web.example.utocnik.example/a.png' => false,
    'https://utocnik.example/stary-web.example/a.png' => false,
    'https://stary-web.example@utocnik.example/a.png' => false,      // doména schovaná za jménem
    'https://uzivatel:heslo@stary-web.example/a.png' => false,       // přihlašovací údaje v adrese
    'ftp://stary-web.example/a.png' => false,
    'file:///etc/passwd' => false,
    'gopher://stary-web.example/' => false,
    '//stary-web.example/a.png' => false,
    'http://127.0.0.1/a.png' => false,
    'http://169.254.169.254/latest/meta-data/' => false,
    "https://stary-web.example/a.png\r\nHost: jinam" => false,       // vložené hlavičky
    'https://stary-web.example\\@utocnik.example/a.png' => false,
    '' => false,
] as $wpUrl => $expectedResult) {
    check('StahovaniObrazku::povolenaAdresa ' . json_encode((string) $wpUrl), $wpDownload->povolenaAdresa((string) $wpUrl), $expectedResult);
}
check('StahovaniObrazku: bez adresy starého webu se nestahuje nic', (new Kaleta\Core\StahovaniObrazku(''))->povolenaAdresa('https://cokoli.example/a.png'), false);
foreach ([
    '93.184.216.34' => true, '8.8.8.8' => true, '172.32.0.1' => true, '100.128.0.1' => true, '2606:4700:4700::1111' => true, '::ffff:93.184.216.34' => true,
    '10.0.0.5' => false, '172.16.0.1' => false, '172.31.255.255' => false, '192.168.1.1' => false, '127.0.0.1' => false, '127.255.255.254' => false,
    '169.254.169.254' => false, '100.64.0.1' => false, '100.127.255.255' => false, '0.0.0.0' => false, '0.1.2.3' => false, '224.0.0.1' => false, '255.255.255.255' => false,
    '192.0.2.10' => false, '198.18.0.1' => false, '::1' => false, '::' => false, 'fc00::1' => false, 'fd12:3456::1' => false, 'fe80::1' => false, 'ff02::1' => false,
    '::ffff:10.0.0.1' => false, '::ffff:127.0.0.1' => false, '64:ff9b::a00:1' => false, '::10.0.0.1' => false, '2002:a00:1::1' => false, '2001:0:4136:e378:8000:63bf:3fff:fdd2' => false,
    '2001:4860:4860::8888' => true, '[::1]' => false, 'neni-ip' => false, '' => false,
] as $wpIp => $expectedResult) {
    check('StahovaniObrazku::verejnaIp ' . $wpIp, Kaleta\Core\StahovaniObrazku::verejnaIp((string) $wpIp), $expectedResult);
}
check('StahovaniObrazku: IP adresa místo domény se posuzuje stejně', [$wpDownload->overenaIp('127.0.0.1'), $wpDownload->overenaIp('[::1]'), $wpDownload->overenaIp('93.184.216.34')], [null, null, '93.184.216.34']);
check('StahovaniObrazku: přesměrování na jinou doménu neprojde dalším kolem kontroly', $wpDownload->povolenaAdresa(Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/a.png', 'https://utocnik.example/a.png')), false);
check('StahovaniObrazku: přesměrování //jinam a do vnitřní sítě neprojde', [$wpDownload->povolenaAdresa(Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/a.png', '//utocnik.example/a.png')), $wpDownload->povolenaAdresa(Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/a.png', 'http://169.254.169.254/'))], [false, false]);
check('StahovaniObrazku: relativní přesměrování zůstává na starém webu', [Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/u/a.png', '/jinde/b.png'), Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/u/a.png', 'b.png')], ['https://stary-web.example/jinde/b.png', 'https://stary-web.example/u/b.png']);
$wpPng = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$wpSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
check('StahovaniObrazku::typObrazku: PNG podle hlavičky i obsahu', Kaleta\Core\StahovaniObrazku::typObrazku('image/png; charset=binary', $wpPng), 'image/png');
check('StahovaniObrazku::typObrazku: hlavička tvrdí obrázek, obsah je HTML', Kaleta\Core\StahovaniObrazku::typObrazku('image/jpeg', '<html><body>přihlášení</body></html>'), null);
check('StahovaniObrazku::typObrazku: obsah je obrázek, hlavička ne', Kaleta\Core\StahovaniObrazku::typObrazku('text/html', $wpPng), null);
check('StahovaniObrazku::typObrazku: SVG se odmítá vždy', [Kaleta\Core\StahovaniObrazku::typObrazku('image/svg+xml', $wpSvg), Kaleta\Core\StahovaniObrazku::typObrazku('image/png', $wpSvg)], [null, null]);
check('StahovaniObrazku::typObrazku: prázdná odpověď', Kaleta\Core\StahovaniObrazku::typObrazku('image/png', ''), null);
check('StahovaniObrazku: limity podle zadání (15 MB, 3 přesměrování, 5 s spojení, 20 s celkem)', [Kaleta\Core\StahovaniObrazku::MAX_BAJTU, Kaleta\Core\StahovaniObrazku::MAX_PRESMEROVANI, Kaleta\Core\StahovaniObrazku::CAS_SPOJENI, Kaleta\Core\StahovaniObrazku::CAS_CELKEM], [15 * 1024 * 1024, 3, 5, 20]);
$wpSourceHtml = (string) file_get_contents(KALETA_ROOT . '/system/src/Core/StahovaniObrazku.php');
check('StahovaniObrazku: přesměrování se nikdy nenásledují automaticky a nic se neposílá navíc', [substr_count($wpSourceHtml, 'CURLOPT_FOLLOWLOCATION => false'), str_contains($wpSourceHtml, "'follow_location' => 0"), (bool) preg_match('/CURLOPT_(COOKIE\w*|USERPWD|HTTPHEADER|HTTPAUTH)\b/', $wpSourceHtml), str_contains($wpSourceHtml, "'Kaleta-import'")], [1, true, false, true]);

/* ---------- builder: validátor, styl, design system, knihovna ---------- */
[$buildS, $buildErrors] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [
    ['typ' => 'nadpis', 'id' => 'abc', 'obsah' => ['text' => '<script>x</script>Ahoj <b>světe</b>']],
    ['typ' => 'neznamy'],
    ['typ' => 'html', 'obsah' => ['kod' => '<p>a</p>']],
    ['typ' => 'tlacitko', 'obsah' => ['odkaz' => 'javascript:alert(1)']],
    ['typ' => 'nadpis', 'id' => 'abc', 'deti' => [['typ' => 'text']]],
]], false);
check('Stavba::vycisti: skript z nadpisu pryč, tučné zůstane', $buildS['deti'][0]['obsah']['text'], 'Ahoj <b>světe</b>');
check('Stavba::vycisti: neznámý typ, cizí HTML a vnořené děti nadpisu vypadnou', array_map(fn (array $p): string => $p['typ'], $buildS['deti']), ['nadpis', 'tlacitko', 'nadpis']);
check('Stavba::vycisti: javascript: odkaz se zahodí a nahlásí', [$buildS['deti'][1]['obsah']['odkaz'], isset($buildErrors['deti[3].obsah.odkaz'])], ['', true]);
check('Stavba::vycisti: duplicitní id dostane nové', $buildS['deti'][2]['id'] !== 'abc', true);
check('Stavba::vycisti: chyby mají cestu', array_keys($buildErrors), ['deti[1]', 'deti[2]', 'deti[3].obsah.odkaz', 'deti[4].deti']);
$buildHtml = ['deti' => [['typ' => 'html', 'id' => 'h1x', 'obsah' => ['kod' => '<p onclick="x()">a</p><script>1</script><a href="javascript:x">b</a>']]]];
[$buildAdmin] = Kaleta\Stavitel\Stavba::vycisti($buildHtml, true);
check('Stavba::vycisti: vlastní HTML správce bez skriptů a obsluh', $buildAdmin['deti'][0]['obsah']['kod'], '<p>a</p><a href="#">b</a>');
[$buildEditor] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [['typ' => 'html', 'id' => 'h1x', 'obsah' => ['kod' => '<p>podvrh</p>']]]], false, $buildAdmin);
check('Stavba::vycisti: editor nezmění vlastní HTML správce, jen ho ponechá', $buildEditor['deti'][0]['obsah']['kod'], '<p>a</p><a href="#">b</a>');
$buildDeep = ['typ' => 'text'];
for ($i = 0; $i < 20; $i++) {
    $buildDeep = ['typ' => 'kontejner', 'deti' => [$buildDeep]];
}
[, $buildErrors] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [$buildDeep]]);
check('Stavba::vycisti: hloubka je omezená', count($buildErrors), 1);
[$buildMany, $buildErrors] = Kaleta\Stavitel\Stavba::vycisti(['deti' => array_fill(0, 900, ['typ' => 'oddelovac'])]);
check('Stavba::vycisti: počet prvků je omezený', [count($buildMany['deti']), count($buildErrors)], [Kaleta\Stavitel\Stavba::MAX_PRVKU, 1]);
check('Stavba::vycisti: obrázek jen z Médií nebo https', Kaleta\Stavitel\Stavba::vycisti(['deti' => [['typ' => 'obrazek', 'obsah' => ['src' => 'http://x.cz/a.jpg']], ['typ' => 'obrazek', 'obsah' => ['src' => 'media/2026/a.jpg']]]])[0]['deti'][1]['obsah']['src'], 'media/2026/a.jpg');
check('Stavba::zTextu: nadpis h1 a text', array_map(fn (array $p): string => $p['znacka'], Kaleta\Stavitel\Stavba::zTextu('O nás', '<p>x</p>')['deti'][0]['deti']), ['h1', 'div']);
$buildStyleErrors = [];
check('Styl::vycisti: vloženo CSS, neznámá vlastnost a stav vypadnou', Kaleta\Stavitel\Styl::vycisti(['zaklad' => ['barva' => 'red;}body{x:y', 'neznama' => '1', 'sirka' => '50%'], 'tisk' => []], 's', $buildStyleErrors), ['zaklad' => ['sirka' => '50%']]);
check('Styl::vycisti: chyby', array_keys($buildStyleErrors), ['s.zaklad.barva', 's.zaklad.neznama', 's.tisk']);
check('Styl::css: tokeny, sloupce, hover a breakpoint', Kaleta\Stavitel\Styl::css('#s-a', ['zaklad' => ['odsazeni_y' => 'xl', 'barva' => 'primarni', 'sloupce' => '3'], 'mobil' => ['sloupce' => '1'], 'hover' => ['barva' => '#ff0000']]),
    "#s-a { padding-block: var(--ka-mezera-xl); color: var(--ka-barva-primarni); grid-template-columns: repeat(3, minmax(0, 1fr)); }\n#s-a:is(:hover, :focus-visible) { color: #ff0000; }\n@media (max-width: 767px) { #s-a { grid-template-columns: repeat(1, minmax(0, 1fr)); } }\n");
check('Html::bezpecne: bez skriptů, obsluh událostí a javascript:, se strukturou a třídami', Kaleta\Core\Html::bezpecne('<p class="x" onclick="a()">A <a href="javascript:alert(1)">b</a><img src="x" onerror="alert(1)"><script>alert(1)</script></p><iframe src="https://x"></iframe><a href="/k" target="_blank" data-vlozit="javascript:x">k</a>'),
    '<p class="x">A <a>b</a><img src="x"></p><a href="/k" target="_blank" rel="noopener">k</a>');
check('Stavba: háčky skriptů webu nejdou vložit jako vlastní atribut', [preg_match(Kaleta\Stavitel\Stavba::VZOR_ATRIBUT, 'data-vlozit'), preg_match(Kaleta\Stavitel\Stavba::VZOR_ATRIBUT, 'data-samo'), preg_match(Kaleta\Stavitel\Stavba::VZOR_ATRIBUT, 'data-sledovat')], [0, 0, 1]);
check('Styl::css: najetí a stisk zvlášť pro tablet a mobil', Kaleta\Stavitel\Styl::css('#x', ['mobil' => ['mezera' => 's'], 'hover_mobil' => ['barva' => 'primarni'], 'aktivni_tablet' => ['meritko' => '0.95']]),
    "@media (max-width: 1023px) { #x:active { scale: 0.95; } }\n@media (max-width: 767px) { #x { gap: var(--ka-mezera-s); } #x:is(:hover, :focus-visible) { color: var(--ka-barva-primarni); } }\n");
check('Styl::css: typografický styl první, jednotlivé vlastnosti ho doladí', Kaleta\Stavitel\Styl::css('#x', ['zaklad' => ['velikost_pisma' => '3', 'typ_styl' => 'nadtitulek']]),
    "#x { font: var(--ka-typ-nadtitulek); text-transform: uppercase; letter-spacing: 0.08em; font-size: var(--ka-krok-3); }\n");
check('Styl: vlastní stín a rámeček s tokeny barev', [Kaleta\Stavitel\Styl::hodnota('stin', '0 8px 24px 0 primarni'), Kaleta\Stavitel\Styl::hodnota('stin', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)'), Kaleta\Stavitel\Styl::hodnota('ramecek', '2px dashed primarni')],
    ['0 8px 24px 0 var(--ka-barva-primarni)', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)', '2px dashed var(--ka-barva-primarni)']);
check('Styl: stín a rámeček nepustí nic nebezpečného', [Kaleta\Stavitel\Styl::hodnota('stin', '0 0 1px url(x)'), Kaleta\Stavitel\Styl::hodnota('stin', '0 0 red; color: red'), Kaleta\Stavitel\Styl::hodnota('ramecek', '1px solid red}')], [null, null, null]);
check('Styl: mřížka – řádky, oblasti a oblast prvku', [Kaleta\Stavitel\Styl::hodnota('radky', '3'), Kaleta\Stavitel\Styl::hodnota('oblasti', 'hlava hlava / bok obsah'), Kaleta\Stavitel\Styl::hodnota('oblasti', 'a b / c'), Kaleta\Stavitel\Styl::hodnota('oblast', 'bok'), Kaleta\Stavitel\Styl::hodnota('oblast', 'x"y')],
    ['repeat(3, auto)', '"hlava hlava" "bok obsah"', null, 'bok', null]);
check('DesignSystem: typografické styly jako tokeny, úprava ve Vzhledu', [str_contains(Kaleta\Stavitel\DesignSystem::css(Kaleta\Stavitel\DesignSystem::vycisti([])), '--ka-typ-perex: 400 var(--ka-krok-1)/1.55 var(--ka-pismo-text);'),
    str_contains(Kaleta\Stavitel\DesignSystem::css(Kaleta\Stavitel\DesignSystem::vycisti(['typografie' => ['perex' => ['krok' => '2', 'tloustka' => '500'], 'titulek' => ['krok' => '99']]])), '--ka-typ-perex: 500 var(--ka-krok-2)/1.55'),
    Kaleta\Stavitel\DesignSystem::vycisti(['typografie' => ['titulek' => ['krok' => '99']]])['typografie']], [true, true, []]);
check('Styl::css: obrázek pozadí z Médií od kořene instalace', str_contains(Kaleta\Stavitel\Styl::css('#s', ['zaklad' => ['obrazek_pozadi' => 'media/2026/09/a.jpg']], '', '/web'), 'url("/web/media/2026/09/a.jpg")'), true);
$takenSlugs = ['o-nas' => 1, 'o-nas-2' => 1, str_repeat('a', 10) => 1];
check('Volná adresa: číslo za obsazenou, s číslem se vejde do sloupce', [
    Kaleta\Core\Adresa::volna('o-nas', fn (string $a): bool => isset($takenSlugs[$a])),
    Kaleta\Core\Adresa::volna('sluzby', fn (string $a): bool => isset($takenSlugs[$a])),
    Kaleta\Core\Adresa::volna(str_repeat('a', 12), fn (string $a): bool => isset($takenSlugs[$a]), 10),
], ['o-nas-3', 'sluzby', 'aaaaaaaa-2']);
check('Kontejner jako odkaz: odkazy uvnitř se změní na span', Kaleta\Stavitel\Prvky\Kontejner::vykresli(['znacka' => 'div', 'obsah' => ['odkaz' => '/k']], '', '<p>x</p><a class="ka-tlacitko" href="/y" target="_blank">B</a><abbr>z</abbr>', new Kaleta\Stavitel\Kontext((new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor())),
    '<a class="ka-karta-odkaz" href="/k"><p>x</p><span class="ka-tlacitko">B</span><abbr>z</abbr></a>');
check('Menu::vycisti: neznámý typ, nebezpečná adresa a třetí úroveň vypadnou', Kaleta\Core\Menu::vycisti([
    ['typ' => 'skript'], ['typ' => 'odkaz', 'text' => 'X', 'url' => 'javascript:alert(1)'],
    ['typ' => 'skupina', 'text' => 'Služby', 'deti' => [['typ' => 'stranka', 'ids' => 3, 'deti' => [['typ' => 'novinky']]], ['typ' => 'odkaz', 'text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => 1]]],
]), [['typ' => 'skupina', 'text' => 'Služby', 'deti' => [['typ' => 'stranka', 'text' => '', 'ids' => 3], ['typ' => 'odkaz', 'text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => true]]]]);
check('Menu::html: podmenu, aktivní položka a větev, úvod jen přesnou shodou', Kaleta\Core\Menu::html([
    ['text' => 'Úvod', 'url' => '/', 'nove_okno' => false, 'deti' => []],
    ['text' => 'Služby', 'url' => '', 'nove_okno' => false, 'deti' => [['text' => 'Kuchyně', 'url' => '/kuchyne', 'nove_okno' => false, 'deti' => []]]],
], '/kuchyne/detail', '/'), '<li><a href="/">Úvod</a></li><li class="podmenu aktivni"><button type="button" class="menu-skupina">Služby</button><ul><li><a href="/kuchyne" aria-current="page">Kuchyně</a></li></ul></li>');
check('Hledani::najdi: shoda v názvu má přednost', array_column(Kaleta\Core\Hledani::najdi('search', [
    ['titulek' => 'Menus', 'adresa' => 'menus', 'text' => 'Link to site search from the menu.'],
    ['titulek' => 'Site search', 'adresa' => 'site-search', 'text' => 'How search works.'],
    ['titulek' => 'SEO', 'adresa' => 'seo', 'text' => 'Search engines and search results; search console.'],
]), 'adresa'), ['site-search', 'seo', 'menus']);
check('Hledani::najdi: bez diakritiky, všechna slova, úryvek', Kaleta\Core\Hledani::najdi('zkusenosti kuchyne', [
    ['titulek' => 'O nás', 'adresa' => 'o-nas', 'text' => '<p>Máme dvacet let zkušeností s nábytkem.</p>'],
    ['titulek' => 'Kuchyně', 'adresa' => 'kuchyne', 'text' => '<p>Kuchyně na míru – bohaté zkušenosti.</p>'],
]), [['titulek' => 'Kuchyně', 'adresa' => 'kuchyne', 'uryvek' => 'Kuchyně na míru – bohaté zkušenosti.']]);
check('Styl::css: bílé pozadí si nese tmavý text i v tmavém režimu', str_contains(Kaleta\Stavitel\Styl::css('#s', ['zaklad' => ['pozadi' => 'bila']]), '--ka-barva-text: var(--ka-barva-text-svetle); color: var(--ka-barva-text-svetle)'), true);
check('Styl::css: vlastní barva textu na bílém pozadí se nepřepíše', str_contains(Kaleta\Stavitel\Styl::css('#s', ['zaklad' => ['pozadi' => 'bila', 'barva' => 'primarni']]), 'text-svetle'), false);
$buildDiscarded = [];
check('Styl::vlastniCss: jen bezpečné deklarace', Kaleta\Stavitel\Styl::vlastniCss('color:red; background:url(javascript:x); --ka-x: 1; @import url(x); width: expression(1); a{b:c}', $buildDiscarded), 'color: red; --ka-x: 1;');
check('Styl::vlastniCss: zahozené se hlásí', count($buildDiscarded), 4);
check('DesignSystem::kontrast: černá na bílé', round(Kaleta\Stavitel\DesignSystem::kontrast('#ffffff', '#000000'), 1), 21.0);
check('DesignSystem::css: pořadí vrstev na začátku', str_starts_with(Kaleta\Stavitel\DesignSystem::css(Kaleta\Stavitel\DesignSystem::VYCHOZI), Kaleta\Stavitel\DesignSystem::VRSTVY), true);
check('DesignSystem::vycisti: nesmysl nahradí výchozí', Kaleta\Stavitel\DesignSystem::vycisti(['barvy' => ['primarni' => 'red;}']])['barvy']['primarni'], Kaleta\Stavitel\DesignSystem::VYCHOZI['barvy']['primarni']);
$buildLibraryErrors = [];
// surové stavby (sekci() už čistí, neplatná hodnota by tak zmizela potichu)
foreach ((new ReflectionMethod(Kaleta\Stavitel\Knihovna::class, 'sekce'))->invoke(null) as $buildKey => $buildSection) {
    $buildSection['klic'] = $buildKey;
    [, $buildErrors] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [($buildSection['stavba'])()]]);
    $buildLibraryErrors += array_map(fn (string $c): string => $buildSection['klic'] . ': ' . $c, $buildErrors);
}
check('Knihovna: všechny hotové sekce projdou validátorem', $buildLibraryErrors, []);
check('Stavba::schema: bez vlastního HTML pro ne-správce', in_array('html', array_column(Kaleta\Stavitel\Stavba::schema(false)['prvky'], 'typ'), true), false);

$fromHtml = Kaleta\Stavitel\ZHtml::preved('<style>.hero { padding: 2rem; background: url(x) } .hero h1 { color: red } @media (max-width: 9px) { .hero { padding: 0 } }</style>'
    . '<header class="hero container-x"><div class="wrap"><h1>A <em>b</em></h1><p>Jedna.</p><p>Dvě.</p><a class="btn btn-outline" href="/k">K</a></div></header>'
    . '<p>Volný text</p><details><summary>Otázka?</summary><p>Odpověď.</p></details><form></form><svg></svg><script>x</script>');
$fromHtmlTypes = fn (array $children): array => array_map(fn (array $p): string => $p['typ'] . '<' . $p['znacka'] . '>', $children);
check('ZHtml: sekce z <header>, vnitřní obal bez stylu odpadne', $fromHtmlTypes($fromHtml['stavba']['deti'][0]['deti']), ['nadpis<h1>', 'text<div>', 'tlacitko<a>']);
check('ZHtml: souvislé odstavce v jednom prvku Text', $fromHtml['stavba']['deti'][0]['deti'][1]['obsah']['html'], '<p>Jedna.</p><p>Dvě.</p>');
check('ZHtml: tlačítko s variantou podle třídy', [$fromHtml['stavba']['deti'][0]['deti'][2]['obsah']['varianta'], $fromHtml['stavba']['deti'][0]['deti'][2]['tridy']], ['obrys', ['btn', 'btn-outline']]);
check('ZHtml: volné prvky na konci se zabalí do sekce, details → FAQ, form → Formulář', $fromHtmlTypes($fromHtml['stavba']['deti'][1]['deti']), ['text<div>', 'faq<div>', 'formular<form>']);
check('ZHtml: třída z <style> jen s bezpečnými deklaracemi', $fromHtml['tridy'], ['hero' => 'padding: 2rem;']);
check('ZHtml: hlášení o @media, složitém selektoru, url(), formuláři, SVG a skriptu', count($fromHtml['hlaseni']), 6);
$fromHtml2 = Kaleta\Stavitel\ZHtml::preved('<style>.mriz { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .karta:hover { box-shadow: var(--ka-stin-m); transform: translateY(-4px) }'
    . ' @media (max-width: 1023px) { .mriz { grid-template-columns: repeat(2, 1fr) } } @media (max-width: 767px) { .mriz { grid-template-columns: 1fr; gap: var(--ka-mezera-m) } .karta { padding: var(--ka-mezera-m) var(--ka-mezera-s) } }'
    . ' @media (min-width: 768px) { .mriz { gap: 0 } }</style><section><div class="mriz"><div class="karta"><h3>A</h3></div><div>B</div></div></section>');
check('ZHtml: @media (max-width) a :hover jako stavy třídy', $fromHtml2['tridy_styl'], ['mriz' => ['tablet' => ['sloupce' => '2'], 'mobil' => ['sloupce' => '1', 'mezera' => 'm']],
    'karta' => ['mobil' => ['odsazeni_y' => 'm', 'odsazeni_x' => 's'], 'hover' => ['stin' => 'm', 'posun' => '0 -4px']]]);
check('ZHtml: prvek se stylovanou třídou nemá výchozí styl (přebil by třídu), bez třídy ho má', [$fromHtml2['stavba']['deti'][0]['deti'][0]['styl'], $fromHtml2['stavba']['deti'][0]['deti'][0]['deti'][1]['styl']['zaklad']['zobrazeni'] ?? null], [[], 'flex']);
check('ZHtml: mobile-first @media (min-width) se nahlásí', count(array_filter($fromHtml2['hlaseni'], fn (string $h): bool => str_contains($h, 'min-width'))), 1);
check('Styl::zCss: tokeny, zkratky a mřížka', [Kaleta\Stavitel\Styl::zCss('padding', 'var(--ka-mezera-l) 2rem'), Kaleta\Stavitel\Styl::zCss('margin', '0 auto'), Kaleta\Stavitel\Styl::zCss('grid-template-columns', 'repeat(auto-fit, minmax(16rem, 1fr))'),
    Kaleta\Stavitel\Styl::zCss('color', 'var(--ka-barva-tlumeny)'), Kaleta\Stavitel\Styl::zCss('font-size', 'var(--ka-krok--1)'), Kaleta\Stavitel\Styl::zCss('color', 'expression(1)'), Kaleta\Stavitel\Styl::zCss('filter', 'blur(2px)')],
    [['odsazeni_y' => 'l', 'odsazeni_x' => '2rem'], ['okraj_nahore' => '0', 'okraj_dole' => '0', 'na_stred' => 'auto'], ['sloupce' => 'auto:16rem'], ['barva' => 'tlumeny'], ['velikost_pisma' => '-1'], null, null]);

$editBuild = ['v' => 1, 'deti' => [['id' => 'sek1', 'typ' => 'sekce', 'deti' => [['id' => 'nad1', 'typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'styl' => ['zaklad' => ['barva' => 'primarni']]], ['id' => 'tl1', 'typ' => 'tlacitko', 'obsah' => ['text' => 'B', 'odkaz' => '/docs']]]], ['id' => 'sek2', 'typ' => 'sekce']]];
$editErrors = [];
$edit = Kaleta\Stavitel\Upravy::proved($editBuild, [
    ['op' => 'uprav', 'id' => 'tl1', 'obsah' => ['odkaz' => '/guide']],
    ['op' => 'uprav', 'id' => 'nad1', 'styl' => ['zaklad' => ['barva' => null], 'mobil' => ['zarovnani_textu' => 'center']], 'tridy' => ['nadpis-sekce']],
    ['op' => 'vloz', 'do' => 'sek2', 'prvky' => [['id' => 'txt1', 'typ' => 'text']]],
    ['op' => 'presun', 'id' => 'tl1', 'za' => 'txt1'],
    ['op' => 'vloz', 'pozice' => 0, 'prvek' => ['id' => 'sek0', 'typ' => 'sekce']],
    ['op' => 'smaz', 'id' => 'neni'],
    ['op' => 'presun', 'id' => 'sek2', 'do' => 'txt1'],
    ['op' => 'kouzlo'],
], $editErrors);
check('Upravy: úprava obsahu a stylu (null odebere), vložení, přesun, vložení na začátek', [array_column($edit['deti'], 'id'), $edit['deti'][1]['deti'][0]['styl'], $edit['deti'][1]['deti'][0]['tridy'], array_column($edit['deti'][2]['deti'], 'id'), $edit['deti'][2]['deti'][1]['obsah']['odkaz']],
    [['sek0', 'sek1', 'sek2'], ['mobil' => ['zarovnani_textu' => 'center']], ['nadpis-sekce'], ['txt1', 'tl1'], '/guide']);
check('Upravy: chybné operace se nahlásí a přeskočí (i přesun do potomka)', array_keys($editErrors), ['op[5]', 'op[6]', 'op[7]']);
$editErrors2 = [];
check('Upravy: přesun prvku do jeho potomka nejde', Kaleta\Stavitel\Upravy::proved($editBuild, [['op' => 'presun', 'id' => 'sek1', 'do' => 'nad1']], $editErrors2) === $editBuild && isset($editErrors2['op[0]']), true);

[$compactBuild] = Kaleta\Stavitel\Stavba::vycisti(['v' => 1, 'deti' => [['typ' => 'sekce', 'id' => 'abc', 'deti' => [['typ' => 'tlacitko', 'id' => 'def', 'obsah' => ['text' => 'Jdi']], ['typ' => 'kontejner', 'id' => 'ghi', 'styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]]]]]]);
$compact = Kaleta\Stavitel\Stavba::kompaktni($compactBuild);
check('Stavba::kompaktni: bez výchozích hodnot, styl zůstane', $compact, ['v' => 1, 'deti' => [['id' => 'abc', 'typ' => 'sekce', 'deti' => [['id' => 'def', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Jdi']], ['id' => 'ghi', 'typ' => 'kontejner', 'styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]]]]]]);
check('Stavba: zvýraznění <mark> v nadpisu zůstane, třídy a styly ne', Kaleta\Stavitel\Stavba::vycisti(['v' => 1, 'deti' => [['typ' => 'nadpis', 'id' => 'mk1', 'obsah' => ['text' => 'Publish<mark class="x" style="color:red">.</mark>']]]])[0]['deti'][0]['obsah']['text'], 'Publish<mark>.</mark>');
$buttonContext = null;
check('Styl::zCss: aliasy margin-top a flex-start', [Kaleta\Stavitel\Styl::zCss('margin-bottom', '24px'), Kaleta\Stavitel\Styl::zCss('align-items', 'flex-start')], [['okraj_dole' => '24px'], ['zarovnani' => 'start']]);
check('Styl::zCss: text-align left/right', [Kaleta\Stavitel\Styl::zCss('text-align', 'left'), Kaleta\Stavitel\Styl::zCss('text-align', 'right')], [['zarovnani_textu' => 'start'], ['zarovnani_textu' => 'end']]);
check('Ikony: GitHub v sadě', str_contains(Kaleta\Stavitel\Ikony::svg('github'), 'M9 19c-4'), true);
check('Tlačítko: ikona za textem a vlevo od textu', [
    (bool) preg_match('#>Start<svg#', Kaleta\Stavitel\Prvky\Tlacitko::vykresli(['obsah' => ['text' => 'Start', 'odkaz' => '/x', 'varianta' => 'primarni', 'nove_okno' => false, 'ikona' => 'sipka', 'ikona_vlevo' => false]], '', '', (new ReflectionClass(Kaleta\Stavitel\Kontext::class))->newInstanceWithoutConstructor())),
    (bool) preg_match('#</svg>GitHub</a>#', Kaleta\Stavitel\Prvky\Tlacitko::vykresli(['obsah' => ['text' => 'GitHub', 'odkaz' => '/x', 'varianta' => 'obrys', 'nove_okno' => false, 'ikona' => 'github', 'ikona_vlevo' => true]], '', '', (new ReflectionClass(Kaleta\Stavitel\Kontext::class))->newInstanceWithoutConstructor())),
    Kaleta\Stavitel\Prvky\Tlacitko::vykresli(['obsah' => ['text' => 'Bez', 'odkaz' => '/x', 'varianta' => 'primarni', 'nove_okno' => false]], '', '', (new ReflectionClass(Kaleta\Stavitel\Kontext::class))->newInstanceWithoutConstructor()) === '<a class="ka-tlacitko ka-tlacitko--primarni" href="/x">Bez</a>',
], [true, true, true]);
check('Stavba::kompaktni: po vyčištění stejná stavba', Kaleta\Stavitel\Stavba::vycisti($compact)[0], $compactBuild);
$overview = Kaleta\Stavitel\Stavba::prehled(Kaleta\Stavitel\Stavba::schema());
check('Stavba::prehled: prvek na řádek, výchozí možnost s hvězdičkou, schéma výrazně menší', [str_contains($overview['prvky']['tlacitko'], 'varianta:vyber(primarni*|'), strlen((string) json_encode($overview)) < strlen((string) json_encode(Kaleta\Stavitel\Stavba::schema())) / 2],
    [true, true]);
check('ZHtml: výsledek projde validátorem bez chyb', Kaleta\Stavitel\Stavba::vycisti($fromHtml['stavba'])[1], []);
check('Stavba::jakoText: sémantický obsah bez rozložení', Kaleta\Stavitel\Stavba::jakoText($fromHtml['stavba']), "<h1>A <em>b</em></h1>\n<p>Jedna.</p><p>Dvě.</p>\n<p><a href=\"/k\">K</a></p>\n<p>Volný text</p>\n<h3>Otázka?</h3><p>Odpověď.</p>");

// vypnutá rozšíření: prvky ani sekce s nimi builder nenabízí
$schemaTypes = array_column(Kaleta\Stavitel\Stavba::schema(true, 'cs', false, ['statistika'])['prvky'], 'typ');
check('Rozšíření: bez novinek a poptávek schéma nemá jejich prvky', [in_array('novinky', $schemaTypes, true), in_array('formular', $schemaTypes, true), in_array('nadpis', $schemaTypes, true)], [false, false, true]);
$libraryKey = array_column(Kaleta\Stavitel\Knihovna::seznam(['statistika']), 'klic');
check('Rozšíření: knihovna bez sekcí s formulářem a novinkami', [in_array('kontakt-formular', $libraryKey, true), in_array('novinky', $libraryKey, true), in_array('uvod', $libraryKey, true)], [false, false, true]);
check('Rozšíření: bez omezení je knihovna celá', count(Kaleta\Stavitel\Knihovna::seznam()) > count($libraryKey), true);
$libraryEn = Kaleta\Stavitel\Knihovna::sekci('uvod', 'en')['prvek'];
check('Knihovna: sekce v angličtině včetně odkazů na stránky', [$libraryEn['deti'][0]['obsah']['text'], $libraryEn['deti'][2]['deti'][0]['obsah']['odkaz'], $libraryEn['deti'][2]['deti'][1]['obsah']['odkaz']], ['We help businesses grow – quickly and hassle-free', '/contact', '/services']);
check('Knihovna: česky se odkazuje na české adresy', Kaleta\Stavitel\Knihovna::sekci('uvod')['prvek']['deti'][2]['deti'][0]['obsah']['odkaz'], '/kontakt');
check('Knihovna: jazyk se po sestavení sekce vrátí', Kaleta\Core\Jazyk::kod(), 'cs');
$librarySchema = array_column(Kaleta\Stavitel\Stavba::schema(true, 'en')['prvky'], 'vlastnosti', 'typ');
check('Stavba::schema: výchozí obsah prvků v jazyce stránky', [$librarySchema['nadpis']['text']['vychozi'], $librarySchema['tlacitko']['text']['vychozi']], ['Heading', 'Contact us']);
$libraryEnDictionary = require KALETA_ROOT . '/system/jazyky/en.php';
preg_match_all("/\bt\('((?:[^'\\\\]|\\\\.)*)'\)/", file_get_contents(KALETA_ROOT . '/system/src/Stavitel/Knihovna.php') . implode('', array_map('file_get_contents', glob(KALETA_ROOT . '/system/src/Stavitel/Prvky/*.php'))), $libraryTexts);
check('Knihovna a prvky: všechny ukázkové texty mají anglický překlad', array_values(array_diff(array_unique($libraryTexts[1]), array_keys($libraryEnDictionary), ['Menu', 'Standard', 'Video'])), []);

check('Firma::hodiny: rozsah dnů, víc úseků, zavřeno', Kaleta\Front\Firma::hodiny("Po–Pá 8:00–17:00\nÚt 8-12, 13-17\nNe zavřeno"), [
    ['dny' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'od' => '08:00', 'do' => '17:00'],
    ['dny' => ['Tuesday'], 'od' => '08:00', 'do' => '12:00'], ['dny' => ['Tuesday'], 'od' => '13:00', 'do' => '17:00'],
]);
check('Firma::hodiny: nesrozumitelný řádek se odmítne', [Kaleta\Front\Firma::hodiny('každý den 8-17'), Kaleta\Front\Firma::hodiny('Po 8-25')], [null, null]);
check('Firma: typy v Nastavení odpovídají Firma::TYPY', (new ReflectionClassConstant(Kaleta\Admin\Moduly\Konfigurace::class, 'TYPY_FIRMY'))->getValue(), implode('|', array_keys(Kaleta\Front\Firma::TYPY)));

/* ---------- kolekce ---------- */
$collectionFields = Kaleta\Stavitel\Kolekce::vycistiPole([['popisek' => 'Citát zákazníka', 'typ' => 'radky'], ['popisek' => 'Název', 'typ' => 'text'], ['popisek' => 'Logo', 'typ' => 'nesmysl'], ['popisek' => '']]);
check('Kolekce::vycistiPole: klíč z popisku, vestavěný název se nepřepíše, neznámý typ = text', array_map(fn (array $p): string => $p['klic'] . ':' . $p['typ'], $collectionFields), ['citat_zakaznika:radky', 'nazev_2:text', 'logo:text']);
$collectionErrors = [];
$collectionData = Kaleta\Stavitel\Kolekce::vycistiData([['klic' => 'web', 'popisek' => 'Web', 'typ' => 'odkaz'], ['klic' => 'foto', 'popisek' => 'Foto', 'typ' => 'obrazek'], ['klic' => 'cena', 'popisek' => 'Cena', 'typ' => 'cislo'], ['klic' => 'bio', 'popisek' => 'Bio', 'typ' => 'html']],
    ['web' => 'javascript:alert(1)', 'foto' => 'media/2026/a.jpg', 'cena' => '1 200', 'bio' => '<p onclick="x">Ahoj</p><script>1</script>'], $collectionErrors);
check('Kolekce::vycistiData: nebezpečný odkaz pryč, obrázek z médií, číslo bez mezer, HTML vyčištěné', [$collectionData['web'], $collectionData['foto'], $collectionData['cena'], $collectionData['bio'], array_keys($collectionErrors)], ['', 'media/2026/a.jpg', '1200', '<p>Ahoj</p>', ['web']]);
$collectionValues = ['nazev' => ['Jan <b>Novák</b>', 'text'], 'bio' => ['<p>Truhlář</p>', 'html'], 'poznamka' => ["řádek 1\nřádek 2", 'radky'], 'url' => ['/tym/jan', 'odkaz'], 'zly' => ['javascript:x', 'odkaz']];
check('Kolekce::dosad: značky v dosazené hodnotě se znovu nedosazují', Kaleta\Stavitel\Kolekce::dosad('<p>{{text}}</p><p>{{nazev}}</p>', 'html', ['text' => ['<p>Napište {{nazev}} nebo {{url}}.</p>', 'html'], 'nazev' => ['Návod', 'text'], 'url' => ['/navod', 'text']]), '<p>Napište {{nazev}} nebo {{url}}.</p><p>Návod</p>');
check('Kolekce::dosad: jeden průchod i v řádkovém textu', Kaleta\Stavitel\Kolekce::dosad('{{popis}} – {{nazev}}', 'inline', ['popis' => ['Viz {{nazev}}', 'radky'], 'nazev' => ['X', 'text']]), 'Viz {{nazev}} – X');
check('Kolekce::dosad: text se escapuje až prvkem, inline a html hned, html pole zůstane HTML', [
    Kaleta\Stavitel\Kolekce::dosad('{{nazev}}', 'text', $collectionValues), Kaleta\Stavitel\Kolekce::dosad('Tým: {{nazev}}', 'inline', $collectionValues),
    Kaleta\Stavitel\Kolekce::dosad('{{bio}}', 'html', $collectionValues), Kaleta\Stavitel\Kolekce::dosad('<p>{{poznamka}}</p>', 'html', $collectionValues),
    Kaleta\Stavitel\Kolekce::dosad('{{url}}', 'odkaz', $collectionValues), Kaleta\Stavitel\Kolekce::dosad('{{zly}}', 'odkaz', $collectionValues), Kaleta\Stavitel\Kolekce::dosad('{{neni}}', 'inline', $collectionValues),
], ['Jan <b>Novák</b>', 'Tým: Jan &lt;b&gt;Novák&lt;/b&gt;', '<p>Truhlář</p>', '<p>řádek 1<br>' . "\n" . 'řádek 2</p>', '/tym/jan', '', '']);
[$collectionBuild, $collectionErrors] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [['typ' => 'kolekce', 'obsah' => ['kolekce' => 'tym'], 'deti' => [['typ' => 'obrazek', 'obsah' => ['src' => '{{foto}}']], ['typ' => 'tlacitko', 'obsah' => ['odkaz' => '{{url}}']]]]]]);
check('Stavba::vycisti: značky {{pole}} v obrázku a odkazu projdou', [$collectionBuild['deti'][0]['deti'][0]['obsah']['src'], $collectionBuild['deti'][0]['deti'][1]['obsah']['odkaz'], $collectionErrors], ['{{foto}}', '{{url}}', []]);

/* ---------- angličtina builderu: texty editoru (JS) a popisky schématu (PHP) ---------- */
preg_match_all("/\bT\('((?:[^'\\\\]|\\\\.)*)'\)/", (string) file_get_contents(KALETA_ROOT . '/image/stavitel.js'), $enJs);
preg_match('/window\.KALETA_PREKLAD = (\{.*\});/s', (string) file_get_contents(KALETA_ROOT . '/image/jazyky/admin-en.js'), $enJsDictionary);
$enJsKeys = array_keys((array) json_decode((string) preg_replace(['#^\s*//.*$#m', '/,\s*\}$/'], ['', '}'], $enJsDictionary[1] ?? '{}'), true));
check('Builder: všechny texty editoru mají anglický překlad', array_values(array_diff(array_unique(array_map('stripslashes', $enJs[1])), $enJsKeys, ['Tablet', 'Menu'])), []);
$enAdmin = require KALETA_ROOT . '/system/jazyky/admin-en.php';
$enSchema = Kaleta\Stavitel\Stavba::schema(true, 'cs', true);
$enTexts = array_merge(array_column($enSchema['prvky'], 'nazev'), array_column($enSchema['prvky'], 'popis'), array_column($enSchema['prvky'], 'skupina'), array_values($enSchema['skupiny_stylu']));
$enFields = function (array $properties) use (&$enFields, &$enTexts): void {
    foreach ($properties as $d) {
        $enTexts[] = (string) ($d['popisek'] ?? '');
        array_push($enTexts, ...array_values($d['moznosti'] ?? []));
        if (isset($d['pole'])) {
            $enFields($d['pole']);
        }
    }
};
foreach ($enSchema['prvky'] as $p) {
    $enFields($p['vlastnosti']);
}
$enFields($enSchema['styl']);
check('Builder: všechny popisky schématu mají anglický překlad', array_values(array_filter(array_unique($enTexts), fn (string $x): bool => $x !== '' && preg_match('/\p{L}/u', $x) === 1 && !isset($enAdmin[$x]) && !in_array($x, ['Video', 'Logo', 'HTML', 'Text', 'text'], true))), []);

$siteErrors = [];
$siteSections = array_column(Kaleta\Stavitel\Knihovna::seznam(), 'klic');
foreach (Kaleta\Stavitel\Knihovna::WEBY as $siteKey => $site) {
    if (!isset(Kaleta\Stavitel\DesignSystem::PREDVOLBY[$site['predvolba']])) {
        $siteErrors[] = $siteKey . ': předvolba ' . $site['predvolba'];
    }
    foreach ($site['stranky'] as $pageSections) {
        foreach (array_diff($pageSections, $siteSections) as $missing) {
            $siteErrors[] = $siteKey . ': sekce ' . $missing;
        }
    }
}
check('Knihovna::WEBY: předvolby a sekce ukázkových webů existují', $siteErrors, []);

// stránky ukázkových webů projdou kontrolou před publikováním z builderu (image/stavitel.js, kontrola()): tlačítka s odkazem,
// žádný prázdný obrázek, jediný h1 a osnova bez přeskočené úrovně – česky i anglicky, se všemi rozšířeními i bez nich
$pageCheck = function (array $build): array {
    $findings = [];
    $headings = [];
    $walk = function (array $children) use (&$walk, &$findings, &$headings): void {
        foreach ($children as $p) {
            $o = $p['obsah'] ?? [];
            if ($p['typ'] === 'tlacitko' && in_array($o['odkaz'] ?? '', ['', '#'], true)) {
                $findings[] = 'tlačítko bez odkazu: ' . ($o['text'] ?? '');
            }
            if ($p['typ'] === 'obrazek' && (($o['src'] ?? '') === '' || ($o['alt'] ?? '') === '')) {
                $findings[] = 'obrázek bez souboru nebo popisu';
            }
            if ($p['typ'] === 'nadpis' && preg_match('/^h([1-6])$/', $p['znacka'] ?? 'h2', $m)) {
                $headings[] = (int) $m[1];
            }
            $walk($p['deti'] ?? []);
        }
    };
    $walk($build['deti']);
    if (count(array_keys($headings, 1)) !== 1) {
        $findings[] = count(array_keys($headings, 1)) . '× h1';
    }
    foreach ($headings as $i => $u) {
        if ($i > 0 && $u > $headings[$i - 1] + 1) {
            $findings[] = 'h' . $headings[$i - 1] . ' → h' . $u;
        }
    }

    return $findings;
};
$sitesCheck = [];
foreach (Kaleta\Stavitel\Knihovna::WEBY as $siteKey => $site) {
    foreach (['cs', 'en'] as $language) {
        foreach (['všechna rozšíření' => array_keys(Kaleta\Core\Rozsireni::SEZNAM), 'bez rozšíření' => []] as $variant => $enabled) {
            foreach ($site['stranky'] as $i => $pageSections) {
                if ($pageSections === []) {
                    continue; // textová stránka: nadpis h1 dává šablona
                }
                [$build] = Kaleta\Stavitel\Knihovna::sestav($pageSections, 'Stránka', $language, Kaleta\Stavitel\Stavba::vypnuteTypy($enabled), true);
                foreach ($pageCheck($build) as $finding) {
                    $sitesCheck[] = "$siteKey/$language/$variant/stránka $i: $finding";
                }
            }
        }
    }
}
check('Knihovna::WEBY: stránky ukázkových webů projdou kontrolou před publikováním', array_values(array_unique($sitesCheck)), []);
$contactWithoutForm = Kaleta\Stavitel\Knihovna::sestav(Kaleta\Stavitel\Knihovna::WEBY['remeslo']['stranky'][3], 'Kontakt', 'cs', Kaleta\Stavitel\Stavba::vypnuteTypy([]), true)[0];
check('Knihovna: kontakt bez rozšíření Formuláře má údaje firmy', str_contains((string) json_encode($contactWithoutForm), '"udaj":"adresa"'), true);

/* ---------- AI asistent: poskytovatelé a builder ---------- */
$aiBody = ['model' => 'm1', 'max_tokens' => 50, 'system' => 'S', 'messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['media_type' => 'image/png', 'data' => 'QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]]];
check('Asistent::naOpenAi: systém, obrázek jako data URL, limit tokenů podle poskytovatele', [Asistent::naOpenAi($aiBody, 'openai'), array_keys(Asistent::naOpenAi($aiBody, 'mistral'))], [
    ['model' => 'm1', 'messages' => [['role' => 'system', 'content' => 'S'], ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]], 'max_completion_tokens' => 50],
    ['model', 'messages', 'max_tokens'],
]);
check('Asistent::zOpenAi: odpověď do tvaru Claude API', Asistent::zOpenAi(['choices' => [['message' => ['content' => 'Text'], 'finish_reason' => 'length']]]), ['content' => [['type' => 'text', 'text' => 'Text']], 'stop_reason' => 'max_tokens']);
$aiSettings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($aiSettings, ['nazev_webu' => 'Test', 'ai_klic' => 'x', 'ai_poskytovatel' => 'anthropic', 'ai_model' => 'claude-sonnet-5']);
$aiFake = new class($aiSettings) extends Asistent {
    public string $answer = '';
    public array $last = [];

    protected function zavolej(array $body): array
    {
        $this->last = $body;

        return ['content' => [['type' => 'text', 'text' => $this->answer]]];
    }
};
$aiFake->answer = "Tady je sekce:\n```html\n<section class=\"sluzby-ai\"><h2>Služby</h2><p>Text <script>x</script></p><a class=\"btn\" href=\"javascript:alert(1)\">Klik</a></section><style>.sluzby-ai { padding: var(--ka-mezera-l); }</style>\n```";
$aiHtml = $aiFake->navrhniSekci('Tři karty se službami a odkazem na kontakt.', 'cs', 'Služby');
$aiConversion = Kaleta\Stavitel\ZHtml::preved($aiHtml);
[$aiBuild] = Kaleta\Stavitel\Stavba::vycisti($aiConversion['stavba'], false);
check('Asistent::navrhniSekci: HTML z bloku ```html, zadání uvnitř <zadani>, výsledek bez skriptu a javascript: odkazu', [
    str_starts_with($aiHtml, '<section'), str_contains((string) $aiFake->last['messages'][0]['content'], '<zadani>'), str_contains(json_encode($aiBuild), 'script'), str_contains(json_encode($aiBuild), 'javascript'), $aiConversion['tridy'],
], [true, true, false, false, ['sluzby-ai' => 'padding: var(--ka-mezera-l);']]);
$aiFake->answer = '<p>Kratší <strong>text</strong> <img src=x onerror=alert(1)></p>';
check('Asistent::prepis: HTML odpověď vyčištěná, prostý text bez značek', [$aiFake->prepis('<p>Dlouhý text k přepsání.</p>', 'kratsi', true), $aiFake->prepis('Nadpis', 'formalne', false)], ['<p>Kratší <strong>text</strong> </p>', 'Kratší text']);
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($aiSettings, ['nazev_webu' => 'Test', 'ai_klic' => 'x', 'ai_poskytovatel' => 'openai', 'ai_model' => 'claude-sonnet-5']);
try {
    $aiFake->prepis('Text', 'kratsi', false);
    $aiError = '';
} catch (RuntimeException $e) {
    $aiError = $e->getMessage();
}
check('Asistent: u jiného poskytovatele než Claude je potřeba zadat jeho model', str_contains($aiError, 'Zadejte název modelu'), true);

/* ---------- class renames (tools/rename.php) ---------- */
$classAliases = require KALETA_SYSTEM . '/class-aliases.php';
check('class aliases: every old name resolves to its new class', array_filter($classAliases, fn (string $newName, string $oldName): bool => !class_exists($oldName) || !is_a($oldName, $newName, true), ARRAY_FILTER_USE_BOTH), []);
check('class aliases: no old name is still a class file', array_filter(array_keys($classAliases), fn (string $oldName): bool => is_file(KALETA_SYSTEM . '/src/' . str_replace('\\', '/', substr($oldName, 7)) . '.php')), []);
exec('php ' . escapeshellarg(__DIR__ . '/rename.php') . ' --self-test', $renameOutput, $renameCode);
check('tools/rename.php self-test', $renameCode, 0);

echo $errors === 0 ? "  ok     jednotkové testy ({$total})\n" : "  NALEZENO CHYB: {$errors} z {$total}\n";
exit($errors === 0 ? 0 : 1);
