<?php
/**
 * Editing a page or news item directly on the site: the same editor as in the admin, embedded in the site template.
 * It is saved with a normal form to the admin (action uloz_text), so the same permissions and versions apply.
 *
 * @var Kaleta\Core\App $app
 * @var string $typ       novinka | stranka
 * @var array<string, mixed> $zaznam
 * @var string $akce      url for saving
 * @var string $zpet      url to return to after saving or cancelling
 * @var bool $chyba       saving failed (empty title)
 */
?>
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
<article class="clanek clanek-cely ka-upravit-text ka-ui">
	<form method="post" action="<?= e($akce) ?>">
		<input type="hidden" name="_csrf" value="<?= e($app->session->csrfToken()) ?>">
		<input type="hidden" name="id" value="<?= (int) ($zaznam['idc'] ?? $zaznam['ids']) ?>">
		<input type="hidden" name="zpet" value="<?= e($zpet) ?>">
<?php if ($chyba): ?>
		<p class="ka-upravit-hlaska"><?= e(t('Titulek nesmí zůstat prázdný.')) ?></p>
<?php endif ?>
		<p><label for="ka-titulek"><?= e(t('Titulek')) ?></label>
			<input class="ka-upravit-titulek" type="text" id="ka-titulek" name="titulek" value="<?= e($zaznam['titulek']) ?>" maxlength="200" required></p>
<?php if ($typ === 'novinka'): ?>
		<p><label for="ka-uvod"><?= e(t('Perex')) ?></label>
			<textarea id="ka-uvod" name="uvod" rows="4" data-editor="maly"><?= e($zaznam['uvod']) ?></textarea></p>
<?php endif ?>
		<p><label for="ka-text"><?= e(t('Text')) ?></label>
			<textarea id="ka-text" name="text" rows="18" data-editor><?= e($zaznam['text']) ?></textarea></p>
		<div class="ka-upravit-lista">
			<button class="ka-tl" type="submit"><?= e(t('Uložit')) ?></button>
			<a class="ka-tl ka-tl-vedlejsi" href="<?= e($zpet) ?>"><?= e(t('Zrušit')) ?></a>
			<a class="ka-upravit-vse" href="<?= e($app->url('admin.php?module=' . ($typ === 'novinka' ? 'novinky' : 'stranky') . '&action=edit&id=' . (int) ($zaznam['idc'] ?? $zaznam['ids']))) ?>"><?= e(t('Všechna nastavení v administraci')) ?></a>
		</div>
	</form>
</article>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(KALETA_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" defer></script>
