<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Poptávky a zprávy z formulářů webu (prvek Formulář v builderu, Front\Formulare). Stav: 0 nová, 1 přečtená, 2 vyřízená.
 * Obsahují osobní údaje – po nastaveném počtu měsíců se samy mažou a jdou vyvézt do CSV.
 */
final class Enquiries extends Module
{
    public const string IDENT = 'poptavky';
    public const string EXTENSION = 'poptavky';
    public const string NAME = 'Poptávky';
    public const string GROUP = 'Obsah';
    public const string ICON = 'poptavky';

    public const array STATUSES = [0 => 'nová', 1 => 'přečtená', 2 => 'vyřízená'];
    private const int PER_PAGE = 50;

    protected function akceVypis(): Response
    {
        self::deleteExpired($this->db, $this->app->settings());
        $filter = $this->request->get('stav');
        $conditions = match ($filter) {
            'otevrene' => ['stav < 2'],
            'vyrizene' => ['stav = 2'],
            'moje' => ['prirazeno = ' . (int) $this->app->auth()->id()],
            default => [],
        };
        $params = [];
        $search = mb_substr(trim($this->request->get('hledat')), 0, 100);
        if ($search !== '') {
            $conditions[] = '(email LIKE ? OR formular LIKE ? OR data LIKE ? OR poznamka LIKE ?)';
            // data jsou JSON s \uXXXX místo diakritiky – hledá se i v té podobě
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            $jsonPattern = '%' . addcslashes(substr((string) json_encode($search), 1, -1), '%_\\') . '%';
            array_push($params, $pattern, $pattern, $jsonPattern, $pattern);
        }
        $whereParts = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $pageNumber = max(1, $this->request->getInt('strana', 1));

        return $this->view('vypis', 'Poptávky', [
            'poptavky' => $this->db->all('SELECT idp, datum, formular, stranka, email, stav, data, prirazeno FROM {poptavky} ' . $whereParts . ' ORDER BY idp DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'celkem' => (int) $this->db->value('SELECT COUNT(*) FROM {poptavky} ' . $whereParts, $params),
            'filtr' => $filter, 'hledat' => $search, 'strana' => $pageNumber, 'naStranu' => self::PER_PAGE,
            'uzivatele' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 ORDER BY 2"),
            'mesice' => $this->app->settings()->int('poptavky_mesice'),
        ]);
    }

    protected function akceDetail(): Response
    {
        $p = $this->db->one('SELECT * FROM {poptavky} WHERE idp = ?', [$this->request->getInt('id')]);
        if ($p === null) {
            return $this->error('Poptávka neexistuje.', 404);
        }
        if ((int) $p['stav'] === 0) {
            $this->db->update('poptavky', ['stav' => 1], ['idp' => $p['idp']]);
            $p['stav'] = 1;
        }

        return $this->view('detail', t('Poptávka') . ' #' . $p['idp'], ['p' => $p, 'data' => json_decode((string) $p['data'], true) ?: [],
            'uzivatele' => $this->listAssignees((int) $p['prirazeno'])]);
    }

    /**
     * Kdo může poptávku vyřizovat: aktivní správci a uživatelé s právem k Poptávkám (ne třeba autor novinek, který je nevidí).
     * Už přiřazený uživatel v seznamu zůstane, i když právo mezitím ztratil – uložení poznámky ho potichu neodebere.
     *
     * @return array<int, string>
     */
    private function listAssignees(int $assignee = 0): array
    {
        return $this->db->pairs(
            "SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} u WHERE (blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))) OR idu = ? ORDER BY 2",
            [\Kaleta\Core\Auth::ADMIN, self::IDENT, $assignee],
        );
    }

    /** Interní poznámka a kdo poptávku vyřizuje. */
    protected function akcePoznamka(): Response
    {
        $idp = $this->request->postInt('idp');
        if ($this->request->isPost()) {
            $who = $this->request->postInt('prirazeno');
            $this->db->update('poptavky', ['poznamka' => mb_substr(trim($this->request->post('poznamka')), 0, 5000),
                'prirazeno' => $who > 0 && isset($this->listAssignees((int) $this->db->value('SELECT prirazeno FROM {poptavky} WHERE idp = ?', [$idp]))[$who]) ? $who : null], ['idp' => $idp]);
        }

        return $this->back('Poznámka byla uložena.', 'detail', ['id' => $idp]);
    }

    /** Příloha z formuláře ke stažení (jen přihlášenému s přístupem k poptávkám). */
    protected function akcePriloha(): Response
    {
        $p = $this->db->one('SELECT data FROM {poptavky} WHERE idp = ?', [$this->request->getInt('id')]);
        $item = ($p !== null ? (json_decode((string) $p['data'], true) ?: []) : [])[$this->request->getInt('pole')] ?? null;
        $path = is_array($item) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#', (string) ($item[2] ?? '')) ? KALETA_ROOT . '/storage/prilohy/' . $item[2] : null;
        if ($path === null || !is_file($path)) {
            return $this->error('Příloha už neexistuje.', 404);
        }
        $displayName = preg_replace('/ \([^)]*\)$/', '', (string) $item[1]) ?: basename($path);

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($displayName)]);
    }

    /** Hromadně: označit jako vyřízené, nebo smazat (i s přílohami). */
    protected function akceHromadne(): Response
    {
        $ids = array_map('intval', $this->request->postList('oznacene'));
        if (!$this->request->isPost() || $ids === []) {
            return $this->back();
        }
        $v = implode(',', $ids);
        if ($this->request->post('provest') === 'smazat') {
            self::deleteAttachments($this->db->all('SELECT data FROM {poptavky} WHERE idp IN (' . $v . ')'));
            $this->db->run('DELETE FROM {poptavky} WHERE idp IN (' . $v . ')');

            return $this->back(t('Smazáno poptávek: %d.', count($ids)));
        }
        $this->db->run('UPDATE {poptavky} SET stav = 2 WHERE idp IN (' . $v . ')');

        return $this->back(t('Vyřízeno poptávek: %d.', count($ids)));
    }

    /** @param list<array{data: string}> $rows */
    private static function deleteAttachments(array $rows): void
    {
        foreach ($rows as $r) {
            foreach (json_decode((string) $r['data'], true) ?: [] as $item) {
                if (is_array($item) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#', (string) ($item[2] ?? ''))) {
                    @unlink(KALETA_ROOT . '/storage/prilohy/' . $item[2]);
                }
            }
        }
    }

    protected function akceStav(): Response
    {
        if ($this->request->isPost()) {
            $state = $this->request->postInt('stav');
            $this->db->update('poptavky', ['stav' => isset(self::STATUSES[$state]) ? $state : 1], ['idp' => $this->request->postInt('idp')]);
        }

        return $this->back($this->request->postInt('stav') === 2 ? 'Poptávka je vyřízená.' : 'Poptávka je znovu otevřená.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            self::deleteAttachments($this->db->all('SELECT data FROM {poptavky} WHERE idp = ?', [$this->request->postInt('idp')]));
            $this->db->delete('poptavky', ['idp' => $this->request->postInt('idp')]);
        }

        return $this->back('Poptávka byla smazána.');
    }

    /** Uložení doby, po které se poptávky samy mažou (jen správce). */
    protected function akceNastaveni(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->app->settings()->set('poptavky_mesice', (string) max(0, min(120, $this->request->postInt('mesice'))));
        }

        return $this->back('Nastavení poptávek bylo uloženo.');
    }

    /** Všechny poptávky do CSV (UTF-8 s BOM, středník – otevře se rovnou v Excelu). */
    protected function akceCsv(): Response
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Číslo'), t('Datum'), t('Formulář'), t('Stav'), t('E-mail'), t('Stránka'), t('Kampaň'), t('Obsah')], ';', '"', '');
        foreach ($this->db->all('SELECT * FROM {poptavky} ORDER BY idp') as $p) {
            $content = implode("\n", array_map(fn (array $d): string => $d[0] . ': ' . $d[1], json_decode((string) $p['data'], true) ?: []));
            // buňka začínající = + - @ by se v tabulkovém procesoru spustila jako vzorec
            $row = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $p['idp'], (string) $p['datum'], (string) $p['formular'], t(self::STATUSES[(int) $p['stav']] ?? ''), (string) $p['email'], (string) $p['stranka'], \Kaleta\Front\Forms::campaignText((string) ($p['kampan'] ?? '')), $content]);
            fputcsv($f, $row, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \Kaleta\Admin\ChangeLog::write($this->app, 'poptavky', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="poptavky-' . date('Y-m-d') . '.csv"']);
    }

    /** Smaže poptávky starší než nastavený počet měsíců. */
    /** Smaže poptávky starší než nastavený počet měsíců i s přílohami (volá i úklid na pozadí, Core\Oznameni). */
    public static function deleteExpired(\Kaleta\Core\Db $db, \Kaleta\Core\Settings $siteSettings): void
    {
        $months = $siteSettings->int('poptavky_mesice');
        if ($months > 0) {
            self::deleteAttachments($db->all('SELECT data FROM {poptavky} WHERE datum < NOW() - INTERVAL ? MONTH', [$months]));
            $db->run('DELETE FROM {poptavky} WHERE datum < NOW() - INTERVAL ? MONTH', [$months]);
        }
    }
}
