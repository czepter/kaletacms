<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Images;
use Kaleta\Core\Response;
use Kaleta\Front\Layouts;
use Kaleta\Builder\DesignSystem;

/**
 * Site appearance: layout, logo and design system (colors, fonts, sizes, width, rounding) with a live preview of the home page.
 * Both the layout and the builder take tokens from the design system, so a change here recolors the whole site.
 */
final class Appearance extends Module
{
    public const string IDENT = 'appearance';
    public const string NAME = 'Vzhled webu';
    public const string GROUP = 'Vzhled';
    public const string ICON = 'identita';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $siteSettings = $this->app->settings();
        $ds = DesignSystem::load($siteSettings);

        return $this->view('list', 'Vzhled webu', [
            'layouts' => Layouts::listAll(),
            'ds' => $ds,
            'contrasts' => DesignSystem::contrasts($ds),
            'presets' => array_map(fn (string $k): array => ['nazev' => DesignSystem::PRESETS[$k][0], 'popis' => DesignSystem::PRESETS[$k][1], 'ds' => DesignSystem::preset($k)], array_combine(array_keys(DesignSystem::PRESETS), array_keys(DesignSystem::PRESETS))),
            'values' => ['layout' => $siteSettings->get('layout'), 'logo' => $siteSettings->get('logo'), 'favicon' => $siteSettings->get('favicon'), 'dark_mode' => $siteSettings->get('dark_mode'), 'theme_switcher' => $siteSettings->get('theme_switcher'), 'site_name' => $siteSettings->get('site_name')],
        ]);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $siteSettings = $this->app->settings();
        if (isset(Layouts::listAll()[$r->post('layout')])) {
            $siteSettings->set('layout', $r->post('layout'));
        }
        $siteSettings->set('logo', mb_substr($r->post('logo'), 0, 255));
        $icon = mb_substr($r->post('favicon'), 0, 255);
        if ($icon !== $siteSettings->get('favicon') || ($icon !== '' && !is_file(KALETA_ROOT . '/media/ikona-180.png'))) {
            // icons for phones and for installing the site are prepared from the icon once, when saving
            $ok = $icon !== '' && preg_match('#^/?(?:[A-Za-z0-9_.-]+/){0,3}(media/[A-Za-z0-9/_.-]+)$#', $icon, $m) && !str_contains($m[1], '..') && Images::icons(KALETA_ROOT . '/' . $m[1]);
            if (!$ok) {
                array_map(fn (int $n): bool => @unlink(KALETA_ROOT . '/media/ikona-' . $n . '.png'), Images::ICON_SIZES);
            }
        }
        $siteSettings->set('favicon', $icon);
        $siteSettings->set('dark_mode', in_array($r->post('dark_mode'), ['auto', 'tmavy'], true) ? $r->post('dark_mode') : 'vypnuto');
        $siteSettings->set('theme_switcher', $r->postBool('theme_switcher') ? '1' : '0');
        $siteSettings->set('design_system', (string) json_encode($this->parseForm(), JSON_UNESCAPED_SLASHES));
        $siteSettings->set('appearance_saved', '1'); // first steps: the appearance was chosen by the administrator, not by the starter site
        // older Identity keys: they are not read once the design system is saved, so they do not confuse the export or other tools
        $siteSettings->set('brand_accent', '');
        $siteSettings->set('brand_heading_font', 'vychozi');
        $siteSettings->set('brand_text_font', 'vychozi');
        \Kaleta\Front\Cache::clear();

        return $this->back('Vzhled webu byl uložen.');
    }

    /** Design tokens for download in the DTCG format (Figma, Tokens Studio, Style Dictionary). */
    protected function actionTokens(): Response
    {
        $json = (string) json_encode(DesignSystem::toDtcg(DesignSystem::load($this->app->settings())), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="tokeny-' . date('Y-m-d') . '.tokens.json"']);
    }

    /** Import of DTCG tokens: the whole appearance from a Kaleta export, the colors from another tool. */
    protected function actionTokensImport(): Response
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
        $siteSettings->set('appearance_saved', '1');
        \Kaleta\Front\Cache::clear();

        return $this->back('Design tokeny byly načteny.');
    }

    /** Live preview: token CSS and a readability check for the unsaved form (JSON). Saves nothing. */
    protected function actionPreview(): Response
    {
        $ds = $this->parseForm();

        return Response::json(['css' => DesignSystem::css($ds, $this->app->request->basePath()), 'kontrasty' => array_map(fn (array $k): array => ['popis' => t($k['popis'])] + $k, DesignSystem::contrasts($ds))]);
    }

    /** @return array<string, mixed> */
    private function parseForm(): array
    {
        $ds = is_array($_POST['ds'] ?? null) ? $_POST['ds'] : [];
        // sizes are entered in pixels in the form, the design system keeps them in rem
        foreach (['zaklad_min', 'zaklad_max', 'sirka', 'sirka_textu'] as $key) {
            if (isset($ds[$key]) && is_numeric($ds[$key])) {
                $ds[$key] = (float) $ds[$key] / 16;
            }
        }

        return DesignSystem::sanitize($ds);
    }
}
