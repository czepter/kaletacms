<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Odesílání e-mailů: buď funkcí mail() serveru, nebo přes vlastní SMTP server (Nastavení → Pošta).
 *
 * SMTP je spolehlivější - zprávy odcházejí z ověřené schránky (SPF, DKIM) a nekončí ve spamu. Klient je
 * záměrně malý a bez knihoven: STARTTLS nebo SSL, přihlášení AUTH LOGIN/PLAIN, jedno spojení se při
 * frontě pošty používá opakovaně.
 */
final class Mail
{
    /** Text poslední chyby (pro zkušební e-mail a Stav systému). */
    public static string $error = '';

    /** @var resource|null otevřené SMTP spojení */
    private static $connection = null;

    /** Za jak dlouho se nepovedené odeslání zkusí znovu (minuty); po posledním pokusu zpráva zůstane ve frontě jako chybná. */
    private const array RETRY_DELAYS = [5, 30, 120, 720];

    /**
     * Odešle zprávu hned. Když to nejde (výpadek SMTP), uloží ji do fronty a zkusí to později znovu - potvrzení
     * registrace nebo nové heslo se tak neztratí. Každá zpráva má záznam v protokolu (Nastavení → Pošta).
     *
     * @param array<string, string> $headers další hlavičky (např. List-Unsubscribe)
     * @param bool $queueOnFailure false = jednorázová zpráva, která se při chybě neopakuje (zkušební e-mail)
     */
    public static function send(Settings $siteSettings, string $recipient, string $subject, string $text, string $html = '', array $headers = [], bool $queueOnFailure = true): bool
    {
        $ok = self::deliver($siteSettings, $recipient, $subject, $text, $html, $headers);
        $error = self::$error;
        try {
            $siteSettings->db()->insert('posta', [
                'komu' => mb_substr($recipient, 0, 190), 'predmet' => mb_substr($subject, 0, 255), 'vytvoreno' => date('Y-m-d H:i:s'), 'pokusu' => 1,
                'odeslano' => $ok ? date('Y-m-d H:i:s') : null, 'chyba' => mb_substr($error, 0, 255),
                'telo' => $ok || !$queueOnFailure ? null : json_encode(['text' => $text, 'html' => $html, 'hlavicky' => $headers], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'dalsi_pokus' => $ok || !$queueOnFailure ? null : date('Y-m-d H:i:s', time() + self::RETRY_DELAYS[0] * 60),
            ]);
            if (random_int(1, 50) === 1) {
                $siteSettings->db()->run('DELETE FROM {posta} WHERE vytvoreno < NOW() - INTERVAL 30 DAY');
            }
        } catch (\Throwable) {
            // protokol pošty nesmí shodit odeslání (např. před provedením migrace tabulka ještě není)
        }
        self::$error = $error;

        return $ok;
    }

    /** Další pokus o zprávy čekající ve frontě; volá se z úloh na pozadí. Vrací počet odeslaných. */
    public static function processQueue(Settings $siteSettings, int $maxCount = 10): int
    {
        $db = $siteSettings->db();
        $sent = 0;
        foreach ($db->all('SELECT * FROM {posta} WHERE odeslano IS NULL AND telo IS NOT NULL AND dalsi_pokus <= NOW() ORDER BY idp LIMIT ' . max(1, $maxCount)) as $z) {
            $body = json_decode((string) $z['telo'], true) ?: [];
            $attempt = (int) $z['pokusu'] + 1;
            // nejdřív posunout další pokus: souběžný požadavek tak stejnou zprávu neodešle podruhé
            $db->update('posta', ['pokusu' => $attempt, 'dalsi_pokus' => date('Y-m-d H:i:s', time() + (self::RETRY_DELAYS[$attempt - 1] ?? 0) * 60)], ['idp' => $z['idp']]);
            if (self::deliver($siteSettings, $z['komu'], $z['predmet'], (string) ($body['text'] ?? ''), (string) ($body['html'] ?? ''), (array) ($body['hlavicky'] ?? []))) {
                $db->update('posta', ['odeslano' => date('Y-m-d H:i:s'), 'telo' => null, 'dalsi_pokus' => null, 'chyba' => ''], ['idp' => $z['idp']]);
                $sent++;
            } else {
                $end = !isset(self::RETRY_DELAYS[$attempt - 1]);
                $db->update('posta', ['chyba' => mb_substr(self::$error, 0, 255)] + ($end ? ['telo' => null, 'dalsi_pokus' => null] : []), ['idp' => $z['idp']]);
            }
        }

        return $sent;
    }

    /** @param array<string, string> $headers */
    private static function deliver(Settings $siteSettings, string $recipient, string $subject, string $text, string $html = '', array $headers = []): bool
    {
        self::$error = '';
        $from = $siteSettings->get('posta_od') !== '' ? $siteSettings->get('posta_od') : $siteSettings->get('email_webu');
        if ($from === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\x00-\x20\x7F"<>]/', $recipient)) {
            self::$error = $from === '' ? 'Není vyplněný e-mail webu (Nastavení → Základní) ani adresa odesílatele.' : 'Adresa příjemce nemá platný tvar.';

            return false;
        }
        $displayName = '=?UTF-8?B?' . base64_encode($siteSettings->get('nazev_webu')) . '?=';
        $h = ['From' => "{$displayName} <{$from}>", 'MIME-Version' => '1.0'] + $headers;
        if ($siteSettings->get('posta_odpoved') !== '') {
            $h['Reply-To'] = $siteSettings->get('posta_odpoved');
        }
        if ($html === '') {
            $h['Content-Type'] = 'text/plain; charset=utf-8';
            $h['Content-Transfer-Encoding'] = 'base64';
            $body = chunk_split(base64_encode($text));
        } else {
            $boundary = 'kaleta-' . bin2hex(random_bytes(8));
            $h['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
                . "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--{$boundary}--\r\n";
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $rows = [];
        foreach ($h as $name => $value) {
            $rows[] = $name . ': ' . str_replace(["\r", "\n"], '', $value); // hlavičky nesmí jít rozdělit vloženým koncem řádku
        }

        if ($siteSettings->get('posta_rezim') === 'smtp' && $siteSettings->get('smtp_host') !== '') {
            try {
                self::smtp($siteSettings, $from, $recipient, array_merge(['Date: ' . date('r'), 'To: ' . $recipient, 'Subject: ' . $encodedSubject,
                    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr((string) strrchr($from, '@'), 1) ?: 'localhost') . '>'], $rows), $body);

                return true;
            } catch (\RuntimeException $e) {
                self::$error = $e->getMessage();
                self::close();

                return false;
            }
        }
        if (!function_exists('mail')) {
            self::$error = 'Funkce mail() je na serveru vypnutá – nastavte odesílání přes SMTP.';

            return false;
        }
        $ok = @mail($recipient, $encodedSubject, $body, implode("\r\n", $rows));
        if (!$ok) {
            self::$error = 'Server zprávu odmítl odeslat (funkce mail() selhala).';
        }

        return $ok;
    }

    /** @param list<string> $headers */
    private static function smtp(Settings $siteSettings, string $from, string $recipient, array $headers, string $body): void
    {
        if (!is_resource(self::$connection)) {
            self::connect($siteSettings);
        } else {
            self::statement('RSET', [250]);
        }
        self::statement('MAIL FROM:<' . $from . '>', [250]);
        self::statement('RCPT TO:<' . $recipient . '>', [250, 251]);
        self::statement('DATA', [354]);
        // řádek začínající tečkou se zdvojuje, samotná tečka ukončuje zprávu
        $message = preg_replace('/^\./m', '..', implode("\r\n", $headers) . "\r\n\r\n" . $body) ?? '';
        self::statement(rtrim($message, "\r\n") . "\r\n.", [250]);
    }

    private static function connect(Settings $siteSettings): void
    {
        $host = $siteSettings->get('smtp_host');
        $encryption = $siteSettings->get('smtp_sifrovani');
        $port = $siteSettings->int('smtp_port') ?: ($encryption === 'ssl' ? 465 : 587);
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new \RuntimeException('Adresa SMTP serveru nemá platný tvar.');
        }
        $connection = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $number, $error, 10);
        if ($connection === false) {
            throw new \RuntimeException("K SMTP serveru {$host}:{$port} se nepodařilo připojit" . ($error !== '' ? " ({$error})" : '') . '. Zkontrolujte adresu, port a šifrování; některé hostingy odchozí SMTP blokují.');
        }
        stream_set_timeout($connection, 15);
        self::$connection = $connection;
        self::response([220]);
        $me = 'EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost');
        $options = self::statement($me, [250]);
        if ($encryption === 'tls') {
            self::statement('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new \RuntimeException('Šifrované spojení (STARTTLS) se nepodařilo navázat – server má nejspíš neplatný certifikát.');
            }
            $options = self::statement($me, [250]);
        }
        if ($siteSettings->get('smtp_uzivatel') !== '') {
            try {
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $options)) {
                    self::statement('AUTH PLAIN ' . base64_encode("\0" . $siteSettings->get('smtp_uzivatel') . "\0" . $siteSettings->get('smtp_heslo')), [235], true);
                } else {
                    self::statement('AUTH LOGIN', [334]);
                    self::statement(base64_encode($siteSettings->get('smtp_uzivatel')), [334], true);
                    self::statement(base64_encode($siteSettings->get('smtp_heslo')), [235], true);
                }
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('SMTP server odmítl přihlášení – zkontrolujte jméno a heslo (u Gmailu a Seznamu je potřeba „heslo pro aplikace“). ' . $e->getMessage());
            }
        }
        register_shutdown_function(self::close(...));
    }

    /** @param list<int> $expected */
    private static function statement(string $statement, array $expected, bool $secret = false): string
    {
        if (@fwrite(self::$connection, $statement . "\r\n") === false) {
            throw new \RuntimeException('Spojení se SMTP serverem se přerušilo.');
        }

        return self::response($expected, $secret ? '(přihlašovací údaje)' : strtok($statement, "\r\n "));
    }

    /** @param list<int> $expected */
    private static function response(array $expected, string $commandName = 'připojení'): string
    {
        $response = '';
        do {
            $row = fgets(self::$connection, 1024);
            if ($row === false) {
                throw new \RuntimeException('SMTP server neodpověděl včas.');
            }
            $response .= $row;
        } while (isset($row[3]) && $row[3] === '-');
        if (!in_array((int) substr($response, 0, 3), $expected, true)) {
            throw new \RuntimeException('Odpověď SMTP serveru na ' . $commandName . ': ' . mb_substr(trim($response), 0, 200));
        }

        return $response;
    }

    public static function close(): void
    {
        if (is_resource(self::$connection)) {
            @fwrite(self::$connection, "QUIT\r\n");
            @fclose(self::$connection);
        }
        self::$connection = null;
    }
}
