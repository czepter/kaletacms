<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pages $module
 * @var string $csrf
 * @var list<array<string, mixed>> $pages
 * @var bool $trash     zobrazen koš
 * @var string $search
 * @var int $inTrash    počet stránek v koši
 */
$home = $app->settings()->int('home_page');
$url = fn (array $s): string => ($s['jazyk'] !== '' ? $s['jazyk'] . '/' : '') . ((int) $s['ids'] === $home ? '' : $s['seo_link']);
?>
<div class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('Nová stránka')) ?></a>
	<form class="vradku" method="post" action="<?= e($module->url('import')) ?>" enctype="multipart/form-data"><?= $csrf ?>
		<label class="navigace"><?= e(t('Import stránky (JSON)')) ?> <input type="file" name="soubor" accept="application/json,.json" data-odeslat-pri-zmene></label></form></div>
<?php if ($inTrash > 0 || $trash): // záložky jen s košem – samotné „Všechny“ nemají smysl ?>
<nav class="zalozky" aria-label="<?= e(t('Stránky')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $trash ? '' : ' class="aktivni" aria-current="true"' ?>><?= e(t('Všechny')) ?></a>
	<a href="<?= e($module->url('', ['stav' => 'kos'])) ?>"<?= $trash ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Koš')) ?> (<?= $inTrash ?>)</a>
</nav>
<?php endif ?>
<?php if (!$trash && ($pages !== [] || $search !== '' || $language !== '')): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="module" value="pages">
<?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language]) ?>
	<label><?= e(t('Název nebo adresa obsahuje:')) ?> <input class="textpole" type="search" name="hledat" value="<?= e($search) ?>" size="20"></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>">
</form>
<br>
<?php endif ?>
<?php if ($pages === [] && $trash): ?>
<?= $app->view->render('admin/empty', ['icon' => 'stranky', 'heading' => t('Koš je prázdný.'), 'text' => t('Smazané stránky tu zůstávají 30 dní, potom se smažou natrvalo.'), 'action' => [$module->url(), t('Zpět na stránky')]]) ?>
<?php elseif ($pages === [] && $search !== ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'stranky', 'heading' => t('Hledání neodpovídá žádná stránka.'), 'text' => t('Zkuste jiné slovo.'), 'action' => [$module->url(), t('Zrušit hledání')]]) ?>
<?php elseif ($pages === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'stranky', 'heading' => t('Zatím žádné stránky.'), 'text' => t('Firemní web obvykle tvoří Úvod, O nás, Služby a Kontakt.'), 'action' => [$module->url('new'), t('Založit první stránku')]]) ?>
<?php elseif ($trash): ?>
<p class="smltxt"><?= e(t('Stránky v koši nejsou na webu. Obnovená stránka se vrátí jako skrytá; po 30 dnech se z koše smaže natrvalo.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('V koši od')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($pages as $s): ?>
<tr class="nevydany">
	<td><?= e($s['titulek']) ?></td>
	<td>/<?= e($s['seo_link']) ?></td>
	<td class="cislo"><?= e(format_date($s['smazano'], true)) ?></td>
	<td class="akce">
		<form class="vradku" method="post" action="<?= e($module->url('restore')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Obnovit')) ?></button></form> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete_permanently')) ?>" data-potvrdit="<?= e(t('Smazat stránku natrvalo? Nejde to vrátit.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat natrvalo')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('V navigaci')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($pages as $s): ?>
<tr<?= $s['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><?= !empty($s['uroven']) ? '<span class="odsazeni-stromu" style="padding-inline-start:' . ((int) $s['uroven'] - 1) * 1.2 . 'em">↳ </span>' : '' ?><a href="<?= e($module->url('edit', ['id' => $s['ids']])) ?>"><?= e($s['titulek']) ?></a><?= (int) $s['ids'] === $home ? ' <span class="stitek">' . e(t('úvodní')) . '</span>' : '' ?><?= $s['stavba'] !== null || $s['stavba_koncept'] !== null ? ' <span class="stitek stitek-vydano">' . e(t('stavba')) . '</span>' : '' ?><?= $s['stavba_koncept'] !== null ? ' <span class="stitek stitek-koncept" title="' . e(t('V builderu jsou změny, které ještě nejsou na webu.')) . '">' . e(t('nepublikované změny')) . '</span>' : '' ?><?= $s['noindex'] ? ' <span class="stitek">noindex</span>' : '' ?><?= $s['zverejnit_od'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Zveřejní se sama')) . '">' . e(t('od %s', format_date($s['zverejnit_od'], true))) . '</span>' : '' ?></td>
	<td><a href="<?= e($app->url($url($s)) . ($s['zobrazit'] ? '' : '?stavba=koncept')) ?>" target="_blank" rel="noopener"<?= $s['zobrazit'] ? '' : ' title="' . e(t('Náhled skryté stránky')) . '"' ?>>/<?= e($url($s)) ?></a></td>
	<td><span class="stitek stitek-<?= $s['zobrazit'] ? 'vydano' : 'koncept' ?>"><?= e(t($s['zobrazit'] ? 'zveřejněná' : 'skrytá')) ?></span></td>
	<td><?= e(t($s['v_menu'] ? 'Ano' : 'Ne')) ?></td>
	<td class="akce"><a href="<?= e($module->url('builder', ['id' => $s['ids']])) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $s['ids']])) ?>"><?= e(t('Nastavení')) ?></a> ·
		<a href="<?= e($module->url('new', ['nadrazena' => $s['ids']])) ?>" title="<?= e(t('Nová stránka pod touto')) ?>"><?= e(t('Podstránka')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('duplicate')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Duplikovat')) ?></button></form>
<?php if ((int) $s['ids'] !== $home): ?> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Přesunout stránku do koše? Z webu zmizí, obnovit ji můžete 30 dní.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
