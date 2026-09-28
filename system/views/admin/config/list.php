<?php
/**
 * Nastavení: záložky + formulář zvolené záložky (config/<zalozka>.php).
 *
 * @var Kaleta\Admin\Modules\Settings $module
 * @var string $csrf
 * @var string $tab
 * @var array<string, string> $values
 * @var array<string, array{nazev:string, popis:string, rozvrzeni:string}> $layouts
 * @var list<array{skupina:string, nazev:string, stav:string, info:string}> $checks
 * @var string $siteUrl
 * @var list<string> $enabledExtensions
 * @var list<array{soubor:string, velikost:int, cas:int}> $backups
 * @var array<string, mixed>|null $update
 * @var list<array{kategorie:string, pocet:int}> $consents
 * @var list<string> $errorLog  poslední řádky záznamu chyb
 * @var string $remoteStatus  výsledek posledního nahrání zálohy mimo server
 * @var string $tasksToken  tajná část adresy /ulohy pro cron
 * @var array<int, string> $pages  stránky pro volbu úvodní stránky (záložka Základní)
 */
use Kaleta\Admin\Modules\Settings;

/** Řádek formuláře: $field('klic', 'Popisek', 'text|radky|kod|ano|cislo|url|email', 'nápověda', [atributy]) */
$invalidFields ??= [];
$field = function (string $key, string $labelText, string $kind = 'text', string $hint = '', string $attributes = '') use ($values, $app, $invalidFields): void {
    $h = $values[$key] ?? '';
    if (in_array($key, $invalidFields, true)) {
        $attributes .= ' aria-invalid="true"'; // neuložená hodnota k opravě (hláška nahoře říká, co je špatně)
    }
    $labelText = t($labelText);
    $hint = $hint === '' ? '' : t($hint);
    // nápověda bez vlastního HTML: cesty v nabídce („Nastavení → Pošta“) se promění v odkazy
    $hintHtml = $hint !== '' ? '<span class="napoveda">' . (str_contains($hint, '<') ? $hint : Kaleta\Admin\MenuPaths::links($app->url('admin.php'), $hint, ['config', 'vzhled', 'bloky'])) . '</span>' : '';
    echo '<div class="radek">';
    if ($kind === 'ano') {
        echo '<span class="popisek">' . e($labelText) . '</span><div class="volby"><label><input type="checkbox" name="' . e($key) . '" value="1"' . ($h === '1' ? ' checked' : '') . '> ' . e(t('Ano')) . '</label>' . $hintHtml . '</div>';
    } elseif ($kind === 'radky' || $kind === 'kod') {
        echo '<label for="' . e($key) . '">' . e($labelText) . '</label><div><textarea class="textbox nizky' . ($kind === 'kod' ? ' kod' : '') . '" id="' . e($key) . '" name="' . e($key) . '" rows="4" ' . $attributes . '>' . e($h) . '</textarea>' . $hintHtml . '</div>';
    } else {
        $type = ['cislo' => 'number', 'url' => 'url', 'email' => 'email'][$kind] ?? 'text';
        echo '<label for="' . e($key) . '">' . e($labelText) . '</label><div><input class="textpole' . ($type === 'number' ? '' : ' siroke') . '" type="' . $type . '" id="' . e($key) . '" name="' . e($key) . '" value="' . e($h) . '" ' . $attributes . '>' . $hintHtml . '</div>';
    }
    echo '</div>';
};
?>
<?php if ($module::IDENT === 'config'): ?>
<nav class="zalozky" aria-label="<?= e(t('Sekce nastavení')) ?>">
<?php foreach (Settings::TABS as $key => $name): ?>
	<a href="<?= e($module->url('', ['zalozka' => $key])) ?>"<?= $tab === $key ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('uloz')) ?>">
<?php /* první odesílací tlačítko ve formuláři určuje, co udělá Enter: uložit nastavení (ne zálohu, kontrolu aktualizací ani zkušební e-mail) */ ?>
<button type="submit" class="vychozi-odeslani" tabindex="-1" aria-hidden="true"><?= e(t('Uložit nastavení')) ?></button>
<?= $csrf ?>
<input type="hidden" name="zalozka" value="<?= e($tab) ?>">
<?php require __DIR__ . '/' . $tab . '.php'; ?>
<?php if ($tab !== 'stav'): ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit nastavení')) ?>"></p>
<?php endif ?>
</form>
