<?php
/**
 * Varianta záhlaví nebo patičky: název a stránky, na kterých platí místo výchozí podoby.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\SiteParts $module
 * @var string $csrf
 * @var string $type
 * @var string $language
 * @var string $variant
 * @var string $name
 * @var list<int> $selected
 * @var list<array{ids: int, titulek: string}> $pages
 */
?>
<form class="formular" method="post" action="<?= e($module->url('uloz_variantu', ['typ' => $type, 'jazyk' => $language])) ?>">
<?= $csrf ?>
<input type="hidden" name="varianta" value="<?= e($variant) ?>">
<p class="napoveda"><?= e(t('Varianta platí jen na vybraných stránkách, jinde zůstává výchozí podoba. Třeba landing page s jednodušším záhlavím. Když v builderu necháte variantu prázdnou, stránka záhlaví (patičku) mít nebude.')) ?></p>
<div class="radek"><label for="nazev"><?= e(t('Název varianty')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($name) ?>" maxlength="100" required placeholder="<?= e(t('např. Landing page')) ?>"></div></div>
<div class="radek"><span class="popisek"><?= e(t('Stránky')) ?></span><div class="volby">
<?php foreach ($pages as $s): ?>
	<label><input type="checkbox" name="stranky[]" value="<?= (int) $s['ids'] ?>"<?= in_array((int) $s['ids'], $selected, true) ? ' checked' : '' ?>> <?= e($s['titulek']) ?></label><br>
<?php endforeach ?>
</div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($variant === '' ? 'Vytvořit a otevřít v builderu' : 'Uložit')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
