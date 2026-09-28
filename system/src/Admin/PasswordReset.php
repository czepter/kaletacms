<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\Mail;
use Kaleta\Core\Response;

/**
 * Obnova zapomenutého hesla do administrace odkazem z e-mailu (admin.php?akce=heslo).
 *
 * - Odpověď na žádost je vždy stejná, ať účet existuje nebo ne - stránka neprozradí, kdo web spravuje.
 * - V databázi je jen otisk tokenu; odkaz platí hodinu a jde použít jednou.
 * - Dvoufázové přihlášení obnova NEVYPÍNÁ: kdo získá přístup do e-mailu, bez kódu z aplikace se stejně nepřihlásí.
 * - Změna hesla ukončí všechna ostatní přihlášení účtu (otisk hesla v session přestane sedět).
 */
final class PasswordReset
{
    private const int LINK_LIFETIME = 3600;

    public function __construct(private readonly App $app)
    {
    }

    public function handle(): Response
    {
        $r = $this->app->request;
        $token = $r->isPost() ? $r->post('token') : $r->get('token');

        return $token !== '' ? $this->setNewPassword($token) : $this->handleRequest();
    }

    private function handleRequest(): Response
    {
        $app = $this->app;
        $sent = false;
        $error = null;
        if ($app->request->isPost()) {
            $ip = Antispam::hash($app->request->ip());
            $attempts = (int) $app->db()->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'obnova' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [$ip]);
            if ($attempts >= 5) {
                $error = t('Příliš mnoho žádostí. Zkuste to znovu za 15 minut.');
            } else {
                $app->db()->insert('kontrola_ip', ['ip_adresa' => $ip, 'typ' => 'obnova', 'cas' => date('Y-m-d H:i:s')]);
                $who = trim($app->request->post('kdo'));
                $user = $who === '' ? null : $app->db()->one("SELECT * FROM {uzivatele} WHERE (user = ? OR email = ?) AND blokovat = 0 AND email <> '' LIMIT 1", [$who, $who]);
                if ($user !== null) {
                    $this->sendLink($user);
                }
                $sent = true;
            }
        }

        return $this->page(['step' => 'zadost', 'sent' => $sent, 'error' => $error], $error === null ? 200 : 429);
    }

    /**
     * Odkaz na nastavení hesla e-mailem: na žádost uživatele (platí hodinu), nebo jako pozvánka nového uživatele
     * či na pokyn správce (platí 3 dny – odkaz „platí“ tak, že čas obnovy leží v budoucnosti).
     *
     * @param array<string, mixed> $user
     */
    public function sendLink(array $user, string $reason = 'zadost'): void
    {
        $app = $this->app;
        $token = bin2hex(random_bytes(32));
        $time = $reason === 'zadost' ? time() : time() + 71 * 3600;
        $app->db()->update('uzivatele', ['obnova_otisk' => hash('sha256', $token), 'obnova_cas' => date('Y-m-d H:i:s', $time)], ['idu' => $user['idu']]);
        $link = rtrim($app->settings()->get('adresa_webu') ?: $app->request->origin(), '/') . $app->url('admin.php?akce=heslo&token=' . $token);
        $language = (string) ($user['jazyk'] ?? '') !== '' ? (string) $user['jazyk'] : Language::defaults($app->settings());
        $siteSettings = $app->settings()->get('nazev_webu');
        [$subject, $text] = Language::runWith($language, fn (): array => match ($reason) {
            'pozvanka' => [t('Pozvánka do administrace') . ' – ' . $siteSettings,
                t('Dobrý den,') . "\n\n" . t('dostali jste přístup do administrace webu %s. Vaše přihlašovací jméno je %s.', $siteSettings, (string) $user['user'])
                    . "\n\n" . t('Heslo si nastavíte na této adrese (platí 3 dny a jde použít jednou):') . "\n" . $link],
            'spravce' => [t('Nové heslo do administrace') . ' – ' . $siteSettings,
                t('Dobrý den,') . "\n\n" . t('správce webu %s vám poslal odkaz na nastavení nového hesla k účtu %s.', $siteSettings, (string) $user['user'])
                    . "\n\n" . t('Heslo si nastavíte na této adrese (platí 3 dny a jde použít jednou):') . "\n" . $link],
            default => [t('Nové heslo do administrace') . ' – ' . $siteSettings,
                t('Dobrý den,') . "\n\n" . t('někdo (nejspíš vy) požádal o nové heslo k účtu %s v administraci webu %s.', (string) $user['user'], $siteSettings)
                    . "\n\n" . t('Nové heslo nastavíte na této adrese (platí hodinu a jde použít jednou):') . "\n" . $link
                    . "\n\n" . t('Pokud jste o nové heslo nežádali, e-mail smažte – heslo zůstává beze změny.')],
        }, 'admin-');
        Mail::send($app->settings(), (string) $user['email'], $subject, $text);
        ChangeLog::write($app, 'prihlaseni', 'obnova-hesla', ($reason === 'pozvanka' ? 'pozvánka' : 'odeslán odkaz') . ', účet: ' . $user['user']);
    }

    private function setNewPassword(string $token): Response
    {
        $app = $this->app;
        $user = preg_match('/^[a-f0-9]{64}$/', $token) === 1
            ? $app->db()->one('SELECT * FROM {uzivatele} WHERE obnova_otisk = ? AND blokovat = 0 AND obnova_cas > ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() - self::LINK_LIFETIME)])
            : null;
        if ($user === null) {
            return $this->page(['step' => 'neplatny', 'sent' => false, 'error' => t('Odkaz už neplatí nebo byl použit. Požádejte o nový.')], 400);
        }
        $error = null;
        if ($app->request->isPost()) {
            $password = (string) ($_POST['password'] ?? '');
            if (mb_strlen($password) < 10) {
                $error = t('Heslo musí mít alespoň 10 znaků.');
            } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
                $error = t('Hesla se neshodují.');
            } else {
                $app->db()->update('uzivatele', [
                    'password' => password_hash($password, PASSWORD_DEFAULT), 'obnova_otisk' => '', 'obnova_cas' => null, 'pocet_chyb' => 0, 'zamceno_do' => null,
                ], ['idu' => $user['idu']]);
                // kdo heslo obnovuje, mohl o účet přijít: tokeny napojení (MCP) přestanou platit
                $app->db()->delete('api_tokeny', ['idu' => $user['idu']]);
                ChangeLog::write($app, 'prihlaseni', 'obnova-hesla', 'heslo změněno, tokeny napojení zrušeny, účet: ' . $user['user']);
                return Response::redirect($app->url('admin.php?heslo=zmeneno'));
            }
        }

        return $this->page(['step' => 'heslo', 'sent' => false, 'error' => $error, 'token' => $token, 'account' => (string) $user['user']], $error === null ? 200 : 422);
    }

    /** @param array<string, mixed> $data */
    private function page(array $data, int $status): Response
    {
        return Response::html($this->app->view->render('admin/password', ['app' => $this->app, 'token' => '', 'account' => ''] + $data), $status);
    }
}
