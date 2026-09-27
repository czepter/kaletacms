<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Přihlašovací klíče (passkeys, standard WebAuthn): otisk prstu, Face ID, Windows Hello nebo bezpečnostní klíč
 * místo opisování kódu z aplikace. Čisté PHP, bez knihoven - podpis ověřuje rozšíření openssl.
 *
 * Jak to funguje: zařízení si při registraci vytvoří pár klíčů vázaný na doménu webu. Soukromý klíč zařízení nikdy
 * neopustí, serveru pošle jen veřejný. Při přihlášení server pošle náhodnou výzvu, zařízení ji (po otisku prstu)
 * podepíše a server podpis ověří uloženým veřejným klíčem. Podvržená stránka na jiné doméně podpis nedostane.
 *
 * Co se ověřuje (WebAuthn Level 2, kap. 7.1 a 7.2) a proč:
 *  - typ odpovědi (webauthn.create / webauthn.get) - odpověď z registrace nejde použít k přihlášení a naopak,
 *  - výzva - odpověď patří k TOMUTO pokusu (proti přehrání staré odpovědi),
 *  - původ (origin) a otisk domény (rpIdHash) - odpověď vznikla na našem webu, ne na podvržené stránce,
 *  - příznak přítomnosti uživatele (UP) - někdo se zařízení opravdu dotkl,
 *  - podpis nad daty autentikátoru a otiskem údajů klienta,
 *  - počitadlo podpisů - pokud ho zařízení vede, musí růst (odhalí zkopírovaný klíč).
 *
 * Atestace (doklad o výrobci zařízení) se nevyžaduje ani neověřuje ("none"): redakční systém nepotřebuje vědět,
 * jaké zařízení uživatel má, a nechce o něm nic zjišťovat. Veřejný klíč proto dodává prohlížeč ve tvaru SPKI
 * (getPublicKey()); u klíčů ES256 se navíc kontroluje, že tentýž klíč stojí i v datech autentikátoru.
 *
 * Třída nic neukládá a nesahá do session ani do databáze - jen počítá. O výzvy, účty a uložené klíče se stará volající.
 */
final class Passkey
{
    /** Podporované algoritmy podpisu (čísla COSE): ES256 = ECDSA P-256 + SHA-256, RS256 = RSA PKCS#1 v1.5 + SHA-256. */
    public const array ALGORITHMS = [-7, -257];

    private const int FLAG_UP = 0x01; // uživatel byl přítomen
    private const int FLAG_AT = 0x40; // data obsahují nově vytvořený klíč (jen při registraci)

    /** Náhodná výzva pro jeden pokus (base64url). Volající ji uloží do session a po použití zahodí. */
    public static function challenge(): string
    {
        return self::b64(random_bytes(32));
    }

    /** Doména, na kterou se klíče vážou (rpId), z adresy webu: https://www.web.cz:8443/x -> www.web.cz */
    public static function rpId(string $siteUrl): string
    {
        return strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
    }

    /** Původ, který prohlížeč uvede v odpovědi (schéma://host[:port]), z adresy webu. */
    public static function origin(string $siteUrl): string
    {
        $c = parse_url($siteUrl);
        $port = isset($c['port']) ? ':' . $c['port'] : '';

        return strtolower(($c['scheme'] ?? 'https') . '://' . ($c['host'] ?? '')) . $port;
    }

