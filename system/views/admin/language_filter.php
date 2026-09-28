<?php
/**
 * Výběr jazykové verze ve filtru výpisu (stránky, kategorie, položky kolekcí). Web s jediným jazykem nic nevypíše.
 * S $submitOnChange se výpis přefiltruje hned po výběru (formulář bez dalších polí).
 *
 * @var list<string> $siteLanguages
 * @var string $language zvolený kód ('' = všechny)
 * @var bool|null $submitOnChange
 */
if ($siteLanguages === []) {
    return;
}
?>
	<label><?= e(t('Jazyk:')) ?>
		<select name="jazyk"<?= !empty($submitOnChange) ? ' data-odeslat-pri-zmene' : '' ?>>
			<option value=""><?= e(t('všechny')) ?></option>
<?php foreach ($siteLanguages as $code): ?>
			<option value="<?= e($code) ?>"<?= $language === $code ? ' selected' : '' ?>><?= e(Kaleta\Core\Language::AVAILABLE[$code][0]) ?></option>
<?php endforeach ?>
		</select>
	</label>
