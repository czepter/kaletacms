<?php
/**
 * Části webu: záhlaví, patička a obálky – stav (ze šablony / z builderu) a vstup do builderu.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\SiteParts $module
 * @var string $csrf
 * @var array<string, array{0:string, 1:string}> $types
 * @var list<string> $languages  '' = výchozí jazyk webu
 * @var array<string, string> $languageNames
 * @var array<string, array<string, mixed>> $rows  "typ:jazyk" => stav části
 * @var array<string, list<array<string, mixed>>> $variants  "typ:jazyk" => varianty (záhlaví, patička)
 * @var array<int, string> $pageNames
 */
?>
<p class="napoveda"><?= e(t('Záhlaví a patička jsou na každé stránce webu. Obálky přidají sekce kolem obsahu, který skládá systém – novinky, výpisu a hlášení 404. Dokud část nepublikujete z builderu, má výchozí podobu.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Část')) ?></th><?php if (count($languages) > 1): ?><th scope="col"><?= e(t('Jazyk')) ?></th><?php endif ?><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($types as $type => [$name, $description]): ?>
<?php foreach ($languages as $language): $r = $rows[$type . ':' . $language] ?? null; $params = ['typ' => $type, 'jazyk' => $language]; ?>
<tr>
	<td><a href="<?= e($module->url('stavitel', $params)) ?>"><strong><?= e(t($name)) ?></strong></a><br><small class="napoveda"><?= e(t($description)) ?></small></td>
<?php if (count($languages) > 1): ?>
	<td><?= e($languageNames[$language]) ?></td>
<?php endif ?>
	<td><?php if ($r !== null && $r['publikovana']): ?><span class="stitek stitek-vydano"><?= e(t('z builderu')) ?></span><?php else: ?><span class="stitek"><?= e(t('výchozí')) ?></span><?php endif ?><?= $r !== null && $r['zmeny'] ? ' <span class="stitek stitek-koncept">' . e(t('nepublikované změny')) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($module->url('stavitel', $params)) ?>"><?= e(t($r === null ? 'Upravit v builderu' : 'Builder')) ?></a><?php if (in_array($type, Kaleta\Builder\SiteParts::WITH_VARIANTS, true)): ?> · <a href="<?= e($module->url('varianta', $params)) ?>"><?= e(t('Přidat variantu')) ?></a><?php endif ?><?php if ($r !== null): ?> ·
		<form class="vradku" method="post" action="<?= e($module->url('sablona', $params)) ?>" data-potvrdit="<?= e(t('Vrátit část na výchozí podobu? Podoba z builderu zůstane ve verzích.')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Vrátit na výchozí')) ?></button></form><?php endif ?></td>
</tr>
<?php foreach ($variants[$type . ':' . $language] ?? [] as $v): $variantParams = $params + ['varianta' => $v['varianta']]; $onPages = array_filter(array_map(fn (int $i): ?string => $pageNames[$i] ?? null, array_map('intval', json_decode((string) $v['stranky'], true) ?: []))); ?>
<tr>
	<td>↳ <a href="<?= e($module->url('stavitel', $variantParams)) ?>"><?= e($v['nazev']) ?></a><br><small class="napoveda"><?= $onPages === [] ? e(t('zatím na žádné stránce')) : e(t('na stránkách: %s', implode(', ', $onPages))) ?></small></td>
<?php if (count($languages) > 1): ?>
	<td></td>
<?php endif ?>
	<td><?php if ($v['publikovana']): ?><span class="stitek stitek-vydano"><?= e(t('varianta')) ?></span><?php else: ?><span class="stitek stitek-koncept"><?= e(t('nepublikovaná')) ?></span><?php endif ?><?= $v['zmeny'] && $v['publikovana'] ? ' <span class="stitek stitek-koncept">' . e(t('nepublikované změny')) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($module->url('stavitel', $variantParams)) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('varianta', $variantParams)) ?>"><?= e(t('Stránky')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('sablona', $variantParams)) ?>" data-potvrdit="<?= e(t('Smazat variantu? Vybrané stránky dostanou výchozí podobu.')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
<?php endforeach ?>
<?php endforeach ?>
</tbody>
</table>
</div>
