<?php
/**
 * @var string $base
 * @var list<array{nazev:string, ok:bool, info:string}> $requirements
 * @var array<string, string> $data
 * @var array<string, string> $errors
 * @var string $language  jazyk instalace (cs, en)
 * @var array<string, string> $languages
 * @var list<string> $extensions  zaškrtnutá rozšíření
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e($errors[$field]) . '</span>' : '';
$fulfilled = !in_array(false, array_column($requirements, 'ok'), true);
?>
<!doctype html>
<html lang="<?= e($language) ?>">
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
<nav class="jazyky" aria-label="Language">
<?php foreach ($languages as $code => $languageName): ?>
	<a href="?jazyk=<?= e($code) ?>"<?= $code === $language ? ' class="aktivni" aria-current="true"' : '' ?> lang="<?= e($code) ?>"><?= e($languageName) ?></a>
<?php endforeach ?>
</nav>
<header class="uvod">
	<div class="znacka"><?php $height = 40; $markOnly = false; require KALETA_SYSTEM . '/views/admin/logo.php'; ?></div>
	<h1><?= e(t('Instalace Kalety')) ?></h1>
	<p><?= e(t('Pár krátkých kroků a váš web běží. Vše lze později změnit v administraci.')) ?></p>
</header>

<section class="krok">
	<h2><span>1</span> <?= e(t('Kontrola serveru')) ?></h2>
	<ul class="kontrola">
<?php foreach ($requirements as $p): ?>
		<li<?= $p['ok'] ? '' : ' class="spatne"' ?>><div><?= e($p['nazev']) ?> <small>– <?= e($p['info']) ?></small></div></li>
<?php endforeach ?>
	</ul>
</section>

<?php if (!$fulfilled): ?>
<p class="hlaska hlaska-chyba" role="alert"><?= e(t('Server nesplňuje požadavky. Opravte položky označené křížkem a obnovte stránku.')) ?></p>
<?php else: ?>
<?php if ($errors !== []): ?>
<p class="hlaska hlaska-chyba" role="alert"><?= e(t('Instalaci se nepodařilo dokončit – zkontrolujte zvýrazněná pole.')) ?></p>
<?php endif ?>
<form method="post" autocomplete="off">
<input type="hidden" name="jazyk" value="<?= e($language) ?>">
<section class="krok">
	<h2><span>2</span> <?= e(t('Databáze')) ?></h2>
	<p><?= e(t('MySQL nebo MariaDB. Prázdnou databázi založte předem – na hostingu v jeho administraci.')) ?></p>
	<div class="pole">
		<div class="cele s-portem">
			<div><label for="db_host"><?= e(t('Server')) ?></label><input type="text" id="db_host" name="db_host" value="<?= e($data['db_host']) ?>"><?= $error('db_host') ?></div>
			<div><label for="db_port"><?= e(t('Port')) ?></label><input type="number" id="db_port" name="db_port" value="<?= e($data['db_port']) ?>"></div>
		</div>
		<div><label for="db_name"><?= e(t('Název databáze')) ?></label><input type="text" id="db_name" name="db_name" value="<?= e($data['db_name']) ?>" required><?= $error('db_name') ?></div>
		<div><label for="db_prefix"><?= e(t('Předpona tabulek')) ?></label><input type="text" id="db_prefix" name="db_prefix" value="<?= e($data['db_prefix']) ?>" required><?= $error('db_prefix') ?></div>
		<div><label for="db_user"><?= e(t('Uživatel')) ?></label><input type="text" id="db_user" name="db_user" value="<?= e($data['db_user']) ?>" required><?= $error('db_user') ?></div>
		<div><label for="db_password"><?= e(t('Heslo')) ?></label><input type="password" id="db_password" name="db_password" autocomplete="off"></div>
	</div>
</section>

<section class="krok">
	<h2><span>3</span> <?= e(t('Web a administrátor')) ?></h2>
	<p><?= e(t('Účet, kterým se poprvé přihlásíte do administrace.')) ?></p>
	<div class="pole">
		<div class="cele"><label for="nazev_webu"><?= e(t('Název webu')) ?></label><input type="text" id="nazev_webu" name="nazev_webu" value="<?= e($data['nazev_webu']) ?>" required></div>
		<fieldset class="cele weby">
			<legend><?= e(t('Začít s webem')) ?></legend>
<?php foreach (Kaleta\Builder\Library::SITES as $key => $w): $colors = Kaleta\Builder\DesignSystem::PRESETS[$w['predvolba']][2]['barvy']; ?>
			<label class="web"><input type="radio" name="web" value="<?= e($key) ?>"<?= ($data['web'] ?: 'firemni') === $key ? ' checked' : '' ?>>
				<span class="vzorky"><i style="background:<?= e($colors['primarni']) ?>"></i><i style="background:<?= e($colors['sekundarni']) ?>"></i><i style="background:<?= e($colors['plocha']) ?>"></i></span>
				<strong><?= e(t($w['nazev'])) ?></strong><small><?= e(t($w['popis'])) ?></small></label>
<?php endforeach ?>
			<span class="napoveda"><?= e(t('Startovací web přinese stránky Úvod, O nás, Služby a Kontakt s ukázkovými texty a svůj styl – obsah pak upravíte v builderu, styl ve Vzhledu webu.')) ?></span>
		</fieldset>
		<div><label for="user"><?= e(t('Přihlašovací jméno')) ?></label><input type="text" id="user" name="user" value="<?= e($data['user']) ?>" required><?= $error('user') ?></div>
		<div><label for="jmeno"><?= e(t('Jméno a příjmení')) ?></label><input type="text" id="jmeno" name="jmeno" value="<?= e($data['jmeno']) ?>"><span class="napoveda"><?= e(t('Zobrazuje se u novinek.')) ?></span></div>
		<div class="cele"><label for="email"><?= e(t('E-mail')) ?></label><input type="email" id="email" name="email" value="<?= e($data['email']) ?>"><?= $error('email') ?></div>
		<div><label for="password"><?= e(t('Heslo')) ?></label><input type="password" id="password" name="password" autocomplete="new-password" minlength="10" required><?= $error('password') ?><span class="napoveda"><?= e(t('Alespoň 10 znaků.')) ?></span></div>
		<div><label for="password2"><?= e(t('Heslo znovu')) ?></label><input type="password" id="password2" name="password2" autocomplete="new-password" required></div>
		<div class="cele"><label for="jazyk_webu"><?= e(t('Jazyk webu')) ?></label><select id="jazyk_webu" name="jazyk_webu">
<?php foreach (Kaleta\Core\Language::AVAILABLE as $code => [$languageName]): ?>
			<option value="<?= e($code) ?>"<?= $data['jazyk_webu'] === $code ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
		</select><span class="napoveda"><?= e(t('V tomto jazyce vzniknou ukázkové stránky a texty pro návštěvníky. Administrace zůstane v jazyce instalace.')) ?></span></div>
		<div class="cele"><label for="casove_pasmo"><?= e(t('Časové pásmo')) ?></label><select id="casove_pasmo" name="casove_pasmo">
<?php foreach (DateTimeZone::listIdentifiers() as $timeZone): ?>
			<option value="<?= e($timeZone) ?>"<?= $data['casove_pasmo'] === $timeZone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $timeZone)) ?></option>
<?php endforeach ?>
		</select><span class="napoveda"><?= e(t('Podle něj se vydávají naplánované novinky a zobrazují data.')) ?></span></div>
	</div>
</section>

<section class="krok">
	<h2><span>4</span> <?= e(t('Co chcete mít zapnuté')) ?></h2>
	<p><?= e(t('Rozšíření lze kdykoli zapnout nebo vypnout v administraci (Rozšíření). Vypnutím se nic nesmaže.')) ?></p>
	<div class="rozsireni">
<?php foreach (Kaleta\Core\Extensions::CATALOG as $key => [$extensionName, $extensionDescription]): ?>
		<label class="web"><input type="checkbox" name="rozsireni[]" value="<?= e($key) ?>"<?= in_array($key, $extensions, true) ? ' checked' : '' ?>>
			<strong><?= e(t($extensionName)) ?></strong><small><?= e(t($extensionDescription)) ?></small></label>
<?php endforeach ?>
	</div>
</section>

<div class="akce">
	<button class="tlacitko" type="submit"><?= e(t('Nainstalovat Kaletu')) ?></button>
	<small><?= e(t('Vytvoří tabulky v databázi a soubor config.php.')) ?></small>
</div>
</form>
<?php endif ?>
</main>
</body>
</html>
