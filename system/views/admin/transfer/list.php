<?php
/**
 * Import and export: step 1 of the WordPress import (file) and export of the whole site.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array{soubor:string, velikost:int, cas:int, stav:array<string,mixed>|null}> $files  WordPress exports in storage/import/
 * @var int $uploadLimit  how many bytes the server allows to upload through a form
 * @var bool $missingXml  the server lacks the extension for reading XML
 * @var list<array{soubor:string, velikost:int, cas:int}> $exports
 * @var bool $hasZip
 */
$phase = [
    'analyza' => 'being read', 'nahled' => 'ready to import', 'import' => 'import in progress', 'hotovo' => 'content imported',
    'obrazky' => 'downloading images', 'obrazky-hotovo' => 'imported including images',
];
?>
<h2><?= e(t('Import from WordPress')) ?></h2>
<?= $app->view->render('admin/transfer/steps', ['step' => 1]) ?>
<p><?= e(t('In WordPress, open Tools → Export, choose “All content” and download the .xml file. Then upload it here. Pages, posts (as news), categories and tags are converted and redirects from the old addresses are created; nothing changes on the site until you confirm the import in the next step.')) ?></p>
<?php if ($missingXml): ?>
<p class="hlaska hlaska-chyba"><?= e(t('The PHP extension xmlreader or dom is missing on the server – a WordPress export cannot be read without them.')) ?></p>
<?php else: ?>
<form class="formular" method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>">
<?= $csrf ?>
<div class="radek"><label for="soubor"><?= e(t('WordPress export')) ?></label><div><input type="file" id="soubor" name="soubor" accept=".xml,text/xml,application/xml" required>
	<span class="napoveda"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/ folder – it will appear in the list below.', Kaleta\Core\Files::size($uploadLimit))) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Upload and show preview')) ?>"></p>
</form>
<?php endif ?>

<?php if ($files !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Uploaded')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($files as $s): $state = $s['stav']; ?>
<tr>
	<td><?= e($s['soubor']) ?></td>
	<td class="cislo"><?= e(Kaleta\Core\Files::size($s['velikost'])) ?></td>
	<td class="cislo"><?= e(format_date(date('Y-m-d H:i:s', $s['cas']), true)) ?></td>
	<td><?= $state === null ? '–' : e(t($phase[$state['faze']] ?? '–')) . ($state['faze'] === 'import' ? ' (' . (int) $state['pozice'] . ' / ' . (int) $state['celkem'] . ')' : '') ?></td>
	<td class="akce">
<?php if ($state !== null): ?>
		<a href="<?= e($module->url($state['faze'] === 'nahled' ? 'preview' : 'progress', ['soubor' => $s['soubor']])) ?>"><?= e(t(in_array($state['faze'], ['hotovo', 'obrazky-hotovo'], true) ? 'Result' : 'Continue')) ?></a>
<?php endif ?>
<?php if (!$missingXml): ?>
		<form class="vradku" method="post" action="<?= e($module->url('select')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace" type="submit"><?= e(t($state === null ? 'Show preview' : 'Read again')) ?></button></form>
<?php endif ?>
		<form class="vradku" method="post" action="<?= e($module->url('delete_file')) ?>" data-potvrdit="<?= e(t('Delete the file %s? Content that has already been imported stays on the site.', $s['soubor'])) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('You can import the same file repeatedly – whatever has already been imported is skipped. Delete the file when the import is finished; it contains e-mail addresses of authors and commenters from the old site.')) ?></p>
<?php endif ?>

<h2><?= e(t('Export of the whole site')) ?></h2>
<p><?= e(t('One archive gives you the whole site in an open format (JSON): pages, news, categories, tags, redirects, menus, collections, pop-ups, site parts, components, shared classes and uploaded files – as a content backup or for moving elsewhere. Enquiries, passwords, keys and accounts are not included.')) ?></p>
<?php if (!$hasZip): ?>
<p class="hlaska"><?= e(t('The PHP zip extension is missing on the server, so the export contains data only (JSON). Download the media/ folder over FTP.')) ?></p>
<?php endif ?>
<form method="post" action="<?= e($module->url('export')) ?>"><?= $csrf ?><p><button class="tl" type="submit"><?= e(t('Create export')) ?></button></p></form>
<?php if ($exports !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Vytvořeno')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($exports as $x): ?>
<tr>
	<td><?= e($x['soubor']) ?></td>
	<td class="cislo"><?= e(Kaleta\Core\Files::size($x['velikost'])) ?></td>
	<td class="cislo"><?= e(format_date(date('Y-m-d H:i:s', $x['cas']), true)) ?></td>
	<td class="akce"><a href="<?= e($module->url('download', ['soubor' => $x['soubor']])) ?>"><?= e(t('Download')) ?></a>
		<form class="vradku" method="post" action="<?= e($module->url('delete_export')) ?>" data-potvrdit="<?= e(t('Delete the export %s?', $x['soubor'])) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($x['soubor']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('The last three exports are kept. To restore this site, use the database backup in Settings → Backups and updates.')) ?></p>
<?php endif ?>
