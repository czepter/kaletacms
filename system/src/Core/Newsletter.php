<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Odběratelé do mailingové služby, kterou web už používá: po potvrzení odběru (double opt-in) se adresa přidá do seznamu
 * ve službě, po odhlášení se z něj odebere. Rozesílání, doručitelnost a odhlašování z e-mailů řeší služba.
 *
 * Potvrzení a odhlášení jen zapíšou úlohu do fronty (ka_odber_fronta); odešle ji úklid na pozadí (Oznameni::naPozadi),
 * aby návštěvník na službu nečekal. Nepovedený pokus se zopakuje později (5 min, 30 min, 2 h, 12 h), potom se vzdá –
 * odběratel má v administraci stav „chyba“ a jde zkusit znovu.
 *
 * Klíč API se ukládá jen na webu (Nastavení → Rozšíření) a přes MCP se neukazuje ani nemění.
 */
final class Newsletter
{
    /** služba => [název, potřebuje klíč a seznam] */
    public const array SERVICES = [
        'brevo' => ['Brevo', true],
        'mailerlite' => ['MailerLite', true],
        'mailchimp' => ['Mailchimp', true],
        'ecomail' => ['Ecomail', true],
        'smartemailing' => ['SmartEmailing', true],
        'webhook' => ['Jiná služba přes webhook (Make, Zapier, n8n)', false],
    ];

    /** Po kolika minutách další pokus (podle počtu nepovedených). */
    private const array RETRY_DELAYS = [5, 30, 120, 720];

    public static function isEnabled(Settings $s): bool
    {
        $service = $s->get('newsletter_sluzba');
        if (!isset(self::SERVICES[$service])) {
            return false;
        }

        return self::SERVICES[$service][1] ? $s->get('newsletter_klic') !== '' && $s->get('newsletter_seznam') !== '' : preg_match('#^https://#i', $s->get('newsletter_webhook')) === 1;
    }

    /** Zařadí přidání (po potvrzení) nebo odebrání (po odhlášení) adresy; bez nastavené služby nic. */
    public static function enqueue(App $app, string $email, string $action): void
    {
        if (!self::isEnabled($app->settings()) || !in_array($action, ['pridat', 'odebrat'], true)) {
            return;
        }
        $db = $app->db();
        // starší nevyřízená úloha pro tutéž adresu je přebytečná – platí poslední stav
        $db->run('DELETE FROM {odber_fronta} WHERE email = ?', [$email]);
        $db->insert('odber_fronta', ['email' => $email, 'akce' => $action, 'pokusy' => 0, 'dalsi' => date('Y-m-d H:i:s'), 'vytvoreno' => date('Y-m-d H:i:s')]);
        $db->run("UPDATE {odberatele} SET sync = 'ceka', sync_chyba = '' WHERE email = ?", [$email]);
    }

    /** Všichni potvrzení odběratelé, kteří ve službě ještě nejsou (po napojení služby). @return int kolik jich čeká */
    public static function enqueueAll(App $app): int
    {
        if (!self::isEnabled($app->settings())) {
            return 0;
        }
        $count = 0;
        foreach ($app->db()->all("SELECT email FROM {odberatele} WHERE stav = 1 AND sync <> 'ok'") as $o) {
            self::enqueue($app, (string) $o['email'], 'pridat');
            $count++;
        }

        return $count;
    }

    /** Vzdané úlohy zkusit znovu hned. */
    public static function retry(App $app): int
    {
        return $app->db()->run('UPDATE {odber_fronta} SET dalsi = NOW(), pokusy = 0 WHERE dalsi IS NULL')->rowCount();
    }

    /** Odešle úlohy, na které přišla řada (volá úklid na pozadí). @return int vyřízených */
    public static function processQueue(App $app, int $limit = 10): int
    {
        $s = $app->settings();
        if (!self::isEnabled($s)) {
            return 0;
        }
        $db = $app->db();
        $done = 0;
        foreach ($db->all('SELECT * FROM {odber_fronta} WHERE dalsi IS NOT NULL AND dalsi <= NOW() ORDER BY idf LIMIT ' . max(1, $limit)) as $u) {
            try {
                self::apply($s, (string) $u['email'], (string) $u['akce'], (string) $db->value('SELECT zdroj FROM {odberatele} WHERE email = ?', [$u['email']]));
                $db->delete('odber_fronta', ['idf' => $u['idf']]);
                $db->run("UPDATE {odberatele} SET sync = 'ok', sync_chyba = '' WHERE email = ?", [$u['email']]);
                $done++;
            } catch (\RuntimeException $e) {
                $attempts = (int) $u['pokusy'] + 1;
                $delay = self::RETRY_DELAYS[$attempts - 1] ?? null;
                $error = mb_substr($e->getMessage(), 0, 250);
                $db->update('odber_fronta', ['pokusy' => $attempts, 'chyba' => $error, 'dalsi' => $delay === null ? null : date('Y-m-d H:i:s', time() + $delay * 60)], ['idf' => $u['idf']]);
                if ($delay === null) {
                    $db->run("UPDATE {odberatele} SET sync = 'chyba', sync_chyba = ? WHERE email = ?", [$error, $u['email']]);
                }
            }
        }

        return $done;
    }

