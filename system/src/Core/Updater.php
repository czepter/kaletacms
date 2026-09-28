<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * System updates from the admin.
 *
 * The source is the file aktualizace.json: {"verze","vydano","url","sha256","podpis","min_php","bezpecnostni","zmeny":[...]}.
 * A release marked "bezpecnostni": true can install itself ("Nastavení -> Zálohy a aktualizace", Settings -> Backups and updates).
 * The package (ZIP) is accepted only when the SHA-256 and the Ed25519 signature (Core\Signature::packageMessage) match,
 * verified by one of the public keys in system/aktualizace.pub (operational + backup, see docs/RELEASING.md). Only the
 * publisher has the private key (tools/release.php).
 * config.php, media/, storage/, install.php and layouts that are not part of the package are never overwritten.
 */
final class Updater
{
    /** Default update source; to be filled in once the project website runs. Can be overridden in Settings. */
    public const string DEFAULT_URL = 'https://kaletacms.com/aktualizace.json';

    private const array PROTECTED_PATHS = ['config.php', 'install.php', 'media/', 'storage/', 'image/ukazka/', 'tools/', '.git/'];
    private const int MAX_BYTES = 60 * 1024 * 1024;

    public function __construct(
        private readonly Settings $settings,
        private readonly string $root = KALETA_ROOT,
        private readonly string $keyFile = KALETA_SYSTEM . '/aktualizace.pub',
    ) {
    }

    public function url(): string
    {
        return $this->settings->get('update_url') !== '' ? $this->settings->get('update_url') : self::DEFAULT_URL;
    }

    /**
     * Update status; the result of the request is remembered for 12 hours.
     *
     * @return array{nastaveno:bool, aktualni:string, nova:?array<string, mixed>, chyba:?string, overeno:int}
     */
    public function state(bool $force = false): array
    {
        $state = ['nastaveno' => $this->url() !== '', 'aktualni' => KALETA_VERSION, 'nova' => null, 'chyba' => null, 'overeno' => 0];
        if (!$state['nastaveno']) {
            return $state;
        }
        $cache = json_decode($this->settings->get('update_cache'), true);
        if (!$force && is_array($cache) && ($cache['url'] ?? '') === $this->url() && time() - (int) ($cache['overeno'] ?? 0) < 12 * 3600) {
            $manifest = $cache['manifest'] ?? null;
            $state['chyba'] = $cache['chyba'] ?? null;
            $state['overeno'] = (int) $cache['overeno'];
        } else {
            try {
                $manifest = $this->manifest();
            } catch (\RuntimeException $e) {
                $manifest = null;
                $state['chyba'] = $e->getMessage();
            }
            $state['overeno'] = time();
            $this->settings->set('update_cache', (string) json_encode(['url' => $this->url(), 'overeno' => time(), 'manifest' => $manifest, 'chyba' => $state['chyba']], JSON_UNESCAPED_UNICODE));
        }
        if (is_array($manifest) && version_compare((string) $manifest['verze'], KALETA_VERSION, '>')) {
            $state['nova'] = $manifest;
        }

        return $state;
    }

