<?php
/**
 * Collection items.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var list<array<string, mixed>> $items
 * @var list<string> $languages other languages of the site (a detail template for each one separately)
 * @var list<string> $siteLanguages all languages of the site for the filter (empty = a single language)
 * @var string $language language selected in the filter ('' = all)
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('item', ['id' => $k['idk']])) ?>"><?= e(t('Add item')) ?></a>
	<a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('All collections')) ?></a>
<?php if ($app->auth()->isAdmin()): ?>
	<a class="navigace" href="<?= e($module->url('edit', ['id' => $k['idk']])) ?>"><?= e(t('Fields and settings')) ?></a>
<?php if ($k['detail']): ?>
	<a class="navigace" href="<?= e($module->url('builder', ['id' => $k['idk']])) ?>"><?= e(t('Detail template')) ?></a>
<?php foreach ($languages as $language): ?>
	<a class="navigace" href="<?= e($module->url('builder', ['id' => $k['idk'], 'jazyk' => $language])) ?>"><?= e(t('Detail template (%s)', strtoupper($language))) ?></a>
<?php endforeach ?>
<?php endif ?>
<?php endif ?></p>
<?php if ($siteLanguages !== []): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt"><input type="hidden" name="module" value="collections"><input type="hidden" name="action" value="items"><input type="hidden" name="id" value="<?= (int) $k['idk'] ?>"><?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true]) ?></form>
<?php endif ?>
<?php if ($items === [] && $language === ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('The collection is empty.'), 'text' => t('Add the first item – then put it on the site with the Collection list element in the builder.'), 'action' => [$module->url('item', ['id' => $k['idk']]), t('Add item')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($items as $p): ?>
<tr<?= $p['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($module->url('item', ['id' => $k['idk'], 'polozka' => $p['idp']])) ?>"><?= e($p['nazev']) ?></a><?= $p['jazyk'] !== '' ? ' <span class="stitek">' . e(strtoupper($p['jazyk'])) . '</span>' : '' ?></td>
	<td><?= (int) $p['poradi'] ?></td>
	<td><span class="stitek stitek-<?= $p['zobrazit'] ? 'vydano' : 'koncept' ?>"><?= e(t($p['zobrazit'] ? 'zveřejněná' : 'skrytá')) ?></span></td>
	<td class="akce"><?php if ($k['detail'] && $p['zobrazit']): ?><a href="<?= e($app->url(($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $k['seo_link'] . '/' . $p['seo_link'])) ?>" target="_blank" rel="noopener"><?= e(t('Show')) ?></a> · <?php endif ?>
		<form class="vradku" method="post" action="<?= e($module->url('duplicate_item')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace" type="submit"><?= e(t('Duplicate')) ?></button></form> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete_item')) ?>" data-potvrdit="<?= e(t('Really delete this item?')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
