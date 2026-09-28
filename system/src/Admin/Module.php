<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Request;
use Kaleta\Core\Response;

/**
 * Base class of admin modules.
 *
 * The action from the URL (admin.php?module=news&action=edit) calls the method actionEdit().
 * The default action is "list". A new module = one class + templates in views/admin/<ident>/.
 */
abstract class Module
{
    /** Identifier in the URL and in the permissions table. */
    public const string IDENT = '';

    /** Title in the menu. */
    public const string NAME = '';

    /** Group in the menu: Obsah | Vzhled | Správa (Content | Appearance | Administration). */
    public const string GROUP = 'Obsah';

    /** Icon in the menu (key into the set in views/admin/icons.php). */
    public const string ICON = 'clanek';

    /** Key of the extension (Core\Extensions) the module belongs to; empty = core, cannot be disabled. */
    public const string EXTENSION = '';

    /** Only the administrator sees the module (authors, configuration...). */
    public const bool ADMIN_ONLY = false;

    /** The module belongs under another one (ident): it is not shown separately in the menu, the parent is highlighted (Categories and Tags under News). */
    public const string PARENT = '';

    /** The module is available to every signed-in user without setting permissions. */
    public const bool FOR_ALL_USERS = false;

    protected readonly App $app;
    protected readonly Db $db;
    protected readonly Request $request;

    public function __construct(protected readonly Kernel $kernel)
    {
        $this->app = $kernel->app;
        $this->db = $this->app->db();
        $this->request = $this->app->request;
    }

    public function handle(string $action): Response
    {
        $method = 'action' . str_replace('_', '', ucwords($action, '_'));
        if (!preg_match('/^[a-z][a-z_]*$/', $action) || !method_exists($this, $method)) {
            return $this->error('Neznámá akce.', 404);
        }

        return $this->$method();
    }

    /** @param array<string, mixed> $data */
    protected function view(string $template, string $heading, array $data = []): Response
    {
        $data += ['app' => $this->app, 'module' => $this, 'csrf' => $this->app->session->csrfField()];

        return $this->kernel->page($heading, $this->app->view->render('admin/' . static::IDENT . '/' . $template, $data));
    }

    protected function error(string $text, int $status = 400): Response
    {
        return $this->kernel->page('Chyba', $this->app->view->render('admin/error', ['text' => $text]), $status);
    }

    public function app(): App
    {
        return $this->app;
    }

    /** @param array<string, scalar> $params */
    public function url(string $action = '', array $params = []): string
    {
        $query = ['module' => static::IDENT] + ($action !== '' ? ['action' => $action] : []) + $params;

        return $this->app->url('admin.php?' . http_build_query($query));
    }

    /** Redirect back to the module with a message (Post/Redirect/Get pattern). */
    /** Return to the site page after editing "directly on the site": only a local path under the site root, never a foreign URL. */
    /**
     * Language version filter in lists (pages, categories, collection items) by ?jazyk=code.
     *
     * @return array{0: list<string>, 1: string, 2: ?string} site languages (empty = single language), selected code, value of the jazyk column (null = all)
     */
    protected function readLanguageFilter(): array
    {
        $siteSettings = $this->app->settings();
        $additional = \Kaleta\Core\Language::additional($siteSettings);
        $languages = $additional === [] ? [] : [\Kaleta\Core\Language::defaults($siteSettings), ...$additional];
        $code = in_array($this->request->get('jazyk'), $languages, true) ? $this->request->get('jazyk') : '';

        return [$languages, $code, $code === '' ? null : \Kaleta\Core\Language::column($siteSettings, $code)];
    }

    protected function redirectToSite(string $target, string $suffix = ''): Response
    {
        $root = $this->app->request->basePath() . '/';
        $isLocal = str_starts_with($target, $root) && !str_starts_with($target, '//') && !str_contains($target, '\\') && !preg_match('#[\r\n]|^/[/\\\\]#', $target);

        return Response::redirect(($isLocal ? strtok($target, '?#') : $root) . ($isLocal ? $suffix : ''), 303);
    }

    protected function back(string $message = '', string $action = '', array $params = [], string $type = 'ok'): Response
    {
        if ($message !== '') {
            $this->app->session->flash($type, $message);
        }

        return Response::redirect($this->url($action, $params));
    }
}
