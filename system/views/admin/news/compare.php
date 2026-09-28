<?php
/**
 * Comparison of a saved version of a news item with its current wording.
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var array<string, mixed> $newsItem
 * @var array<string, mixed> $versions
 * @var array{html:string, pridano:int, smazano:int} $title
 * @var array{html:string, pridano:int, smazano:int} $home
 * @var array{html:string, pridano:int, smazano:int} $text
 */
$added = $title['pridano'] + $home['pridano'] + $text['pridano'];
$deleted = $title['smazano'] + $home['smazano'] + $text['smazano'];
?>
<p class="navigace-radek">
	<a class="navigace" href="<?= e($module->url('edit', ['id' => (int) $newsItem['idc']])) ?>"><?= e(t('Zpět do novinky')) ?></a>
	<a class="navigace" href="<?= e($module->url('versions', ['id' => (int) $newsItem['idc'], 'idr' => (int) $versions['idr']])) ?>"><?= e(t('Načíst tuto verzi do editoru')) ?></a>
</p>
<p><?= e(t('Verze z %s', format_date($versions['datum'], true))) ?><?= ($versions['kdo_jm'] ?? '') !== '' ? ' · ' . e($versions['kdo_jm']) : '' ?> → <?= e(t('současné znění')) ?>.
	<ins><?= e(t('přidáno')) ?>: <?= $added ?></ins> · <del><?= e(t('smazáno')) ?>: <?= $deleted ?></del></p>
<?php if ($added + $deleted === 0): ?>
<p class="hlaska"><?= e(t('Text se od této verze nezměnil (změny formátování a obrázků se neporovnávají).')) ?></p>
<?php endif ?>
<div class="porovnani">
	<h2><?= e(t('Titulek')) ?></h2>
	<div class="porovnani-titulek"><?= $title['html'] ?></div>
	<h2><?= e(t('Perex (úvod)')) ?></h2>
	<?= $home['html'] ?>
	<h2><?= e(t('Text')) ?></h2>
	<?= $text['html'] ?>
</div>
