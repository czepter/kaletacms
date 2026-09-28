<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Request;
use Kaleta\Core\Response;

/**
 * Předek modulů administrace.
 *
 * Akce z adresy (admin.php?modul=novinky&akce=edit) volá metodu akceEdit().
 * Výchozí akce je "vypis". Nový modul = jedna třída + šablony ve views/admin/<ident>/.
 */
abstract class Module
{
    /** Identifikátor v adrese a v tabulce práv. */
    public const string IDENT = '';

    /** Titulek v menu. */
    public const string NAME = '';

    /** Skupina v menu: Obsah | Vzhled | Správa. */
    public const string GROUP = 'Obsah';

    /** Ikona v menu (klíč do sady ve views/admin/icons.php). */
    public const string ICON = 'clanek';

    /** Klíč rozšíření (Core\Rozsireni), ke kterému modul patří; prázdné = jádro, nejde vypnout. */
    public const string EXTENSION = '';

    /** Modul vidí jen admin (autoři, konfigurace...). */
    public const bool ADMIN_ONLY = false;

    /** Modul patří pod jiný (ident): v menu se neukazuje samostatně, zvýrazní se nadřazený (Kategorie a Štítky pod Novinkami). */
    public const string PARENT = '';

    /** Modul je dostupný všem přihlášeným bez nastavování práv. */
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
        $method = 'akce' . str_replace('_', '', ucwords($action, '_'));
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
        $query = ['modul' => static::IDENT] + ($action !== '' ? ['akce' => $action] : []) + $params;

        return $this->app->url('admin.php?' . http_build_query($query));
    }

    /** Přesměrování zpět do modulu s hláškou (vzor Post/Redirect/Get). */
    /** Návrat na stránku webu po úpravě „přímo na webu“: jen místní cesta pod kořenem webu, nikdy cizí adresa. */
    /**
     * Filtr jazykové verze ve výpisech (stránky, kategorie, položky kolekcí) podle ?jazyk=kód.
     *
     * @return array{0: list<string>, 1: string, 2: ?string} jazyky webu (prázdné = jediný jazyk), zvolený kód, hodnota sloupce jazyk (null = všechny)
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
