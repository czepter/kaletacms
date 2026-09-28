<?php
/**
 * Komponenty webu.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Components $module
 * @var string $csrf
 * @var list<array<string, mixed>> $components  i s počtem použití (pouziti) a jejich místy (mista)
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('Nová komponenta')) ?></a></p>
<?php if ($components === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'komponenta', 'heading' => t('Zatím žádné komponenty.'), 'text' => t('Komponenta je blok, který používáte na víc místech – karta služby, kontaktní pruh, výzva. V builderu vyberte prvek a zvolte „Uložit jako komponentu“; když ji pak upravíte, změní se všude najednou.'), 'action' => [$module->url('new'), t('Založit komponentu')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Komponenta')) ?></th><th scope="col"><?= e(t('Vlastnosti')) ?></th><th scope="col"><?= e(t('Použitá')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($components as $k): ?>
<tr>
	<td><a href="<?= e($module->url('builder', ['id' => $k['idm']])) ?>"><strong><?= e($k['nazev']) ?></strong></a><?= $k['stavba_koncept'] !== null && $k['stavba'] !== null ? ' <span class="stitek stitek-koncept">' . e(t('nepublikované změny')) . '</span>' : '' ?></td>
	<td><?= $k['vlastnosti'] === [] ? '—' : implode(' ', array_map(fn (array $v): string => '<code>{{' . e($v['klic']) . '}}</code>', $k['vlastnosti'])) ?></td>
	<td<?= $k['mista'] !== [] ? ' title="' . e(implode(', ', $k['mista'])) . '"' : '' ?>><?= e(t('%s×', (string) $k['pouziti'])) ?></td>
	<td class="akce"><a href="<?= e($module->url('builder', ['id' => $k['idm']])) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $k['idm']])) ?>"><?= e(t('Název a vlastnosti')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e($k['pouziti'] > 0 ? t('Komponentu „%s“ používá: %s. Po smazání tam zůstane prázdné místo. Opravdu ji smazat?', $k['nazev'], implode(', ', array_slice($k['mista'], 0, 8)) . (count($k['mista']) > 8 ? ' ' . t('a %d dalších', count($k['mista']) - 8) : '')) : t('Opravdu smazat komponentu?')) ?>"><?= $csrf ?><input type="hidden" name="idm" value="<?= (int) $k['idm'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
