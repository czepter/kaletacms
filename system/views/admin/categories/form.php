<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Categories $module
 * @var string $csrf
 * @var array<string, mixed> $category
 * @var array<string, string> $errors
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="idt" value="<?= (int) $category['idt'] ?>">
<div class="radek">
	<label for="nazev"><?= e(t('Název kategorie')) ?></label>
	<div><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($category['nazev']) ?>" maxlength="100" required><?= $error('nazev') ?></div>
</div>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($category['seo_link']) ?>" maxlength="110" placeholder="<?= e(t('vytvoří se z názvu')) ?>">
	<span class="napoveda"><?= e(t('Část adresy za %s.', substr($app->url('novinky/kategorie/'), strlen($app->request->basePath())))) ?></span></div>
</div>
<div class="radek">
	<label for="popis"><?= e(t('Popis')) ?></label>
	<div><textarea class="textbox" id="popis" name="popis" rows="4"><?= e($category['popis']) ?></textarea>
	<span class="napoveda"><?= e(t('Zobrazí se nad výpisem novinek kategorie a použije se jako popis pro vyhledávače.')) ?></span></div>
</div>
<div class="radek">
	<label for="hodnost"><?= e(t('Pořadí')) ?></label>
	<div><input class="textpole" type="number" id="hodnost" name="hodnost" value="<?= (int) $category['hodnost'] ?>" min="0" max="65535">
	<span class="napoveda"><?= e(t('Vyšší číslo = výš v seznamu.')) ?></span></div>
</div>
<?= $app->view->render('admin/language_field', ['app' => $app, 'value' => (string) ($category['jazyk'] ?? ''), 'translationOf' => (int) ($category['preklad_z'] ?? 0), 'originals' => $app->db()->pairs("SELECT idt, nazev FROM {kategorie} WHERE jazyk = '' ORDER BY nazev"), 'hint' => t('Novinky v kategorii patří do této jazykové verze webu.')]) ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($category['idt'] ? 'Uložit' : 'Přidat')) ?>"></p>
</form>
