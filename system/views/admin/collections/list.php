<?php
/**
 * Kolekce webu.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var list<array<string, mixed>> $collection
 */
$admin = $app->auth()->isAdmin();
?>
<?php if ($admin): ?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('Nová kolekce')) ?></a></p>
<?php endif ?>
<?php if ($collection === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('Zatím žádné kolekce.'), 'text' => t('Kolekce je seznam podobných věcí s vlastními poli – reference, členové týmu, produkty, pobočky, ceník. Na web je dostanete prvkem Výpis kolekce v builderu, každá položka může mít i vlastní stránku.'), 'action' => $admin ? [$module->url('new'), t('Založit kolekci')] : null]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Kolekce')) ?></th><th scope="col"><?= e(t('Položek')) ?></th><th scope="col"><?= e(t('Stránky položek')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($collection as $k): ?>
<tr>
	<td><a href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"><strong><?= e($k['nazev']) ?></strong></a></td>
	<td><?= (int) $k['pocet'] ?></td>
	<td><?= $k['detail'] ? '/' . e($k['seo_link']) . '/…' : e(t('ne')) ?></td>
	<td class="akce"><a href="<?= e($module->url('item', ['id' => $k['idk']])) ?>"><?= e(t('Přidat položku')) ?></a><?php if ($admin): ?> · <a href="<?= e($module->url('edit', ['id' => $k['idk']])) ?>"><?= e(t('Pole a nastavení')) ?></a><?php if ($k['detail']): ?> · <a href="<?= e($module->url('builder', ['id' => $k['idk']])) ?>"><?= e(t('Šablona detailu')) ?></a><?php endif ?><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