    /**
     * Jedno přidání nebo odebrání ve službě. Chyba služby = RuntimeException s kódem a začátkem odpovědi (bez klíče).
     */
    public static function apply(Settings $s, string $email, string $action, string $source = ''): void
    {
        $service = $s->get('newsletter_sluzba');
        $key = str_replace(["\r", "\n"], '', $s->get('newsletter_klic')); // klíč jde do hlavičky – bez zalomení řádku
        $items = $s->get('newsletter_seznam');
        $toAdd = $action === 'pridat';
        [$method, $url, $headers, $body, $missingOk] = match ($service) {
            'brevo' => $toAdd
                ? ['POST', 'https://api.brevo.com/v3/contacts', ['api-key: ' . $key], ['email' => $email, 'listIds' => [(int) $items], 'updateEnabled' => true], false]
                : ['POST', 'https://api.brevo.com/v3/contacts/lists/' . rawurlencode($items) . '/contacts/remove', ['api-key: ' . $key], ['emails' => [$email]], true],
            'mailerlite' => ['POST', 'https://connect.mailerlite.com/api/subscribers', ['Authorization: Bearer ' . $key],
                $toAdd ? ['email' => $email, 'groups' => [$items], 'status' => 'active'] : ['email' => $email, 'status' => 'unsubscribed'], !$toAdd],
            'mailchimp' => [$toAdd ? 'PUT' : 'PATCH', 'https://' . self::dataCenter($key) . '.api.mailchimp.com/3.0/lists/' . rawurlencode($items) . '/members/' . md5(mb_strtolower($email)),
                ['Authorization: Basic ' . base64_encode('kaleta:' . $key)], $toAdd ? ['email_address' => $email, 'status_if_new' => 'subscribed', 'status' => 'subscribed'] : ['status' => 'unsubscribed'], !$toAdd],
            'ecomail' => $toAdd
                ? ['POST', 'https://api2.ecomailapp.cz/lists/' . rawurlencode($items) . '/subscribe', ['key: ' . $key], ['subscriber_data' => ['email' => $email], 'update_existing' => true, 'resubscribe' => true, 'skip_confirmation' => true], false]
                : ['DELETE', 'https://api2.ecomailapp.cz/lists/' . rawurlencode($items) . '/unsubscribe', ['key: ' . $key], ['email' => $email], true],
            'smartemailing' => ['POST', 'https://app.smartemailing.cz/api/v3/import', ['Authorization: Basic ' . base64_encode($key)],
                ['settings' => ['update' => true, 'skip_invalid_emails' => true], 'data' => [['emailaddress' => $email, 'contactlists' => [['id' => (int) $items, 'status' => $toAdd ? 'confirmed' : 'unsubscribed']]]]], false],
            'webhook' => ['POST', $s->get('newsletter_webhook'), [], ['udalost' => $toAdd ? 'novy_odberatel' : 'odhlaseni_odberu', 'web' => $s->get('nazev_webu'), 'email' => $email,
                'zdroj' => $source, 'cas' => date('c')], false],
            default => throw new \RuntimeException('Mailingová služba není nastavená.'),
        };
        // testy: adresa služby se dá přesměrovat na místní falešný server (jen přes databázi, v administraci není)
        $test = $s->get('newsletter_test_url');
        if ($test !== '' && preg_match('#^http://127\.0\.0\.1:\d+$#', $test)) {
            $url = $test . '/' . $service . (string) parse_url($url, PHP_URL_PATH);
        }
        [$code, $response] = self::http($method, $url, $headers, $body);
        if (($code >= 200 && $code < 300) || ($missingOk && $code === 404)) {
            return; // odebrání adresy, kterou služba nezná, je v pořádku
        }
        // text chyby se ukládá k odběrateli; „Služba neodpověděla.“ přeloží administrace při zobrazení
        throw new \RuntimeException($code === 0 ? 'Služba neodpověděla.' : 'HTTP ' . $code . ($response !== '' ? ': ' . mb_substr(trim(strip_tags($response)), 0, 180) : ''));
    }

    /** Mailchimp: datové centrum je za pomlčkou v klíči (…-us21). */
    private static function dataCenter(string $key): string
    {
        return preg_match('/-([a-z]{2}\d{1,3})$/', $key, $m) ? $m[1] : 'us1';
    }

    /** @param list<string> $headers @return array{0: int, 1: string} kód odpovědi (0 = bez odpovědi) a tělo */
    private static function http(string $method, string $url, array $headers, ?array $body): array
    {
        $response = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => $method, 'timeout' => 6, 'ignore_errors' => true, 'follow_location' => 0, // služba nepřesměruje požadavek jinam
            'header' => implode("\r\n", array_merge(['Content-Type: application/json; charset=utf-8', 'Accept: application/json', 'User-Agent: Kaleta/' . KALETA_VERSION], $headers)) . "\r\n",
            'content' => $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]]));
        $code = isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) ? (int) $m[1] : 0;

        return [$code, is_string($response) ? $response : ''];
    }
}
