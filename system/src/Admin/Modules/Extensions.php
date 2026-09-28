<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\Response;

/**
 * Rozšíření jako samostatná položka hlavní nabídky (dřív záložka Nastavení).
 *
 * Obrazovka i ukládání jsou tytéž jako u záložek Nastavení - modul jen drží pevnou „záložku“ rozsireni,
 * vykresluje šablony ze složky config/ a vrací se na vlastní adresu (admin.php?module=extensions).
 */
final class Extensions extends Settings
{
    public const string IDENT = 'extensions';
    public const string NAME = 'Rozšíření';
    public const string ICON = 'rozsireni';

    protected function tab(string $tab): string
    {
        return 'extensions';
    }

    protected function view(string $template, string $heading, array $data = []): Response
    {
        $data += ['app' => $this->app, 'module' => $this, 'csrf' => $this->app->session->csrfField()];

        return $this->kernel->page(self::NAME, $this->app->view->render('admin/settings/' . $template, $data));
    }
}
