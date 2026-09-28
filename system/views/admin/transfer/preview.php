<?php
/**
 * Import z WordPressu, krok 2: náhled – co v souboru je, co se nepřevede, a volby importu. Do databáze se zatím nic nezapsalo.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  stav importu (Core\WpImport::newState)
 * @var list<string> $languages  jazykové verze webu, první je výchozí
 * @var list<array{idt:int, nazev:string, jazyk:string}> $categories  kategorie novinek
 * @var bool $redirectsEnabled
 */
$p = $state['prehled'];
$options = $state['volby'];
$statuses = ['publish' => 'vydané', 'future' => 'naplánované', 'draft' => 'koncepty', 'pending' => 'čekají na schválení', 'private' => 'soukromé', 'trash' => 'v koši', 'auto-draft' => 'automatické koncepty', 'inherit' => 'revize'];
$byStatus = function (array $counts) use ($statuses): string {
    $parts = [];
    foreach ($counts as $s => $count) {
        $parts[] = (int) $count . ' ' . t($statuses[$s] ?? 'jiné');
    }

    return implode(', ', $parts);
};
$converts = fn (array $counts): int => array_sum(array_intersect_key($counts, ['publish' => 1, 'future' => 1, 'draft' => 1, 'pending' => 1]));
?>
<?= $app->view->render('admin/transfer/steps', ['step' => 2]) ?>
<p><?= e(t('Soubor %s – web „%s“ (%s). Zatím se nic neimportovalo; tohle je jen přehled toho, co v souboru je.', $state['soubor'], $state['web']['nazev'], $state['web']['adresa'])) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) array_sum($p['clanky']) ?></strong><span><?= e(t('Příspěvky')) ?><?= $p['clanky'] !== [] ? ': ' . e($byStatus($p['clanky'])) : '' ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) array_sum($p['stranky']) ?></strong><span><?= e(t('Stránky')) ?><?= $p['stranky'] !== [] ? ': ' . e($byStatus($p['stranky'])) : '' ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['rubriky'] ?></strong><span><?= e(t('Kategorie (založí se ty, které mají příspěvky)')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['stitky'] ?></strong><span><?= e(t('Štítky')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['prilohy'] ?></strong><span><?= e(t('Soubory v knihovně médií')) ?> · <?= e(t('obrázků v textech: %s', (int) $p['obrazky'])) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['autori'] ?></strong><span><?= e(t('Autoři')) ?></span></div>
</div>

<div class="hlaska hlaska-varovani">
<p><strong><?= e(t('Co se nepřevede')) ?></strong></p>
<ul>
	<li><?= e(t('Účty a hesla uživatelů – novinky budou patřit vám. Komentáře se nepřenášejí.')) ?></li>
	<li><?= e(t('Nabídky (menu), widgety, vzhled a nastavení doplňků – navigaci si na novém webu sestavíte znovu.')) ?></li>
	<li><?= e(t('Soukromé příspěvky, koš, revize a automatické koncepty. Příspěvek chráněný heslem se převede jako koncept.')) ?></li>
<?php if ($p['jine'] !== []): ?>
	<li><?= e(t('Vlastní typy obsahu:')) ?> <?= e(implode(', ', array_map(fn (string $type, int $count): string => $type . ' (' . $count . ')', array_keys($p['jine']), $p['jine']))) ?></li>
<?php endif ?>
<?php if ($p['zkratky'] !== []): ?>
	<li><?= e(t('Zkratky doplňků (formuláře, buildery stránek…) – značka zmizí, text uvnitř zůstane:')) ?> <?= e(implode(', ', array_map(fn (string $z, int $count): string => '[' . $z . '] ' . $count . '×', array_keys($p['zkratky']), $p['zkratky']))) ?></li>
<?php endif ?>
	<li><?= e(t('Obrázky zatím zůstanou na starém webu; po importu je můžete jedním tlačítkem stáhnout k sobě.')) ?></li>
</ul>
</div>

<form class="formular" method="post" action="<?= e($module->url('run')) ?>">
<?= $csrf ?>
<input type="hidden" name="soubor" value="<?= e($state['soubor']) ?>">
<fieldset>
<legend><?= e(t('Volby importu')) ?></legend>
<?php if (count($languages) > 1): ?>
<div class="radek"><label for="jazyk"><?= e(t('Jazyková verze')) ?></label><div><select id="jazyk" name="jazyk">
<?php foreach ($languages as $i => $code): ?>
	<option value="<?= $i === 0 ? '' : e($code) ?>"<?= ($i === 0 ? '' : $code) === $options['jazyk'] ? ' selected' : '' ?>><?= e(Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code) ?><?= $i === 0 ? ' – ' . e(t('výchozí jazyk webu')) : '' ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('Do které jazykové verze webu nové kategorie a stránky patří.')) ?></span></div></div>
<?php endif ?>
<div class="radek"><span class="popisek"><?= e(t('Co importovat')) ?></span><div class="volby">
	<label><input type="checkbox" name="koncepty" value="1"<?= $options['koncepty'] ? ' checked' : '' ?>> <?= e(t('koncepty a příspěvky čekající na schválení (%s)', (int) (($p['clanky']['draft'] ?? 0) + ($p['clanky']['pending'] ?? 0)))) ?></label>
	<label><input type="checkbox" name="stranky" value="1"<?= $options['stranky'] ? ' checked' : '' ?>> <?= e(t('stránky (%s)', $converts($p['stranky']))) ?></label>
	<label><input type="checkbox" name="stavitel" value="1"<?= ($options['stavitel'] ?? true) ? ' checked' : '' ?>> <?= e(t('stránky rovnou do builderu – upravíte je vizuálně; původní text zůstane jako záloha')) ?></label>
	<label><input type="checkbox" name="presmerovani" value="1"<?= $options['presmerovani'] ? ' checked' : '' ?>> <?= e(t('přesměrování ze starých adres na nové')) ?></label>
</div></div>
<?php if (!$redirectsEnabled): ?>
<p class="napoveda"><?= e(t('Přesměrování se zapíší, ale začnou platit, až zapnete rozšíření Přesměrování.')) ?></p>
<?php endif ?>
<div class="radek"><label for="rubrika"><?= e(t('Příspěvky bez kategorie dát do')) ?></label><div><select id="rubrika" name="rubrika">
	<option value="0"><?= e(t('nové kategorie „Nezařazené“')) ?></option>
<?php foreach ($categories as $r): ?>
	<option value="<?= (int) $r['idt'] ?>"<?= (int) $r['idt'] === (int) $options['rubrika'] ? ' selected' : '' ?>><?= e($r['nazev']) ?><?= $r['jazyk'] !== '' ? ' (' . e($r['jazyk']) . ')' : '' ?></option>
<?php endforeach ?>
</select></div></div>
</fieldset>
<p class="napoveda"><?= e(t('Importované novinky se neoznamují: žádný webhook ani IndexNow. Před větším importem si v Nastavení → Zálohy a aktualizace vytvořte zálohu databáze.')) ?></p>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Spustit import')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
