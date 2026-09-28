<?php
/**
 * News item editor: text on the left, settings on the right (one below the other on a narrow screen).
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var string $csrf
 * @var array<string, mixed> $newsItem
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $category
 * @var array<int, string> $authors
 * @var bool $canPublish
 * @var bool $assistant  the AI assistant is enabled and has a key
 * @var list<string> $translationLanguages  languages the news item can be translated into (only for a saved news item in the default language)
 * @var array<string, int> $translations  existing translations: language => news item number
 * @var array{cas:string, data:string}|null $draftOnServer  unsaved work stored on the server (from another device)
 * @var bool $siteLanguages  the site has other language versions
 * @var string $original  url of the news item this one is a translation of
 * @var string $tags  comma-separated tags
 * @var list<string> $allTags
 * @var list<array<string, mixed>> $versions
 */
$dt = fn (?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět na přehled novinek')) ?></a></p>

<?php if (!empty($draftOnServer)): ?>
<script type="application/json" id="koncept-server"><?= json_encode(['cas' => strtotime($draftOnServer['cas']) * 1000, 'pole' => json_decode($draftOnServer['data'], true)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
<form class="formular formular-clanek" method="post" action="<?= e($module->url('save')) ?>" data-koncept="novinka-<?= (int) $newsItem['idc'] ?>" data-koncept-url="<?= e($module->url('draft')) ?>"<?= $assistant ? ' data-asistent="' . e($module->url('assistant')) . '"' : '' ?>>
<?= $csrf ?>
<input type="hidden" name="idc" value="<?= (int) $newsItem['idc'] ?>">

<div class="clanek-hlavni">
	<div class="radek pres-celou">
		<label for="titulek"><?= e(t('Titulek')) ?></label>
		<input class="textpole siroke titulek-pole" type="text" id="titulek" name="titulek" value="<?= e($newsItem['titulek']) ?>" maxlength="255" required placeholder="<?= e(t('Titulek novinky')) ?>"><?= $error('titulek') ?>
	</div>
	<div class="radek pres-celou">
		<label for="uvod"><?= e(t('Perex (úvod)')) ?></label>
		<textarea class="textbox" id="uvod" name="uvod" rows="5" data-editor="maly"><?= e($newsItem['uvod']) ?></textarea>
		<span class="napoveda"><?= e(t('Zobrazuje se ve výpisech i na začátku novinky – v textu ho neopakujte.')) ?></span>
	</div>
	<div class="radek pres-celou">
		<label for="text"><?= e(t('Text')) ?></label>
		<textarea class="textbox vysoky" id="text" name="text" rows="20" data-editor><?= e($newsItem['text']) ?></textarea>
		<span class="napoveda"><?= e(t('Video vložíte tak, že jeho adresu (YouTube, Vimeo) dáte na samostatný řádek. Návštěvníkovi se načte až po kliknutí.')) ?></span>
	</div>
</div>

<aside class="clanek-nastaveni">
<fieldset>
<legend><?= e(t('Vydání')) ?></legend>
<div class="radek">
	<label for="stav"><?= e(t('Stav')) ?></label>
	<div><select id="stav" name="stav">
		<option value="koncept"<?= !$newsItem['visible'] ? ' selected' : '' ?>><?= e(t('Koncept')) ?></option>
<?php if ($canPublish): ?>
		<option value="vydany"<?= $newsItem['visible'] ? ' selected' : '' ?>><?= e(t('Vydaná')) ?></option>
<?php endif ?>
	</select>
<?php if (!$canPublish): ?>
	<span class="napoveda"><?= e(t('Novinku vydá editor nebo správce webu.')) ?></span>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="datum"><?= e(t('Datum vydání')) ?></label>
	<div><input class="textpole" type="datetime-local" id="datum" name="datum" value="<?= e($dt($newsItem['datum'])) ?>" required>
	<span class="napoveda"><?= e(t('Budoucí datum = novinka se vydá sama v daný čas.')) ?></span></div>
</div>
<?php if ($newsItem['visible']): ?>
<div class="radek"><span class="popisek"></span><div class="volby"><label><input type="checkbox" name="oznacit_aktualizaci" value="1"> <?= e(t('Označit jako aktualizovanou (s dnešním datem)')) ?></label></div></div>
<?php endif ?>
<?php $readOnly = $newsItem['visible'] && !$canPublish; /* a published news item is edited only by an editor – the author sees it but cannot save it */ ?>
<?php if ($readOnly): ?>
<p class="napoveda"><?= e(t('Novinka je vydaná – změny v ní uloží jen editor nebo správce. Požádejte je o úpravu.')) ?></p>
<?php endif ?>
<p class="tlacitka ulozit-lista">
	<button class="tl" type="submit" name="po_ulozeni" value="vypis"<?= $readOnly ? ' disabled' : '' ?>><?= e(t('Uložit')) ?></button>
	<button class="tl" type="submit" name="po_ulozeni" value="zustat"<?= $readOnly ? ' disabled' : '' ?>><?= e(t('Uložit a pokračovat')) ?></button>
<?php if ($newsItem['idc']): ?>
	<a class="navigace" href="<?= e($module->app()->url('novinky/' . $newsItem['seo_link'] . '?nahled=1')) ?>" target="_blank" rel="noopener"><?= e(t('Náhled')) ?></a>
<?php endif ?>
</p>
</fieldset>

<fieldset>
<legend><?= e(t('Zařazení')) ?></legend>
<?php if (count($category) < 2): ?>
<input type="hidden" name="tema" value="<?= (int) ($category[0]['idt'] ?? $newsItem['tema']) ?>">
<?php else: ?>
<div class="radek">
	<label for="tema"><?= e(t('Kategorie')) ?></label>
	<div><select id="tema" name="tema" required>
<?php foreach ($category as $k): ?>
		<option value="<?= (int) $k['idt'] ?>"<?= (int) $newsItem['tema'] === (int) $k['idt'] ? ' selected' : '' ?>><?= e($k['nazev']) ?></option>
<?php endforeach ?>
	</select><?= $error('tema') ?></div>
</div>
<?php endif ?>
<?php if (count($authors) < 2): ?>
<input type="hidden" name="autor" value="<?= (int) (array_key_first($authors) ?? $newsItem['autor']) ?>">
<?php else: ?>
<div class="radek">
	<label for="autor"><?= e(t('Autor')) ?></label>
	<div><select id="autor" name="autor">
<?php foreach ($authors as $userId => $displayName): ?>
		<option value="<?= (int) $userId ?>"<?= (int) $newsItem['autor'] === (int) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select><?= $error('autor') ?></div>
</div>
<?php endif ?>
<div class="radek">
	<label for="stitky"><?= e(t('Štítky')) ?></label>
	<div><input class="textpole siroke" type="text" id="stitky" name="stitky" value="<?= e($tags) ?>" maxlength="600" list="stitky-seznam" autocomplete="off" data-stitky>
	<datalist id="stitky-seznam"><?php foreach ($allTags as $s): ?><option value="<?= e($s) ?>"><?php endforeach ?></datalist>
	<span class="napoveda"><?= e(t('Oddělené čárkou. Návštěvník si podle štítku zobrazí související novinky.')) ?></span></div>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Hlavní obrázek')) ?></legend>
<div class="radek pres-celou">
	<input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($newsItem['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('vyberte z médií, nebo vložte adresu')) ?>" aria-label="<?= e(t('Hlavní obrázek')) ?>" data-obrazek>
	<span class="napoveda"><?= e(t('Použije se ve výpisech a při sdílení na sociálních sítích.')) ?></span>
</div>
<div class="radek pres-celou">
	<label for="obrazek_popis"><?= e(t('Popisek obrázku')) ?></label>
	<input class="textpole siroke" type="text" id="obrazek_popis" name="obrazek_popis" value="<?= e($newsItem['obrazek_popis']) ?>" maxlength="300">
</div>
<div class="radek pres-celou">
	<label for="obrazek_autor"><?= e(t('Autor obrázku')) ?></label>
	<div><input class="textpole siroke" type="text" id="obrazek_autor" name="obrazek_autor" value="<?= e($newsItem['obrazek_autor']) ?>" maxlength="120">
	<span class="napoveda"><?= e(t('Prázdné pole = popisek a autor z knihovny Médií.')) ?></span></div>
</div>
</fieldset>

<?php if ($siteLanguages): ?>
<details class="pokrocile"<?= $original !== '' || $translations !== [] ? ' open' : '' ?>>
<summary><?= e(t('Překlad')) ?></summary>
<?php if ($translationLanguages !== []): ?>
<div class="radek pres-celou">
	<span class="popisek"><?= e(t('Jazykové verze')) ?></span>
	<div class="volby">
<?php foreach ($translationLanguages as $languageCode): $languageName = Kaleta\Core\Language::AVAILABLE[$languageCode][0]; ?>
<?php if (isset($translations[$languageCode])): ?>
		<a class="navigace" href="<?= e($module->url('edit', ['id' => $translations[$languageCode]])) ?>"><?= e($languageName) ?>: <?= e(t('otevřít překlad')) ?></a>
<?php elseif ($assistant): ?>
		<button class="navigace" type="submit" name="prelozit_do" value="<?= e($languageCode) ?>" formaction="<?= e($module->url('translate')) ?>" formnovalidate data-potvrdit="<?= e(t('Přeložit uloženou verzi asistentem? Vznikne koncept, který před vydáním přečtete. Překlad může trvat i minutu.')) ?>"><?= e(t('Přeložit asistentem')) ?>: <?= e($languageName) ?></button>
<?php else: ?>
		<span class="napoveda vradku"><?= e($languageName) ?>: <?= e(t('zatím bez překladu')) ?></span>
<?php endif ?>
<?php endforeach ?>
	</div>
</div>
<?php endif ?>
<div class="radek pres-celou">
	<label for="preklad_z"><?= e(t('Originál ve výchozím jazyce')) ?></label>
	<input class="textpole siroke" type="text" id="preklad_z" name="preklad_z" value="<?= e($original) ?>" maxlength="255" placeholder="<?= e(t('adresa nebo číslo původní novinky')) ?>">
	<span class="napoveda"><?= e(t('Vyplňte jen u novinky v jiné jazykové verzi (jazyk určuje kategorie).')) ?></span>
</div>
</details>
<?php endif ?>

<fieldset class="kontrola" data-kontrola>
<legend><?= e(t('Kontrola přístupnosti')) ?></legend>
<div data-kontrola-vysledek aria-live="polite"><p class="napoveda"><?= e(t('Kontrola běží při psaní (potřebuje JavaScript).')) ?></p></div>
</fieldset>

<details class="pokrocile"<?= $newsItem['seo_titulek'] !== '' || $newsItem['seo_popis'] !== '' || (string) $newsItem['faq'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('SEO a další nastavení')) ?></summary>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($newsItem['seo_link']) ?>" maxlength="150" placeholder="<?= e(t('vytvoří se z titulku')) ?>">
	<span class="napoveda"><?= e(t('Část adresy za %s. Když ji po vydání změníte, stará adresa se sama přesměruje.', substr($app->url('novinky/'), strlen($app->request->basePath())))) ?></span></div>
</div>
<div class="radek">
	<label for="seo_titulek"><?= e(t('Titulek pro vyhledávače')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_titulek" name="seo_titulek" value="<?= e($newsItem['seo_titulek']) ?>" maxlength="255" placeholder="<?= e(t('prázdné = titulek novinky')) ?>"></div>
</div>
<div class="radek">
	<label for="seo_popis"><?= e(t('Popis pro vyhledávače')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_popis" name="seo_popis" value="<?= e($newsItem['seo_popis']) ?>" maxlength="320" placeholder="<?= e(t('prázdné = začátek perexu')) ?>"></div>
</div>
<div class="radek">
	<label for="t_slova"><?= e(t('Klíčová slova')) ?></label>
	<div><input class="textpole siroke" type="text" id="t_slova" name="t_slova" value="<?= e($newsItem['t_slova']) ?>" maxlength="500">
	<span class="napoveda"><?= e(t('Oddělená čárkou; pomáhají vyhledávání na webu.')) ?></span></div>
</div>
<div class="radek">
	<label for="faq"><?= e(t('Otázky a odpovědi')) ?></label>
	<div><textarea class="textbox nizky" id="faq" name="faq" rows="5"><?= e((string) $newsItem['faq']) ?></textarea>
	<span class="napoveda"><?= e(t('Otázka na jednom řádku, odpověď pod ní, mezi dvojicemi prázdný řádek. Zobrazí se pod textem a ve strukturovaných datech (FAQ).')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Možnosti')) ?></span>
	<div class="volby"><label><input type="checkbox" name="noindex" value="1"<?= $newsItem['noindex'] ? ' checked' : '' ?>> <?= e(t('Skrýt před vyhledávači (noindex)')) ?></label></div>
</div>
</details>
<?php if ($versions !== []): ?>
<details class="pokrocile">
<summary><?= e(t('Historie verzí (%s)', count($versions))) ?></summary>
<ul class="revize">
<?php foreach ($versions as $version): ?>
	<li><a href="<?= e($module->url('versions', ['id' => $newsItem['idc'], 'idr' => $version['idr']])) ?>" title="<?= e($version['titulek']) ?>"><?= e(format_date($version['datum'], true)) ?></a> <span class="napoveda vradku"><?= e($version['kdo_jm'] ?? '') ?></span> · <a href="<?= e($module->url('compare', ['id' => $newsItem['idc'], 'idr' => $version['idr']])) ?>"><?= e(t('co se změnilo')) ?></a></li>
<?php endforeach ?>
</ul>
<p class="napoveda"><?= e(t('Kliknutím načtete starší verzi do editoru. Uchovává se posledních 20 verzí.')) ?></p>
</details>
<?php endif ?>
</aside>
</form>
<script src="<?= e($module->app()->url('image/pomocnik.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
