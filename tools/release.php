<?php
/**
 * Kaleta - příprava vydání (spouští vydavatel na svém počítači, na web se nenahrává).
 *
 *   php tools/release.php 1.0.1 --url=https://github.com/phprs-cms/kaletacms/releases/download/v1.0.1/kaleta-1.0.1.zip \
 *       --zmena="Oprava ..." --zmena="Nové ..." [--bezpecnostni]
 *
 * --bezpecnostni označí vydání jako bezpečnostní opravu: instalace se na ně aktualizují samy a správce dostane e-mail.
 * Soukromý klíč lze místo souboru předat proměnnou prostředí KALETA_KLIC (base64) - pro vydávání z GitHub Actions.
 *
 * Vytvoří dist/kaleta-<verze>.zip (soubory sledované gitem) a dist/aktualizace.json podepsaný soukromým klíčem.
 * Klíče: tools/klice/vydavatel.key (provozní) a tools/klice/zalozni.key (záložní, má ležet offline) jsou SOUKROMÉ - nikdy do gitu.
 * system/aktualizace.pub nese veřejné klíče (na řádek jeden), je součástí systému. Výměna a odvolání klíče: docs/RELEASING.md.
 *   php tools/release.php --novy-klic=zalozni      založí pár klíčů a veřejný připíše do system/aktualizace.pub
 *   php tools/release.php 1.0.1 --klic=zalozni …   podepíše vydání záložním klíčem (ztráta nebo únik provozního)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Jen z příkazové řádky.');
}
$root = dirname(__DIR__);
$version = $argv[1] ?? '';
$options = ['url' => '', 'zmeny' => [], 'bezpecnostni' => false, 'klic' => 'provozni'];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $options['url'] = substr($arg, 6);
    } elseif ($arg === '--bezpecnostni') {
        $options['bezpecnostni'] = true;
    } elseif (str_starts_with($arg, '--zmena=')) {
        $options['zmeny'][] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--klic=')) {
        $options['klic'] = substr($arg, 7);
    }
}
if (str_starts_with($version, '--novy-klic=')) {
    // nový pár klíčů: soukromý do tools/klice/ (nikdy do gitu), veřejný se PŘIPÍŠE do system/aktualizace.pub
    require_once $root . '/system/src/Core/Signature.php';
    $name = substr($version, 12);
    $target = ['provozni' => $root . '/tools/klice/vydavatel.key', 'zalozni' => $root . '/tools/klice/zalozni.key'][$name] ?? exit("Použití: --novy-klic=provozni nebo --novy-klic=zalozni\n");
    if (is_file($target)) {
        exit("Soubor {$target} už existuje. Při výměně klíče ho nejdřív přesuňte do archivu - nikdy ho nepřepisujte naslepo.\n");
    }
    @mkdir(dirname($target), 0700, true);
    $pair = sodium_crypto_sign_keypair();
    file_put_contents($target, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
    chmod($target, 0600);
    $pk = sodium_crypto_sign_publickey($pair);
    $pub = $root . '/system/aktualizace.pub';
    file_put_contents($pub, rtrim((string) @file_get_contents($pub)) . "\n" . base64_encode($pk) . ' ' . $name . ' ' . date('Y-m-d') . ' id=' . Kaleta\Core\Signature::id($pk) . "\n");
    file_put_contents($pub, ltrim((string) file_get_contents($pub)));
    exit("Nový klíč „{$name}“ (id " . Kaleta\Core\Signature::id($pk) . ") je v {$target}.\n"
        . "1) Soukromý soubor si HNED zazálohujte mimo tento počítač" . ($name === 'zalozni' ? " a z disku ho pak smažte - záložní klíč má ležet offline" : '') . ".\n"
        . "2) system/aktualizace.pub commitněte; instalace nový klíč poznají až po vydání, které ho přinese (podepsaném klíčem, který už znají).\n");
}
if (!preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version)) {
    exit("Použití: php tools/release.php <verze> --url=<adresa ZIPu> [--zmena=\"...\"]\n");
}
if (!str_contains((string) file_get_contents($root . '/system/bootstrap.php'), "const KALETA_VERSION = '{$version}';")) {
    exit("V system/bootstrap.php není KALETA_VERSION = '{$version}'. Nejprve zvyšte verzi a změnu commitněte.\n");
}

// --- klíče: system/aktualizace.pub nese víc veřejných klíčů (provozní + záložní), podpis platí vůči kterémukoli - viz docs/RELEASING.md
require_once $root . '/system/src/Core/Signature.php';
$publicKeyFile = $root . '/system/aktualizace.pub';
$keyFiles = ['provozni' => $root . '/tools/klice/vydavatel.key', 'zalozni' => $root . '/tools/klice/zalozni.key'];
$privateKeyFile = $keyFiles[$options['klic']] ?? exit("Neznámý klíč „{$options['klic']}“ - použijte --klic=provozni nebo --klic=zalozni.\n");
$sk = base64_decode(trim(getenv('KALETA_KLIC') !== false ? (string) getenv('KALETA_KLIC') : (string) @file_get_contents($privateKeyFile)), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    exit("Soukromý klíč {$privateKeyFile} chybí nebo je poškozený. Nový pár založíte příkazem: php tools/release.php --novy-klic={$options['klic']}\n");
}
$keyId = Kaleta\Core\Signature::id(sodium_crypto_sign_publickey_from_secretkey($sk));
if (!isset(Kaleta\Core\Signature::keys($publicKeyFile)[$keyId])) {
    exit("Klíč {$keyId} není uveden v system/aktualizace.pub - instalace by jeho podpis odmítly.\n");
}

// --- balíček ze souborů sledovaných gitem
$files = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files')));
$exclude = ['tools/', 'docs/', '.github/', '.claude/', 'CLAUDE.md', '.gitignore', '.gitleaks.toml', '.git-blame-ignore-revs']; // kořenový CLAUDE.md je pro vývoj; layout/CLAUDE.md (pravidla šablon) do balíčku patří
@mkdir($root . '/dist');
$zipFile = $root . "/dist/kaleta-{$version}.zip";
@unlink($zipFile);
$zip = new ZipArchive();
$zip->open($zipFile, ZipArchive::CREATE);
$hashes = [];
foreach ($files as $file) {
    foreach ($exclude as $v) {
        if ($file === $v || str_starts_with($file, $v)) {
            continue 2;
        }
    }
    $zip->addFile($root . '/' . $file, $file);
    // seznam souborů jádra s otisky: instalace podle něj pozná změněné, chybějící a přidané soubory (Core\Integrita)
    // bez uživatelských složek a bez install.php (aktualizace ho nepřepisuje a správce ho po instalaci může smazat)
    if (!preg_match('#^(media|storage)/|^install\.php$#', $file)) {
        $hashes[$file] = hash_file('sha256', $root . '/' . $file);
    }
}
// classes of the previous release that this one renamed or removed ride along unchanged: the previous release installs
// this package and, in the same request, may still load its own classes after its cleanup. The new version deletes them
// on the first admin load (Aktualizace::uklidZrusene, 'legacy' list).
$legacy = [];
$previous = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git describe --tags --abbrev=0 HEAD^ 2>/dev/null'));
if ($previous !== '') {
    foreach (array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ls-tree -r --name-only ' . escapeshellarg($previous) . ' -- system/src/'))) as $old) {
        if (!in_array($old, $files, true)) {
            $content = (string) shell_exec('cd ' . escapeshellarg($root) . ' && git show ' . escapeshellarg($previous . ':' . $old));
            $zip->addFromString($old, $content);
            $legacy[$old] = hash('sha256', $content);
        }
    }
}
require_once $root . '/system/src/Core/Integrity.php';
ksort($hashes);
$zip->addFromString('system/soubory.json', json_encode([
    'verze' => $version, 'soubory' => $hashes, 'legacy' => $legacy,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Integrity::stringToSign($version, $hashes), $sk)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$zip->close();

$sha = hash_file('sha256', $zipFile);
$manifest = [
    'verze' => $version, 'vydano' => date('Y-m-d'), 'url' => $options['url'], 'sha256' => $sha,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Signature::packageMessage($version, $sha, $options['bezpecnostni']), $sk)),
    'klic' => $keyId, // jen pro přehled, kterým klíčem se podepisovalo; instalace zkouší všechny klíče, které znají
    'min_php' => '8.4', 'bezpecnostni' => $options['bezpecnostni'], 'zmeny' => $options['zmeny'],
];
file_put_contents($root . '/dist/aktualizace.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Hotovo: dist/kaleta-{$version}.zip (" . round(filesize($zipFile) / 1024) . " kB) a dist/aktualizace.json\n";
echo $options['url'] === '' ? "POZOR: nezadali jste --url, doplňte adresu ZIPu do dist/aktualizace.json PŘED podpisem (spusťte znovu s --url).\n" : "1) ZIP nahrajte na {$options['url']}\n2) aktualizace.json nahrajte na web projektu.\n";
