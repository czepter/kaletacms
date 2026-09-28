<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Antispam;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Subscription to news by e-mail (the Newsletter extension). The address is saved only after confirmation via the link in the e-mail
 * (double opt-in); the subscriber list can be exported from the admin to a mailing tool.
 */
final class Newsletter extends Element
{
    public const string TYPE = 'newsletter';
    public const string NAME = 'Odběr novinek';
    public const string DESCRIPTION = 'Pole pro e-mail s potvrzením odběru – adresy najdete v administraci v Odběratelích.';
    public const string ICON = 'newsletter';
    public const string GROUP = 'Dynamické';
    public const string EXTENSION = 'newsletter';
    public const array HTML_TAGS = ['form'];

    public static function properties(): array
    {
        return [
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Tlačítko', 'vychozi' => t('Odebírat'), 'max' => 40],
            'souhlas' => ['typ' => 'text', 'popisek' => 'Text pod polem', 'vychozi' => t('Pošleme vám jen novinky a nabídky. Odhlásit se můžete jedním kliknutím v každém e-mailu.'), 'max' => 300],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-newsletter { display: grid; gap: var(--ka-mezera-xs); max-width: 32rem; }
.ka-newsletter-radek { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs); }
.ka-newsletter input[type="email"] { flex: 1 1 14rem; min-width: 0; padding: 0.65em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: inherit; font: inherit; }
.ka-newsletter button { padding: 0.65em 1.2em; border: 0; border-radius: var(--ka-zaobleni); background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font: inherit; font-weight: 600; cursor: pointer; }
.ka-newsletter small { color: var(--ka-barva-tlumeny); }
.ka-newsletter-hlaska { margin: 0; font-weight: 600; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $r = $k->app->request;
        $id = 'nl-' . $p['id'];
        $result = $r->get('odber');
        $message = match ($result) {
            'ok' => t('Děkujeme! Poslali jsme vám e-mail s odkazem – odběr potvrďte kliknutím na něj.'),
            'chyba' => t('Zkontrolujte prosím e-mailovou adresu.'),
            'limit' => t('Příliš mnoho pokusů za sebou. Zkuste to prosím za chvíli.'),
            default => '',
        };
        $antispam = new Antispam($k->app->db(), $k->app->settings());
        // anchor for the return after sending: the element id (anchor or style), otherwise its own
        $anchor = preg_match('/ id="([^"]*)"/', $a, $m) ? $m[1] : $id;
        if ($anchor === $id) {
            $a = ' id="' . e($id) . '"' . $a;
        }

        return '<form' . Text::withClass($a, 'ka-newsletter') . ' method="post" action="' . e($k->url('odber')) . '">'
            . ($message !== '' ? '<p class="ka-newsletter-hlaska" role="status">' . e($message) . '</p>' : '')
            . '<label class="ka-jen-ctecka" for="' . e($id) . '-email">' . e(t('Váš e-mail')) . '</label>'
            . '<div class="ka-newsletter-radek"><input type="email" id="' . e($id) . '-email" name="email" autocomplete="email" required maxlength="190" placeholder="' . e(t('vas@email.cz')) . '">'
            . '<button type="submit">' . e($o['tlacitko']) . '</button></div>'
            . ($o['souhlas'] !== '' ? '<small>' . e($o['souhlas']) . '</small>' : '')
            . '<input type="hidden" name="zpet" value="' . e($k->app->url($r->path())) . '"><input type="hidden" name="kotva" value="' . e($anchor) . '">'
            . $antispam->fields('odber') . '</form>';
    }
}
