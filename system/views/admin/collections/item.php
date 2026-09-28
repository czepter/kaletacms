<?php
/**
 * Collection item form – fields according to the collection definition.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $p
 */
use Kaleta\Core\Language;

$languages = Language::additional($app->settings());
?>
<form class="formular" method="post" action="<?= e($module->url('save_item')) ?>">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Název')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($p['nazev']) ?>" maxlength="200" required></div></div>
<?php foreach ($k['pole'] as $field): $h = (string) ($p['data'][$field['klic']] ?? ''); $id = 'pole-' . $field['klic']; $displayName = 'data[' . $field['klic'] . ']'; ?>
<div class="radek<?= $field['typ'] === 'html' ? ' pres-celou' : '' ?>">
	<label for="<?= e($id) ?>"><?= e($field['popisek']) ?></label>
	<div><?= match ($field['typ']) {
        'radky' => '<textarea class="textbox nizky" id="' . e($id) . '" name="' . e($displayName) . '" rows="4">' . e($h) . '</textarea>',
        'html' => '<textarea class="textbox" id="' . e($id) . '" name="' . e($displayName) . '" rows="10" data-editor>' . e($h) . '</textarea>',
        'obrazek' => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500" data-obrazek>',
        'odkaz' => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500" placeholder="https://… ' . e(t('or')) . ' /stranka">',
        'cislo' => '<input class="textpole" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" inputmode="decimal" size="12">',
        'datum' => '<input class="textpole" type="date" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '">',
        default => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500">',
    } ?> <code class="napoveda">{{<?= e($field['klic']) ?>}}</code></div>
</div>
<?php endforeach ?>
<details class="pokrocile">
<summary><?= e(t('Address, order and visibility')) ?></summary>
<?php if ($k['detail']): ?>
<div class="radek"><label for="seo_link"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="seo_link" name="seo_link" value="<?= e($p['seo_link']) ?>" maxlength="150"><span class="napoveda">/<?= e($k['seo_link']) ?>/…</span></div></div>
<?php else: ?>
<input type="hidden" name="seo_link" value="<?= e($p['seo_link']) ?>">
<?php endif ?>
<div class="radek"><label for="poradi"><?= e(t('Pořadí')) ?></label><div><input class="textpole" type="number" id="poradi" name="poradi" value="<?= (int) $p['poradi'] ?>" min="-9999" max="9999"><span class="napoveda"><?= e(t('Smaller number = earlier in the list.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Display')) ?></span><div class="volby"><label><input type="checkbox" name="zobrazit" value="1"<?= $p['zobrazit'] ? ' checked' : '' ?>> <?= e(t('published on the site')) ?></label></div></div>
<?php if ($languages !== []): ?>
<div class="radek"><label for="jazyk"><?= e(t('Language')) ?></label><div><select id="jazyk" name="jazyk">
	<option value=""><?= e(Language::AVAILABLE[Language::defaults($app->settings())][0]) ?></option>
<?php foreach ($languages as $j): ?>
	<option value="<?= e($j) ?>"<?= $p['jazyk'] === $j ? ' selected' : '' ?>><?= e(Language::AVAILABLE[$j][0]) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endif ?>
</details>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save item')) ?>"> <a class="navigace" href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"><?= e(t('Back')) ?></a></p>
</form>
