<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Images;
use Kaleta\Core\Response;

/**
 * Media: uploading, also by drag and drop and directly from the editor, folders,
 * labels, deleting and an overview of where an image is used (news, builds, collections, logo…).
 * An image is inserted into the text from the editor.
 */
final class Media extends Module
{
    public const string IDENT = 'media';
    public const string NAME = 'Média';
    public const string GROUP = 'Obsah';
    public const string ICON = 'media';

    /** Everyone who writes news must be able to upload; but only the administrator changes and deletes other people's images. */
    public const bool FOR_ALL_USERS = true;

    private const int PER_PAGE = 40;

    protected function actionList(): Response
    {
        $pageNumber = max(1, $this->request->getInt('strana', 1));
        [$where, $params, $filter] = $this->filter();
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {media} o WHERE {$where}", $params);

        return $this->view('list', 'Média', [
            'images' => $this->load($where, $params, $pageNumber, self::PER_PAGE),
            'pageNumber' => $pageNumber,
            'pageCount' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'limit' => \Kaleta\Core\Files::limitText(),
            'filter' => $filter,
            'folders' => $this->folders(),
            'newsItem' => $filter['clanek'] > 0 ? $this->db->value('SELECT titulek FROM {novinky} WHERE idc = ?', [$filter['clanek']]) : null,
        ]);
    }

    /** JSON list for the image picker dialog in the editor; the same filters as in the list. */
    protected function actionListing(): Response
    {
        [$where, $params] = $this->filter();

        return Response::json([
            'obrazky' => array_map($this->toJson(...), $this->load($where, $params, max(1, $this->request->getInt('strana', 1)), 60)),
            'slozky' => array_map(fn (array $s): array => ['id' => (int) $s['ids'], 'nazev' => $s['nazev']], $this->folders()),
        ]);
    }

    /** Creating or renaming a folder. */
    protected function actionFolder(): Response
    {
        $name = mb_substr($this->request->post('nazev'), 0, 100);
        if (!$this->request->isPost() || $name === '') {
            return $this->back();
        }
        $ids = $this->request->postInt('ids');
        if ($ids > 0) {
            $this->db->update('media_slozky', ['nazev' => $name], ['ids' => $ids]);
        } else {
            $ids = $this->db->insert('media_slozky', ['nazev' => $name]);
        }

        return $this->back('Složka byla uložena.', '', ['sekce' => $ids]);
    }

