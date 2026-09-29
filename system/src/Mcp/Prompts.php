<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Core\App;

/**
 * What the site offers Claude besides tools (2.2): the site owner's instructions and an overview as MCP resources, and
 * prompts for the jobs people ask for most. A client shows prompts as ready-made tasks (in Claude: the "+" menu).
 */
final class Prompts
{
    /** name => [description, [argument => [description, required]], text with {argument} placeholders] */
    private const array PROMPTS = [
        'build_page' => ['Build a new page in the look of the site, as a draft to review.',
            ['topic' => ['What the page is about, e.g. "our kitchen renovation service"', true], 'audience' => ['Who it is for (optional)', false]],
            'Build a new page about {topic}{audience}. First read the site instructions (resource kaleta://instructions), site_info and builder_schema, and reuse the shared classes, components and sections the site already has. Create the page hidden, build it with build_from_html or save_build as a draft, open the preview link and fix what the check before publishing lists. Then tell me what you built, give me a preview link from preview_link, and ask before you publish anything.'],
        'audit_and_fix' => ['Run the site audit and fix what can be fixed safely.',
            [],
            'Run site_audit. Fix what is safe to fix without asking – missing descriptions of pages and item pages, broken internal links, images without alternative text, buttons without links – as drafts or small edits, and tell me what you changed. List the rest (for example addresses that need a redirect, or duplicate titles that need a decision) with a suggestion for each, and wait for my answer.'],
        'translate_page' => ['Translate a page into another language version of the site.',
            ['page_id' => ['ID of the page to translate (list_pages)', true], 'language' => ['Language code, e.g. de', true]],
            'Translate page {page_id} into the language version {language}. Read the site instructions first. Create the translation with create_page (language, translation_of: {page_id}, copy_build: true), then get_build with texts_only and translate the texts with edit_build "update" operations; point internal links to the translated pages where they exist. Keep it hidden, give me the preview link and a list of links you could not translate.'],
        'write_news' => ['Write a news item for the company blog, as a draft.',
            ['topic' => ['What the news item is about', true]],
            'Write a news item about {topic}. Read the site instructions and the latest news (list_news) to match their tone and length. Create it with create_news as a draft with a title, a short intro, the text, a category and a search engine description; do not publish it. Then show me the result and the preview.'],
        'weekly_review' => ['What happened on the site this week, and what to do next.',
            [],
            'Give me a short weekly review of the site: what changed in the last 7 days and who changed it (list_changes with since), how many new enquiries arrived (list_enquiries), which addresses ended in 404 (list_redirects) and what the site audit finds (site_audit). End with the three things you would do next, and do not change anything yet.'],
    ];

    public const string INSTRUCTIONS_URI = 'kaleta://instructions';
    public const string OVERVIEW_URI = 'kaleta://overview';

    /** @return list<array<string, mixed>> for prompts/list */
    public static function listAll(): array
    {
        return array_map(fn (string $name, array $p): array => ['name' => $name, 'description' => $p[0],
            'arguments' => array_map(fn (string $arg, array $d): array => ['name' => $arg, 'description' => $d[0], 'required' => $d[1]], array_keys($p[1]), $p[1])],
            array_keys(self::PROMPTS), self::PROMPTS);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> for prompts/get
     */
    public static function get(string $name, array $arguments): array
    {
        $p = self::PROMPTS[$name] ?? throw new \InvalidArgumentException('Unknown prompt: ' . $name);
        $values = [];
        foreach ($p[1] as $arg => [, $required]) {
            $value = trim((string) ($arguments[$arg] ?? ''));
            if ($required && $value === '') {
                throw new \InvalidArgumentException('The prompt ' . $name . ' needs the argument ' . $arg . '.');
            }
            $values['{' . $arg . '}'] = $arg === 'audience' ? ($value !== '' ? ' for ' . $value : '') : $value;
        }

        return ['description' => $p[0], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => strtr($p[2], $values)]]]];
    }

    /** @return list<array<string, mixed>> for resources/list */
    public static function resources(): array
    {
        return [
            ['uri' => self::INSTRUCTIONS_URI, 'name' => 'Site instructions', 'mimeType' => 'text/plain',
                'description' => 'What the site owner wants Claude to keep to: brand voice, words to use or avoid, house rules.'],
            ['uri' => self::OVERVIEW_URI, 'name' => 'Site overview', 'mimeType' => 'application/json',
                'description' => 'The site, its languages and extensions, and what this connection may do.'],
        ];
    }

    /** @return array<string, mixed> for resources/read */
    public static function read(App $app, string $uri): array
    {
        $s = $app->settings();
        $text = match ($uri) {
            self::INSTRUCTIONS_URI => self::instructions($app) ?: 'The site owner has not written any instructions yet (Settings → Extensions → Claude connection).',
            self::OVERVIEW_URI => (string) json_encode([
                'site' => $s->get('site_name'), 'url' => $app->request->origin() . $app->url(''), 'description' => $s->get('site_description'),
                'kaleta_version' => KALETA_VERSION, 'languages' => array_values(array_unique(array_merge([\Kaleta\Core\Language::defaults($s)], \Kaleta\Core\Language::additional($s)))),
                'extensions' => \Kaleta\Core\Extensions::enabled($s), 'connection' => $app->auth()->connection() ?? ['name' => '', 'access' => 'full'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            default => throw new \InvalidArgumentException('Unknown resource: ' . $uri),
        };

        return ['contents' => [['uri' => $uri, 'mimeType' => $uri === self::OVERVIEW_URI ? 'application/json' : 'text/plain', 'text' => $text]]];
    }

    /** The site owner's instructions for Claude (Settings → Extensions → Claude connection). */
    public static function instructions(App $app): string
    {
        return trim($app->settings()->get('claude_instructions'));
    }

    /** Server instructions for this connection: how to work with Kaleta, what the connection may do, the owner's instructions. */
    public static function serverInstructions(App $app): string
    {
        $access = $app->auth()->connection()['access'] ?? 'full';
        $text = Translator::instructions() . ' '
            . match ($access) {
                'read' => 'THIS CONNECTION CAN ONLY READ: look, check and suggest; the user makes the changes.',
                'drafts' => 'THIS CONNECTION CAN ONLY SAVE DRAFTS: builds, hidden pages, news drafts and the draft look – never publish; tell the user what is ready to publish.',
                default => '',
            };
        $own = self::instructions($app);

        return trim($text) . ($own !== '' ? "\n\nTHE SITE OWNER'S INSTRUCTIONS – keep to them:\n" . $own : '');
    }
}
