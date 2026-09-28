<?php
/**
 * Uživatel: jméno, přihlášení a role. Oprávnění plynou z role; ruční nastavení je schované v „Podrobném nastavení“.
 *
 * @var Kaleta\Admin\Modules\Users $module
 * @var string $csrf
 * @var array<string, mixed> $author
 * @var array<string, string> $errors
 * @var bool $isSelf  admin upravuje vlastní účet
 * @var array<string, string> $modules  ident => název (sekce, ke kterým se přístup nastavuje)
 * @var list<string> $hasModules
 * @var bool $manual  přístup do sekcí je nastavený ručně (liší se od výchozího pro roli)
 * @var list<array<string, mixed>> $customRoles  role z Uživatelé → Role
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
$role = [
    0 => ['Autor novinek', 'Píše a upravuje vlastní novinky. Vydává je editor.'],
    1 => ['Editor', 'Spravuje veškerý obsah webu – stránky, novinky, média – a vydává.'],
    2 => ['Správce', 'Všechno včetně uživatelů, vzhledu a nastavení webu.'],
];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<?php if (($summary ?? '') !== ''): ?>
<p class="hlaska"><strong><?= e(t('Co teď smí:')) ?></strong> <?= e($summary) ?></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" autocomplete="off">
<?= $csrf ?>
<input type="hidden" name="idu" value="<?= (int) $author['idu'] ?>">
<div class="radek">
	<label for="jmeno"><?= e(t('Jméno a příjmení')) ?></label>
	<div><input class="textpole siroke" type="text" id="jmeno" name="jmeno" value="<?= e($author['jmeno']) ?>" maxlength="100"><span class="napoveda"><?= e(t('Zobrazuje se u novinek.')) ?></span></div>
</div>
<div class="radek">
	<label for="user"><?= e(t('Přihlašovací jméno')) ?></label>
	<div><input class="textpole" type="text" id="user" name="user" value="<?= e($author['user']) ?>" maxlength="40" size="30" required><?= $error('user') ?></div>
</div>
<div class="radek">
	<label for="email"><?= e(t('E-mail')) ?></label>
	<div><input class="textpole siroke" type="email" id="email" name="email" value="<?= e($author['email']) ?>" maxlength="190"><?= $error('email') ?></div>
</div>
<div class="radek">
	<label for="password"><?= e(t($author['idu'] ? 'Nové heslo' : 'Heslo')) ?></label>
	<div><input class="textpole" type="password" id="password" name="password" size="30" minlength="10" autocomplete="new-password">
	<label style="font-weight:normal"><input type="checkbox" data-ukaz-heslo="password"> <?= e(t('zobrazit')) ?></label><?= $error('password') ?>
	<span class="napoveda"><?= e(t('Alespoň 10 znaků.')) ?> <?= e(t($author['idu'] ? 'Nechte prázdné, pokud heslo neměníte.' : 'Uživatel si ho pak změní v nabídce Můj účet.')) ?></span>
<?php if (!$author['idu']): ?>
	<label style="font-weight:normal"><input type="checkbox" name="pozvat" value="1"> <?= e(t('Místo hesla poslat pozvánku e-mailem – heslo si uživatel nastaví sám')) ?></label>
<?php endif ?>
	</div>
</div>

<fieldset>
<legend><?= e(t('Role')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach ($role as $value => [$name, $description]): ?>
	<label class="karta-volba">
		<input type="radio" name="admin" value="<?= $value ?>"<?= (int) $author['admin'] === $value && empty($author['role']) ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
		<strong><?= e(t($name)) ?></strong>
		<span><?= e(t($description)) ?></span>
	</label>
<?php endforeach ?>
<?php foreach ($customRoles as $customRole): ?>
	<label class="karta-volba">
		<input type="radio" name="admin" value="r<?= (int) $customRole['idr'] ?>"<?= (int) ($author['role'] ?? 0) === (int) $customRole['idr'] ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
		<strong><?= e($customRole['nazev']) ?></strong>
		<span><?= e($customRole['popis'] !== '' ? $customRole['popis'] : t('Vlastní role')) ?></span>
	</label>
<?php endforeach ?>
</div>
<p class="napoveda"><a href="<?= e($app->url('admin.php?module=roles')) ?>"><?= e(t('Vlastní role')) ?></a> – <?= e(t('pojmenovaná sada sekcí, třeba jen Poptávky pro obchodníka.')) ?></p>
<?php if ($isSelf): ?>
<p class="napoveda"><?= e(t('Vlastní účet nemůžete zbavit práv správce.')) ?></p>
<?php endif ?>
</fieldset>

<details class="pokrocile"<?= $manual || $author['blokovat'] || !empty($author['url']) ? ' open' : '' ?>>
<summary><?= e(t('Podrobné nastavení')) ?></summary>
<div class="radek">
	<label for="url"><?= e(t('Web uživatele')) ?></label>
	<input class="textpole siroke" type="url" id="url" name="url" value="<?= e($author['url']) ?>" maxlength="255" placeholder="https://">
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Přístup do sekcí')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="rucne" value="1"<?= $manual ? ' checked' : '' ?>> <?= e(t('nastavit ručně (jinak podle role)')) ?></label><br>
<?php foreach ($modules as $ident => $name): ?>
		<label style="margin-left:22px"><input type="checkbox" name="moduly[]" value="<?= e($ident) ?>"<?= in_array($ident, $hasModules, true) ? ' checked' : '' ?>> <?= e(t($name)) ?></label><br>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('Autor má běžně jen Novinky, editor všechny obsahové sekce, správce vše. U vlastní role platí sekce z role.')) ?></span>
	</div>
</div>
<?php if (!empty($author['totp_tajemstvi'])): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Dvoufázové přihlášení')) ?></span>
	<div class="volby"><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span> <label><input type="checkbox" name="totp_reset" value="1"> <?= e(t('vypnout (uživatel ztratil telefon i záložní kódy)')) ?></label></div>
</div>
<?php endif ?>
<?php if (!$isSelf): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Zablokovat účet')) ?></span>
	<div class="volby"><label><input type="checkbox" name="blokovat" value="1"<?= $author['blokovat'] ? ' checked' : '' ?>> <?= e(t('uživatel se nepřihlásí')) ?></label></div>
</div>
<?php endif ?>
</details>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($author['idu'] ? 'Uložit' : 'Přidat uživatele')) ?>"></p>
</form>
<?php if ($author['idu'] && $author['email'] !== '' && !$author['blokovat']): ?>
<div class="navigace-radek akce-dole"><form class="vradku" method="post" action="<?= e($module->url('password_link')) ?>" data-potvrdit="<?= e(t('Poslat uživateli e-mailem odkaz na nastavení nového hesla?')) ?>"><?= $csrf ?><input type="hidden" name="idu" value="<?= (int) $author['idu'] ?>"><input type="hidden" name="user" value="<?= e($author['user']) ?>"><button class="navigace" type="submit"><?= e(t('Poslat odkaz na nové heslo')) ?></button></form></div>
<?php endif ?>
