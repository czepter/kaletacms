<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Kernel;
use Kaleta\Admin\Module;
use Kaleta\Core\Auth;
use Kaleta\Core\Db;
use Kaleta\Core\Response;

/**
 * Vlastní role (Uživatelé → Role): pojmenovaná sada sekcí administrace a úroveň – třeba „Obchodník“ jen s Poptávkami
 * nebo „Marketing“ se Stránkami, Médii a Novinkami. Uložení role přepíše práva všem jejím členům; smazání role
 * členům práva nechá, jen už nejsou svázaná.
 */
final class Roles extends Module
{
    public const string IDENT = 'roles';
    public const string NAME = 'Role';
    public const string GROUP = 'Správa';
    public const string ICON = 'uzivatele';
    public const bool ADMIN_ONLY = true;
    public const string PARENT = 'users';

    /** Úrovně vlastní role (správce vlastní rolí být nemůže – to je vestavěná role Správce). */
    public const array LEVELS = [
        Auth::AUTHOR => ['Píše vlastní obsah', 'Novinky jen své a bez vydávání – vydává editor.'],
        Auth::EDITOR => ['Spravuje obsah všech', 'Upravuje a vydává novinky všech autorů.'],
    ];

    protected function actionList(): Response
    {
        $role = $this->db->all('SELECT r.*, (SELECT COUNT(*) FROM {uzivatele} u WHERE u.role = r.idr) AS clenu FROM {role} r ORDER BY r.nazev');

        return $this->view('list', 'Role', ['role' => $role, 'names' => self::configurable()]);
    }

    protected function actionNew(): Response
    {
        return $this->form(['idr' => 0, 'nazev' => '', 'popis' => '', 'uroven' => Auth::AUTHOR, 'moduly' => '']);
    }

    protected function actionEdit(): Response
    {
        $role = $this->db->one('SELECT * FROM {role} WHERE idr = ?', [$this->request->getInt('id')]);

        return $role === null ? $this->error('Role neexistuje.', 404) : $this->form($role);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('idr');
        $data = [
            'nazev' => mb_substr($r->post('nazev'), 0, 60),
            'popis' => mb_substr($r->post('popis'), 0, 200),
            'uroven' => isset(self::LEVELS[$r->postInt('uroven')]) ? $r->postInt('uroven') : Auth::AUTHOR,
            'moduly' => implode(',', array_values(array_intersect($r->postList('moduly'), array_keys(self::configurable())))),
        ];
        $errors = [];
        if ($data['nazev'] === '') {
            $errors['nazev'] = 'Vyplňte název role.';
        } elseif (in_array(mb_strtolower($data['nazev']), array_map(fn (string $n): string => mb_strtolower(t($n)), ['Autor novinek', 'Editor', 'Správce']), true)
            || $this->db->value('SELECT idr FROM {role} WHERE nazev = ? AND idr <> ?', [$data['nazev'], $id]) !== null) {
            $errors['nazev'] = 'Role s tímto názvem už existuje.';
        }
        if ($data['moduly'] === '') {
            $errors['moduly'] = 'Vyberte aspoň jednu sekci.';
        }
        if ($errors !== []) {
            return $this->form(['idr' => $id] + $data, $errors);
        }
        $this->db->transaction(function (Db $db) use (&$id, $data): void {
            if ($id > 0) {
                $db->update('role', $data, ['idr' => $id]);
            } else {
                $id = $db->insert('role', $data);
            }
            self::applyToMembers($db, $id);
        });

        return $this->back('Role byla uložena.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            // členové si ponechají dosavadní práva, jen už je role při další změně nepřepíše
            $this->db->run('UPDATE {uzivatele} SET role = NULL WHERE role = ?', [$this->request->postInt('idr')]);
            $this->db->delete('role', ['idr' => $this->request->postInt('idr')]);
        }

        return $this->back('Role byla smazána. Její členové si ponechali dosavadní přístup.');
    }

    /** Práva role přepíše všem jejím členům (úroveň i sekce). Správce se nemění – ten má vždy vše. */
    public static function applyToMembers(Db $db, int $idr): void
    {
        $role = $db->one('SELECT * FROM {role} WHERE idr = ?', [$idr]);
        if ($role === null) {
            return;
        }
        foreach ($db->all('SELECT idu FROM {uzivatele} WHERE role = ? AND admin < ?', [$idr, Auth::ADMIN]) as $u) {
            $db->update('uzivatele', ['admin' => (int) $role['uroven']], ['idu' => (int) $u['idu']]);
            $db->delete('uzivatele_prava', ['fk_id_user' => (int) $u['idu']]);
            foreach (array_filter(explode(',', (string) $role['moduly'])) as $ident) {
                $db->insert('uzivatele_prava', ['fk_id_user' => (int) $u['idu'], 'ident_modulu' => $ident]);
            }
        }
    }

    /** @return array<string, string> sekce, ke kterým se přístup nastavuje: ident => název */
    public static function configurable(): array
    {
        $section = [];
        foreach (Kernel::MODULES as $class) {
            if (!$class::ADMIN_ONLY && !$class::FOR_ALL_USERS) {
                $section[$class::IDENT] = $class::NAME;
            }
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $role
     * @param array<string, string> $errors
     */
    private function form(array $role, array $errors = []): Response
    {
        return $this->view('form', (int) $role['idr'] > 0 ? 'Úprava role' : 'Nová role', [
            'role' => $role, 'errors' => $errors, 'section' => self::configurable(),
            'selected' => array_filter(explode(',', (string) $role['moduly'])),
            'members' => (int) $role['idr'] > 0 ? $this->db->all('SELECT idu, user, jmeno FROM {uzivatele} WHERE role = ? ORDER BY user', [(int) $role['idr']]) : [],
        ]);
    }
}
