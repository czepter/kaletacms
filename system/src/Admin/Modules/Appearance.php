<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Images;
use Kaleta\Core\Response;
use Kaleta\Front\Layouts;
use Kaleta\Builder\DesignSystem;

/**
 * Vzhled webu: šablona, logo a design systém (barvy, písma, velikosti, šířka, zaoblení) s živým náhledem úvodní stránky.
 * Z design systému berou tokeny šablona i builder, takže změna tady přebarví celý web.
 */
final class Appearance extends Module
{
    public const string IDENT = 'vzhled';
    public const string NAME = 'Vzhled webu';
    public const string GROUP = 'Vzhled';
    public const string ICON = 'identita';
    public const bool ADMIN_ONLY = true;

    protected function akceVypis(): Response
    {
        $siteSettings = $this->app->settings();
        $ds = DesignSystem::load($siteSettings);

        return $this->view('vypis', 'Vzhled webu', [
            'layouty' => Layouts::listAll(),
            'ds' => $ds,
            'kontrasty' => DesignSystem::contrasts($ds),
            'predvolby' => array_map(fn (string $k): array => ['nazev' => DesignSystem::PRESETS[$k][0], 'popis' => DesignSystem::PRESETS[$k][1], 'ds' => DesignSystem::preset($k)], array_combine(array_keys(DesignSystem::PRESETS), array_keys(DesignSystem::PRESETS))),
            'hodnoty' => ['layout' => $siteSettings->get('layout'), 'logo_webu' => $siteSettings->get('logo_webu'), 'favicon' => $siteSettings->get('favicon'), 'tmavy_rezim' => $siteSettings->get('tmavy_rezim'), 'tmavy_prepinac' => $siteSettings->get('tmavy_prepinac'), 'nazev_webu' => $siteSettings->get('nazev_webu')],
        ]);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $siteSettings = $this->app->settings();
        if (isset(Layouts::listAll()[$r->post('layout')])) {
            $siteSettings->set('layout', $r->post('layout'));
        }
        $siteSettings->set('logo_webu', mb_substr($r->post('logo_webu'), 0, 255));
        $icon = mb_substr($r->post('favicon'), 0, 255);
        if ($icon !== $siteSettings->get('favicon') || ($icon !== '' && !is_file(KALETA_ROOT . '/media/ikona-180.png'))) {
            // ikony pro telefony a instalaci webu se připraví z ikony jednou při uložení
            $ok = $icon !== '' && preg_match('#^/?(?:[A-Za-z0-9_.-]+/){0,3}(media/[A-Za-z0-9/_.-]+)$#', $icon, $m) && !str_contains($m[1], '..') && Images::icons(KALETA_ROOT . '/' . $m[1]);
            if (!$ok) {
                array_map(fn (int $n): bool => @unlink(KALETA_ROOT . '/media/ikona-' . $n . '.png'), Images::ICON_SIZES);
            }
        }
        $siteSettings->set('favicon', $icon);
        $siteSettings->set('tmavy_rezim', in_array($r->post('tmavy_rezim'), ['auto', 'tmavy'], true) ? $r->post('tmavy_rezim') : 'vypnuto');
        $siteSettings->set('tmavy_prepinac', $r->postBool('tmavy_prepinac') ? '1' : '0');
        $siteSettings->set('design_system', (string) json_encode($this->parseForm(), JSON_UNESCAPED_SLASHES));
        $siteSettings->set('vzhled_ulozen', '1'); // první kroky: vzhled zvolil správce, ne startovací web
        // starší klíče Identity: od uložení design systému se nečtou, ať nemate export ani jiné nástroje
        $siteSettings->set('brand_akcent', '');
        $siteSettings->set('brand_pismo_titulky', 'vychozi');
        $siteSettings->set('brand_pismo_text', 'vychozi');
        \Kaleta\Front\Cache::clear();

        return $this->back('Vzhled webu byl uložen.');
    }

    /** Design tokeny ke stažení ve formátu DTCG (Figma, Tokens Studio, Style Dictionary). */
    protected function akceTokeny(): Response
    {
        $json = (string) json_encode(DesignSystem::toDtcg(DesignSystem::load($this->app->settings())), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="tokeny-' . date('Y-m-d') . '.tokens.json"']);
    }

    /** Import tokenů DTCG: z exportu Kalety celý vzhled, z jiného nástroje barvy. */
    protected function akceTokenyImport(): Response
    {
        $file = $_FILES['tokeny'] ?? null;
        $content = $this->request->isPost() && is_array($file) && ($file['error'] ?? 1) === UPLOAD_ERR_OK && (int) $file['size'] < 1_000_000 ? (string) file_get_contents((string) $file['tmp_name']) : '';
        $tokens = json_decode($content, true);
        $siteSettings = $this->app->settings();
        $ds = is_array($tokens) ? DesignSystem::fromDtcg($tokens, DesignSystem::load($siteSettings)) : null;
        if ($ds === null) {
            return $this->back('Soubor neobsahuje design tokeny, které by šly použít (čekáme JSON ve formátu DTCG).', '', [], 'chyba');
        }
        $siteSettings->set('design_system', (string) json_encode($ds, JSON_UNESCAPED_SLASHES));
        $siteSettings->set('vzhled_ulozen', '1');
        \Kaleta\Front\Cache::clear();

        return $this->back('Design tokeny byly načteny.');
    }

    /** Živý náhled: CSS tokenů a kontrola čitelnosti pro rozpracovaný formulář (JSON). Nic neukládá. */
    protected function akceNahled(): Response
    {
        $ds = $this->parseForm();

        return Response::json(['css' => DesignSystem::css($ds, $this->app->request->basePath()), 'kontrasty' => array_map(fn (array $k): array => ['popis' => t($k['popis'])] + $k, DesignSystem::contrasts($ds))]);
    }

    /** @return array<string, mixed> */
    private function parseForm(): array
    {
        $ds = is_array($_POST['ds'] ?? null) ? $_POST['ds'] : [];
        // velikosti se ve formuláři zadávají v pixelech, design systém je drží v rem
        foreach (['zaklad_min', 'zaklad_max', 'sirka', 'sirka_textu'] as $key) {
            if (isset($ds[$key]) && is_numeric($ds[$key])) {
                $ds[$key] = (float) $ds[$key] / 16;
            }
        }

        return DesignSystem::sanitize($ds);
    }
}