    /**
     * Background maintenance: once every 12 hours checks for a new version; installs a security release itself (if that is
     * allowed), otherwise notifies the administrator by e-mail. Called after the page is sent, so it does not hold up the visitor.
     */
    public static function runInBackground(App $app): void
    {
        $s = $app->settings();
        $a = new self($s);
        if ($a->url() === '') {
            return;
        }
        $cache = json_decode($s->get('update_cache'), true);
        if (is_array($cache) && time() - (int) ($cache['overeno'] ?? 0) < 12 * 3600) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        $newVersion = $a->state(true)['nova'];
        if ($newVersion === null || empty($newVersion['bezpecnostni']) || $s->get('update_attempt') === $newVersion['verze']) {
            return;
        }
        $s->set('update_attempt', (string) $newVersion['verze']); // each version is tried and announced only once
        // written to the site e-mail (an address without an account): admin texts in the site's default language. The task also
        // runs from the public site, where the admin dictionary is not loaded – Language::runWith() loads it just for this moment
        // (also for installation error messages).
        [$subject, $text] = Language::runWith(Language::defaults($s), function () use ($app, $a, $s, $newVersion): array {
            $result = t('Security update %s is available. Install it in the administration: Settings → Backups and updates.', (string) $newVersion['verze']);
            if ($s->bool('auto_updates')) {
                try {
                    Backup::create($app->db(), 'predaktualizaci');
                    $a->install($app->db());
                    $result = t('Security update %s was installed automatically. A database backup was created before the installation.', (string) $newVersion['verze']);
                } catch (\Throwable $e) {
                    $result .= ' ' . t('The automatic installation failed: %s', $e->getMessage());
                }
            }

            return [
                t('Kaleta: security update %s', (string) $newVersion['verze']),
                $result . "\n\n" . t('Changes:') . "\n- " . implode("\n- ", $newVersion['zmeny']) . "\n\n" . $s->get('site_name'),
            ];
        }, 'admin-');
        $recipient = $s->get('site_email');
        if ($recipient !== '') {
            Mail::send($s, $recipient, $subject, $text);
        }
    }

