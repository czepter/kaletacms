<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Kernel;
use Kaleta\Admin\Module;
use Kaleta\Core\Auth;
use Kaleta\Core\Response;

/**
 * Uživatelé administrace: účty, role (správce, editor, autor novinek) a případně ruční přístup do sekcí.
 */
final class Users extends Module
{
    public const string IDENT = 'users';
    public const string NAME = 'Uživatelé';
    public const string GROUP = 'Správa';
    public const string ICON = 'uzivatele';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $authors = $this->db->all('SELECT u.*, r.nazev AS nazev_role, (SELECT COUNT(*) FROM {novinky} c WHERE c.autor = u.idu AND c.smazano IS NULL) AS pocet_clanku FROM {uzivatele} u LEFT JOIN {role} r ON r.idr = u.role ORDER BY u.user');
        $modules = [];
        foreach ($this->db->all('SELECT fk_id_user, ident_modulu FROM {uzivatele_prava}') as $r) {
            $modules[(int) $r['fk_id_user']][] = (string) $r['ident_modulu'];
        }
        foreach ($authors as &$a) {
            $a['shrnuti'] = self::summary((int) $a['admin'], $modules[(int) $a['idu']] ?? [], (bool) $a['blokovat']);
        }
        unset($a);

        return $this->view('list', 'Uživatelé', ['authors' => $authors]);
    }

    protected function actionNew(): Response
    {
        return $this->form(['idu' => 0, 'user' => '', 'jmeno' => '', 'email' => '', 'url' => '', 'admin' => Auth::AUTHOR, 'role' => null, 'blokovat' => 0]);
    }

    protected function actionEdit(): Response
    {
        $author = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$this->request->getInt('id')]);

        return $author === null ? $this->error('Uživatel neexistuje.', 404) : $this->form($author);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('idu');
        $isSelf = $id === $this->app->auth()->id();
        $data = [
            'user' => $r->post('user'),
            'jmeno' => $r->post('jmeno'),
            'email' => $r->post('email'),
            'url' => $r->post('url'),
            'admin' => array_key_exists($r->postInt('admin'), Auth::TYPES) ? $r->postInt('admin') : Auth::AUTHOR,
            'role' => null,
            'blokovat' => (int) $r->postBool('blokovat'),
        ];
        // vlastní role (hodnota „r<id>“): úroveň i sekce určuje role
        $custom = preg_match('/^r(\d+)$/', $r->post('admin'), $m) ? $this->db->one('SELECT * FROM {role} WHERE idr = ?', [(int) $m[1]]) : null;
        if ($custom !== null) {
            $data['admin'] = (int) $custom['uroven'];
            $data['role'] = (int) $custom['idr'];
        }
        if ($isSelf) {
            // admin si nesmí sám sobě vzít práva ani se zablokovat - zamkl by si administraci
            $data['admin'] = Auth::ADMIN;
            $data['role'] = null;
            $data['blokovat'] = 0;
        }
        if (!$data['blokovat']) {
            $data['pocet_chyb'] = 0;
        }
        if ($r->postBool('totp_reset')) {
            // uživatel ztratil telefon i záložní kódy: administrátor mu dvoufázové přihlášení vypne
            $data['totp_tajemstvi'] = '';
            $data['totp_zalozni'] = null;
            $this->app->db()->run('DELETE FROM {uzivatele_klice} WHERE idu = ?', [$id]); // přihlašovací klíče stojí na dvoufázovém přihlášení
        }

        $errors = [];
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $data['user'])) {
            $errors['user'] = 'Přihlašovací jméno: 2-40 znaků, jen písmena bez diakritiky, číslice, tečka, pomlčka a podtržítko.';
        } elseif ($this->db->value('SELECT idu FROM {uzivatele} WHERE user = ? AND idu <> ?', [$data['user'], $id]) !== null) {
            $errors['user'] = 'Toto přihlašovací jméno už používá jiný uživatel.';
        }
        if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'E-mail nemá platný tvar.';
        }
        $password = $r->post('password');
        $invite = $id === 0 && $r->postBool('pozvat');
        if ($invite && $data['email'] === '') {
            $errors['email'] = 'Pozvánka potřebuje e-mail.';
        }
        if ($invite && $password === '') {
            // pozvaný si heslo nastaví sám z odkazu v e-mailu; do té doby se nepřihlásí (náhodné heslo nikdo nezná)
            $data['password'] = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        } elseif ($password !== '' || $id === 0) {
            if (mb_strlen($password) < 10) {
                $errors['password'] = 'Heslo musí mít alespoň 10 znaků.';
            } else {
                $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
        }
        if ($errors !== []) {
            return $this->form(['idu' => $id] + $data, $errors);
        }

        // přístup do sekcí plyne z role; ruční výběr jen když o něj administrátor výslovně stojí
        $modules = match (true) {
            $data['role'] !== null => array_filter(explode(',', (string) $custom['moduly'])),
            $r->postBool('rucne') => array_intersect($r->postList('moduly'), array_map(fn (string $c): string => $c::IDENT, Kernel::MODULES)),
            default => self::defaultModules((int) $data['admin']),
        };

        $this->db->transaction(function () use (&$id, $data, $modules): void {
            if ($id > 0) {
                $this->db->update('uzivatele', $data, ['idu' => $id]);
            } else {
                $id = $this->db->insert('uzivatele', $data);
            }
            $this->db->delete('uzivatele_prava', ['fk_id_user' => $id]);
            foreach ($modules as $ident) {
                $this->db->insert('uzivatele_prava', ['fk_id_user' => $id, 'ident_modulu' => $ident]);
            }
        });

        if ($invite) {
            (new \Kaleta\Admin\PasswordReset($this->app))->sendLink(['idu' => $id] + $data, 'pozvanka');

            return $this->back(t('Uživatel je založený a pozvánka odešla na %s.', $data['email']));
        }

        return $this->back('Uživatel byl uložen.');
    }

    /** Správce pošle uživateli odkaz na nastavení nového hesla (platí 3 dny). */
    protected function actionPasswordLink(): Response
    {
        $user = $this->request->isPost() ? $this->db->one("SELECT * FROM {uzivatele} WHERE idu = ? AND email <> '' AND blokovat = 0", [$this->request->postInt('idu')]) : null;
        if ($user === null) {
            return $this->back('Uživatel nemá e-mail nebo je zablokovaný.', '', [], 'chyba');
        }
        (new \Kaleta\Admin\PasswordReset($this->app))->sendLink($user, 'spravce');

        return $this->back(t('Odkaz na nové heslo odešel na %s.', $user['email']));
    }

    /**
     * Oprávnění uživatele jednou větou - správce po uložení potřebuje vidět, co z role a sekcí dohromady vyšlo.
     *
     * @param list<string> $modules identifikátory modulů, ke kterým má přístup
     */
    public static function summary(int $role, array $modules, bool $blocked = false): string
    {
        if ($blocked) {
            return t('Účet je zablokovaný – do administrace se nepřihlásí.');
        }
        if ($role >= Auth::ADMIN) {
            return t('Smí všechno včetně nastavení webu a správy uživatelů.');
        }
        $parts = [];
        if (!in_array('novinky', $modules, true)) {
            $parts[] = t('Nepíše novinky');
        } elseif ($role >= Auth::EDITOR) {
            $parts[] = t('Píše, upravuje a vydává novinky všech autorů');
        } else {
            $parts[] = t('Píše a upravuje vlastní novinky, vydává je editor');
        }
        $names = [];
        foreach (Kernel::MODULES as $class) {
            if ($class::IDENT !== 'news' && !$class::ADMIN_ONLY && !$class::FOR_ALL_USERS && in_array($class::IDENT, $modules, true)) {
                $names[] = t($class::NAME);
            }
        }
        $sentence = implode(', ', $parts) . '.';
        if ($names !== []) {
            $sentence .= ' ' . t('Dál má přístup k: %s.', implode(', ', $names));
        }

        return $sentence;
    }

    /**
     * Sekce, do kterých má role přístup, když je správce nenastaví ručně: autor jen Novinky, editor veškerý obsah.
     *
     * @return list<string>
     */
    public static function defaultModules(int $role): array
    {
        $modules = [];
        foreach (Kernel::MODULES as $class) {
            if ($class::ADMIN_ONLY || $class::FOR_ALL_USERS) {
                continue;
            }
            if ($role >= Auth::EDITOR || $class::IDENT === 'news') {
                $modules[] = $class::IDENT;
            }
        }

        return $modules;
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('idu');
        if ($id === $this->app->auth()->id()) {
            return $this->back('Nemůžete smazat sám sebe.', type: 'chyba');
        }
        $this->db->delete('uzivatele', ['idu' => $id]);

        return $this->back('Uživatel byl smazán. Jeho novinky zůstaly zachované bez autora.');
    }

    /**
     * @param array<string, mixed> $author
     * @param array<string, string> $errors
     */
    private function form(array $author, array $errors = []): Response
    {
        $id = (int) $author['idu'];
        $configurable = [];
        foreach (Kernel::MODULES as $class) {
            if (!$class::ADMIN_ONLY && !$class::FOR_ALL_USERS) {
                $configurable[$class::IDENT] = $class::NAME;
            }
        }

        // shrnutí platí pro uložený stav - nad formulářem říká, co uživatel smí TEĎ (u nového uživatele není co shrnovat)
        $summary = $id > 0 && !$this->request->isPost() ? self::summary(
            (int) $author['admin'],
            array_column($this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu'),
            (bool) $author['blokovat'],
        ) : '';

        return $this->view('form', $id ? 'Úprava uživatele' : 'Nový uživatel', [
            'author' => $author,
            'customRoles' => $this->db->all('SELECT idr, nazev, popis FROM {role} ORDER BY nazev'),
            'summary' => $summary,
            'errors' => $errors,
            'isSelf' => $id === $this->app->auth()->id(),
            'modules' => $configurable,
            'hasModules' => $this->request->isPost()
                ? $this->request->postList('moduly')
                : array_column($this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu'),
            'manual' => $this->request->isPost() ? $this->request->postBool('rucne') : ($id > 0 && (int) $author['admin'] !== Auth::ADMIN && (function () use ($id, $author): bool {
                $ma = array_column($this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu');
                $defaults = self::defaultModules((int) $author['admin']);
                sort($ma);
                sort($defaults);

                return $ma !== $defaults;
            })()),
        ]);
    }
}
