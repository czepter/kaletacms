<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\Collections as KolekceObsahu;
use Kaleta\Builder\Publisher;

/**
 * Collections – custom content types (references, team, products, branches…). The field definition and the item template
 * are changed by the administrator, the items by anyone with access to the module. The Collection list element in the
 * builder puts them on the site.
 */
final class Collections extends Module
{
    use BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'collections';
    public const string NAME = 'Collections';
    public const string GROUP = 'Content';
    public const string ICON = 'kolekce';

    protected function actionList(): Response
    {
        return $this->view('list', 'Collections', [
            'collection' => $this->db->all('SELECT k.idk, k.nazev, k.seo_link, k.detail, (SELECT COUNT(*) FROM {kolekce_polozky} p WHERE p.idk = k.idk AND p.smazano IS NULL) AS pocet FROM {kolekce} k ORDER BY k.nazev'),
        ]);
    }

    /* ---------- collection definition (administrator) ---------- */

    protected function actionNew(): Response
    {
        return $this->admin() ?? $this->view('form', 'New collection', ['k' => ['idk' => 0, 'nazev' => '', 'seo_link' => '', 'detail' => 0, 'pole' => [
            ['klic' => '', 'popisek' => t('Description'), 'typ' => 'radky'], ['klic' => '', 'popisek' => t('Image'), 'typ' => 'obrazek'],
        ]]]);
    }

