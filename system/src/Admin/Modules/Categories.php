<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Db;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Core\Settings;

/**
 * Kategorie novinek (tabulka ka_kategorie). Plochý seznam – firemní blog stromové rubriky nepotřebuje.
 * Kategorie určuje i jazykovou verzi novinky.
 */
final class Categories extends Module
{
    public const string IDENT = 'kategorie';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Kategorie';
    public const string GROUP = 'Obsah';
    public const string ICON = 'rubriky';
    public const string PARENT = 'novinky';

    /**
     * Kategorie seřazené podle pořadí a názvu, s počtem novinek.
     *
     * @return list<array<string, mixed>>
     */
    public static function listAll(Db $db, ?string $language = null): array
    {
        $whereParts = $language !== null && preg_match('/^([a-z]{2})?$/', $language) ? " WHERE t.jazyk = '{$language}'" : '';

        return $db->all(
            'SELECT t.*, (SELECT COUNT(*) FROM {novinky} c WHERE c.tema = t.idt AND c.smazano IS NULL) AS pocet_clanku
             FROM {kategorie} t' . $whereParts . ' ORDER BY t.hodnost DESC, t.nazev',
        );
    }

    /**
     * Novinky potřebují aspoň jednu kategorii. Když žádná není (novinky zapnuté až po instalaci), založí výchozí
     * „Aktuality“ v jazyce webu, jako to dělá instalace. Vrací id nové kategorie, nebo null, když už nějaká je.
     */
    public static function createDefault(Db $db, Settings $s): ?int
    {
        if ($db->value('SELECT 1 FROM {kategorie} LIMIT 1') !== null) {
            return null;
        }
        $name = Language::runWith(Language::defaults($s), fn (): string => t('Aktuality'));

        return $db->insert('kategorie', ['nazev' => $name, 'seo_link' => slugify($name), 'popis' => '']);
    }

    protected function akceVypis(): Response
    {
        [$siteLanguages, $language, $column] = $this->readLanguageFilter();

        return $this->view('vypis', 'Kategorie', ['kategorie' => self::listAll($this->db, $column), 'jazykyWebu' => $siteLanguages, 'jazyk' => $language]);
    }

    protected function akceNovy(): Response
    {
        return $this->form(['idt' => 0, 'nazev' => '', 'seo_link' => '', 'popis' => '', 'hodnost' => 100]);
    }

    protected function akceEdit(): Response
    {
        $category = $this->db->one('SELECT * FROM {kategorie} WHERE idt = ?', [$this->request->getInt('id')]);

        return $category === null ? $this->error('Kategorie neexistuje.', 404) : $this->form($category);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('idt');
        $data = [
            'nazev' => $r->post('nazev'),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('nazev'), 110),
            'popis' => \Kaleta\Core\Html::forUser($r->post('popis'), $this->app->auth()),
            'hodnost' => max(0, min(65535, $r->postInt('hodnost', 100))),
            'jazyk' => \Kaleta\Core\Language::column($this->app->settings(), $r->post('jazyk')),
        ];
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT idt FROM {kategorie} WHERE idt = ? AND jazyk = '' AND idt <> ?", [$r->postInt('preklad_z'), $id]) ?: null);
        if ($data['nazev'] === '') {
            return $this->form(['idt' => $id] + $data, ['nazev' => 'Vyplňte název kategorie.']);
        }

        $data['seo_link'] = \Kaleta\Core\Slug::makeUnique($data['seo_link'], fn (string $a): bool => $this->db->value('SELECT idt FROM {kategorie} WHERE seo_link = ? AND idt <> ?', [$a, $id]) !== null, 120);

        if ($id > 0) {
            $previous = $this->db->value('SELECT seo_link FROM {kategorie} WHERE idt = ?', [$id]);
            $this->db->update('kategorie', $data, ['idt' => $id]);
            if ($previous !== null && $previous !== $data['seo_link']) {
                // kategorie změnila adresu: stará se přesměruje, odkazy ani vyhledávače o stránku nepřijdou
                Redirects::add($this->db, 'novinky/kategorie/' . $previous, 'novinky/kategorie/' . $data['seo_link']);
            }
            $this->db->run('UPDATE {novinky} SET jazyk = ? WHERE tema = ?', [$data['jazyk'], $id]); // novinky mají jazyk své kategorie
        } else {
            $this->db->insert('kategorie', $data);
        }

        return $this->back('Kategorie byla uložena.');
    }

    protected function akceSmaz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('idt');
        if ((int) $this->db->value('SELECT COUNT(*) FROM {novinky} WHERE tema = ?', [$id]) > 0) {
            return $this->back('Kategorii nelze smazat, dokud v ní jsou novinky (i v koši). Nejprve je přesuňte jinam.', type: 'chyba');
        }
        $this->db->delete('kategorie', ['idt' => $id]);

        return $this->back('Kategorie byla smazána.');
    }

    /**
     * @param array<string, mixed> $category
     * @param array<string, string> $errors
     */
    private function form(array $category, array $errors = []): Response
    {
        return $this->view('formular', $category['idt'] ? 'Úprava kategorie' : 'Nová kategorie', ['kategorie' => $category, 'chyby' => $errors]);
    }
}
