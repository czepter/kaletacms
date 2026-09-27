<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Mail;
use Kaleta\Core\Response;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\Components;
use Kaleta\Stavitel\Prvky;
use Kaleta\Builder\Elements\Form;
use Kaleta\Builder\Build;

/**
 * Odeslání formuláře z builderu (POST /formular). Pole a příjemce bere z PUBLIKOVANÉ stavby podle zdroje a id prvku –
 * návštěvník nemůže přidat pole ani změnit adresáta. Výsledek: poptávka v ka_poptavky, upozornění e-mailem a návrat
 * na stránku s kódem výsledku (?formular=<id>&vysledek=ok|pole|limit|rychle|overeni).
 */
final class Forms
{
    /** Kolik zpráv smí jedna IP adresa odeslat za 10 minut. */
    private const int LIMIT = 5;

    public function __construct(private readonly App $app)
    {
    }

    public function process(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $source = $r->post('zdroj');
        $back = $r->post('zpet');
        $back = preg_match('#^/[^\s\\\\]*$#', $back) && !str_starts_with($back, '//') ? $back : $this->app->url('');
        $element = $this->element($source, $r->post('prvek'));
        if ($element === null) {
            return Response::redirect($back, 303);
        }
        $redirectUri = fn (string $result, int $field = -1): Response => Response::redirect($back . '?formular=' . rawurlencode($element['id']) . '&vysledek=' . $result . ($field >= 0 ? '&pole=' . $field : '') . '#' . Form::anchor($element), 303);

        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $reason = $antispam->reason($r, 'formular|' . $source . '|' . $element['id']);
        if ($reason === 'robot') {
            return $redirectUri('ok'); // robot se nedozví, že neprošel
        }
        if ($reason !== null) {
            // příliš rychlé odeslání (automatické vyplnění) má vlastní hlášení: stačí chvilku počkat, obnovovat stránku netřeba
            return $redirectUri($reason === 'rychle' ? 'rychle' : 'overeni');
        }
        if ($antispam->count($r->ip(), 'formular', 0, 10) >= self::LIMIT) {
            return $redirectUri('limit');
        }

        $data = [];
        $email = '';
        $attachments = [];
        foreach ($element['obsah']['pole'] as $i => $field) {
            if ($field['typ'] === 'soubor') {
                $file = $_FILES['p' . $i] ?? null;
                $uploaded = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name']);
                $extension = $uploaded ? strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) : '';
                if ($uploaded && (!in_array($extension, Form::ATTACHMENT_EXTENSIONS, true) || (int) $file['size'] > Form::MAX_ATTACHMENT)) {
                    return $redirectUri('pole', $i);
                }
                if (!$uploaded && $field['povinne']) {
                    return $redirectUri('pole', $i);
                }
                $data[] = [$field['popisek'], $uploaded ? mb_substr(basename((string) $file['name']), 0, 120) . ' (' . \Kaleta\Core\Files::size((int) $file['size']) . ')' : ''];
                if ($uploaded) {
                    $attachments[count($data) - 1] = [(string) $file['tmp_name'], $extension];
                }
                continue;
            }
            $value = trim(str_replace("\r\n", "\n", $r->post('p' . $i)));
            $value = match ($field['typ']) {
                'textarea' => mb_substr($value, 0, 5000),
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($value, 0, 190) : ($value === '' ? '' : null),
                'tel' => $value === '' || preg_match('/^[+()\d\s\/.-]{6,30}$/', $value) ? $value : null,
                'vyber', 'volba' => $value === '' || in_array($value, Form::options($field), true) ? $value : null,
                'datum' => $value === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) ? $value : null,
                'cislo' => $value === '' || preg_match('/^-?\d{1,12}([.,]\d{1,6})?$/', $value) ? $value : null,
                'souhlas' => $value === '1' ? t('ano') : '',
                default => mb_substr(str_replace("\n", ' ', $value), 0, 300),
            };
            if ($value === null || ($field['povinne'] && $value === '')) {
                return $redirectUri('pole', $i);
            }
            if ($field['typ'] === 'email' && $email === '') {
                $email = $value;
            }
            $data[] = [$field['popisek'], $value];
        }
        $antispam->write($r->ip(), 'formular', 0);
        // přílohy mimo veřejné složky (storage/ je z webu nepřístupné); stáhne je jen přihlášený v Poptávkách
        foreach ($attachments as $index => [$tmp, $extension]) {
            $path = date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
            $target = KALETA_ROOT . '/storage/prilohy/' . $path;
            if ((is_dir(dirname($target)) || mkdir(dirname($target), 0775, true)) && move_uploaded_file($tmp, $target)) {
                $data[$index][2] = $path;
            }
        }

        $db = $this->app->db();
        $campaign = self::campaign($r->referer(), $r->origin());
        $idp = $db->insert('poptavky', [
            'datum' => date('Y-m-d H:i:s'), 'formular' => mb_substr((string) $element['obsah']['nazev'], 0, 120), 'zdroj' => $source, 'prvek' => $element['id'],
            'stranka' => mb_substr($back, 0, 255), 'kampan' => $campaign, 'email' => $email, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'stav' => 0,
        ]);
        $this->notify($idp, $element, $data, $email, $campaign);
        \Kaleta\Core\Webhook::enquiryReceived($this->app, $idp, (string) $element['obsah']['nazev'], $data, $email, $back, $campaign);
        if (!empty($element['obsah']['potvrzeni']) && $email !== '') {
            // potvrzení odesílateli: jen poděkování a název formuláře – obsah zprávy ne, aby formulář nešel zneužít k rozesílání cizích textů
            $siteSettings = $this->app->settings();
            Mail::send($siteSettings, $email, t('Potvrzení: %s', $siteSettings->get('nazev_webu')), $element['obsah']['dekujeme'] . "\n\n—\n" . $siteSettings->get('nazev_webu') . "\n" . rtrim($siteSettings->get('adresa_webu') ?: $r->origin(), '/'), '');
        }
        $thankYouUrl = (string) ($element['obsah']['dekovna'] ?? '');
        // „/\cizi.cz“ prohlížeč chápe jako //cizi.cz – zpětné lomítko v adrese děkovné stránky neprojde
        if ($thankYouUrl !== '' && !str_contains($thankYouUrl, '\\') && (str_starts_with($thankYouUrl, '/') && !str_starts_with($thankYouUrl, '//') || preg_match('#^https://#', $thankYouUrl))) {
            // adresa na webu je celá cesta (i s jazykem, /en/…), jen se doplní složka instalace
            // ?odeslano=<název> na děkovné stránce ohlásí konverzi měření (image/web.js), stejně jako poděkování na místě
            $thankYouUrl = (str_starts_with($thankYouUrl, '/') ? $r->basePath() . $thankYouUrl : $thankYouUrl);
            $thankYouUrl .= (str_contains($thankYouUrl, '?') ? '&' : '?') . 'odeslano=' . rawurlencode((string) $element['obsah']['nazev']);

            return Response::redirect($thankYouUrl, 303);
        }

        return $redirectUri('ok');
    }

    /** Formulář z publikované stavby stránky nebo části webu. @return array<string, mixed>|null */
    private function element(string $source, string $id): ?array
    {
        $db = $this->app->db();
        $build = match (true) {
            (bool) preg_match('/^stranka:(\d+)$/', $source, $m) => Build::fromJson($db->value('SELECT stavba FROM {stranky} WHERE ids = ? AND zobrazit = 1', [(int) $m[1]])),
            (bool) preg_match('/^cast:([a-z]+):([a-z]{0,2})(?::([a-z0-9-]{1,40}))?$/', $source, $m) && isset(SiteParts::TYPES[$m[1]]) => SiteParts::build($db, $m[1], $m[2], false, $m[3] ?? ''),
            (bool) preg_match('/^kolekce:(\d+)$/', $source, $m) => Build::fromJson($db->value('SELECT stavba FROM {kolekce} WHERE idk = ? AND detail = 1', [(int) $m[1]])),
            (bool) preg_match('/^popup:(\d+)$/', $source, $m) => Build::fromJson($db->value('SELECT stavba FROM {popupy} WHERE idpp = ? AND aktivni = 1', [(int) $m[1]])),
            default => null,
        };
        // formulář může být i uvnitř komponenty (její publikovaná stavba); hloubka jako při vykreslení
        $find = function (array $children, array $nesting = []) use (&$find, $id, $db): ?array {
            foreach ($children as $p) {
                if (($p['id'] ?? '') === $id) {
                    return ($p['typ'] ?? '') === Form::TYPE ? $p : null;
                }
                if (($found = $find($p['deti'] ?? [], $nesting)) !== null) {
                    return $found;
                }
                $idm = ($p['typ'] ?? '') === \Kaleta\Builder\Elements\Component::TYPE ? (int) ($p['obsah']['komponenta'] ?? 0) : 0;
                if ($idm > 0 && !in_array($idm, $nesting, true) && count($nesting) < Components::MAX_NESTING) {
                    $component = Components::byId($db, $idm);
                    $inner = $component === null ? null : Build::fromJson($component['stavba'] ?? $component['stavba_koncept']);
                    if ($inner !== null && ($found = $find($inner['deti'] ?? [], [...$nesting, $idm])) !== null) {
                        return $found;
                    }
                }
            }

            return null;
        };

        return $build === null || $id === '' ? null : $find($build['deti'] ?? []);
    }

    /** @param list<array{0:string, 1:string}> $data */
    public const array UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Kampaň z adresy stránky s formulářem (hlavička Referer odeslání): jen parametry utm_*, jen z vlastního webu.
     * Bez cookies a bez ukládání v prohlížeči – kampaň se zapíše, když je formulář přímo na stránce, na kterou reklama vede.
     */
    public static function campaign(string $referer, string $origin): string
    {
        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '' || $host !== strtolower((string) parse_url($origin, PHP_URL_HOST))) {
            return '';
        }
        parse_str((string) parse_url($referer, PHP_URL_QUERY), $query);
        $utm = [];
        foreach (self::UTM as $key) {
            $value = is_string($query[$key] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query[$key])) : '';
            if ($value !== '') {
                $utm[$key] = mb_substr($value, 0, 80);
            }
        }
        $campaign = http_build_query($utm);

        return strlen($campaign) <= 255 ? $campaign : '';
    }

    /** Kampaň pro člověka: „google / cpc / jarni-akce“ (zdroj / médium / kampaň, případně klíčové slovo a obsah). */
    public static function campaignText(string $campaign): string
    {
        parse_str($campaign, $utm);

        return implode(' / ', array_filter(array_map(fn (string $k): string => is_string($utm[$k] ?? null) ? $utm[$k] : '', self::UTM), fn (string $h): bool => $h !== ''));
    }

    private function notify(int $idp, array $element, array $data, string $email, string $campaign): void
    {
        $siteSettings = $this->app->settings();
        $recipient = filter_var($element['obsah']['prijemce'], FILTER_VALIDATE_EMAIL) !== false ? $element['obsah']['prijemce'] : $siteSettings->get('email_webu');
        if ($recipient === '') {
            return; // poptávka je uložená v administraci i bez e-mailu
        }
        $url = rtrim($siteSettings->get('adresa_webu') !== '' ? $siteSettings->get('adresa_webu') : $this->app->request->origin(), '/');
        $text = implode("\n\n", array_map(fn (array $d): string => $d[0] . ":\n" . $d[1], $data))
            . ($campaign !== '' ? "\n\n" . t('Kampaň') . ":\n" . self::campaignText($campaign) : '')
            . "\n\n—\n" . t('Poptávka v administraci: %s', $url . $this->app->url('admin.php?modul=poptavky&akce=detail&id=' . $idp));
        Mail::send($siteSettings, $recipient, t('%s: %s', $element['obsah']['nazev'], $siteSettings->get('nazev_webu')), $text, '', $email !== '' ? ['Reply-To' => $email] : []);
    }
}
