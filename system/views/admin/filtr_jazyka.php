<?php
/**
 * Výběr jazykové verze ve filtru výpisu (stránky, kategorie, položky kolekcí). Web s jediným jazykem nic nevypíše.
 * S $odeslat se výpis přefiltruje hned po výběru (formulář bez dalších polí).
 *
 * @var list<string> $jazykyWebu
 * @var string $jazyk zvolený kód ('' = všechny)
 * @var bool|null $odeslat
 */
if ($jazykyWebu === []) {
    return;
}
?>
	<label><?= e(t('Jazyk:')) ?>
		<select name="jazyk"<?= !empty($odeslat) ? ' data-odeslat-pri-zmene' : '' ?>>
			<option value=""><?= e(t('všechny')) ?></option>
<?php foreach ($jazykyWebu as $kod): ?>
			<option value="<?= e($kod) ?>"<?= $jazyk === $kod ? ' selected' : '' ?>><?= e(Kaleta\Core\Jazyk::DOSTUPNE[$kod][0]) ?></option>
<?php endforeach ?>
		</select>
	</label>