    /** Deleting a folder; the images stay and move to the unsorted ones. */
    protected function actionFolderDelete(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->db->delete('media_slozky', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->back('Složka byla smazána, její obrázky jsou mezi nezařazenými.');
    }

    /**
     * Recounts which images a news item uses: the main image, images inserted by the editor
     * (data-id, file URL). Called when a news item is saved.
     */
    public static function recordUsage(\Kaleta\Core\Db $db, int $idc, string ...$html): void
    {
        $all = implode(' ', $html);
        preg_match_all('/data-id="(\d+)"/', $all, $m);
        $ids = array_map(intval(...), $m[1]);
        preg_match_all('#media/\d{4}/\d{2}/[A-Za-z0-9._-]+\.[a-z0-9]{2,5}#', $all, $paths); // images and attachments (PDF, documents…)
        foreach (array_unique($paths[0]) as $path) {
            $ido = $db->value('SELECT ido FROM {media} WHERE obr_poloha = ? OR nahl_poloha = ?', [$path, $path]);
            if ($ido !== null) {
                $ids[] = (int) $ido;
            }
        }
        $db->delete('media_pouziti', ['idc' => $idc]);
        foreach (array_unique($ids) as $ido) {
            $db->run('INSERT IGNORE INTO {media_pouziti} (ido, idc) SELECT ido, ? FROM {media} WHERE ido = ?', [$idc, $ido]);
        }
    }

    /**
     * Media used outside the news usage table: builds of pages, site parts, collection item templates and components (drafts
     * too), text pages, collection items, classes (background image) and settings (logo, icon, sharing image).
     * Computed when shown – so the overview is always up to date without tracking on every save.
     *
     * @return array<int, list<string>> ido => descriptions of the places
     */
    public static function findUsagesElsewhere(\Kaleta\Core\Db $db): array
    {
        $sources = [
            [t('stránka'), 'SELECT titulek AS kde, CONCAT_WS(\' \', text, stavba, stavba_koncept, obrazek) AS obsah FROM {stranky}'],
            [t('štítek'), 'SELECT nazev AS kde, CONCAT_WS(\' \', popis, obrazek) AS obsah FROM {stitky}'],
            [t('kategorie'), 'SELECT nazev AS kde, popis AS obsah FROM {kategorie}'],
            [t('moje sekce'), 'SELECT nazev AS kde, prvek AS obsah FROM {sekce}'],
            [t('uživatel'), 'SELECT user AS kde, foto AS obsah FROM {uzivatele}'],
            [t('část webu'), 'SELECT CONCAT(typ, IF(nazev = \'\', \'\', CONCAT(\' – \', nazev))) AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {casti}'],
            [t('kolekce'), 'SELECT nazev AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {kolekce}'],
            [t('položka kolekce'), 'SELECT nazev AS kde, data AS obsah FROM {kolekce_polozky}'],
            [t('komponenta'), 'SELECT nazev AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {komponenty}'],
            [t('třída'), 'SELECT nazev AS kde, CONCAT_WS(\' \', styl, css) AS obsah FROM {tridy}'],
            [t('nastavení'), 'SELECT promenna AS kde, hodnota AS obsah FROM {nastaveni} WHERE hodnota LIKE \'%media%\''],
        ];
        $usages = [];
        foreach ($sources as [$kind, $sql]) {
            foreach ($db->all($sql) as $r) {
                // paths also in JSON (media\/2026\/…), with and without the site URL
                preg_match_all('#media(?:\\\\?/)\d{4}(?:\\\\?/)\d{2}(?:\\\\?/)[A-Za-z0-9._-]+#', (string) $r['obsah'], $m);
                foreach ($m[0] as $path) {
                    $usages[str_replace('\\/', '/', $path)][$kind . ' ' . $r['kde']] = true;
                }
            }
        }
        if ($usages === []) {
            return [];
        }
        $used = [];
        foreach ($db->all('SELECT ido, obr_poloha, nahl_poloha FROM {media}') as $o) {
            foreach ([$o['obr_poloha'], $o['nahl_poloha']] as $path) {
                if ($path !== '' && isset($usages[$path])) {
                    $used[(int) $o['ido']] = array_keys(($used[(int) $o['ido']] ?? []) + $usages[$path]);
                }
            }
        }

        return $used;
    }

    /** Upload of one or more files; with the parameter format=json it answers the editor with JSON. */
    protected function actionUpload(): Response
    {
        $json = $this->request->get('format') === 'json';
        $section = $this->db->value('SELECT ids FROM {media_slozky} WHERE ids = ?', [$this->request->postInt('sekce')]);
        $section = $section === null ? null : (int) $section;
        $uploaded = [];
        $errors = [];
        $imageCount = 0;
        foreach ($this->files() as $file) {
            try {
                $attachment = \Kaleta\Core\Files::isAttachment((string) ($file['name'] ?? ''));
                $data = match (true) {
                    strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'svg' => self::saveSvg($file),
                    $attachment => \Kaleta\Core\Files::save($file),
                    default => Images::save($file),
                };
                if (!$attachment) {
                    // the image name is also the description for the blind (alt): a file name ("IMG 2041", "foto dilna") does not describe the image
                    // and the checks would take it as filled in – it stays empty and the list and the pre-publish check ask for it
                    $data['nazev'] = '';
                    $imageCount++;
                }
                $data['ido'] = $this->db->insert('media', $data + ['vlastnik' => $this->app->auth()->id(), 'sekce' => $section, 'datum' => date('Y-m-d H:i:s')]);
                $uploaded[] = $this->toJson($data + ['popis' => '']);
            } catch (\RuntimeException $e) {
                $errors[] = ($file['name'] ?? t('soubor')) . ': ' . t($e->getMessage());
            }
        }
        if ($uploaded === [] && $errors === []) {
            $errors[] = t('Nebyl vybrán žádný soubor.');
        }
        if ($json) {
            return Response::json(['obrazky' => $uploaded, 'chyby' => $errors], $uploaded === [] ? 400 : 200);
        }
        foreach ($errors as $error) {
            $this->app->session->flash('chyba', $error);
        }

        $message = $uploaded === [] ? '' : t('Nahráno souborů: %d.', count($uploaded)) . ($imageCount > 0 ? ' ' . t('U obrázků doplňte popis pro nevidomé (alt): co na obrázku je.') : '');

        return $this->back($message, '', $section !== null ? ['sekce' => $section] : []);
    }

    /**
     * SVG (logo, icon): sanitized to allowed tags and attributes; without a thumbnail and variants, the browser scales it itself.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private static function saveSvg(array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new \RuntimeException('Soubor SVG se nepodařilo přečíst (nejvýš 2 MB, platné SVG).');
        }

        return self::saveSvgContent((string) file_get_contents($tmp), (string) ($file['name'] ?? 'obrazek'));
    }

    /**
     * SVG from text (upload and MCP): cleaned of scripts and outbound links (Core\Svg) and saved under a new name.
     *
     * @return array<string, mixed> row for the media table
     */
    public static function saveSvgContent(string $content, string $displayName): array
    {
        $svg = strlen($content) < 2_000_000 ? \Kaleta\Core\Svg::sanitize($content) : null;
        if ($svg === null) {
            throw new \RuntimeException('Soubor SVG se nepodařilo přečíst (nejvýš 2 MB, platné SVG).');
        }
        $folder = 'media/' . date('Y/m');
        if (!is_dir(KALETA_ROOT . '/' . $folder)) {
            mkdir(KALETA_ROOT . '/' . $folder, 0775, true);
        }
        $name = pathinfo($displayName, PATHINFO_FILENAME);
        $path = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3)) . '.svg';
        file_put_contents(KALETA_ROOT . '/' . $path, $svg);
        [$w, $h] = \Kaleta\Core\Svg::dimensions($svg);

