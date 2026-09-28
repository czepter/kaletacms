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
<title><?= e(t('Instalace Kalety')) ?></title>
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
	<h1><?= e(t('Kaleta je už nainstalovaná')) ?></h1>
	<p><?= e(t('Soubor config.php existuje, instalátor proto nic nemění.')) ?></p>
<?php else: ?>
	<h1><?= e(t('Hotovo, web běží')) ?></h1>
	<p><?= e(t('Databáze je připravena a konfigurace zapsána.')) ?></p>
<?php endif ?>
</header>
<?php if ($deleted): ?>
<p class="hlaska hlaska-ok"><?= e(t('Soubor install.php se z bezpečnostních důvodů smazal sám – nic dalšího dělat nemusíte.')) ?></p>
<?php else: ?>
<p class="hlaska <?= $alreadyInstalled ? 'hlaska-chyba' : 'hlaska-ok' ?>"><?= e(t('Z bezpečnostních důvodů teď ze serveru smažte soubor')) ?> <strong>install.php</strong>.</p>
<?php endif ?>
<div class="akce">
	<a class="tlacitko" href="<?= e($base) ?>/admin.php"><?= e(t('Přejít do administrace')) ?></a>
	<a class="tlacitko druhe" href="<?= e($base) ?>/"><?= e(t('Zobrazit web')) ?></a>
</div>
</main>
</body>
</html>
