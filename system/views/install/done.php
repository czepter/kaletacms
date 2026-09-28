<?php
/**
 * @var string $base
 * @var bool $alreadyInstalled
 * @var bool $deleted the installer deleted itself
 */
?>
<!doctype html>
<html lang="<?= e($language ?? 'cs') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('Kaleta installation')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e($base) ?>/image/kaleta-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($base) ?>/image/kaleta-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($base) ?>/image/kaleta-znacka-180.png">
<link rel="stylesheet" href="<?= e($base) ?>/image/install.css?v=<?= e(KALETA_VERSION) ?>">
</head>
<body>
<main class="instalator">
<header class="uvod">
	<div class="znacka"><?php $height = 40; $markOnly = false; require KALETA_SYSTEM . '/views/admin/logo.php'; ?></div>
<?php if ($alreadyInstalled): ?>
	<h1><?= e(t('Kaleta is already installed')) ?></h1>
	<p><?= e(t('The config.php file exists, so the installer changes nothing.')) ?></p>
<?php else: ?>
	<h1><?= e(t('Done, your website is running')) ?></h1>
	<p><?= e(t('The database is ready and the configuration has been written.')) ?></p>
<?php endif ?>
</header>
<?php if ($deleted): ?>
<p class="hlaska hlaska-ok"><?= e(t('For security reasons install.php has deleted itself – there is nothing else you need to do.')) ?></p>
<?php else: ?>
<p class="hlaska <?= $alreadyInstalled ? 'hlaska-chyba' : 'hlaska-ok' ?>"><?= e(t('For security reasons, now delete this file from the server:')) ?> <strong>install.php</strong>.</p>
<?php endif ?>
<div class="akce">
	<a class="tlacitko" href="<?= e($base) ?>/admin.php"><?= e(t('Go to the administration')) ?></a>
	<a class="tlacitko druhe" href="<?= e($base) ?>/"><?= e(t('Zobrazit web')) ?></a>
</div>
</main>
</body>
</html>
