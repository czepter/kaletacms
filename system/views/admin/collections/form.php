<?php
/**
 * Collection definition: name, slug, item pages and fields.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 */
use Kaleta\Builder\Collections;

$field = array_merge($k['pole'], array_fill(0, 3, ['klic' => '', 'popisek' => '', 'typ' => 'text']));
?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Collection name')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($k['nazev']) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Testimonials, Team, Products')) ?>"></div></div>
<div class="radek"><label for="seo_link"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="seo_link" name="seo_link" value="<?= e($k['seo_link']) ?>" maxlength="110"><span class="napoveda"><?= e(t('From the name if left empty. Item pages will then be at /address/item-name.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Item pages')) ?></span><div class="volby"><label><input type="checkbox" name="detail" value="1"<?= $k['detail'] ? ' checked' : '' ?>> <?= e(t('each item has its own page (detail)')) ?></label>
<span class="napoveda"><?= e(t('Design the detail page in the builder (Detail template). Without a detail page, items are just cards in the list.')) ?></span></div></div>
<fieldset>
<legend><?= e(t('Item fields')) ?></legend>
<p class="napoveda"><?= e(t('Every item always has a name. Add the fields you need – in the builder you insert them with a {{key}} tag. An empty label removes the field; the key of a saved field never changes.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Label')) ?></th><th scope="col"><?= e(t('Typ')) ?></th><th scope="col"><?= e(t('Značka')) ?></th></tr></thead>
<tbody>
<?php foreach ($field as $i => $p): ?>
<tr>
	<td><input class="textpole" name="pole[<?= $i ?>][popisek]" value="<?= e($p['popisek']) ?>" maxlength="80" aria-label="<?= e(t('Label')) ?>"><input type="hidden" name="pole[<?= $i ?>][klic]" value="<?= e($p['klic']) ?>"></td>
	<td><select name="pole[<?= $i ?>][typ]" aria-label="<?= e(t('Typ')) ?>">
<?php foreach (Collections::FIELD_TYPES as $type => $name): ?>
		<option value="<?= e($type) ?>"<?= $p['typ'] === $type ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></td>
	<td><?= $p['klic'] !== '' ? '<code>{{' . e($p['klic']) . '}}</code>' : '<span class="napoveda">' . e(t('created from the label')) . '</span>' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save collection')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
<?php if ($k['idk'] > 0): ?>
<div class="navigace-radek akce-dole"><form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the collection with all its items? Lists on the site will disappear.')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete collection')) ?></button></form></div>
<?php endif ?>
