<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * Admin URLs of Kaleta 1.3 and older (admin.php?modul=stranky&akce=uloz&zalozka=zalohy): bookmarks, links in sent
 * e-mails and forms open during an update keep working. admin.php translates the old parameter names and values to the
 * current ones (module, action, tab) before anything reads them; a GET request is redirected to the current URL.
 * The old names stay until 2.0.
 */
final class LegacyUrls
{
    /** @var array<string, string> old module ident => current */
    public const array MODULES = [
        'stranky' => 'pages', 'novinky' => 'news', 'kolekce' => 'collections', 'poptavky' => 'enquiries', 'odberatele' => 'subscribers',
        'kategorie' => 'categories', 'stitky' => 'tags', 'intergal' => 'media', 'vzhled' => 'appearance', 'casti' => 'parts',
        'komponenty' => 'components', 'popupy' => 'popups', 'role' => 'roles', 'stat' => 'stats', 'presmerovani' => 'redirects',
        'protokol' => 'changelog', 'prenos' => 'transfer', 'rozsireni' => 'extensions', 'config' => 'settings',
    ];

    /** @var array<string, string> old action => current (modules and the admin kernel share one list) */
    public const array ACTIONS = [
        'aktualizuj' => 'update', 'asistent' => 'assistant', 'automaticky' => 'automatic', 'detail' => 'detail', 'duplikuj' => 'duplicate',
        'duplikuj_polozku' => 'duplicate_item', 'hledej_json' => 'search_json', 'hromadne' => 'bulk', 'koncept' => 'draft', 'nahled' => 'preview',
        'nahradit' => 'replace', 'nahraj' => 'upload', 'nastaveni' => 'settings', 'novy' => 'new', 'obnov' => 'restore', 'obnov_verzi' => 'restore_version',
        'obnov_zalohu' => 'restore_backup', 'obrazky' => 'images', 'odkaz_hesla' => 'password_link', 'odkazy' => 'links', 'polozka' => 'item',
        'polozky' => 'items', 'porovnej' => 'compare', 'poznamka' => 'note', 'preloz' => 'translate', 'prepni' => 'toggle', 'priloha' => 'attachment',
        'prubeh' => 'progress', 'revize' => 'versions', 'sablona' => 'template', 'seznam' => 'listing', 'slozka' => 'folder', 'slozka_smaz' => 'folder_delete',
        'smaz' => 'delete', 'smaz_export' => 'delete_export', 'smaz_log' => 'delete_log', 'smaz_natrvalo' => 'delete_permanently', 'smaz_polozku' => 'delete_item',
        'smaz_soubor' => 'delete_file', 'smaz_zalohu' => 'delete_backup', 'spust' => 'run', 'stahni' => 'download', 'stahni_zalohu' => 'download_backup',
        'stav' => 'status', 'stavba_ai_sekce' => 'build_ai_section', 'stavba_ai_text' => 'build_ai_text', 'stavba_obnov' => 'build_restore',
        'stavba_publikuj' => 'build_publish', 'stavba_revize' => 'build_versions', 'stavba_sdilet' => 'build_share', 'stavba_sekce' => 'build_section',
        'stavba_smaz_sekci' => 'build_delete_section', 'stavba_text' => 'build_text', 'stavba_trida' => 'build_class', 'stavba_uloz' => 'build_save',
        'stavba_uloz_sekci' => 'build_save_section', 'stavba_zahod' => 'build_discard', 'stavitel' => 'builder', 'synchronizuj' => 'sync',
        'test_posty' => 'test_mail', 'tokeny' => 'tokens', 'tokeny_import' => 'tokens_import', 'uloz' => 'save', 'uloz_polozku' => 'save_item',
        'uloz_popis' => 'save_caption', 'uloz_text' => 'save_text', 'uloz_variantu' => 'save_variant', 'varianta' => 'variant', 'vyber' => 'select',
        'vycisti' => 'clear', 'vynuluj' => 'reset', 'vypis' => 'list', 'z_prvku' => 'from_element', 'zaloha_medii' => 'media_backup', 'zalohuj' => 'backup',
        'zaloz' => 'create', 'zkontroluj' => 'check', 'znovu' => 'retry',
        // admin kernel
        'heslo' => 'password', 'ucet' => 'account', 'pruvodce_skryt' => 'hide_first_steps',
    ];

    /** @var array<string, string> old settings tab => current */
    public const array TABS = [
        'zakladni' => 'general', 'mereni' => 'analytics', 'posta' => 'mail', 'zalohy' => 'backups', 'stav' => 'health', 'firma' => 'company',
        'rozsireni' => 'extensions',
    ];

    private const array PARAMS = ['modul' => ['module', self::MODULES], 'akce' => ['action', self::ACTIONS], 'zalozka' => ['tab', self::TABS]];

    /**
     * @param array<string, mixed> $query
     * @return array{0: array<string, mixed>, 1: bool} the query with current names and values; true when anything was old
     */
    public static function normalize(array $query): array
    {
        $legacy = false;
        foreach (self::PARAMS as $old => [$new, $values]) {
            if (array_key_exists($old, $query)) {
                $legacy = true;
                if (!array_key_exists($new, $query)) {
                    $query[$new] = $query[$old];
                }
                unset($query[$old]);
            }
            if (is_string($query[$new] ?? null) && isset($values[$query[$new]]) && $values[$query[$new]] !== $query[$new]) {
                $legacy = true;
                $query[$new] = $values[$query[$new]];
            }
        }

        return [$query, $legacy];
    }
}
