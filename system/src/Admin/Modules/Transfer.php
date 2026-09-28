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
 * Import and export: moving from WordPress (a WXR file) and export of the whole site to an open format.
 *
 * The import has three steps on one screen: 1. file (uploaded with the form, or via FTP to storage/import/),
 * 2. preview – what is in the file and what will not be converted, 3. import in batches (the form submits itself,
 * data-auto-odeslat). Images from the old site are downloaded only in a separate step on explicit request. Core\WpImport
 * does all the work; here are only the form handlers. The state of an ongoing import is in a file next to the export,
 * not in the session.
 */
final class Transfer extends Module
{
    public const string IDENT = 'transfer';
    public const string NAME = 'Import a export';
    public const string GROUP = 'Správa';
    public const string ICON = 'b-archiv';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $files = [];
        foreach (WpFile::listAll() as $s) {
            $files[] = $s + ['stav' => WpImport::loadState($s['soubor'])];
        }

        return $this->view('list', 'Import a export', [
            'files' => $files,
            'uploadLimit' => min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size'))),
            'missingXml' => !class_exists(\XMLReader::class) || !class_exists(\Dom\HTMLDocument::class),
            'exports' => SiteExport::listAll(),
            'hasZip' => class_exists(\ZipArchive::class),
        ]);
    }

    /* ---------- import: 1. file ---------- */

    /** Uploading the export with the form; the file ends up in storage/import/ just like one uploaded via FTP. */
    protected function actionUpload(): Response
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
            // verify first, only then save: nothing that is not a WordPress export gets into the folder
            (new WpFile((string) $file['tmp_name']))->verifyContent();
            if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                throw new \RuntimeException('Soubor se nepodařilo uložit – zkontrolujte práva k zápisu do storage/import.');
            }
        } catch (\RuntimeException $e) {
            return $this->back(self::message($e), type: 'chyba');
        }

        return $this->start($name);
    }

    /** Choosing a file that already lies in storage/import/ (uploaded via FTP or earlier). */
    protected function actionSelect(): Response
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

        return $this->back('', 'progress', ['soubor' => $file]);
    }

    protected function actionDeleteFile(): Response
    {
        $path = WpFile::path($this->request->post('soubor'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
            WpImport::deleteState($this->request->post('soubor'));
        }

        return $this->back('Soubor byl smazán. Převedený obsah na webu zůstává.');
    }

    /* ---------- import: 2. preview and options ---------- */

    protected function actionPreview(): Response
    {
        $state = $this->state();
        if ($state === null || $state['faze'] === 'analyza') {
            return $this->back('', $state === null ? '' : 'progress', $state === null ? [] : ['soubor' => $state['soubor']]);
        }
        $settings = $this->app->settings();

        return $this->view('preview', 'Import z WordPressu', [
            'state' => $state,
            'languages' => array_merge([Language::defaults($settings)], Language::additional($settings)),
            'categories' => $this->db->all('SELECT idt, nazev, jazyk FROM {kategorie} ORDER BY jazyk, nazev'),
            'redirectsEnabled' => \Kaleta\Core\Extensions::isEnabled($settings, 'presmerovani'),
        ]);
    }

    /** Saves the options from the preview and starts the import. */
    protected function actionRun(): Response
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

        return $this->back('', 'progress', ['soubor' => $state['soubor']]);
    }

    /* ---------- import: 3. progress in batches (preview, content and images) ---------- */

    /**
     * GET only shows where the import is; POST does one batch. Until it is done, the template submits the form again by itself.
     * A lock on the state file prevents two browser windows from importing at the same time.
     */
    protected function actionProgress(): Response
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
                    $state = WpImport::loadState($state['soubor']) ?? $state; // fresh state only under the lock
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
            return $this->back('', 'preview', ['soubor' => $state['soubor']]);
        }

        return $this->view('progress', 'Import z WordPressu', [
            'state' => $state, 'error' => $error,
            'canDownload' => ImageDownloader::isAvailable() && extension_loaded('gd'),
            'domain' => ImageDownloader::domainFromUrl((string) $state['web']['adresa']),
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

    /** Explicit start of downloading images from the old site (only after the content import). */
    protected function actionImages(): Response
    {
        $state = $this->state();
        if (!$this->request->isPost() || $state === null || !in_array($state['faze'], ['hotovo', 'obrazky-hotovo'], true) || !ImageDownloader::isAvailable()) {
            return $this->back();
        }
        (new WpImport($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id()))->startImages($state);
        WpImport::saveState($state);

        return $this->back('', 'progress', ['soubor' => $state['soubor']]);
    }

    /** @return array<string, mixed>|null import state of the file from the URL or the form */
    private function state(): ?array
    {
        $file = $this->request->isPost() && $this->request->post('soubor') !== '' ? $this->request->post('soubor') : $this->request->get('soubor');

        return WpFile::path($file) === null ? null : WpImport::loadState($file);
    }

    /* ---------- export ---------- */

    protected function actionExport(): Response
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
     * Download of the export. The archive can have hundreds of MB, so it is not sent via Response (which holds the whole body
     * in memory), but in chunks directly from the file. Access is guarded by the admin (the module is administrator only),
     * the file name by Core\SiteExport::path().
     */
    protected function actionDownload(): Response
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

    protected function actionDeleteExport(): Response
    {
        $path = SiteExport::path($this->request->post('soubor'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
        }

        return $this->back('Export byl smazán.');
    }

    /** Exception message in the admin language; the number (XML line, response code) is carried by getCode(), so that the text can be translated. */
    private static function message(\RuntimeException $e): string
    {
        return t($e->getMessage()) . (is_int($e->getCode()) && $e->getCode() > 0 ? ' ' . $e->getCode() : '');
    }

    /** "8M" from php.ini → bytes. */
    private static function bytes(string $value): int
    {
        $number = (int) $value;

        return $number <= 0 ? PHP_INT_MAX : $number * match (strtolower(substr(trim($value), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
    }
}
