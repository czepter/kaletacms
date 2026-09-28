<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/** Protokol změn - přehled akcí v administraci (jen pro administrátora). */
final class ChangeLog extends Module
{
    public const string IDENT = 'protokol';
    public const string NAME = 'Protokol změn';
    public const string GROUP = 'Správa';
    public const string ICON = 'protokol';
    public const bool ADMIN_ONLY = true;

    private const int PER_PAGE = 100;

    protected function akceVypis(): Response
    {
        $who = $this->request->getInt('kdo');
        $whereParts = $this->request->get('kde');
        $search = mb_substr(trim($this->request->get('hledat')), 0, 100);
        $conditions = [];
        $params = [];
        if ($who > 0) {
            $conditions[] = 'kdo = ?';
            $params[] = $who;
        }
        if ($whereParts !== '' && preg_match('/^[a-z_]{2,30}$/', $whereParts)) {
            $conditions[] = 'modul = ?';
            $params[] = $whereParts;
        }
        if ($search !== '') {
            $conditions[] = 'popis LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $sql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {protokol}' . $sql, $params);
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));
        $pageNumber = max(1, min($pageCount, $this->request->getInt('strana', 1)));

        return $this->view('list', 'Protokol změn', [
            'records' => $this->db->all('SELECT * FROM {protokol}' . $sql . ' ORDER BY idp DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'users' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} ORDER BY 2"),
            'modules' => array_column($this->db->all('SELECT DISTINCT modul FROM {protokol} ORDER BY modul'), 'modul'),
            'who' => $who, 'whereParts' => $whereParts, 'search' => $search, 'pageNumber' => $pageNumber, 'pageCount' => $pageCount, 'total' => $total,
        ]);
    }
}
