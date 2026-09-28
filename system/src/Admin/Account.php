<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Passkey;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\Totp;

/**
 * Můj účet: vlastní jméno a e-mail, změna hesla, dvoufázové přihlášení. Dostupné každému přihlášenému.
 */
final class Account
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(): Response
    {
        $app = $this->kernel->app;
        $r = $app->request;
        $db = $app->db();
        $user = $app->auth()->user();
        $data = ['backupCodes' => [], 'newSecret' => '', 'newToken' => ''];

        if ($r->isPost()) {
            $message = null;
            switch ($r->postInt('smaz_token') > 0 ? 'token_smaz' : ($r->post('odpojit_klient') !== '' ? 'aplikace_odpojit' : $r->post('co'))) {
                case 'profil':
                    if ($r->post('email') !== '' && filter_var($r->post('email'), FILTER_VALIDATE_EMAIL) === false) {
                        $message = ['chyba', 'E-mail nemá platný tvar.'];
                        break;
                    }
                    $db->update('uzivatele', ['jmeno' => mb_substr($r->post('jmeno'), 0, 100), 'email' => mb_substr($r->post('email'), 0, 190), 'url' => mb_substr($r->post('url'), 0, 255), 'pozice' => mb_substr($r->post('pozice'), 0, 100), 'foto' => mb_substr($r->post('foto'), 0, 255), 'bio' => mb_substr($r->post('bio'), 0, 1200),
                        // jazyk administrace i čeština výslovně – prázdná hodnota by znamenala jazyk webu
                        'jazyk' => isset(\Kaleta\Core\Language::ADMIN_LANGUAGES[$r->post('jazyk')]) ? $r->post('jazyk') : ''], ['idu' => $user['idu']]);
                    $message = ['ok', 'Údaje byly uloženy.'];
                    break;
                case 'heslo':
                    $newItems = (string) ($_POST['nove'] ?? '');
                    $message = match (true) {
                        !password_verify((string) ($_POST['soucasne'] ?? ''), $user['password']) => ['chyba', 'Současné heslo není správné.'],
                        mb_strlen($newItems) < 10 => ['chyba', 'Nové heslo musí mít alespoň 10 znaků.'],
                        $newItems !== (string) ($_POST['nove2'] ?? '') => ['chyba', 'Nová hesla se neshodují.'],
                        default => null,
                    };
                    if ($message === null) {
                        $newHash = password_hash($newItems, PASSWORD_DEFAULT);
                        $db->update('uzivatele', ['password' => $newHash], ['idu' => $user['idu']]);
                        $app->auth()->refreshAfterPasswordChange($newHash); // ostatní přihlášení tohoto účtu tím končí
                        $revoked = $r->postBool('zrusit_tokeny') ? $db->delete('api_tokeny', ['idu' => $user['idu']]) : 0;
                        ChangeLog::write($app, 'ucet', 'změna hesla' . ($revoked > 0 ? ', zrušeny tokeny napojení (' . $revoked . ')' : ''));
                        $message = ['ok', $revoked > 0 ? 'Heslo bylo změněno, ostatní přihlášení ukončena a tokeny napojení zrušeny.' : 'Heslo bylo změněno a ostatní přihlášení tohoto účtu ukončena.'];
                    }
                    break;
                case 'token_novy':
                    if (!Extensions::isEnabled($app->settings(), 'claude')) {
                        break;
                    }
                    if ($app->auth()->isMissingRequired2fa($app->settings())) {
                        $message = ['chyba', 'Web vyžaduje dvoufázové přihlášení – token vytvoříte, až si ho zapnete.'];
                        break;
                    }
                    $token = 'kaleta_' . bin2hex(random_bytes(24));
                    $db->insert('api_tokeny', ['idu' => $user['idu'], 'nazev' => mb_substr($r->post('nazev') ?: 'Claude', 0, 100), 'otisk' => hash('sha256', $token), 'vytvoren' => date('Y-m-d H:i:s')]);
                    ChangeLog::write($app, 'ucet', 'vytvořen token pro Claude');
                    // token se ukazuje jen teď - proto bez přesměrování
                    return $this->page(['newToken' => $token] + $data);
                case 'token_smaz':
                    $db->delete('api_tokeny', ['idt' => $r->postInt('smaz_token'), 'idu' => $user['idu']]);
                    $message = ['ok', 'Token byl zrušen.'];
                    break;
                case 'aplikace_odpojit':
                    $db->delete('api_tokeny', ['klient' => $r->post('odpojit_klient'), 'idu' => $user['idu']]);
                    ChangeLog::write($app, 'ucet', 'odpojena aplikace');
                    $message = ['ok', 'Aplikace je odpojená – do webu se už nedostane, dokud ji znovu nepovolíte.'];
                    break;
                case 'totp_start':
                    $app->session->set('totp_nove', Totp::newSecret());
                    break;
                case 'totp_potvrd':
                    $secret = (string) $app->session->get('totp_nove', '');
                    if ($secret === '' || !Totp::verify($secret, $r->post('kod'))) {
                        $message = ['chyba', 'Kód nesouhlasí. Zkontrolujte čas v telefonu a zkuste to znovu.'];
                        break;
                    }
                    [$codes, $json] = Totp::backupCodes();
                    $db->update('uzivatele', ['totp_tajemstvi' => $secret, 'totp_zalozni' => $json], ['idu' => $user['idu']]);
                    $app->session->remove('totp_nove');
                    ChangeLog::write($app, 'ucet', 'zapnuto dvoufázové přihlášení');
                    // záložní kódy se ukazují jen teď - proto bez přesměrování
                    return $this->page(['backupCodes' => $codes] + $data);
                case 'klic_moznosti':
                case 'klic_uloz':
                    return $this->key($r->post('co') === 'klic_uloz');
                case 'klic_smaz':
                    $db->run('DELETE FROM {uzivatele_klice} WHERE idk = ? AND idu = ?', [$r->postInt('idk'), $user['idu']]);
                    ChangeLog::write($app, 'ucet', 'odebrán přihlašovací klíč');
                    $message = ['ok', 'Přihlašovací klíč je odebrán.'];
                    break;
                case 'totp_vypni':
                    if (!password_verify((string) ($_POST['soucasne'] ?? ''), $user['password'])) {
                        $message = ['chyba', 'Pro vypnutí zadejte správné heslo.'];
                        break;
                    }
                    $db->update('uzivatele', ['totp_tajemstvi' => '', 'totp_zalozni' => null], ['idu' => $user['idu']]);
                    $db->run('DELETE FROM {uzivatele_klice} WHERE idu = ?', [$user['idu']]); // klíče jsou náhrada kódu z aplikace - bez něj nemají smysl
                    ChangeLog::write($app, 'ucet', 'vypnuto dvoufázové přihlášení');
                    $message = ['ok', 'Dvoufázové přihlášení je vypnuté.'];
                    break;
            }
            if ($message !== null) {
                $app->session->flash(...$message);

                return Response::redirect($app->url('admin.php?akce=ucet'));
            }
        }

        return $this->page(['newSecret' => (string) $app->session->get('totp_nove', '')] + $data);
    }

    /**
     * Registrace přihlašovacího klíče (otisk prstu, Face ID, bezpečnostní klíč) - volá ji skript image/klice.js.
     * Klíč jde přidat jen k účtu se zapnutým dvoufázovým přihlášením: je to pohodlnější náhrada kódu z aplikace,
     * kód a záložní kódy zůstávají jako záloha pro případ ztráty zařízení.
     */
    private function key(bool $save): Response
    {
        $app = $this->kernel->app;
        $user = $app->auth()->user();
        if ((string) $user['totp_tajemstvi'] === '') {
            return Response::json(['chyba' => t('Nejdřív zapněte dvoufázové přihlášení.')], 400);
        }
        $url = $app->settings()->get('adresa_webu') ?: $app->request->origin();
        if (!$save) {
            $challenge = Passkey::challenge();
            $app->session->set('klic_registrace', $challenge);

            return Response::json(Passkey::registrationOptions(
                $challenge, Passkey::rpId($url), $app->settings()->get('nazev_webu'),
                Passkey::b64(substr(hash('sha256', 'kaleta-klic|' . $url . '|' . $user['idu'], true), 0, 16)),
                (string) $user['user'], (string) $user['jmeno'],
                array_map(static fn (array $k): string => (string) $k['id_klice'], $app->auth()->accountKeys((int) $user['idu'])),
            ));
        }
        $challenge = (string) $app->session->get('klic_registrace', '');
        $app->session->remove('klic_registrace');
        try {
            $new = Passkey::verifyRegistration((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $challenge, Passkey::origin($url), Passkey::rpId($url));
        } catch (\RuntimeException $e) {
            return Response::json(['chyba' => t($e->getMessage())], 400);
        }
        $hash = hash('sha256', Passkey::fromB64($new['id']));
        if ($app->db()->value('SELECT idk FROM {uzivatele_klice} WHERE otisk_id = ?', [$hash]) !== null) {
            return Response::json(['chyba' => t('Tenhle klíč už je zaregistrovaný.')], 400);
        }
        $name = mb_substr(trim($app->request->post('nazev')), 0, 80);
        $app->db()->insert('uzivatele_klice', [
            'idu' => $user['idu'], 'nazev' => $name !== '' ? $name : t('Přihlašovací klíč'), 'otisk_id' => $hash, 'id_klice' => $new['id'],
            'verejny' => $new['klic'], 'alg' => $new['alg'], 'pocitadlo' => $new['pocitadlo'], 'vytvoreno' => date('Y-m-d H:i:s'),
        ]);
        ChangeLog::write($app, 'ucet', 'přidán přihlašovací klíč', $name);
        $app->session->flash('ok', 'Přihlašovací klíč je přidán. Při příštím přihlášení ho můžete použít místo kódu z aplikace.');

        return Response::json(['ok' => true]);
    }

    /** @param array<string, mixed> $data */
    private function page(array $data): Response
    {
        $app = $this->kernel->app;
        $user = $app->db()->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$app->auth()->id()]);

        return $this->kernel->page('Můj účet', $app->view->render('admin/account', $data + [
            'app' => $app, 'user' => $user, 'csrf' => $app->session->csrfField(),
            'uri' => $data['newSecret'] !== '' ? Totp::uri($data['newSecret'], $user['user'], $app->settings()->get('nazev_webu')) : '',
            'codesLeft' => count((array) json_decode((string) $user['totp_zalozni'], true)),
            'claude' => Extensions::isEnabled($app->settings(), 'claude'),
            'keys' => $app->auth()->accountKeys((int) $user['idu']),
            'tokens' => $app->db()->all("SELECT * FROM {api_tokeny} WHERE idu = ? AND druh = 'token' ORDER BY idt DESC", [$user['idu']]),
            // aplikace připojené přes OAuth (konektor Claude): jedna položka na klienta, platí dokud má obnovovací token
            'apps' => $app->db()->all("SELECT klient, MAX(nazev) AS nazev, MIN(vytvoren) AS vytvoren, MAX(pouzit) AS pouzit FROM {api_tokeny} WHERE idu = ? AND klient IS NOT NULL AND expirace > ? GROUP BY klient ORDER BY MIN(vytvoren) DESC",
                [$user['idu'], date('Y-m-d H:i:s')]),
            'mcpUrl' => $app->request->origin() . $app->url('mcp'),
        ]));
    }
}