    /**
     * @param Db|null $db the site database: the new version's migrations run right after the files are uploaded, and when they
     *                    fail, the files are reverted too (so the site is not left with new code over an unmigrated database)
     * @return string the installed version
     */
    public function install(?Db $db = null): string
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException(t('The server lacks the zip or sodium extension – update manually by uploading the files over FTP.'));
        }
        $m = $this->manifest();
        if (!version_compare((string) $m['verze'], KALETA_VERSION, '>')) {
            throw new \RuntimeException(t('No newer version is available.'));
        }
        if (version_compare(PHP_VERSION, (string) ($m['min_php'] ?? '8.4'), '<')) {
            throw new \RuntimeException(t('The new version requires PHP %s; the server runs %s.', (string) $m['min_php'], PHP_VERSION));
        }
        if (!is_writable($this->root) || !is_writable($this->root . '/system')) {
            throw new \RuntimeException(t('The system files are not writable – update manually over FTP.'));
        }
        if (Signature::keys($this->keyFile) === []) {
            throw new \RuntimeException(t('The publisher\'s public key (system/aktualizace.pub) is missing, the package cannot be verified.'));
        }

        // lock: an automatic update from background tasks and an administrator's click (or two visits at once) must not overwrite files simultaneously
        $lock = fopen(KALETA_ROOT . '/storage/cache/aktualizace.zamek', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException(t('An update is already running. Try again in a moment.'));
        }
        $workDir = KALETA_ROOT . '/storage/cache/aktualizace-' . bin2hex(random_bytes(4));
        $zip = $workDir . '.zip';
        try {
            $this->download((string) $m['url'], $zip);
            $sha = hash_file('sha256', $zip);
            if (!hash_equals(strtolower((string) $m['sha256']), $sha)) {
                throw new \RuntimeException(t('The package checksum does not match.'));
            }
            // the signature also covers the security-release flag: whoever controlled only the site with the manifest must not declare a regular release a security one
            if (!Signature::isValid(Signature::packageMessage((string) $m['verze'], $sha, !empty($m['bezpecnostni'])), (string) $m['podpis'], $this->keyFile)) {
                throw new \RuntimeException(t('The package signature is not valid – the package does not come from the Kaleta publisher.'));
            }
            $files = $this->extract($zip, $workDir);
            $previous = $this->releaseFiles();
            $releaseHashes = $this->releaseHashes();
            touch(KALETA_ROOT . '/storage/udrzba.lock');
            // every file being overwritten is set aside first: if writing fails halfway, the site returns to its original state (not a mix of versions)
            $setAside = $workDir . '-puvodni';
            $written = [];
            try {
                foreach ($files as $relativePath) {
                    $target = $this->root . '/' . $relativePath;
                    if ($relativePath === '.htaccess' && is_file($target) && isset($releaseHashes['.htaccess']) && !hash_equals($releaseHashes['.htaccess'], (string) hash_file('sha256', $target))) {
                        // custom edits of .htaccess (HTTPS, www, redirects) are not overwritten – the new version is placed next to it for comparison
                        copy($workDir . '/' . $relativePath, $target . '.kaleta-nova');
                        continue;
                    }
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
                        throw new \RuntimeException(t('Cannot create the folder %s.', dirname($relativePath)));
                    }
                    if (is_file($target)) {
                        if (!is_dir(dirname($setAside . '/' . $relativePath)) && !mkdir(dirname($setAside . '/' . $relativePath), 0775, true) || !copy($target, $setAside . '/' . $relativePath)) {
                            throw new \RuntimeException(t('Cannot write the file %s.', 'storage/cache'));
                        }
                    }
                    if (!copy($workDir . '/' . $relativePath, $target)) {
                        throw new \RuntimeException(t('Cannot write the file %s.', $relativePath));
                    }
                    $written[] = $relativePath;
                }
                if ($db !== null) {
                    // migrations read files from disk, i.e. already from the new version; the changes are additive only, the old code keeps running on them
                    Migration::apply($db, $this->settings);
                }
            } catch (\Throwable $e) {
                foreach (array_reverse($written) as $relativePath) {
                    is_file($setAside . '/' . $relativePath) ? @copy($setAside . '/' . $relativePath, $this->root . '/' . $relativePath) : @unlink($this->root . '/' . $relativePath);
                }
                self::deleteFolder($setAside);
                throw new \RuntimeException($e->getMessage() . ' ' . t('The website files have been returned to their state before the update.'), 0, $e);
            }
            self::deleteFolder($setAside);
            self::cleanUpObsolete($this->root, $previous, $files);
        } finally {
            @unlink(KALETA_ROOT . '/storage/udrzba.lock');
            @unlink($zip);
            self::deleteFolder($workDir);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->settings->set('update_cache', '');

        return (string) $m['verze'];
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $json = $this->http($this->url(), 200 * 1024, 6); // short limit: the check runs after the page is sent, but not every server can detach it
        $m = json_decode($json, true);
        if (!is_array($m) || !isset($m['verze'], $m['url'], $m['sha256'], $m['podpis']) || !preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', (string) $m['verze'])) {
            throw new \RuntimeException(t('The update information file is not in a valid format.'));
        }
        $m['zmeny'] = array_values(array_filter(array_map(fn ($z): string => mb_substr((string) $z, 0, 300), (array) ($m['zmeny'] ?? []))));

        return $m;
    }

    private function download(string $url, string $target): void
    {
        file_put_contents($target, $this->http($url, self::MAX_BYTES));
    }

    private function http(string $url, int $maxBytes, int $timeoutSeconds = 30): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $isLocal = in_array($host, ['localhost', '127.0.0.1'], true);
        if (!preg_match('#^https://#i', $url) && !($isLocal && preg_match('#^http://#i', $url))) {
            throw new \RuntimeException(t('The update source must use an https:// address.'));
        }
        $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeoutSeconds, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta/" . KALETA_VERSION . "\r\n"]]), 0, $maxBytes + 1);
        if ($data === false || $data === '') {
            throw new \RuntimeException(t('The update source is not reachable (%s).', $host));
        }
        if (strlen($data) > $maxBytes) {
            throw new \RuntimeException(t('The downloaded file is unexpectedly large.'));
        }

        return $data;
    }

    /**
     * Extracts the package into a working folder and returns the list of files to overwrite (without protected paths).
     *
     * @return list<string>
     */
    private function extract(string $zipFile, string $destination): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException(t('The package cannot be opened.'));
        }
        $fileNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileNames[] = (string) $zip->getNameIndex($i);
        }
        // the package may have everything in one top-level folder (as GitHub does) - it is stripped off
        $first = array_unique(array_map(fn (string $j): string => explode('/', $j, 2)[0], $fileNames));
        $prefix = count($first) === 1 && !in_array('index.php', $fileNames, true) ? $first[0] . '/' : '';

        $files = [];
        foreach ($fileNames as $i => $displayName) {
            $relativePath = substr($displayName, strlen($prefix));
            if ($relativePath === '' || str_ends_with($relativePath, '/')) {
                continue;
            }
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
                throw new \RuntimeException(t('The package contains an unsafe path.'));
            }
            foreach (self::PROTECTED_PATHS as $protectedPath) {
                if ($relativePath === $protectedPath || (str_ends_with($protectedPath, '/') && str_starts_with($relativePath, $protectedPath))) {
                    continue 2;
                }
            }
            $target = $destination . '/' . $relativePath;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $zip->getFromIndex($i));
            $files[] = $relativePath;
        }
        $zip->close();
        if (!in_array('system/bootstrap.php', $files, true) || !in_array('index.php', $files, true)) {
            throw new \RuntimeException(t('The package does not contain Kaleta.'));
        }

        return $files;
    }

    /**
     * Files that left the package before the update could clean up after itself (or the site skipped them): path => hashes
     * of all released versions. The core file list no longer knows about them, so they are cleaned up by this list.
     */
    private const array REMOVED_FILES = [
        // 'path/to/file.php' => ['sha256 of a released version', …] – files the new version removed
    ];

    /**
     * One-time cleanup after moving to a new version: deletes known removed files, but only when they are exactly as we
     * released them. A file the administrator edited is left alone.
     *
     * @return int number of deleted files
     */
    public static function cleanUpRemoved(string $root): int
    {
        $deleted = 0;
        foreach (self::REMOVED_FILES as $relativePath => $hashes) {
            $file = $root . '/' . $relativePath;
            if (is_file($file) && in_array(hash_file('sha256', $file), $hashes, true) && @unlink($file)) {
                $deleted++;
                @rmdir(dirname($file)); // the folder disappears only if it was left empty
            }
        }
        // files of the previous release that the package carries only for the update to run: the old code that installs it
        // still loads its classes in the same request (renamed classes would otherwise be missing) – the new version then deletes them
        $legacy = json_decode((string) @file_get_contents($root . '/system/soubory.json'), true)['legacy'] ?? [];
        foreach (is_array($legacy) ? $legacy : [] as $relativePath => $hash) {
            $file = $root . '/' . $relativePath;
            if (str_starts_with((string) $relativePath, 'system/src/') && !str_contains((string) $relativePath, '..') && is_file($file)
                && hash_equals((string) $hash, (string) hash_file('sha256', $file)) && @unlink($file)) {
                $deleted++;
                for ($folder = dirname($file); $folder !== $root . '/system/src' && @rmdir($folder); $folder = dirname($folder));
            }
        }

        return $deleted;
    }

    /** @return array<string, string> hashes of the files of the currently installed release (system/soubory.json) */
    private function releaseHashes(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/system/soubory.json'), true);

        return is_array($data['soubory'] ?? null) ? array_map('strval', $data['soubory']) : [];
    }

    /** @return list<string> core files per the list of the currently installed release (system/soubory.json); empty without the list */
    private function releaseFiles(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/system/soubory.json'), true);

        return is_array($data['soubory'] ?? null) ? array_map('strval', array_keys($data['soubory'])) : [];
    }

    /**
     * Deletes files that belonged to the old release and are no longer in the new one (renamed and removed parts of the system).
     * Deletes only what the old release itself brought - it does not touch custom files, templates, media or protected paths.
     *
     * @param list<string> $previous
     * @param list<string> $newItems
     * @return int number of deleted files
     */
    public static function cleanUpObsolete(string $root, array $previous, array $newItems): int
    {
        $deleted = 0;
        foreach (array_diff($previous, $newItems) as $relativePath) {
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_contains($relativePath, '\\') || str_contains($relativePath, "\0")) {
                continue;
            }
            foreach (self::PROTECTED_PATHS as $protectedPath) {
                if ($relativePath === $protectedPath || (str_ends_with($protectedPath, '/') && str_starts_with($relativePath, $protectedPath))) {
                    continue 2;
                }
            }
            $file = $root . '/' . $relativePath;
            if (is_file($file) && @unlink($file)) {
                $deleted++;
                @rmdir(dirname($file)); // the folder disappears only if it was left empty
            }
        }

        return $deleted;
    }

    private static function deleteFolder(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($folder);
    }
}