    protected function actionEdit(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));

        return $this->admin() ?? ($k === null ? $this->error('The collection does not exist.', 404) : $this->view('form', $k['nazev'], ['k' => $k]));
    }

    protected function actionSave(): Response
    {
        if (($refusal = $this->admin()) !== null || !$this->request->isPost()) {
            return $refusal ?? $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('idk');
        $previous = $id > 0 ? KolekceObsahu::byId($this->db, $id) : null;
        $name = mb_substr(trim($r->post('nazev')), 0, 100);
        if ($name === '') {
            return $this->back('The collection needs a name.', $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        $seo = slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $name, 110);
        if (in_array($seo, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$seo]) || $this->db->value('SELECT idk FROM {kolekce} WHERE seo_link = ? AND idk <> ?', [$seo, $id]) !== null) {
            return $this->back(t('The address “%s” is already used by the system or another collection.', $seo), $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        // the key of an existing field does not change (item values are stored under it); new fields get it from the label
        $field = KolekceObsahu::sanitizeFields(is_array($_POST['pole'] ?? null) ? array_values($_POST['pole']) : []);
        $data = ['nazev' => $name, 'seo_link' => $seo, 'detail' => $r->postBool('detail') ? 1 : 0, 'pole' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')];
        if ($previous !== null) {
            $this->db->update('kolekce', $data, ['idk' => $id]);
        } else {
            $id = $this->db->insert('kolekce', $data);
        }
        \Kaleta\Front\Cache::clear();

        return $this->back('The collection was saved.', 'items', ['id' => $id]);
    }

    protected function actionDelete(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        if ($this->request->isPost()) {
            $this->db->delete('kolekce', ['idk' => $this->request->postInt('idk')]);
        }

        return $this->back('The collection and its items were deleted.');
    }

    /* ---------- items ---------- */

    protected function actionItems(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->error('The collection does not exist.', 404);
        }

        [$siteLanguages, $language, $column] = $this->readLanguageFilter();
        $trash = $this->request->get('stav') === 'kos';

        return $this->view('items', $k['nazev'], ['k' => $k, 'languages' => Language::additional($this->app->settings()), 'siteLanguages' => $siteLanguages, 'language' => $language,
            'trash' => $trash, 'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NOT NULL', [$k['idk']]),
            'items' => $this->db->all('SELECT idp, nazev, seo_link, poradi, zobrazit, jazyk, datum, smazano FROM {kolekce_polozky} WHERE idk = ? AND smazano IS ' . ($trash ? 'NOT NULL' : 'NULL')
                . ($column !== null ? ' AND jazyk = ?' : '') . ' ORDER BY ' . ($trash ? 'smazano DESC' : 'jazyk, poradi, nazev'), $column !== null ? [$k['idk'], $column] : [$k['idk']])]);
    }

    protected function actionItem(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->error('The collection does not exist.', 404);
        }
        $idp = $this->request->getInt('polozka');
        $p = $idp > 0 ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$idp, $k['idk']]) : null;
        if ($idp > 0 && $p === null) {
            return $this->error('The item does not exist.', 404);
        }
        $p ??= ['idp' => 0, 'nazev' => '', 'seo_link' => '', 'data' => '{}', 'poradi' => 100, 'zobrazit' => 1, 'jazyk' => '', 'datum' => date('Y-m-d H:i:s')];
        $p['data'] = json_decode((string) $p['data'], true) ?: [];

        return $this->view('item', $p['nazev'] !== '' ? $p['nazev'] : t('New item'), ['k' => $k, 'p' => $p]);
    }

    protected function actionSaveItem(): Response
    {
        $r = $this->request;
        $k = $r->isPost() ? KolekceObsahu::byId($this->db, $r->postInt('idk')) : null;
        if ($k === null) {
            return $this->back();
        }
        $idp = $r->postInt('idp');
        $name = mb_substr(trim($r->post('nazev')), 0, 200);
        if ($name === '') {
            return $this->back('The item needs a name.', 'item', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }
        $errors = [];
        $data = KolekceObsahu::sanitizeData($k['pole'], is_array($_POST['data'] ?? null) ? $_POST['data'] : [], $errors);
        $seo = slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $name, 150);
        // the slug is unique within a language: a translation of the item can have the same one (/compare/wordpress, /de/compare/wordpress)
        $language = Language::column($this->app->settings(), $r->post('jazyk'));
        $seo = \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => $this->db->value('SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ? AND idp <> ?', [$k['idk'], $language, $a, $idp]) !== null);
        $row = ['idk' => $k['idk'], 'nazev' => $name, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'poradi' => max(-9999, min(9999, $r->postInt('poradi'))), 'zobrazit' => $r->postBool('zobrazit') ? 1 : 0,
            'jazyk' => $language, 'zmeneno' => date('Y-m-d H:i:s')];
        if ($idp > 0 && $this->db->value('SELECT idp FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$idp, $k['idk']]) !== null) {
            $this->db->update('kolekce_polozky', $row, ['idp' => $idp]);
        } else {
            $idp = $this->db->insert('kolekce_polozky', $row + ['datum' => date('Y-m-d H:i:s')]);
        }
        \Kaleta\Front\Cache::clear();
        if ($errors !== []) {
            return $this->back(t('The item is saved, but these fields had an invalid value and were left empty: %s', implode(', ', $errors)), 'item', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }

        return $this->back('The item was saved.', 'items', ['id' => $k['idk']]);
    }

    /** Copy of an item (hidden, with a free slug) – a quick start for a similar reference, team member, product. */
    protected function actionDuplicateItem(): Response
    {
        $idk = $this->request->postInt('idk');
        $p = $this->request->isPost() ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$this->request->postInt('idp'), $idk]) : null;
        if ($p === null) {
            return $this->back('', 'items', ['id' => $idk]);
        }
        $seo = \Kaleta\Core\Slug::makeUnique($p['seo_link'] . '-kopie', fn (string $a): bool => $this->db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ?', [$idk, $p['jazyk'], $a]) !== null);
        $id = $this->db->insert('kolekce_polozky', ['idk' => $idk, 'nazev' => mb_substr(t('%s (copy)', $p['nazev']), 0, 200), 'seo_link' => $seo, 'data' => $p['data'],
            'poradi' => $p['poradi'], 'zobrazit' => 0, 'jazyk' => $p['jazyk'], 'datum' => date('Y-m-d H:i:s')]);

        return $this->back('The copy of the item is hidden – edit it and publish it.', 'item', ['id' => $idk, 'polozka' => $id]);
    }

    /** To the trash: the item disappears from the site at once and can be restored for 30 days. */
    protected function actionDeleteItem(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost()) {
            self::trashItem($this->db, $this->request->postInt('idp'), $idk);
        }

        return $this->back('The item is in the trash – it is no longer on the site; you can restore it for 30 days.', 'items', ['id' => $idk]);
    }

    /** Back from the trash – hidden, so it does not appear on the site before it is checked. */
    protected function actionRestoreItem(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost()) {
            self::restoreItem($this->db, $this->request->postInt('idp'), $idk);
        }

        return $this->back('The item was restored as hidden.', 'items', ['id' => $idk]);
    }

    protected function actionDeleteItemPermanently(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NOT NULL', [$this->request->postInt('idp'), $idk]);
        }

        return $this->back('The item was deleted permanently.', 'items', ['id' => $idk, 'stav' => 'kos']);
    }

    /** Moves an item to the trash (admin and MCP); returns whether it was there to move. */
    public static function trashItem(\Kaleta\Core\Db $db, int $idp, int $idk): bool
    {
        $moved = $db->run('UPDATE {kolekce_polozky} SET smazano = NOW(), zobrazit = 0 WHERE idp = ? AND idk = ? AND smazano IS NULL', [$idp, $idk])->rowCount() > 0;
        \Kaleta\Front\Cache::clear();

        return $moved;
    }

    public static function restoreItem(\Kaleta\Core\Db $db, int $idp, int $idk): bool
    {
        return $db->run('UPDATE {kolekce_polozky} SET smazano = NULL WHERE idp = ? AND idk = ? AND smazano IS NOT NULL', [$idp, $idk])->rowCount() > 0;
    }

    /** Items longer than 30 days in the trash are deleted permanently (with pages and news, on an admin visit). */
    public static function emptyTrash(\Kaleta\Core\Db $db): void
    {
        $db->run('DELETE FROM {kolekce_polozky} WHERE smazano < NOW() - INTERVAL 30 DAY');
    }

    /* ---------- item template in the builder (administrator) ---------- */

    protected function actionBuilder(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        $k = $this->template();
        if ($k !== null && $k['stavba'] === null && $k['stavba_koncept'] === null) {
            KolekceObsahu::writeTemplate($this->db, $k, ['stavba_koncept' => KolekceObsahu::initialTemplateDraft($this->db, $k), 'zmeneno' => date('Y-m-d H:i:s')]);
        }

        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        if (!$this->app->auth()->isAdmin()) {
            return null;
        }
        $k = $this->template();
        if ($k === null) {
            return null;
        }
        $language = $k['sablona_jazyk'];

        return [
            'radek' => $k, 'stavba' => $k['stavba'], 'koncept' => $k['stavba_koncept'], 'jazyk' => Language::ofContent($this->app->settings(), $language),
            'titulek' => t('Detail: %s', $k['nazev']) . ($language !== '' ? ' (' . strtoupper($language) . ')' : ''), 'revize' => ['cast' => KolekceObsahu::templateKey($k)],
            'parametry' => ['id' => (int) $k['idk']] + ($language !== '' ? ['jazyk' => $language] : []),
        ];
    }

    /** Collection with the template of the language from the URL (?jazyk=de; without it, or with a language the site does not have, the default language). */
    private function template(): ?array
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        $language = $this->request->get('jazyk');

        return $k === null ? null : KolekceObsahu::inLanguage($this->db, $k, in_array($language, Language::additional($this->app->settings()), true) ? $language : '');
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        KolekceObsahu::writeTemplate($this->db, $target['radek'], ['stavba_koncept' => $draft]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::collection($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $k = $target['radek'];
        $language = $k['sablona_jazyk'];
        // preview on the first item in the template's language (an additional language has URLs /<language>/…)
        $seo = $this->db->value('SELECT seo_link FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND zobrazit = 1 ORDER BY poradi, nazev LIMIT 1', [$k['idk'], $language]);
        $url = $this->app->url(($language !== '' ? $language . '/' : '') . $k['seo_link'] . '/' . ($seo ?? '_ukazka'));

        return [
            'adresa' => $url, 'nahled' => $url . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $k['detail'], 'casti' => false,
            'zpet' => ['adresa' => $this->url('items', ['id' => (int) $k['idk']]), 'text' => $k['nazev']], 'nastaveni' => $this->url('edit', ['id' => (int) $k['idk']]), 'textNastaveni' => t('Collection fields and settings'),
            'kolekce' => ['seo_link' => $k['seo_link'], 'nazev' => $k['nazev'], 'pole' => $k['pole'], 'detail' => (bool) $k['detail']],
            'podpis' => KolekceObsahu::templateKey($k),
        ];
    }

    private function admin(): ?Response
    {
        return $this->app->auth()->isAdmin() ? null : $this->error('Only the site administrator can change the collection definition and detail template.', 403);
    }
}
