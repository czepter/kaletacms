<?php
/**
 * Consent to connecting an app via OAuth (the Claude connector and other MCP clients).
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array{nazev:string, redirect_uri:string} $pending
 * @var array<string, mixed> $user
 * @var string $url  the server the app returns to after consent
 */
$role = t(Kaleta\Core\Auth::TYPES[(int) $user['admin']] ?? '');
?>
<div class="oauth-souhlas">
	<p class="oauth-kdo"><strong><?= e($pending['nazev']) ?></strong> <?= e(t('chce pracovat s webem %s.', $app->settings()->get('site_name'))) ?></p>
	<p><?= e(t('Bude jednat s právy vašeho účtu %s (%s): číst a upravovat stránky, novinky, části webu a vzhled – stejně jako vy v administraci. Stavby a novinky ukládá jako koncept.', (string) $user['user'], $role)) ?></p>
	<p class="napoveda"><?= e(t('Po povolení se vrátíte do aplikace na adrese %s. Připojení kdykoli zrušíte v Můj účet → Připojené aplikace.', $url)) ?></p>
	<form method="post" action="<?= e($app->url('admin.php?action=oauth')) ?>" class="tlacitka">
		<?= $csrf ?>
		<button class="tl" type="submit" name="povolit" value="1"><?= e(t('Povolit přístup')) ?></button>
		<button class="navigace" type="submit" name="povolit" value="0"><?= e(t('Nepovolit')) ?></button>
	</form>
</div>
