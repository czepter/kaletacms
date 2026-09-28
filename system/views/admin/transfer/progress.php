<?php
/**
 * WordPress import, step 3: progress in batches (reading the file, importing content, downloading images) and the result.
 * Until it is done, the form submits itself (data-auto-odeslat in image/admin.js) – each submission is one batch.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state
 * @var string $error  already translated error of the last batch (the import stopped)
 * @var bool $canDownload  the server can download (curl or allow_url_fopen) and has GD
 * @var string $domain  domain of the old site – images are downloaded only from it
 */
$v = $state['vysledek'];
$o = $state['obr'];
$running = in_array($state['faze'], ['analyza', 'import', 'obrazky'], true);
?>
<?= $app->view->render('admin/transfer/steps', ['step' => $state['faze'] === 'analyza' ? 2 : 3]) ?>
<?php if ($error !== ''): ?>
<p class="hlaska hlaska-chyba"><?= e(t('Import se zastavil:')) ?> <?= e($error) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět na Import a export')) ?></a></p>
<?php elseif ($running): ?>
<?php if ($state['faze'] === 'analyza'): ?>
<p class="hlaska" role="status"><?= e(t('Čtu soubor %s: prošel jsem %s položek. Nechte stránku otevřenou.', $state['soubor'], (int) $state['pozice'])) ?></p>
<?php elseif ($state['faze'] === 'import'): ?>
<p class="hlaska" role="status"><?= e(t('Importuji: %s z %s položek. Nechte stránku otevřenou, pokračuji sám.', (int) $state['pozice'], (int) $state['celkem'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $state['celkem']) ?>" value="<?= (int) $state['pozice'] ?>"></progress>
<?php else: ?>
<p class="hlaska" role="status"><?= e(t('Stahuji obrázky ze starého webu: hotovo %s z %s novinek a stránek, staženo %s obrázků. Nechte stránku otevřenou, pokračuji sám.', (int) $o['hotovo'], (int) $o['celkem'], (int) $o['stazeno'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $o['celkem']) ?>" value="<?= (int) $o['hotovo'] ?>"></progress>
<?php endif ?>
<form method="post" action="<?= e($module->url('progress', ['soubor' => $state['soubor']])) ?>" data-auto-odeslat="600">
	<?= $csrf ?>
	<p><button class="tl" type="submit"><?= e(t('Pokračovat')) ?></button></p>
</form>
<?php else: ?>
<p class="hlaska hlaska-ok"><?= e(t('Import obsahu je hotový.')) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) $v['clanky'] ?></strong><span><?= e(t('Nové novinky')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['stranky'] ?></strong><span><?= e(t('Nové stránky')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['rubriky'] ?></strong><span><?= e(t('Nové kategorie')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['presmerovani'] ?></strong><span><?= e(t('Přesměrování ze starých adres')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['preskoceno'] ?></strong><span><?= e(t('Přeskočeno (převedeno už dříve)')) ?></span></div>
</div>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('admin.php?module=news')) ?>"><?= e(t('Zobrazit novinky')) ?></a> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět na Import a export')) ?></a></p>

<h2><?= e(t('Obrázky ze starého webu')) ?></h2>
<?php if ($state['faze'] === 'obrazky-hotovo'): ?>
<p class="hlaska <?= (int) $o['chyb'] > 0 ? 'hlaska-varovani' : 'hlaska-ok' ?>"><?= e(t('Staženo %s obrázků, nepodařilo se %s.', (int) $o['stazeno'], (int) $o['chyb'])) ?></p>
<?php if ($o['chyby'] !== []): ?>
<details class="pokrocile"><summary><?= e(t('Poslední obrázky, které se nepodařilo stáhnout')) ?></summary><ul>
<?php foreach ($o['chyby'] as $row): ?>
	<li><?= e($row) ?></li>
<?php endforeach ?>
</ul></details>
<?php endif ?>
<?php endif ?>
<?php if (!$canDownload): ?>
<p class="hlaska"><?= e(t('Tento server neumí stahovat soubory z jiných webů (chybí curl i allow_url_fopen, případně rozšíření GD). Obrázky přeneste ručně: nahrajte je do Médií a v článcích je vyměňte.')) ?></p>
<?php elseif ($domain === ''): ?>
<p class="hlaska"><?= e(t('V souboru chybí adresa starého webu, obrázky proto nejde stáhnout.')) ?></p>
<?php else: ?>
<p><?= e(t('Novinky a stránky zatím ukazují obrázky ze starého webu. Stažením se hlavní obrázky novinek a obrázky v textech uloží do Médií (zmenší se, vzniknou náhledy a WebP) a odkazy v textech se přepíší. Stahuje se výhradně z domény %s a starý web musí být ještě dostupný.', $domain)) ?></p>
<form method="post" action="<?= e($module->url('images')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($state['soubor']) ?>">
	<p><button class="tl" type="submit"><?= e(t($state['faze'] === 'obrazky-hotovo' ? 'Zkusit stáhnout znovu' : 'Stáhnout obrázky ze starého webu')) ?></button></p></form>
<?php endif ?>
<?php endif ?>
