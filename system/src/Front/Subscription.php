<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Mail;

/**
 * News subscription (Newsletter extension): sign-up from the News subscription element, confirmation and unsubscribe by
 * a link from the e-mail. An address counts as a subscriber only after confirmation (double opt-in). Whoever signs up
 * a second time only gets a new link – the site does not reveal to anyone whether the address is already on the list.
 */
final class Subscription
{
    private const int LIMIT = 5; // sign-ups from one address per 10 minutes

    public function __construct(private readonly App $app)
    {
    }

    /**
     * POST from the element: saves or renews an unconfirmed address and sends the link.
     *
     * @return string result for the element's message (ok | chyba | limit)
     */
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
            return 'ok'; // already subscribed – we send nothing more
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
     * Link from the e-mail (?potvrdit= / ?odhlasit=). Opening the link (GET) only shows a button – mail link scanners
     * (Safe Links etc.) would otherwise confirm the subscription or unsubscribe the subscriber on their own. The change
     * happens only on submission (POST), unsubscribing also with one click from the mail client (List-Unsubscribe-Post).
     *
     * @return array{0: string, 1: string} page title and content (HTML)
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
                \Kaleta\Core\Newsletter::enqueue($this->app, (string) $o['email'], 'odebrat'); // from the mailing service too
            }

            return [t('Odhlášeno'), '<p>' . e(t('Adresu %s jsme ze seznamu odběratelů smazali.', $o['email'])) . '</p>'];
        }
        if ((int) $o['stav'] === 0) {
            $db->update('odberatele', ['stav' => 1, 'potvrzeno' => date('Y-m-d H:i:s')], ['ido' => (int) $o['ido']]);
            \Kaleta\Core\Newsletter::enqueue($this->app, (string) $o['email'], 'pridat'); // to the mailing service, sent by the background cleanup
        }

        return [t('Odběr je potvrzený'), '<p>' . e(t('Děkujeme, novinky vám budeme posílat na %s. Odhlásit se můžete odkazem v každém e-mailu.', $o['email'])) . '</p>'];
    }

    /** Unsubscribe link for the mailing tool (subscriber export). */
    public static function unsubscribeLink(App $app, string $token): string
    {
        return (new self($app))->address('odber?odhlasit=' . $token);
    }

    private function address(string $path): string
    {
        return rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . $this->app->url($path);
    }
}
