<?php
/** The "Firma" (Company) tab: details for the site (the Company details element) and for search engines (schema.org Organization / LocalBusiness). */
use Kaleta\Front\Company;

?>
<p class="hlaska"><?= e(t('Údaje vyplníte jednou a web je použije všude: v patičce a na kontaktu (prvek Údaje firmy v builderu) i pro Google, Mapy a AI asistenty – ti tak správně odpoví na otázku, kdy máte otevřeno nebo kde vás najít.')) ?></p>
<fieldset>
<legend><?= e(t('Firma')) ?></legend>
<?php
$field('company_name', 'Obchodní firma', 'text', 'Přesný název podle rejstříku, např. „Truhlářství Novák s.r.o.“. Na webu se jinak používá název webu.', 'maxlength="200"');
?>
<div class="radek">
	<label for="company_type"><?= e(t('Druh podniku')) ?></label>
	<div><select id="company_type" name="company_type">
<?php foreach (Company::TYPES as $type => $description): ?>
		<option value="<?= e($type) ?>"<?= $values['company_type'] === $type ? ' selected' : '' ?>><?= e(t($description)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Podle druhu vyhledávače ukazují otevírací dobu, mapu a hodnocení. Máte-li provozovnu pro zákazníky, nevolte „firma bez provozovny“.')) ?></span></div>
</div>
<?php
$field('company_id', 'IČO', 'text', 'Identifikační číslo firmy; v jiné zemi její registrační číslo.', 'maxlength="24"');
$field('company_vat_id', 'DIČ', 'text', 'Jen plátce DPH, např. CZ12345678.', 'maxlength="14"');
$field('company_register', 'Zápis v rejstříku', 'text', 'Soud a spisová značka, např. „Krajský soud v Brně, oddíl C, vložka 12345“ (v Německu Handelsregister). Pro tiráž.', 'maxlength="200"');
$field('company_representative', 'Zastoupení', 'text', 'Kdo firmu zastupuje, např. „jednatel Jan Novák“ (Geschäftsführer). Pro tiráž.', 'maxlength="200"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Adresa a kontakt')) ?></legend>
<?php
$field('company_street', 'Ulice a číslo', 'text', '', 'maxlength="200" autocomplete="street-address"');
$field('company_postcode', 'PSČ', 'text', '', 'maxlength="10" autocomplete="postal-code"');
$field('company_city', 'Město', 'text', '', 'maxlength="120" autocomplete="address-level2"');
$field('company_country', 'Země (kód)', 'text', 'Dvoupísmenný kód: CZ, SK, DE…', 'maxlength="2" size="3"');
$field('company_phone', 'Telefon', 'text', 'S předvolbou, např. +420 123 456 789.', 'maxlength="30" autocomplete="tel"');
$field('company_email', 'Veřejný e-mail', 'email', 'Kontakt pro návštěvníky a vyhledávače. E-mail webu ze záložky Základní (na ten chodí poptávky) se na webu neukazuje.', 'maxlength="190" autocomplete="email"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Otevírací doba a mapa')) ?></legend>
<?php
$field('company_hours', 'Otevírací doba', 'radky', 'Každý řádek jeden den nebo rozsah dnů: „Po–Pá 8:00–17:00“, „So 9–12“, „Ne zavřeno“, polední pauza „Út 8–12, 13–17“. Prázdné = bez otevírací doby.', 'rows="5" spellcheck="false"');
$field('company_map', 'Odkaz na mapu', 'url', 'Adresa místa na Mapy.cz nebo Google Maps – prvek Údaje firmy z ní udělá odkaz „Zobrazit na mapě“.');
$field('company_gps', 'Souřadnice (nepovinné)', 'text', 'Zeměpisná šířka a délka, např. 50.0875, 14.4213 – přesnější místo pro mapy a vyhledávače.', 'maxlength="40"');
?>
</fieldset>