        return ['obr_poloha' => $path, 'obr_width' => min(65535, $w), 'obr_height' => min(65535, $h), 'obr_vel' => strlen($svg),
            'nahl_poloha' => $path, 'nahl_width' => min(65535, $w), 'nahl_height' => min(65535, $h), 'nazev' => mb_substr(str_replace(['_', '-'], ' ', $name), 0, 150)];
    }

    /** A new file in place of the old one with the same URL: links on the site stay and show the new version. */
    protected function actionReplace(): Response
    {
        $ido = $this->request->postInt('ido');
        $image = $this->request->isPost() && $this->canEdit($ido) ? $this->db->one('SELECT * FROM {media} WHERE ido = ?', [$ido]) : null;
        $file = $_FILES['soubor'] ?? null;
        if ($image === null || !is_array($file)) {
            return $this->back();
        }
        try {
            $new = Images::replace($image['obr_poloha'], $file);
        } catch (\RuntimeException $e) {
            return $this->back(t($e->getMessage()), 'list', ['uprav' => $ido], 'chyba');
        }
        $this->db->update('media', $new + ['barva' => ''], ['ido' => $ido]);
        \Kaleta\Front\Cache::clear();

        return $this->back('Soubor byl nahrazen – všude, kde je použitý, se ukazuje nová verze.', 'list', ['uprav' => $ido]);
    }

    protected function actionSave(): Response
    {
        if ($this->request->isPost() && $this->canEdit($this->request->postInt('ido'))) {
            $x = max(0, min(100, $this->request->postInt('ohnisko_x', 50)));
            $y = max(0, min(100, $this->request->postInt('ohnisko_y', 50)));
            $this->db->update('media', [
                'nazev' => mb_substr($this->request->post('nazev'), 0, 150),
                'popis' => mb_substr($this->request->post('popis'), 0, 500),
                'autor' => mb_substr(trim($this->request->post('autor')), 0, 120),
                'ohnisko' => $x === 50 && $y === 50 ? '' : $x . '% ' . $y . '%',
            ], ['ido' => $this->request->postInt('ido')]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('Popis obrázku byl uložen.');
    }

    /** Description for the blind (alt = field "nazev", as in the detail and in the editor) directly from the grid – without reloading (image/admin.js). */
    protected function actionSaveCaption(): Response
    {
        $ido = $this->request->postInt('ido');
        if (!$this->request->isPost() || !$this->canEdit($ido)) {
            return Response::json(['ok' => false, 'chyba' => t('Obrázek nemůžete upravit.')], 403);
        }
        $this->db->update('media', ['nazev' => mb_substr(trim($this->request->post('popis')), 0, 150)], ['ido' => $ido]);
        \Kaleta\Front\Cache::clear();

        return Response::json(['ok' => true]);
    }

    /** Bulk action on the selected images: delete, or move to a folder. */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $move = $this->request->post('provest') === 'presun';
        $target = $this->request->postInt('do_sekce') ?: null;
        $count = 0;
        $skipped = 0;
        $elsewhere = $move ? [] : self::findUsagesElsewhere($this->db);
        foreach ($this->request->postList('oznacene') as $id) {
            $image = $this->db->one('SELECT * FROM {media} WHERE ido = ?', [(int) $id]);
            if ($image === null || !$this->canEdit((int) $image['ido'])) {
                continue;
            }
            if ($move) {
                $count += $this->db->update('media', ['sekce' => $target], ['ido' => $image['ido']]) >= 0 ? 1 : 0;
            } elseif (isset($elsewhere[(int) $image['ido']]) || $this->db->value('SELECT 1 FROM {media_pouziti} WHERE ido = ? LIMIT 1', [$image['ido']]) !== null) {
                $skipped++; // a used file would disappear from the site – it is deleted once it is not used anywhere
            } else {
                Images::delete($image['obr_poloha'], $image['nahl_poloha']);
                \Kaleta\Core\Files::delete($image['obr_poloha']);
                $count += $this->db->delete('media', ['ido' => $image['ido']]);
            }
        }

        if ($skipped > 0) {
            $this->app->session->flash('chyba', t('Nesmazáno %d použitých souborů – nejdřív je odeberte z webu (kde jsou použité, ukáže výpis).', $skipped));
        }

        return $this->back($move ? t('Přesunuto obrázků: %d.', $count) : t('Smazáno obrázků: %d.', $count), '', $move && $target ? ['sekce' => $target] : []);
    }

    private function canEdit(int $ido): bool
    {
        $owner = $this->db->value('SELECT vlastnik FROM {media} WHERE ido = ?', [$ido]);

        return $this->app->auth()->isAdmin() || (int) $owner === $this->app->auth()->id();
    }

    /**
     * List filter from the URL: sekce (folder number, 0 = unsorted), clanek (idc), nepouzite=1, hledat (name, label or file name).
     *
     * @return array{0: string, 1: list<int|string>, 2: array{sekce: ?int, clanek: int, nepouzite: bool, hledat: string, razeni: string}}
     */
    private function filter(): array
    {
        $where = ['1 = 1'];
        $params = [];
        $section = $this->request->get('sekce') === '' ? null : $this->request->getInt('sekce');
        if ($section !== null) {
            $where[] = $section > 0 ? 'o.sekce = ?' : 'o.sekce IS NULL';
            if ($section > 0) {
                $params[] = $section;
            }
        }
        $newsItem = $this->request->getInt('clanek');
        if ($newsItem > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM {media_pouziti} p WHERE p.ido = o.ido AND p.idc = ?)';
            $params[] = $newsItem;
        }
        $search = mb_substr(trim($this->request->get('hledat')), 0, 100);
        if ($search !== '') {
            $where[] = '(o.nazev LIKE ? OR o.popis LIKE ? OR o.obr_poloha LIKE ?)';
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $pattern, $pattern, $pattern);
        }
        $unused = $this->request->get('nepouzite') === '1';
        if ($unused) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM {media_pouziti} p WHERE p.ido = o.ido)';
            $elsewhere = array_keys(self::findUsagesElsewhere($this->db));
            if ($elsewhere !== []) {
                $where[] = 'o.ido NOT IN (' . implode(',', array_map(intval(...), $elsewhere)) . ')';
            }
        }

        $sort = isset(self::SORT_ORDERS[$this->request->get('razeni')]) ? $this->request->get('razeni') : 'nove';

        return [implode(' AND ', $where), $params, ['sekce' => $section, 'clanek' => $newsItem, 'nepouzite' => $unused, 'hledat' => $search, 'razeni' => $sort]];
    }

    /** @return list<array<string, mixed>> folders with the number of images */
    private function folders(): array
    {
        return $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {media} o WHERE o.sekce = s.ids) AS pocet FROM {media_slozky} s ORDER BY s.nazev');
    }

    /** @return list<array<string, mixed>> */
    /** List sort orders: key from the URL => [label, ORDER BY]. */
    public const array SORT_ORDERS = [
        'nove' => ['nejnovější', 'o.ido DESC'], 'stare' => ['nejstarší', 'o.ido ASC'], 'nazev' => ['podle názvu', 'o.nazev ASC, o.ido DESC'],
        'velikost' => ['největší soubory', 'o.obr_vel DESC'], 'nepouzite' => ['nejméně použité', 'pouzito ASC, o.ido DESC'],
    ];

    private function load(string $where, array $params, int $pageNumber, int $count): array
    {
        $order = self::SORT_ORDERS[$this->request->get('razeni')][1] ?? self::SORT_ORDERS['nove'][1];
        $elsewhere = self::findUsagesElsewhere($this->db);

        return array_map(function (array $o) use ($elsewhere): array {
            // where: news by the usage table + places outside news
            $o['kde'] = $elsewhere[(int) $o['ido']] ?? [];
            $o['pouzito'] = (int) $o['pouzito'] + count($o['kde']);

            return $o;
        }, $this->db->all(
            "SELECT o.*, (SELECT COUNT(*) FROM {media_pouziti} p WHERE p.ido = o.ido) AS pouzito
             FROM {media} o WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $count, ($pageNumber - 1) * $count],
        ));
    }

    /** @param array<string, mixed> $o */
    private function toJson(array $o): array
    {
        return [
            'id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'popis' => $o['popis'] ?? '',
            'url' => $this->app->url($o['obr_poloha']), 'nahled' => $o['nahl_poloha'] === '' ? '' : $this->app->url($o['nahl_poloha']),
            'sirka' => (int) $o['obr_width'], 'vyska' => (int) $o['obr_height'],
            // attachment for download (PDF, document, audio…): without a thumbnail, inserted into the text as a link
            'soubor' => $o['nahl_poloha'] === '', 'pripona' => strtoupper(pathinfo($o['obr_poloha'], PATHINFO_EXTENSION)), 'velikost' => \Kaleta\Core\Files::size((int) ($o['obr_vel'] ?? 0)),
        ];
    }

    /** $_FILES['soubory'] (multiple too) converted to a list of individual files. */
    private function files(): array
    {
        $f = $_FILES['soubory'] ?? null;
        if (!is_array($f)) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $files = [];
        foreach (array_keys($f['name']) as $i) {
            if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $files[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
            }
        }

        return array_slice($files, 0, 30);
    }
}
