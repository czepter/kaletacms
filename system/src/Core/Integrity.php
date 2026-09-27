<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Neporušenost jádra: balíček vydání nese podepsaný seznam souborů s otisky (system/soubory.json).
 * Stav systému podle něj pozná soubory jádra, které někdo změnil, smazal nebo přidal. Úpravy jádra se
 * nepodporují - vlastní je jen layout/<vlastní šablona>/, media/, storage/ a config.php; aktualizace
 * vrátí jádro do původní podoby.
 */
final class Integrity
{
    private const string CATALOG = KALETA_SYSTEM . '/soubory.json';

    /** @return array{stav:string, info:string, zmenene:list<string>, chybi:list<string>, navic:list<string>} */
    public static function check(string $keyFile = KALETA_SYSTEM . '/aktualizace.pub'): array
    {
        $prazdne = ['zmenene' => [], 'chybi' => [], 'navic' => []];
        if (!is_file(self::CATALOG)) {
            return ['stav' => 'ok', 'info' => t('vývojová verze bez seznamu souborů – kontrola se týká jen vydaných balíčků')] + $prazdne;
        }
        $data = json_decode((string) file_get_contents(self::CATALOG), true);
        $files = is_array($data['soubory'] ?? null) ? $data['soubory'] : null;
        if ($files === null || !Signature::isValid(self::stringToSign((string) ($data['verze'] ?? ''), $files), (string) ($data['podpis'] ?? ''), $keyFile)) {
            return ['stav' => 'chyba', 'info' => t('seznam souborů jádra (system/soubory.json) je poškozený nebo nemá platný podpis vydavatele')] + $prazdne;
        }
        $changed = $missing = [];
        foreach ($files as $path => $hash) {
            $file = KALETA_ROOT . '/' . $path;
            if (!is_file($file)) {
                $missing[] = $path;
            } elseif (!hash_equals((string) $hash, hash_file('sha256', $file))) {
                $changed[] = $path;
            }
        }
        // soubory PHP, které do jádra nepatří (kořen webu a system/) - typická stopa po napadení webu
        $extra = [];
        $candidates = glob(KALETA_ROOT . '/*.php') ?: [];
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_SYSTEM, \FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $f) {
            if ($f->isFile() && preg_match('/\.(php\d?|phtml|phar)$/i', $f->getFilename())) {
                $candidates[] = $f->getPathname();
            }
        }
        foreach ($candidates as $file) {
            $path = ltrim(str_replace('\\', '/', substr($file, strlen(KALETA_ROOT))), '/');
            if (!isset($files[$path]) && !in_array($path, ['config.php', 'install.php'], true)) {
                $extra[] = $path;
            }
        }
        $count = count($changed) + count($missing) + count($extra);

        return [
            'stav' => $count === 0 ? 'ok' : 'varovani',
            'info' => $count === 0 ? t('všech %d souborů jádra odpovídá vydání %s', count($files), (string) ($data['verze'] ?? ''))
                : t('jádro se liší od vydání: změněno %d, chybí %d, navíc %d – %s', count($changed), count($missing), count($extra), implode(', ', array_slice([...$changed, ...$missing, ...$extra], 0, 6)) . ($count > 6 ? '…' : ''))
                    . '. ' . t('Úpravy jádra se nepodporují; do původní podoby je vrátí aktualizace (Zálohy a aktualizace).'),
            'zmenene' => $changed, 'chybi' => $missing, 'navic' => $extra,
        ];
    }

    /**
     * Text, který vydavatel podepisuje (tools/release.php) a instalace ověřuje.
     *
     * @param array<string, string> $files cesta => sha256
     */
    public static function stringToSign(string $version, array $files): string
    {
        ksort($files);

        return 'kaleta-soubory|' . $version . '|' . hash('sha256', (string) json_encode($files, JSON_UNESCAPED_SLASHES));
    }
}
