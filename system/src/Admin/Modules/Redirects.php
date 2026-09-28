<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Db;
use Kaleta\Core\Response;

/**
 * Přesměrování 301: stará adresa -> nová. Vzniká samo při změně adresy stránky, novinky nebo kategorie,
 * ručně se hodí po přechodu z jiného systému. Použije se, až když web pro adresu nic nenajde.
 */
final class Redirects extends Module
{
    public const string IDENT = 'presmerovani';
    public const string NAME = 'Přesměrování';
    public const string GROUP = 'Správa';
    public const string ICON = 'presmerovani';
    public const string EXTENSION = 'presmerovani';
    public const bool ADMIN_ONLY = true;

    public static function add(Db $db, string $z, string $commandName): void
    {
        $z = trim($z, '/ ');
        if ($z === '' || $z === trim($commandName, '/ ')) {
            return;
        }
        // nová cílová adresa přebírá i starší přesměrování, aby nevznikaly řetězy
        $db->run('UPDATE {presmerovani} SET na_adresu = ? WHERE na_adresu = ?', [$commandName, $z]);
        $db->run('DELETE FROM {presmerovani} WHERE z_adresy = ?', [trim($commandName, '/ ')]);
        $db->run(
            'INSERT INTO {presmerovani} (z_adresy, na_adresu, vytvoreno) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE na_adresu = VALUES(na_adresu)',
            [mb_substr($z, 0, 255), mb_substr($commandName, 0, 255)],
        );
    }

    private const int PER_PAGE = 50;

    protected function akceVypis(): Response
    {
        $search = mb_substr(trim($this->request->get('hledat')), 0, 100);
        $whereParts = $search !== '' ? 'WHERE z_adresy LIKE ? OR na_adresu LIKE ?' : '';
        $params = $search !== '' ? array_fill(0, 2, '%' . addcslashes($search, '%_\\') . '%') : [];
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {presmerovani} ' . $whereParts, $params);
        $pageNumber = max(1, min((int) ceil(max(1, $total) / self::PER_PAGE), $this->request->getInt('strana', 1)));

        return $this->view('list', 'Přesměrování', [
            'records' => $this->db->all('SELECT * FROM {presmerovani} ' . $whereParts . ' ORDER BY idp DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'total' => $total, 'pageNumber' => $pageNumber, 'pageCount' => (int) ceil($total / self::PER_PAGE), 'search' => $search,
            'edit' => $this->request->getInt('upravit') > 0 ? $this->db->one('SELECT * FROM {presmerovani} WHERE idp = ?', [$this->request->getInt('upravit')]) : null,
            'notFound' => $this->db->all('SELECT * FROM {nenalezeno} WHERE naposledy > NOW() - INTERVAL 60 DAY ORDER BY pocet DESC, naposledy DESC LIMIT 25'),
            'fromUrl' => mb_substr($this->request->get('z'), 0, 255),
        ]);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $z = (string) parse_url($this->request->post('z_adresy'), PHP_URL_PATH);
        $commandName = $this->request->post('na_adresu');
        if (trim($z, '/') === '' || $commandName === '' || (!preg_match('#^https?://#i', $commandName) && !preg_match('#^/?[^\s:]*$#', $commandName))) {
            return $this->back('Vyplňte starou adresu (cestu na tomto webu) a cíl – cestu, nebo celou adresu https://…', type: 'chyba');
        }
        $target = preg_match('#^https?://#i', $commandName) ? $commandName : trim($commandName, '/');
        $idp = $this->request->postInt('idp');
        if ($idp > 0) {
            // úprava existujícího záznamu
            $this->db->update('presmerovani', ['z_adresy' => mb_substr(trim($z, '/ '), 0, 255), 'na_adresu' => mb_substr($target, 0, 255)], ['idp' => $idp]);
        } else {
            self::add($this->db, $z, $target);
        }
        $this->db->run('UPDATE {presmerovani} SET typ = ? WHERE z_adresy = ?', [$this->request->postInt('typ') === 302 ? 302 : 301, trim($z, '/ ')]);
        $this->db->delete('nenalezeno', ['cesta' => trim($z, '/')]);

        return $this->back('Přesměrování bylo uloženo.');
    }

    /** Vyprázdní přehled nenalezených adres. */
    protected function akceVycisti(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {nenalezeno}');
        }

        return $this->back('Přehled nenalezených adres je prázdný.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('presmerovani', ['idp' => $this->request->postInt('idp')]);
        }

        return $this->back('Přesměrování bylo smazáno.');
    }
}
