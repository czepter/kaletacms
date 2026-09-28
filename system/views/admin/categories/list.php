<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Categories $module
 * @var string $csrf
 * @var list<array<string, mixed>> $category
 * @var list<string> $siteLanguages
 * @var string $language
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('Nová kategorie')) ?></a> <a class="navigace" href="<?= e($app->url('admin.php?module=news')) ?>"><?= e(t('Zpět na novinky')) ?></a></p>
<?php if ($siteLanguages !== []): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt"><input type="hidden" name="module" value="categories"><?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true]) ?></form>
<?php endif ?>
<?php if ($category === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'rubriky', 'heading' => t('Zatím není založena žádná kategorie.'), 'text' => t('Každá novinka patří do jedné kategorie – bez ní novinka nepůjde uložit.'), 'action' => [$module->url('new'), t('Založit první kategorii')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Novinek')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($category as $k): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => $k['idt']])) ?>"><?= e($k['nazev']) ?></a><?= ($k['jazyk'] ?? '') !== '' ? ' <span class="stitek">' . e(strtoupper($k['jazyk'])) . '</span>' : '' ?></td>
	<td><?= e('/' . (($k['jazyk'] ?? '') !== '' ? $k['jazyk'] . '/' : '') . ltrim(substr($app->url('novinky/kategorie/' . $k['seo_link']), strlen($app->request->basePath())), '/')) ?></td>
	<td class="cislo"><?= (int) $k['pocet_clanku'] ?></td>
	<td class="cislo"><?= (int) $k['hodnost'] ?></td>
	<td class="akce">
		<a href="<?= e($module->url('edit', ['id' => $k['idt']])) ?>"><?= e(t('Upravit')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Opravdu smazat kategorii?')) ?>"><?= $csrf ?><input type="hidden" name="idt" value="<?= (int) $k['idt'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
