<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\SiteExport;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Core\ImageDownloader;
use Kaleta\Core\WpImport;
use Kaleta\Core\WpFile;

/**
 * Import a export: přechod z WordPressu (soubor WXR) a export celého webu do otevřeného formátu.
 *
 * Import má tři kroky na jedné obrazovce: 1. soubor (nahraný formulářem, nebo přes FTP do storage/import/),
 * 2. náhled – co v souboru je a co se nepřevede, 3. import po dávkách (formulář se odesílá sám, data-auto-odeslat).
 * Obrázky ze starého webu se stahují až ve zvláštním kroku na výslovné přání. Všechnu práci dělá Core\WpImport;
 * tady je jen obsluha formulářů. Stav rozpracovaného importu je v souboru vedle exportu, ne v session.
 */
final class Transfer extends Module
{
    public const string IDENT = 'prenos';
    public const string NAME = 'Import a export';
    public const string GROUP = 'Správa';
    public const string ICON = 'b-archiv';
    public const bool ADMIN_ONLY = true;

    protected function akceVypis(): Response
    {
        $files = [];
        foreach (WpFile::listAll() as $s) {
            $files[] = $s + ['stav' => WpImport::loadState($s['soubor'])];
        }

        return $this->view('vypis', 'Import a export', [
            'soubory' => $files,
            'limitNahrani' => min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size'))),
            'chybiXml' => !class_exists(\XMLReader::class) || !class_exists(\Dom\HTMLDocument::class),
            'exporty' => SiteExport::listAll(),
            'umiZip' => class_exists(\ZipArchive::class),
        ]);
    }

    /* ---------- import: 1. soubor ---------- */

    /** Nahrání exportu formulářem; soubor skončí ve storage/import/ stejně jako ten nahraný přes FTP. */
    protected function akceNahraj(): Response
    {
        $file = $this->request->file('soubor');
        if (!$this->request->isPost() || $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return $this->back('Soubor se nepodařilo nahrát. Je-li větší, než server dovoluje, nahrajte ho přes FTP do složky storage/import/.', type: 'chyba');
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'xml') {
            return $this->back('Soubor musí mít příponu .xml – je to export z WordPressu (Nástroje → Export).', type: 'chyba');
        }
        try {
            $name = WpFile::uploadName((string) $file['name']);
            $target = WpFile::folder() . '/' . $name;
            // nejdřív ověřit, až potom uložit: do složky se nedostane nic, co není export z WordPressu
            (new WpFile((string) $file['tmp_name']))->verifyContent();
            if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                throw new \RuntimeException('Soubor se nepodařilo uložit – zkontrolujte práva k zápisu do storage/import.');
            }
        } catch (\RuntimeException $e) {
            return $this->back(self::message($e), type: 'chyba');
        }

