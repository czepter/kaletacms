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
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New collection')) ?></a></p>
<?php endif ?>
<?php if ($collection === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('No collections yet.'), 'text' => t('A collection is a list of similar things with their own fields – testimonials, team members, products, branches, a price list. Put them on the site with the Collection list element in the builder; each item can also have its own page.'), 'action' => $admin ? [$module->url('new'), t('Create a collection')] : null]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Collections')) ?></th><th scope="col"><?= e(t('Items')) ?></th><th scope="col"><?= e(t('Item pages')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($collection as $k): ?>
<tr>
	<td><a href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"><strong><?= e($k['nazev']) ?></strong></a></td>
	<td><?= (int) $k['pocet'] ?></td>
	<td><?= $k['detail'] ? '/' . e($k['seo_link']) . '/…' : e(t('no')) ?></td>
	<td class="akce"><a href="<?= e($module->url('item', ['id' => $k['idk']])) ?>"><?= e(t('Add item')) ?></a><?php if ($admin): ?> · <a href="<?= e($module->url('edit', ['id' => $k['idk']])) ?>"><?= e(t('Fields and settings')) ?></a><?php if ($k['detail']): ?> · <a href="<?= e($module->url('builder', ['id' => $k['idk']])) ?>"><?= e(t('Detail template')) ?></a><?php endif ?><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
