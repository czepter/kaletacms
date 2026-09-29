<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

/**
 * Every MCP tool once (2.1): its English name => [access, the extension it needs ('' = none)].
 *
 * Access is what an MCP client should confirm with the user: "read" changes nothing, "write" changes the site, and
 * "destructive" removes or overwrites something the user may want back, or cannot be taken back (sending). The
 * annotations, the change log, the extension gate and tools/list all come from here. The tool itself is the method
 * Tools::tool<Name> (toolSiteInfo for site_info), its English definition is in Translator and its parameter types in
 * Tools::definitions(); tools/unit-tests.php checks that all four agree.
 */
final class Catalog
{
    /** @var array<string, array{0: 'read'|'write'|'destructive', 1: string}> */
    public const array TOOLS = [
        // Site and pages
        'site_info' => ['read', ''],
        'list_pages' => ['read', ''],
        'get_page' => ['read', ''],
        'create_page' => ['write', ''],
        'update_page' => ['write', ''],
        'trash_page' => ['destructive', ''],
        'get_menu' => ['read', ''],
        'save_menu' => ['write', ''],
        'list_trash' => ['read', ''],
        'restore_from_trash' => ['write', ''],
        // Builder
        'builder_schema' => ['read', ''],
        'get_build' => ['read', ''],
        'edit_build' => ['write', ''],
        'build_from_html' => ['write', ''],
        'save_build' => ['write', ''],
        'insert_section' => ['write', ''],
        'publish_build' => ['write', ''],
        'list_build_versions' => ['read', ''],
        'restore_build_version' => ['destructive', ''],
        'discard_draft' => ['destructive', ''],
        'save_section' => ['write', ''],
        'delete_section' => ['destructive', ''],
        'list_components' => ['read', ''],
        'save_component' => ['write', ''],
        'delete_component' => ['destructive', ''],
        'list_site_parts' => ['read', ''],
        'save_part_variant' => ['write', ''],
        'apply_part_template' => ['write', ''],
        'preview_link' => ['read', ''],
        // Look
        'list_classes' => ['read', ''],
        'save_classes' => ['write', ''],
        'update_design_system' => ['write', ''],
        'publish_look' => ['destructive', ''],
        'discard_look' => ['destructive', ''],
        'list_look_versions' => ['read', ''],
        'restore_look_version' => ['write', ''],
        // Collections
        'list_collections' => ['read', ''],
        'create_collection' => ['write', ''],
        'update_collection' => ['write', ''],
        'delete_collection' => ['destructive', ''],
        'list_collection_items' => ['read', ''],
        'save_collection_item' => ['write', ''],
        'delete_collection_item' => ['destructive', ''],
        'list_item_versions' => ['read', ''],
        'restore_item_version' => ['write', ''],
        // News
        'list_news' => ['read', 'novinky'],
        'get_news' => ['read', 'novinky'],
        'create_news' => ['write', 'novinky'],
        'update_news' => ['write', 'novinky'],
        'trash_news' => ['destructive', 'novinky'],
        'list_categories' => ['read', 'novinky'],
        'create_category' => ['write', 'novinky'],
        'update_category' => ['write', 'novinky'],
        'delete_category' => ['destructive', 'novinky'],
        // Media
        'list_media' => ['read', ''],
        'upload_file' => ['write', ''],
        'update_media' => ['write', ''],
        'delete_media' => ['destructive', ''],
        // Enquiries and pop-ups
        'list_enquiries' => ['read', ''],
        'update_enquiry' => ['write', 'poptavky'],
        'delete_enquiry' => ['destructive', 'poptavky'],
        'list_popups' => ['read', ''],
        'save_popup' => ['write', ''],
        'delete_popup' => ['destructive', ''],
        // Settings, redirects and audit
        'update_settings' => ['write', ''],
        'list_redirects' => ['read', ''],
        'save_redirect' => ['write', ''],
        'ignore_not_found' => ['write', 'presmerovani'],
        'site_audit' => ['read', ''],
        // Newsletter
        'list_newsletters' => ['read', 'newsletter'],
        'draft_newsletter' => ['write', 'newsletter'],
        'send_test_newsletter' => ['write', 'newsletter'],
        'send_newsletter' => ['destructive', 'newsletter'],
        'delete_newsletter' => ['destructive', 'newsletter'],
    ];

    /** The English name of a tool given by either name (Czech names are hidden aliases of the older tools). */
    public static function english(string $name): ?string
    {
        if (isset(self::TOOLS[$name])) {
            return $name;
        }
        $english = array_search($name, array_combine(array_keys(self::TOOLS), array_map(fn (string $en): string => Translator::czech($en) ?? $en, array_keys(self::TOOLS))), true);

        return is_string($english) ? $english : null;
    }

    public static function access(string $name): string
    {
        return self::TOOLS[self::english($name) ?? ''][0] ?? 'read';
    }

    public static function extension(string $name): string
    {
        return self::TOOLS[self::english($name) ?? ''][1] ?? '';
    }

    /** The method of Tools that runs the tool: site_info => toolSiteInfo. */
    public static function method(string $english): string
    {
        return 'tool' . str_replace('_', '', ucwords($english, '_'));
    }
}
