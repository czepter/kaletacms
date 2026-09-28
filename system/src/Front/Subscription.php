<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Mail;

/**
 * Odběr novinek (rozšíření Newsletter): přihlášení z prvku Odběr novinek, potvrzení a odhlášení odkazem z e-mailu.
 * Adresa se počítá za odběratele až po potvrzení (double opt-in). Kdo se přihlásí podruhé, dostane jen nový odkaz –
 * web nikomu neprozradí, jestli adresa už v seznamu je.
 */
final class Subscription
{
    private const int LIMIT = 5; // přihlášení z jedné adresy za 10 minut

    public function __construct(private readonly App $app)
    {
    }

    /** POST z prvku: uloží nebo obnoví nepotvrzenou adresu a pošle odkaz. @return string výsledek pro hlášku prvku (ok | chyba | limit) */
    public function subscribe(): string
    {
        $r = $this->app->request;
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $reason = $antispam->verify($r, 'odber');
        if ($reason === 'robot') {
            return 'ok';
        }
        if ($reason !== null) {
            return 'chyba';
        }
        if ($antispam->count($r->ip(), 'odber', 0, 10) >= self::LIMIT) {
            return 'limit';
        }
        $antispam->write($r->ip(), 'odber', 0);
        $email = mb_strtolower(trim($r->post('email')));
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'chyba';
        }
        $db = $this->app->db();
        $subscriber = $db->one('SELECT * FROM {odberatele} WHERE email = ?', [$email]);
        if ($subscriber !== null && (int) $subscriber['stav'] === 1) {
            return 'ok'; // už odebírá – nic dalšího neposíláme
        }
        $token = bin2hex(random_bytes(16));
        if ($subscriber === null) {
            $db->insert('odberatele', ['email' => $email, 'token' => $token, 'datum' => date('Y-m-d H:i:s'), 'zdroj' => mb_substr($r->post('zpet'), 0, 255)]);
        } else {
            $db->update('odberatele', ['token' => $token, 'datum' => date('Y-m-d H:i:s')], ['ido' => (int) $subscriber['ido']]);
        }
        $siteSettings = $this->app->settings();
        $link = $this->address('odber?potvrdit=' . $token);
        Mail::send($siteSettings, $email, t('Potvrďte odběr novinek – %s', $siteSettings->get('site_name')),
            t('Dobrý den,') . "\n\n" . t('pro potvrzení odběru novinek webu %s klikněte na odkaz:', $siteSettings->get('site_name')) . "\n" . $link . "\n\n"
            . t('Pokud jste o odběr nežádali, e-mail ignorujte – bez potvrzení vám nic posílat nebudeme.') . "\n");

        return 'ok';
    }

    /**
     * Odkaz z e-mailu (?potvrdit= / ?odhlasit=). Otevření odkazu (GET) jen ukáže tlačítko – poštovní skenery odkazů
     * (Safe Links apod.) by jinak odběr samy potvrdily nebo odběratele odhlásily. Změna proběhne až odesláním (POST),
     * odhlášení i jedním klepnutím z poštovního klienta (List-Unsubscribe-Post).
     *
     * @return array{0: string, 1: string} titulek a obsah stránky (HTML)
     */
    public function link(): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $action = preg_match('/^[a-f0-9]{32}$/', $r->get('potvrdit')) ? 'potvrdit' : (preg_match('/^[a-f0-9]{32}$/', $r->get('odhlasit')) ? 'odhlasit' : '');
        $o = $action !== '' ? $db->one('SELECT * FROM {odberatele} WHERE token = ?', [$r->get($action)]) : null;
        if ($o === null) {
            return [t('Odkaz už neplatí'), '<p>' . e(t('Odkaz je neplatný nebo už byl použitý. Pokud chcete novinky odebírat, přihlaste se prosím znovu.')) . '</p>'];
        }
        if (!$r->isPost()) {
            [$heading, $text, $button] = $action === 'potvrdit'
                ? [t('Potvrzení odběru'), t('Potvrďte prosím, že chcete dostávat novinky na %s.', $o['email']), t('Potvrdit odběr')]
                : [t('Odhlášení odběru'), t('Opravdu už nechcete dostávat novinky na %s?', $o['email']), t('Odhlásit odběr')];

            return [$heading, '<p>' . e($text) . '</p><form method="post" action="' . e($this->app->url('odber') . '?' . $action . '=' . $o['token']) . '"><p><button class="tlacitko" type="submit">' . e($button) . '</button></p></form>'];
        }
        if ($action === 'odhlasit') {
            $db->delete('odberatele', ['ido' => (int) $o['ido']]);
            if ((int) $o['stav'] === 1) {
                \Kaleta\Core\Newsletter::enqueue($this->app, (string) $o['email'], 'odebrat'); // i z mailingové služby
            }

            return [t('Odhlášeno'), '<p>' . e(t('Adresu %s jsme ze seznamu odběratelů smazali.', $o['email'])) . '</p>'];
        }
        if ((int) $o['stav'] === 0) {
            $db->update('odberatele', ['stav' => 1, 'potvrzeno' => date('Y-m-d H:i:s')], ['ido' => (int) $o['ido']]);
            \Kaleta\Core\Newsletter::enqueue($this->app, (string) $o['email'], 'pridat'); // do mailingové služby, odešle úklid na pozadí
        }

        return [t('Odběr je potvrzený'), '<p>' . e(t('Děkujeme, novinky vám budeme posílat na %s. Odhlásit se můžete odkazem v každém e-mailu.', $o['email'])) . '</p>'];
    }

    /** Odkaz pro odhlášení do rozesílacího nástroje (export odběratelů). */
    public static function unsubscribeLink(App $app, string $token): string
    {
        return (new self($app))->address('odber?odhlasit=' . $token);
    }

    private function address(string $path): string
    {
        return rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . $this->app->url($path);
    }
}
