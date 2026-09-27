<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Admin\ChangeLog;
use Kaleta\Core\App;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;

/**
 * MCP server (Model Context Protocol, přenos "Streamable HTTP") na adrese /mcp.
 * Přes něj umí Claude pracovat s webem: číst a psát stránky a novinky, spravovat kategorie, kolekce, části webu a vzhled.
 *
 * Přihlášení: hlavička "Authorization: Bearer <token>" – osobní token z nabídky Můj účet, nebo token aplikace připojené
 * přes OAuth (konektor v Claudu, Front\OAuth).
 * Claude pak jedná s právy tohoto uživatele (autor / redaktor / administrátor). Rozšíření je ve výchozím stavu vypnuté.
 */
final class Server
{
    private const string PROTOCOL = '2025-03-26';

    public function __construct(private readonly App $app)
    {
    }

    public function handle(): Response
    {
        $r = $this->app->request;
        if (!Extensions::isEnabled($this->app->settings(), 'claude')) {
            return Response::json(['chyba' => 'Napojení na Claude je vypnuté (nabídka Rozšíření).'], 404);
        }
        $isLocal = in_array((string) parse_url($r->origin(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        if (!$r->isHttps() && !$isLocal) {
            return Response::json(['chyba' => 'MCP je dostupné jen přes HTTPS.'], 403);
        }
        if (!$r->isPost()) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $user = $this->user();
        if ($user === null) {
            // odkaz na metadata OAuth: podle nich se konektor Claude sám zaregistruje a požádá uživatele o souhlas
            return new Response(json_encode(['chyba' => 'Neplatný nebo chybějící token.']), 401, ['Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer resource_metadata="' . (new \Kaleta\Front\OAuth($this->app))->metadataUrl() . '"']);
        }
        $this->app->auth()->signInAs($user);
        if ($this->app->auth()->isMissingRequired2fa($this->app->settings())) {
            return Response::json(['chyba' => 'Web vyžaduje dvoufázové přihlášení. Zapněte si ho v administraci v Můj účet – do té doby napojení nefunguje.'], 403);
        }

        $message = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($message)) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Neplatný JSON.']], 400);
        }
        // dávka zpráv i jediná zpráva
        $batch = array_is_list($message) ? $message : [$message];
        if (count($batch) > 50) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Dávka má nejvýš 50 zpráv.']], 400);
        }
        $responses = array_values(array_filter(array_map($this->process(...), $batch)));
        if ($responses === []) {
            return new Response('', 202);
        }

        return Response::json(array_is_list($message) ? $responses : $responses[0]);
    }

