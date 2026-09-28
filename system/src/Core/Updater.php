<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Aktualizace systému z administrace.
 *
 * Zdroj je soubor aktualizace.json: {"verze","vydano","url","sha256","podpis","min_php","bezpecnostni","zmeny":[...]}.
 * Vydání označené "bezpecnostni": true se umí nainstalovat samo (Nastavení -> Zálohy a aktualizace).
 * Balíček (ZIP) se přijme jen tehdy, když sedí SHA-256 a podpis Ed25519 (Core\Podpis::zpravaBalicku) ověřený některým
 * z veřejných klíčů v system/aktualizace.pub (provozní + záložní, viz docs/RELEASING.md). Soukromý klíč má jen vydavatel (tools/release.php).
 * Nikdy se nepřepisuje config.php, media/, storage/, install.php ani layouty, které nejsou součástí balíčku.
 */
final class Updater
{
    /** Výchozí zdroj aktualizací; doplní se, až poběží web projektu. Lze přepsat v Nastavení. */
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
     * Stav aktualizací; výsledek dotazu se pamatuje 12 hodin.
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
     * Údržba na pozadí: jednou za 12 hodin ověří novou verzi; bezpečnostní vydání nainstaluje samo (je-li to
     * povoleno), jinak administrátora upozorní e-mailem. Volá se po odeslání stránky, takže návštěvníka nezdržuje.
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
        $s->set('update_attempt', (string) $newVersion['verze']); // každá verze se zkouší a oznamuje jen jednou
        // píše se na e-mail webu (adresa bez účtu): texty administrace ve výchozím jazyce webu. Úloha běží i z veřejného webu,
        // kde slovník administrace načtený není – Jazyk::docasne() ho načte jen na tuto chvíli (i pro hlášky chyb instalace).
        [$subject, $text] = Language::runWith(Language::defaults($s), function () use ($app, $a, $s, $newVersion): array {
            $result = t('Je k dispozici bezpečnostní aktualizace %s. Nainstalujte ji v administraci: Nastavení → Zálohy a aktualizace.', (string) $newVersion['verze']);
            if ($s->bool('auto_updates')) {
                try {
                    Backup::create($app->db(), 'predaktualizaci');
                    $a->install($app->db());
                    $result = t('Bezpečnostní aktualizace %s byla nainstalována automaticky. Před instalací vznikla záloha databáze.', (string) $newVersion['verze']);
                } catch (\Throwable $e) {
                    $result .= ' ' . t('Automatická instalace se nezdařila: %s', $e->getMessage());
                }
            }

            return [
                t('Kaleta: bezpečnostní aktualizace %s', (string) $newVersion['verze']),
                $result . "\n\n" . t('Změny:') . "\n- " . implode("\n- ", $newVersion['zmeny']) . "\n\n" . $s->get('site_name'),
            ];
        }, 'admin-');
        $recipient = $s->get('site_email');
        if ($recipient !== '') {
            Mail::send($s, $recipient, $subject, $text);
        }
    }

    /**
     * @param Db|null $db databáze webu: migrace nové verze proběhnou hned po nahrání souborů a když selže, vrátí se
     *                    i soubory (web tak nezůstane s novým kódem nad nezmigrovanou databází)
     * @return string nainstalovaná verze
     */
    public function install(?Db $db = null): string
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException(t('Server nemá rozšíření zip nebo sodium - aktualizujte ručně nahráním souborů přes FTP.'));
        }
        $m = $this->manifest();
        if (!version_compare((string) $m['verze'], KALETA_VERSION, '>')) {
            throw new \RuntimeException(t('Žádná novější verze není k dispozici.'));
        }
        if (version_compare(PHP_VERSION, (string) ($m['min_php'] ?? '8.4'), '<')) {
            throw new \RuntimeException(t('Nová verze vyžaduje PHP %s, na serveru běží %s.', (string) $m['min_php'], PHP_VERSION));
        }
        if (!is_writable($this->root) || !is_writable($this->root . '/system')) {
            throw new \RuntimeException(t('Soubory systému nejsou zapisovatelné - aktualizujte ručně přes FTP.'));
        }
        if (Signature::keys($this->keyFile) === []) {
            throw new \RuntimeException(t('Chybí veřejný klíč vydavatele (system/aktualizace.pub), balíček nelze ověřit.'));
        }

        // zámek: automatická aktualizace z úloh na pozadí a klik správce (nebo dvě návštěvy naráz) nesmějí přepisovat soubory současně
        $lock = fopen(KALETA_ROOT . '/storage/cache/aktualizace.zamek', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException(t('Aktualizace už právě běží. Zkuste to za chvíli.'));
        }
        $workDir = KALETA_ROOT . '/storage/cache/aktualizace-' . bin2hex(random_bytes(4));
        $zip = $workDir . '.zip';
        try {
            $this->download((string) $m['url'], $zip);
            $sha = hash_file('sha256', $zip);
            if (!hash_equals(strtolower((string) $m['sha256']), $sha)) {
                throw new \RuntimeException(t('Kontrolní součet balíčku nesouhlasí.'));
            }
            // podpis kryje i příznak bezpečnostního vydání: kdo by ovládl jen web s manifestem, nesmí běžné vydání prohlásit za bezpečnostní
            if (!Signature::isValid(Signature::packageMessage((string) $m['verze'], $sha, !empty($m['bezpecnostni'])), (string) $m['podpis'], $this->keyFile)) {
                throw new \RuntimeException(t('Podpis balíčku není platný - balíček nepochází od vydavatele Kalety.'));
            }
            $files = $this->extract($zip, $workDir);
            $previous = $this->releaseFiles();
            $releaseHashes = $this->releaseHashes();
            touch(KALETA_ROOT . '/storage/udrzba.lock');
            // každý přepisovaný soubor se nejdřív odloží: selže-li zápis uprostřed, web se vrátí do původního stavu (ne směs verzí)
            $setAside = $workDir . '-puvodni';
            $written = [];
            try {
                foreach ($files as $relativePath) {
                    $target = $this->root . '/' . $relativePath;
                    if ($relativePath === '.htaccess' && is_file($target) && isset($releaseHashes['.htaccess']) && !hash_equals($releaseHashes['.htaccess'], (string) hash_file('sha256', $target))) {
                        // vlastní úpravy .htaccess (HTTPS, www, přesměrování) se nepřepíšou – nová verze leží vedle k porovnání
                        copy($workDir . '/' . $relativePath, $target . '.kaleta-nova');
                        continue;
                    }
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
                        throw new \RuntimeException(t('Nelze vytvořit složku %s.', dirname($relativePath)));
                    }
                    if (is_file($target)) {
                        if (!is_dir(dirname($setAside . '/' . $relativePath)) && !mkdir(dirname($setAside . '/' . $relativePath), 0775, true) || !copy($target, $setAside . '/' . $relativePath)) {
                            throw new \RuntimeException(t('Nelze zapsat soubor %s.', 'storage/cache'));
                        }
                    }
                    if (!copy($workDir . '/' . $relativePath, $target)) {
                        throw new \RuntimeException(t('Nelze zapsat soubor %s.', $relativePath));
                    }
                    $written[] = $relativePath;
                }
                if ($db !== null) {
                    // migrace čtou soubory z disku, tedy už z nové verze; změny jsou jen přidávající, starý kód nad nimi běží dál
                    Migration::apply($db, $this->settings);
                }
            } catch (\Throwable $e) {
                foreach (array_reverse($written) as $relativePath) {
                    is_file($setAside . '/' . $relativePath) ? @copy($setAside . '/' . $relativePath, $this->root . '/' . $relativePath) : @unlink($this->root . '/' . $relativePath);
                }
                self::deleteFolder($setAside);
                throw new \RuntimeException($e->getMessage() . ' ' . t('Soubory webu jsou vrácené do stavu před aktualizací.'), 0, $e);
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
        $json = $this->http($this->url(), 200 * 1024, 6); // krátký limit: kontrola běží po odeslání stránky, ale ne každý server ji umí oddělit
        $m = json_decode($json, true);
        if (!is_array($m) || !isset($m['verze'], $m['url'], $m['sha256'], $m['podpis']) || !preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', (string) $m['verze'])) {
            throw new \RuntimeException(t('Soubor s informací o aktualizaci nemá platný tvar.'));
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
            throw new \RuntimeException(t('Zdroj aktualizací musí být na adrese https://.'));
        }
        $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeoutSeconds, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta/" . KALETA_VERSION . "\r\n"]]), 0, $maxBytes + 1);
        if ($data === false || $data === '') {
            throw new \RuntimeException(t('Zdroj aktualizací není dostupný (%s).', $host));
        }
        if (strlen($data) > $maxBytes) {
            throw new \RuntimeException(t('Stahovaný soubor je nečekaně velký.'));
        }

        return $data;
    }

    /**
     * Rozbalí balíček do pracovní složky a vrátí seznam souborů k přepsání (bez chráněných cest).
     *
     * @return list<string>
     */
    private function extract(string $zipFile, string $destination): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException(t('Balíček nelze otevřít.'));
        }
        $fileNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileNames[] = (string) $zip->getNameIndex($i);
        }
        // balíček může mít všechno v jedné složce navrch (jak to dělá GitHub) - ta se odřízne
        $first = array_unique(array_map(fn (string $j): string => explode('/', $j, 2)[0], $fileNames));
        $prefix = count($first) === 1 && !in_array('index.php', $fileNames, true) ? $first[0] . '/' : '';

        $files = [];
        foreach ($fileNames as $i => $displayName) {
            $relativePath = substr($displayName, strlen($prefix));
            if ($relativePath === '' || str_ends_with($relativePath, '/')) {
                continue;
            }
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
                throw new \RuntimeException(t('Balíček obsahuje nebezpečnou cestu.'));
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
            throw new \RuntimeException(t('Balíček neobsahuje Kaletu.'));
        }

        return $files;
    }

    /**
     * Soubory, které z balíčku odešly dřív, než aktualizace uměla po sobě uklízet (nebo je web přeskočil): cesta => otisky
     * všech vydaných podob. Seznam souborů jádra o nich už neví, proto se uklízejí podle tohoto výčtu.
     */
    private const array REMOVED_FILES = [
        // 'cesta/k/souboru.php' => ['sha256 vydané podoby', …] – soubory, které nová verze zrušila
    ];

    /**
     * Jednorázový úklid po přechodu na novou verzi: smaže známé zrušené soubory, ale jen když jsou přesně takové, jaké
     * jsme je vydali. Soubor, který si správce upravil (nebo šablonu, kterou web právě používá), nechává být.
     *
     * @return int počet smazaných souborů
     */
    public static function cleanUpRemoved(string $root, string $activeLayout = ''): int
    {
        $deleted = 0;
        foreach (self::REMOVED_FILES as $relativePath => $hashes) {
            $file = $root . '/' . $relativePath;
            if (str_starts_with($relativePath, 'layout/' . $activeLayout . '/') && $activeLayout !== '') {
                continue;
            }
            if (is_file($file) && in_array(hash_file('sha256', $file), $hashes, true) && @unlink($file)) {
                $deleted++;
                @rmdir(dirname($file)); // složka zmizí, jen když zůstala prázdná
            }
        }
        // soubory předchozího vydání, které balíček nese jen kvůli průběhu aktualizace: starý kód, který ji instaluje,
        // v tomtéž požadavku ještě načítá své třídy (přejmenované třídy by jinak chyběly) – nová verze je pak smaže
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

    /** @return array<string, string> otisky souborů právě nainstalovaného vydání (system/soubory.json) */
    private function releaseHashes(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/system/soubory.json'), true);

        return is_array($data['soubory'] ?? null) ? array_map('strval', $data['soubory']) : [];
    }

    /** @return list<string> soubory jádra podle seznamu právě nainstalovaného vydání (system/soubory.json); bez seznamu prázdné */
    private function releaseFiles(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/system/soubory.json'), true);

        return is_array($data['soubory'] ?? null) ? array_map('strval', array_keys($data['soubory'])) : [];
    }

    /**
     * Smaže soubory, které patřily ke starému vydání a v novém už nejsou (přejmenované a zrušené části systému).
     * Maže jen to, co staré vydání samo přineslo - vlastní soubory, šablony, média ani chráněné cesty se nedotkne.
     *
     * @param list<string> $previous
     * @param list<string> $newItems
     * @return int počet smazaných souborů
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
                @rmdir(dirname($file)); // složka zmizí, jen když zůstala prázdná
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
