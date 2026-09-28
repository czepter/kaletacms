<?php
/**
 * Obnova zapomenutého hesla do administrace (Admin\ObnovaHesla).
 *
 * @var Kaleta\Core\App $app
 * @var string $step zadost | heslo | neplatny
 * @var bool $sent
 * @var ?string $error
 * @var string $token
 * @var string $account
 */
?>
<!doctype html>
<html lang="<?= e(Kaleta\Core\Language::code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/tema.js')) ?>?v=<?= e(KALETA_VERSION) ?>"></script>
<title><?= e(t('Zapomenuté heslo')) ?> – Kaleta</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/kaleta-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/kaleta-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/kaleta-znacka-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
</head>
<body class="login">
<div class="login-karta">
<?= $app->view->render('admin/logo', ['height' => 36]) ?>
<h1><?= e(t($step === 'heslo' ? 'Nové heslo' : 'Zapomenuté heslo')) ?></h1>
<?php if ($error !== null): ?>
<p class="hlaska hlaska-chyba" role="alert"><?= e($error) ?></p>
<?php endif ?>
<?php if ($step === 'heslo'): ?>
<form method="post" action="<?= e($app->url('admin.php?action=password')) ?>">
<?= $app->session->csrfField() ?>
<input type="hidden" name="token" value="<?= e($token) ?>">
<p><?= e(t('Účet: %s', $account)) ?></p>
<div class="login-pole"><label for="password"><?= e(t('Nové heslo')) ?></label> <input class="textpole" type="password" id="password" name="password" size="20" minlength="10" autocomplete="new-password" required autofocus></div>
<div class="login-pole"><label for="password2"><?= e(t('Heslo znovu')) ?></label> <input class="textpole" type="password" id="password2" name="password2" size="20" minlength="10" autocomplete="new-password" required></div>
<p class="smltxt"><?= e(t('Alespoň 10 znaků. Dvoufázové přihlášení zůstává zapnuté.')) ?></p>
<p><input class="tl" type="submit" value="<?= e(t('Nastavit heslo')) ?>"></p>
</form>
<?php elseif ($sent): ?>
<p class="hlaska hlaska-ok" role="status"><?= e(t('Pokud takový účet existuje a má vyplněný e-mail, poslali jsme na něj odkaz pro nastavení nového hesla. Platí hodinu.')) ?></p>
<?php elseif ($step === 'zadost'): ?>
<form method="post" action="<?= e($app->url('admin.php?action=password')) ?>">
<?= $app->session->csrfField() ?>
<p><?= e(t('Zadejte přihlašovací jméno nebo e-mail svého účtu. Pošleme vám odkaz pro nastavení nového hesla.')) ?></p>
<div class="login-pole"><label for="kdo"><?= e(t('Přihlašovací jméno nebo e-mail')) ?></label> <input class="textpole" type="text" id="kdo" name="kdo" size="20" maxlength="190" autocomplete="username" required autofocus></div>
<p><input class="tl" type="submit" value="<?= e(t('Poslat odkaz')) ?>"></p>
</form>
<?php endif ?>
<p class="login-odkaz"><a href="<?= e($app->url($step === 'neplatny' ? 'admin.php?action=password' : 'admin.php')) ?>"><?= e(t($step === 'neplatny' ? 'Požádat o nový odkaz' : 'Zpět na přihlášení')) ?></a></p>
</div>
</body>
</html>
