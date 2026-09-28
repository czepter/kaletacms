<?php
/**
 * Položky kolekce.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var list<array<string, mixed>> $items
 * @var list<string> $languages další jazyky webu (šablona detailu pro každý zvlášť)
 * @var list<string> $siteLanguages všechny jazyky webu pro filtr (prázdné = jediný jazyk)
 * @var string $language zvolený jazyk filtru ('' = všechny)
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('item', ['id' => $k['idk']])) ?>"><?= e(t('Přidat položku')) ?></a>
	<a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Všechny kolekce')) ?></a>
<?php if ($app->auth()->isAdmin()): ?>
	<a class="navigace" href="<?= e($module->url('edit', ['id' => $k['idk']])) ?>"><?= e(t('Pole a nastavení')) ?></a>
<?php if ($k['detail']): ?>
	<a class="navigace" href="<?= e($module->url('builder', ['id' => $k['idk']])) ?>"><?= e(t('Šablona detailu')) ?></a>
<?php foreach ($languages as $language): ?>
	<a class="navigace" href="<?= e($module->url('builder', ['id' => $k['idk'], 'jazyk' => $language])) ?>"><?= e(t('Šablona detailu (%s)', strtoupper($language))) ?></a>
<?php endforeach ?>
<?php endif ?>
<?php endif ?></p>
<?php if ($siteLanguages !== []): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt"><input type="hidden" name="module" value="collections"><input type="hidden" name="action" value="items"><input type="hidden" name="id" value="<?= (int) $k['idk'] ?>"><?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true]) ?></form>
<?php endif ?>
<?php if ($items === [] && $language === ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('Kolekce je zatím prázdná.'), 'text' => t('Přidejte první položku – na web ji pak dostanete prvkem Výpis kolekce v builderu.'), 'action' => [$module->url('item', ['id' => $k['idk']]), t('Přidat položku')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($items as $p): ?>
<tr<?= $p['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($module->url('item', ['id' => $k['idk'], 'polozka' => $p['idp']])) ?>"><?= e($p['nazev']) ?></a><?= $p['jazyk'] !== '' ? ' <span class="stitek">' . e(strtoupper($p['jazyk'])) . '</span>' : '' ?></td>
	<td><?= (int) $p['poradi'] ?></td>
	<td><span class="stitek stitek-<?= $p['zobrazit'] ? 'vydano' : 'koncept' ?>"><?= e(t($p['zobrazit'] ? 'zveřejněná' : 'skrytá')) ?></span></td>
	<td class="akce"><?php if ($k['detail'] && $p['zobrazit']): ?><a href="<?= e($app->url(($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $k['seo_link'] . '/' . $p['seo_link'])) ?>" target="_blank" rel="noopener"><?= e(t('Zobrazit')) ?></a> · <?php endif ?>
		<form class="vradku" method="post" action="<?= e($module->url('duplicate_item')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace" type="submit"><?= e(t('Duplikovat')) ?></button></form> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete_item')) ?>" data-potvrdit="<?= e(t('Opravdu smazat položku?')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
