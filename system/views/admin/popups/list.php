<?php
/**
 * Pop-up okna webu s počitadly.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Popups $module
 * @var string $csrf
 * @var list<array<string, mixed>> $popups
 */
use Kaleta\Builder\Popups;

?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('Nové pop-up okno')) ?></a></p>
<?php if ($popups === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'popupy', 'heading' => t('Zatím žádná pop-up okna.'), 'text' => t('Okno přes stránku pro přihlášení k newsletteru, materiál ke stažení, oznámení nebo akci. Obsah poskládáte v builderu, v nastavení určíte, kdy a kde se ukáže. Počitadla fungují bez cookies.'), 'action' => [$module->url('new'), t('Založit okno')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Okno')) ?></th><th scope="col"><?= e(t('Kdy se ukáže')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Počet zobrazení')) ?></th><th scope="col"><?= e(t('Zavření')) ?></th><th scope="col"><?= e(t('Konverze')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($popups as $p): ?>
<?php
    $unit = Popups::TRIGGERS[$p['spoustec']][1] ?? '';
    $when = t(Popups::TRIGGERS[$p['spoustec']][0] ?? '') . ($unit !== '' ? ': ' . $p['hodnota'] . ' ' . t($unit) : '');
?>
<tr>
	<td><a href="<?= e($module->url('builder', ['id' => $p['idpp']])) ?>"><strong><?= e($p['nazev']) ?></strong></a><br><span class="napoveda"><?= e(t(Popups::TYPES[$p['typ']][0] ?? '')) ?> · <code>#popup-<?= e($p['adresa']) ?></code></span></td>
	<td><?= e($when) ?><br><span class="napoveda"><?= e($p['pravidla']['kde'] === 'vse' ? t('na celém webu') : t('na vybraných místech')) ?><?= $p['spoustec'] !== 'klik' ? ' · ' . e(t(Popups::FREQUENCIES[$p['cetnost']] ?? '')) : '' ?></span></td>
	<td><?php if ($p['aktivni']): ?><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span><?php elseif ($p['stavba'] === null): ?><span class="stitek stitek-koncept"><?= e(t('nepublikované')) ?></span><?php else: ?><span class="stitek"><?= e(t('vypnuté')) ?></span><?php endif ?><?= $p['stavba_koncept'] !== null && $p['stavba'] !== null && $p['stavba_koncept'] !== $p['stavba'] ? ' <span class="stitek stitek-koncept">' . e(t('nepublikované změny')) . '</span>' : '' ?></td>
	<td class="cislo"><?= (int) $p['zobrazeni'] ?></td>
	<td class="cislo"><?= (int) $p['zavreni'] ?></td>
	<td class="cislo"><?= (int) $p['konverze'] ?><?= $p['zobrazeni'] > 0 ? ' <span class="napoveda">(' . e(t('%d %%', (int) round($p['konverze'] / $p['zobrazeni'] * 100))) . ')</span>' : '' ?></td>
	<td class="akce">
		<a href="<?= e($module->url('builder', ['id' => $p['idpp']])) ?>"><?= e(t('Upravit v builderu')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $p['idpp']])) ?>"><?= e(t('Nastavení')) ?></a>
		· <form class="vradku" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>"><button class="navigace" type="submit"><?= e($p['aktivni'] ? t('Vypnout') : t('Zapnout')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="napoveda"><?= e(t('Konverze je odeslaný formulář nebo přihlášení k odběru v okně. Počitadla fungují bez cookies a bez údajů o návštěvnících.')) ?></p>
<?php endif ?>