        return $this->start($name);
    }

    /** Výběr souboru, který už ve storage/import/ leží (nahraný přes FTP nebo dříve). */
    protected function akceVyber(): Response
    {
        $file = $this->request->post('soubor');
        $path = WpFile::path($file);
        if (!$this->request->isPost() || $path === null) {
            return $this->back('Soubor neexistuje.', type: 'chyba');
        }
        try {
            (new WpFile($path))->verify();
        } catch (\RuntimeException $e) {
            return $this->back(self::message($e), type: 'chyba');
        }

        return $this->start($file);
    }

    private function start(string $file): Response
    {
        WpImport::saveState(WpImport::newState($file));

        return $this->back('', 'prubeh', ['soubor' => $file]);
    }

    protected function akceSmazSoubor(): Response
    {
        $path = WpFile::path($this->request->post('soubor'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
            WpImport::deleteState($this->request->post('soubor'));
        }

        return $this->back('Soubor byl smazán. Převedený obsah na webu zůstává.');
    }

    /* ---------- import: 2. náhled a volby ---------- */

    protected function akceNahled(): Response
    {
        $state = $this->state();
        if ($state === null || $state['faze'] === 'analyza') {
            return $this->back('', $state === null ? '' : 'prubeh', $state === null ? [] : ['soubor' => $state['soubor']]);
        }
        $settings = $this->app->settings();

        return $this->view('nahled', 'Import z WordPressu', [
            'stav' => $state,
            'jazyky' => array_merge([Language::defaults($settings)], Language::additional($settings)),
            'rubriky' => $this->db->all('SELECT idt, nazev, jazyk FROM {kategorie} ORDER BY jazyk, nazev'),
            'presmerovaniZapnuto' => \Kaleta\Core\Extensions::isEnabled($settings, 'presmerovani'),
        ]);
    }

    /** Uloží volby z náhledu a spustí import. */
    protected function akceSpust(): Response
    {
        $state = $this->state();
        if (!$this->request->isPost() || $state === null || $state['faze'] === 'analyza') {
            return $this->back();
        }
        $r = $this->request;
        $state['volby'] = [
            'jazyk' => in_array($r->post('jazyk'), Language::additional($this->app->settings()), true) ? $r->post('jazyk') : '',
            'koncepty' => $r->postBool('koncepty'), 'stranky' => $r->postBool('stranky'), 'stavitel' => $r->postBool('stavitel'),
            'presmerovani' => $r->postBool('presmerovani'), 'rubrika' => $r->postInt('rubrika'),
        ];
        $state['faze'] = 'import';
        $state['pozice'] = 0;
        $state['vysledek'] = WpImport::newState($state['soubor'])['vysledek'];
        WpImport::saveState($state);

        return $this->back('', 'prubeh', ['soubor' => $state['soubor']]);
    }

    /* ---------- import: 3. průběh po dávkách (náhled, obsah i obrázky) ---------- */

    /**
     * GET jen ukáže, kde import je; POST udělá jednu dávku. Dokud není hotovo, šablona formulář sama znovu odešle.
     * Zámek na stavovém souboru brání tomu, aby dvě okna prohlížeče importovala současně.
     */
    protected function akcePrubeh(): Response
    {
        $state = $this->state();
        if ($state === null) {
            return $this->back('Soubor neexistuje.', type: 'chyba');
        }
        $error = '';
        if ($this->request->isPost() && in_array($state['faze'], ['analyza', 'import', 'obrazky'], true)) {
            $lock = fopen(WpFile::folder() . '/import.zamek', 'c');
            if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    @set_time_limit(60);
                    $state = WpImport::loadState($state['soubor']) ?? $state; // čerstvý stav až pod zámkem
                    $this->batch($state);
                } catch (\RuntimeException $e) {
                    $error = self::message($e);
                } finally {
                    WpImport::saveState($state);
                    flock($lock, LOCK_UN);
                }
            }
        }
        if ($state['faze'] === 'nahled' && $error === '') {
            return $this->back('', 'nahled', ['soubor' => $state['soubor']]);
        }

        return $this->view('prubeh', 'Import z WordPressu', [
            'stav' => $state, 'chyba' => $error,
            'stahovaniMozne' => ImageDownloader::isAvailable() && extension_loaded('gd'),
            'domena' => ImageDownloader::domainFromUrl((string) $state['web']['adresa']),
        ]);
    }

    /** @param array<string, mixed> $state */
    private function batch(array &$state): void
    {
        $import = new WpImport($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id());
        match ($state['faze']) {
            'analyza' => WpImport::analyze($state),
            'import' => $import->import($state),
            'obrazky' => $import->images($state, new ImageDownloader((string) $state['web']['adresa'])),
        };
    }

    /** Výslovné spuštění stahování obrázků ze starého webu (až po importu obsahu). */
    protected function akceObrazky(): Response
    {
        $state = $this->state();
        if (!$this->request->isPost() || $state === null || !in_array($state['faze'], ['hotovo', 'obrazky-hotovo'], true) || !ImageDownloader::isAvailable()) {
            return $this->back();
        }
        (new WpImport($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id()))->startImages($state);
        WpImport::saveState($state);

        return $this->back('', 'prubeh', ['soubor' => $state['soubor']]);
    }

    /** @return array<string, mixed>|null stav importu souboru z adresy nebo formuláře */
    private function state(): ?array
    {
        $file = $this->request->isPost() && $this->request->post('soubor') !== '' ? $this->request->post('soubor') : $this->request->get('soubor');

        return WpFile::path($file) === null ? null : WpImport::loadState($file);
    }

    /* ---------- export ---------- */

    protected function akceExport(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $result = SiteExport::create($this->db, $this->app->settings());
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), type: 'chyba');
        }
        if ($result['duvod'] !== '') {
            $this->app->session->flash('info', $result['duvod']);
        }

        return $this->back('Export je hotový – stáhněte si ho ze seznamu níže.');
    }

    /**
     * Stažení exportu. Archiv může mít stovky MB, proto se neposílá přes Response (ta drží celé tělo v paměti),
     * ale po kouscích přímo ze souboru. Přístup hlídá administrace (modul je jen pro správce), název souboru Core\ExportWebu::cesta().
     */
    protected function akceStahni(): Response
    {
        $path = SiteExport::path($this->request->get('soubor'));
        if ($path === null) {
            return $this->error('Export neexistuje.', 404);
        }
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    protected function akceSmazExport(): Response
    {
        $path = SiteExport::path($this->request->post('soubor'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
        }

        return $this->back('Export byl smazán.');
    }

    /** Hláška výjimky v jazyce administrace; číslo (řádek XML, kód odpovědi) nese getCode(), aby šel text přeložit. */
    private static function message(\RuntimeException $e): string
    {
        return t($e->getMessage()) . (is_int($e->getCode()) && $e->getCode() > 0 ? ' ' . $e->getCode() : '');
    }

    /** "8M" z php.ini → bajty. */
    private static function bytes(string $value): int
    {
        $number = (int) $value;

        return $number <= 0 ? PHP_INT_MAX : $number * match (strtolower(substr(trim($value), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
    }
}
