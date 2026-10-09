<?php
/**
 * Kaleta – the "Kaleta for Claude" plugin as a zip (3.8):
 *
 *   php tools/build-plugin.php [--out=<folder>]
 *
 * Packs integrations/claude-plugin/ into dist/kaleta-claude-plugin-<KALETA_VERSION>.zip (or into --out), with the plugin's
 * files under one top-level folder "kaleta/" – the form claude.ai's Customize → Plugins → Upload plugin accepts. Only the
 * plugin's own files go in: the manifest, .mcp.json, the README and the skills. It refuses to build when the manifest's
 * version is not KALETA_VERSION (tools/unit-tests.php checks the same), so a zip never carries another version's name.
 * Nothing is uploaded or published.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
require_once dirname(__DIR__) . '/system/bootstrap.php';

$source = KALETA_ROOT . '/integrations/claude-plugin';
$out = KALETA_ROOT . '/dist';
/** @var list<string> $arguments */
$arguments = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], 'is_string')) : [];
foreach (array_slice($arguments, 1) as $arg) {
    if (str_starts_with($arg, '--out=') && substr($arg, 6) !== '') {
        $out = rtrim(substr($arg, 6), '/');
    } else {
        fwrite(STDERR, "Usage: php tools/build-plugin.php [--out=<folder>]\n");
        exit(2);
    }
}

$manifest = json_decode((string) @file_get_contents($source . '/.claude-plugin/plugin.json'), true);
if (!is_array($manifest) || ($manifest['name'] ?? null) !== 'kaleta') {
    fwrite(STDERR, "integrations/claude-plugin/.claude-plugin/plugin.json is missing or not valid JSON.\n");
    exit(1);
}
if (($manifest['version'] ?? null) !== KALETA_VERSION) {
    fwrite(STDERR, 'The plugin version (' . (is_string($manifest['version'] ?? null) ? $manifest['version'] : 'none') . ') is not KALETA_VERSION ' . KALETA_VERSION
        . " – raise \"version\" in integrations/claude-plugin/.claude-plugin/plugin.json.\n");
    exit(1);
}

// the plugin's files: the manifest, .mcp.json, the README and skills/<name>/… – nothing else (no dot files of an editor)
$files = ['.claude-plugin/plugin.json', '.mcp.json', 'README.md'];
$skills = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/skills', FilesystemIterator::SKIP_DOTS));
foreach ($skills as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && !str_starts_with($file->getFilename(), '.')) {
        $files[] = substr($file->getPathname(), strlen($source) + 1);
    }
}
sort($files);

// a developer tool: --out is the maintainer's own folder on their own machine, not input from a request
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
if (!is_dir($out) && !@mkdir($out, 0775, true)) {
    fwrite(STDERR, "Cannot create {$out}.\n");
    exit(1);
}
$zipFile = $out . '/kaleta-claude-plugin-' . KALETA_VERSION . '.zip';
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
@unlink($zipFile);
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot write {$zipFile}.\n");
    exit(1);
}
foreach ($files as $file) {
    if (!is_file($source . '/' . $file)) {
        fwrite(STDERR, "Missing integrations/claude-plugin/{$file}.\n");
        exit(1);
    }
    $zip->addFile($source . '/' . $file, 'kaleta/' . $file);
}
$zip->close();
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
echo 'Done: ' . $zipFile . ' (' . count($files) . ' files, ' . max(1, (int) round((int) filesize($zipFile) / 1024)) . " kB)\n";