    /** @param array<string, mixed> $z @return array<string, mixed>|null null = oznámení bez odpovědi */
    private function process(array $z): ?array
    {
        $id = $z['id'] ?? null;
        $method = (string) ($z['method'] ?? '');
        if ($id === null) {
            return null;
        }
        $ok = fn (array $result): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        $tools = new Tools($this->app);

        return match ($method) {
            'initialize' => $ok([
                'protocolVersion' => is_string($z['params']['protocolVersion'] ?? null) ? $z['params']['protocolVersion'] : self::PROTOCOL,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'Kaleta – ' . $this->app->settings()->get('nazev_webu'), 'version' => KALETA_VERSION],
                'instructions' => Translator::instructions(),
            ]),
            'ping' => $ok([]),
            'tools/list' => $ok(['tools' => Translator::listAll($tools->listAll())]), // české názvy zůstávají skrytými aliasy
            'tools/call' => $ok($this->call($tools, (string) ($z['params']['name'] ?? ''), (array) ($z['params']['arguments'] ?? []))),
            default => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Neznámá metoda: ' . $method]],
        };
    }

    /**
     * Volání nástroje. Anglický název (tools/list) se přeloží na český nástroj a zpět (Anglicky); český název je skrytý
     * alias pro napojení z doby před 1.1 a chová se jako dřív.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(Tools $tools, string $name, array $arguments): array
    {
        $czech = Translator::czech($name);
        $isEnglish = $czech !== null || !in_array($name, $tools->names(), true);
        try {
            $items = $czech !== null ? Translator::listAll($tools->listAll()) : $tools->listAll();
            $arguments = self::extractJson($items, $name, $arguments);
            $unknownParams = self::unknownParams($items, $name, $arguments);
            if ($czech !== null) {
                $arguments = Translator::arguments($name, $arguments);
            }
            $result = $tools->call($czech ?? $name, $arguments);
            if ($tools->isWriteTool($czech ?? $name)) {
                ChangeLog::write($this->app, 'claude', $czech ?? $name, mb_substr((string) ($arguments['titulek'] ?? $arguments['nazev'] ?? $arguments['sablona'] ?? $arguments['id'] ?? ''), 0, 200));
                \Kaleta\Front\Cache::clear();
            }
            if ($unknownParams !== [] && is_array($result) && !array_is_list($result)) {
                // překlep v názvu parametru by se jinak ztratil beze stopy (nástroj ho nezná, a tak ho vynechá)
                $result['nezname_parametry'] = $unknownParams;
            }
            if ($czech !== null) {
                $result = Translator::result($name, $result);
            }

            return ['content' => [['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
        } catch (\InvalidArgumentException | \DomainException $e) {
            return ['content' => [['type' => 'text', 'text' => $isEnglish ? Translator::message($e->getMessage()) : $e->getMessage()]], 'isError' => true];
        }
    }

    /**
     * Objekt nebo pole poslané jako text JSON (klient bez schématu nástroje, některé proxy) se rozbalí podle typu
     * parametru ve schématu – jinak by ho nástroj nepoznal a hodnoty potichu vynechal.
     *
     * @param list<array<string, mixed>> $items definice nástrojů (tools/list)
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function extractJson(array $items, string $name, array $arguments): array
    {
        foreach ($items as $tool) {
            if (($tool['name'] ?? '') !== $name) {
                continue;
            }
            $properties = (array) ($tool['inputSchema']['properties'] ?? []);
            foreach ($arguments as $key => $value) {
                // typ může být i výčet, např. ["array", "null"] u položek menu
                $types = (array) ($properties[$key]['type'] ?? []);
                // logická hodnota poslaná jako text: „false“ by v PHP byla pravda (skrytá stránka by se zveřejnila)
                if (is_string($value) && in_array('boolean', $types, true) && in_array(strtolower(trim($value)), ['true', 'false', '1', '0', ''], true)) {
                    $arguments[$key] = in_array(strtolower(trim($value)), ['true', '1'], true);
                    continue;
                }
                if (!is_string($value) || !array_intersect($types, ['object', 'array']) || !preg_match('/^\s*[\[{]/', $value)) {
                    continue;
                }
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $kind = $decoded === [] ? null : (array_is_list($decoded) ? 'array' : 'object'); // [] i {} sedí na oba typy
                if ($kind === null || in_array($kind, $types, true)) {
                    $arguments[$key] = $decoded;
                }
            }
            break;
        }

        return $arguments;
    }

    /**
     * Parametry, které nástroj ve schématu nemá – vrátí se ve výsledku, ať volající ví, že se nepoužily.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public static function unknownParams(array $items, string $name, array $arguments): array
    {
        foreach ($items as $tool) {
            if (($tool['name'] ?? '') === $name) {
                return array_values(array_diff(array_map('strval', array_keys($arguments)), array_keys((array) ($tool['inputSchema']['properties'] ?? []))));
            }
        }

        return [];
    }

    /** @return array<string, mixed>|null uživatel podle tokenu */
    private function user(): ?array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $db = $this->app->db();
        $ip = substr(hash('sha256', 'kaleta|' . $this->app->request->ip()), 0, 40);
        if ((int) $db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'mcp' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [$ip]) >= 20) {
            return null;
        }
        // osobní token z Můj účet (kaleta_…) nebo přístupový token aplikace připojené přes OAuth (kaleta_oa_…, platí hodinu)
        if (!preg_match('/^Bearer\s+(kaleta_(?:oa_)?[a-f0-9]{48})$/', $header, $m)) {
            return null;
        }
        $token = $db->one("SELECT t.idt, u.* FROM {api_tokeny} t JOIN {uzivatele} u ON u.idu = t.idu WHERE t.otisk = ? AND u.blokovat = 0 AND t.druh <> 'obnova' AND (t.expirace IS NULL OR t.expirace > ?)",
            [hash('sha256', $m[1]), date('Y-m-d H:i:s')]);
        if ($token === null) {
            $db->insert('kontrola_ip', ['ip_adresa' => $ip, 'typ' => 'mcp', 'cas' => date('Y-m-d H:i:s')]);

            return null;
        }
        $db->run('UPDATE {api_tokeny} SET pouzit = NOW() WHERE idt = ?', [$token['idt']]);

        return $token;
    }
}