    /**
     * Nastavení pro navigator.credentials.create().
     *
     * @param list<string> $existing id už zaregistrovaných klíčů účtu (base64url) - zařízení se nezaregistruje dvakrát
     * @return array<string, mixed>
     */
    public static function registrationOptions(string $challenge, string $rpId, string $siteName, string $userId, string $login, string $displayName, array $existing): array
    {
        return [
            'challenge' => $challenge,
            'rp' => ['id' => $rpId, 'name' => $siteName],
            'user' => ['id' => $userId, 'name' => $login, 'displayName' => $displayName !== '' ? $displayName : $login],
            'pubKeyCredParams' => array_map(static fn (int $alg): array => ['type' => 'public-key', 'alg' => $alg], self::ALGORITHMS),
            'timeout' => 120000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'discouraged', 'userVerification' => 'preferred'],
            'excludeCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => $id], $existing),
        ];
    }

    /**
     * Nastavení pro navigator.credentials.get().
     *
     * @param list<string> $allowed id klíčů účtu (base64url)
     * @return array<string, mixed>
     */
    public static function signInOptions(string $challenge, string $rpId, array $allowed): array
    {
        return [
            'challenge' => $challenge,
            'rpId' => $rpId,
            'timeout' => 120000,
            'userVerification' => 'preferred',
            'allowCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => $id], $allowed),
        ];
    }

    /**
     * Ověří odpověď z registrace a vrátí, co se má uložit.
     *
     * @param array<string, mixed> $response clientDataJSON, authenticatorData, publicKey (SPKI DER) - vše base64url; publicKeyAlgorithm
     * @return array{id:string, klic:string, alg:int, pocitadlo:int} id klíče (base64url), veřejný klíč (PEM), algoritmus, počitadlo
     * @throws \RuntimeException s důvodem odmítnutí
     */
    public static function verifyRegistration(array $response, string $challenge, string $origin, string $rpId): array
    {
        self::verifyClient((string) ($response['clientDataJSON'] ?? ''), 'webauthn.create', $challenge, $origin);
        $data = self::fromB64((string) ($response['authenticatorData'] ?? ''));
        $flags = self::verifyData($data, $rpId);
        if (($flags & self::FLAG_AT) === 0 || strlen($data) < 55) {
            throw new \RuntimeException('Odpověď neobsahuje nový klíč.');
        }
        $idLength = (ord($data[53]) << 8) | ord($data[54]);
        if ($idLength < 1 || $idLength > 1023 || strlen($data) < 55 + $idLength + 1) {
            throw new \RuntimeException('Identifikátor klíče má neplatnou délku.');
        }
        $id = substr($data, 55, $idLength);
        $rest = substr($data, 55 + $idLength); // veřejný klíč tak, jak ho zapsal autentikátor (COSE)

        $alg = (int) ($response['publicKeyAlgorithm'] ?? 0);
        if (!in_array($alg, self::ALGORITHMS, true)) {
            throw new \RuntimeException('Zařízení nabídlo algoritmus, který systém nepodporuje.');
        }
        $der = self::fromB64((string) ($response['publicKey'] ?? ''));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = $der === '' ? false : openssl_pkey_get_public($pem);
        $description = $key === false ? false : openssl_pkey_get_details($key);
        if ($description === false) {
            throw new \RuntimeException('Veřejný klíč zařízení se nepodařilo načíst.');
        }
        if ($alg === -7) {
            // ES256: křivka P-256 a tentýž bod (x, y) musí stát i v datech podepsaných autentikátorem
            $ec = $description['ec'] ?? null;
            if ($description['type'] !== OPENSSL_KEYTYPE_EC || !is_array($ec) || ($ec['curve_name'] ?? '') !== 'prime256v1'
                || !str_contains($rest, str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT)) || !str_contains($rest, str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT))) {
                throw new \RuntimeException('Veřejný klíč neodpovídá datům zařízení.');
            }
        } elseif ($description['type'] !== OPENSSL_KEYTYPE_RSA || $description['bits'] < 2048 || !str_contains($rest, (string) $description['rsa']['n'])) {
            throw new \RuntimeException('Veřejný klíč neodpovídá datům zařízení.');
        }

        return ['id' => self::b64($id), 'klic' => $pem, 'alg' => $alg, 'pocitadlo' => self::counter($data)];
    }

    /**
     * Ověří odpověď z přihlášení. Vrací nové počitadlo podpisů k uložení.
     *
     * @param array<string, mixed> $response clientDataJSON, authenticatorData, signature - vše base64url
     * @throws \RuntimeException s důvodem odmítnutí
     */
    public static function verifySignIn(array $response, string $challenge, string $origin, string $rpId, string $publicKeyPem, int $storedCounter): int
    {
        $client = self::verifyClient((string) ($response['clientDataJSON'] ?? ''), 'webauthn.get', $challenge, $origin);
        $data = self::fromB64((string) ($response['authenticatorData'] ?? ''));
        self::verifyData($data, $rpId);

        $signature = self::fromB64((string) ($response['signature'] ?? ''));
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false || $signature === '' || openssl_verify($data . hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('Podpis zařízení neplatí.');
        }
        // zařízení, která počitadlo vedou, ho musí zvyšovat; stejná nebo nižší hodnota = klíč někdo zkopíroval.
        // Synchronizované klíče (iCloud, Google) posílají trvale nulu - u nich kontrola nemá co porovnat.
        $counter = self::counter($data);
        if (($counter !== 0 || $storedCounter !== 0) && $counter <= $storedCounter) {
            throw new \RuntimeException('Počitadlo klíče se vrátilo zpět - klíč mohl být zkopírován. Odeberte ho a zaregistrujte znovu.');
        }

        return $counter;
    }

    /** Údaje klienta: typ operace, výzva a původ. Vrací surový JSON (jeho otisk je součást podepsaných dat). */
    private static function verifyClient(string $b64, string $type, string $challenge, string $origin): string
    {
        $json = self::fromB64($b64);
        $client = json_decode($json, true);
        if (!is_array($client) || ($client['type'] ?? '') !== $type) {
            throw new \RuntimeException('Odpověď zařízení má nečekaný typ.');
        }
        if ($challenge === '' || !is_string($client['challenge'] ?? null) || !hash_equals($challenge, rtrim($client['challenge'], '='))) {
            throw new \RuntimeException('Odpověď nepatří k tomuto pokusu. Zkuste to znovu.');
        }
        if (!is_string($client['origin'] ?? null) || !hash_equals($origin, strtolower($client['origin']))) {
            throw new \RuntimeException('Odpověď vznikla na jiné adrese, než je adresa webu v Nastavení.');
        }
        if (!empty($client['crossOrigin'])) {
            throw new \RuntimeException('Odpověď vznikla ve vloženém okně cizí stránky.');
        }

        return $json;
    }

    /** Data autentikátoru: otisk domény a příznak přítomnosti uživatele. Vrací bajt příznaků. */
    private static function verifyData(string $data, string $rpId): int
    {
        if (strlen($data) < 37) {
            throw new \RuntimeException('Data zařízení jsou neúplná.');
        }
        if ($rpId === '' || !hash_equals(hash('sha256', $rpId, true), substr($data, 0, 32))) {
            throw new \RuntimeException('Klíč patří k jiné doméně.');
        }
        $flags = ord($data[32]);
        if (($flags & self::FLAG_UP) === 0) {
            throw new \RuntimeException('Zařízení nepotvrdilo přítomnost uživatele.');
        }

        return $flags;
    }

    private static function counter(string $data): int
    {
        return (int) (unpack('N', substr($data, 33, 4))[1] ?? 0);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function fromB64(string $text): string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $text) !== 1) {
            return '';
        }

        return (string) base64_decode(strtr(rtrim($text, '='), '-_', '+/'), true);
    }
}
