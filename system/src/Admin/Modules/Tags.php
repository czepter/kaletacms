<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Štítky a témata. Štítky vznikají samy při psaní novinek; tady se dají přejmenovat, sloučit a smazat.
 * Štítek s popisem a obrázkem se na webu chová jako stránka tématu (/novinky/stitek/<adresa>).
 */
final class Tags extends Module
{
    public const string IDENT = 'stitky';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Štítky a témata';
    public const string GROUP = 'Obsah';
    public const string ICON = 'stitky';
    public const string PARENT = 'novinky';

    protected function akceVypis(): Response
    {
        $edit = $this->db->one('SELECT * FROM {stitky} WHERE ids = ?', [$this->request->getInt('uprav')]);

        return $this->view('list', 'Štítky a témata', [
            'tags' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {novinky_stitky} cs WHERE cs.ids = s.ids) AS pocet FROM {stitky} s ORDER BY (s.popis IS NOT NULL AND s.popis <> \'\') DESC, pocet DESC, s.nazev LIMIT 500'),
            'edit' => $edit,
        ]);
    }

    protected function akceUloz(): Response
    {
        $tag = $this->db->one('SELECT * FROM {stitky} WHERE ids = ?', [$this->request->postInt('ids')]);
        $name = mb_substr(trim($this->request->post('nazev')), 0, 80);
        if (!$this->request->isPost() || $tag === null || $name === '') {
            return $this->back('Vyplňte název štítku.', type: 'chyba');
        }
        $this->db->update('stitky', ['nazev' => $name, 'popis' => \Kaleta\Core\Html::forUser(trim($this->request->post('popis')), $this->app->auth()), 'obrazek' => mb_substr($this->request->post('obrazek'), 0, 255)], ['ids' => $tag['ids']]);

        // sloučení: novinky dostanou cílový štítek, tento zanikne a jeho adresa se přesměruje
        $target = $this->db->one('SELECT * FROM {stitky} WHERE ids = ? AND ids <> ?', [$this->request->postInt('sloucit_do'), $tag['ids']]);
        if ($target !== null) {
            $this->db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) SELECT idc, ? FROM {novinky_stitky} WHERE ids = ?', [$target['ids'], $tag['ids']]);
            $this->db->delete('novinky_stitky', ['ids' => $tag['ids']]);
            $this->db->delete('stitky', ['ids' => $tag['ids']]);
            Redirects::add($this->db, 'novinky/stitek/' . $tag['seo_link'], 'novinky/stitek/' . $target['seo_link']);

            return $this->back(t('Štítek „%s“ byl sloučen do „%s“.', $tag['nazev'], $target['nazev']));
        }

        return $this->back('Štítek byl uložen.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('novinky_stitky', ['ids' => $this->request->postInt('ids')]);
            $this->db->delete('stitky', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->back('Štítek byl smazán. Novinky zůstaly, jen ho už nemají.');
    }
}
