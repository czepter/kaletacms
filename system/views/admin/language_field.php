<?php
/**
 * Form row "Jazyková verze" (language version) - only when the site has other languages (the Language versions extension).
 *
 * @var Kaleta\Core\App $app
 * @var string $value  current value of the jazyk column ('' = default language)
 * @var string $hint
 * @var array<int, string> $originals  items in the default language from which the original of the translation can be chosen
 * @var int $translationOf
 */
use Kaleta\Core\Language;

$additional = Language::additional($app->settings());
if ($additional === []) {
    return;
}
?>
<div class="radek">
	<label for="jazyk"><?= e(t('Jazyková verze')) ?></label>
	<div><select id="jazyk" name="jazyk">
		<option value=""><?= e(Language::AVAILABLE[Language::defaults($app->settings())][0]) ?> (<?= e(t('výchozí')) ?>)</option>
<?php foreach ($additional as $code): ?>
		<option value="<?= e($code) ?>"<?= $value === $code ? ' selected' : '' ?>><?= e(Language::AVAILABLE[$code][0]) ?> – /<?= e($code) ?>/</option>
<?php endforeach ?>
	</select>
<?php if (($hint ?? '') !== ''): ?>
	<span class="napoveda"><?= e($hint) ?></span>
<?php endif ?>
	</div>
</div>
<?php if (($originals ?? []) !== []): ?>
<div class="radek">
	<label for="preklad_z"><?= e(t('Je překladem')) ?></label>
	<div><select id="preklad_z" name="preklad_z">
		<option value="0"><?= e(t('– není překlad –')) ?></option>
<?php foreach ($originals as $originalId => $originalName): ?>
		<option value="<?= (int) $originalId ?>"<?= (int) ($translationOf ?? 0) === (int) $originalId ? ' selected' : '' ?>><?= e($originalName) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Vyplňte u položky v jiné jazykové verzi: přepínač jazyků pak vede přímo na protějšek a vyhledávače dostanou značky hreflang.')) ?></span></div>
</div>
<?php endif ?>
