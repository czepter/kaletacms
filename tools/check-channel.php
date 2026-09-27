<?php
/**
 * Ověří kanál aktualizací tak, jak ho vidí instalace Kalety: co právě visí na webu projektu, musí být podepsané
 * klíčem z repozitáře a balíček ke stažení musí odpovídat podepsanému otisku. Běží denně v GitHub Actions
 * (.github/workflows/denni-kontrola.yml) - odhalí podvržený nebo poškozený soubor dřív, než ho potkají weby uživatelů.
 *
 *   php tools/check-channel.php [adresa aktualizace.json]
 *
 * Nic nepodepisuje a žádný soukromý klíč nepotřebuje.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\Podpis;

$manifestUrl = $argv[1] ?? 'https://kaletacms.com/aktualizace.json';
$keys = dirname(__DIR__) . '/system/aktualizace.pub';
$errors = [];
$download = static function (string $url): string {
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta-kontrola\r\n"]]));
    if ($data === false) {
        throw new RuntimeException("nelze stáhnout $url");
    }

    return $data;
};

try {
    $m = json_decode($download($manifestUrl), true, 16, JSON_THROW_ON_ERROR);
    foreach (['verze', 'url', 'sha256', 'podpis'] as $key) {
        if (!is_string($m[$key] ?? null) || $m[$key] === '') {
            throw new RuntimeException("v souboru chybí položka $key");
        }
    }
    echo "Nabízená verze: {$m['verze']}" . (!empty($m['bezpecnostni']) ? ' (bezpečnostní)' : '') . "\n";
    if (!str_starts_with($m['url'], 'https://')) {
        $errors[] = 'adresa balíčku není https';
    }
    if (!Podpis::plati(Podpis::zpravaBalicku($m['verze'], strtolower($m['sha256']), !empty($m['bezpecnostni'])), $m['podpis'], $keys)) {
        $errors[] = 'podpis souboru aktualizace.json NEPLATÍ pro žádný klíč v system/aktualizace.pub';
    }
    $zip = tempnam(sys_get_temp_dir(), 'kaleta');
    file_put_contents($zip, $download($m['url']));
    if (!hash_equals(strtolower($m['sha256']), hash_file('sha256', $zip))) {
        $errors[] = 'otisk staženého balíčku neodpovídá podepsanému otisku';
    }
    // balíček nesmí instalacím podstrčit jiné veřejné klíče, než jaké jsou v repozitáři
    $archive = new ZipArchive();
    if ($archive->open($zip) === true) {
        $inPackage = $archive->getFromName('system/aktualizace.pub');
        $clean = static fn (string $s): array => array_values(array_filter(array_map(static fn (string $r): string => trim(explode(' ', trim($r))[0]), explode("\n", $s)), static fn (string $r): bool => $r !== '' && $r[0] !== '#'));
        if ($inPackage === false) {
            $errors[] = 'balíček neobsahuje system/aktualizace.pub';
        } elseif (array_diff($clean($inPackage), $clean((string) file_get_contents($keys))) !== []) {
            $errors[] = 'balíček obsahuje veřejný klíč, který v repozitáři není';
        }
        $archive->close();
    } else {
        $errors[] = 'balíček nejde otevřít jako ZIP';
    }
    @unlink($zip);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($errors !== []) {
    fwrite(STDERR, "KANÁL AKTUALIZACÍ NENÍ V POŘÁDKU:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "Kanál aktualizací je v pořádku: podpis platí, balíček odpovídá otisku, klíče sedí.\n";
