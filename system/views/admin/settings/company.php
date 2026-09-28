<?php
/** The "Firma" (Company) tab: details for the site (the Company details element) and for search engines (schema.org Organization / LocalBusiness). */
use Kaleta\Front\Company;

?>
<p class="hlaska"><?= e(t('Fill these in once and the site uses them everywhere: in the footer and on the contact page (the Company details element in the builder) and for Google, maps and AI assistants – so they answer correctly when you are open and where to find you.')) ?></p>
<fieldset>
<legend><?= e(t('Company')) ?></legend>
<?php
$field('company_name', 'Registered company name', 'text', 'The exact registered name, e.g. “Novak Joinery Ltd”. Elsewhere the site uses the site name.', 'maxlength="200"');
?>
<div class="radek">
	<label for="company_type"><?= e(t('Business type')) ?></label>
	<div><select id="company_type" name="company_type">
<?php foreach (Company::TYPES as $type => $description): ?>
		<option value="<?= e($type) ?>"<?= $values['company_type'] === $type ? ' selected' : '' ?>><?= e(t($description)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Search engines show opening hours, a map and reviews depending on the type. If customers visit you, do not choose “company without premises”.')) ?></span></div>
</div>
<?php
$field('company_id', 'Company ID', 'text', 'Company identification number; in other countries its registration number.', 'maxlength="24"');
$field('company_vat_id', 'VAT ID', 'text', 'VAT payers only, e.g. CZ12345678.', 'maxlength="14"');
$field('company_register', 'Commercial register', 'text', 'Register court and entry, e.g. “Amtsgericht München, HRB 12345” or “Companies House, 01234567”. For the imprint.', 'maxlength="200"');
$field('company_representative', 'Represented by', 'text', 'Who represents the company, e.g. “Managing director: Jane Smith” (Geschäftsführer). For the imprint.', 'maxlength="200"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Address and contact')) ?></legend>
<?php
$field('company_street', 'Street and number', 'text', '', 'maxlength="200" autocomplete="street-address"');
$field('company_postcode', 'Postcode', 'text', '', 'maxlength="10" autocomplete="postal-code"');
$field('company_city', 'City', 'text', '', 'maxlength="120" autocomplete="address-level2"');
$field('company_country', 'Country (code)', 'text', 'Two-letter code: CZ, SK, DE…', 'maxlength="2" size="3"');
$field('company_phone', 'Phone', 'text', 'With country code, e.g. +420 123 456 789.', 'maxlength="30" autocomplete="tel"');
$field('company_email', 'Public email', 'email', 'Contact for visitors and search engines. The site email from the General tab (where enquiries are sent) is not shown on the site.', 'maxlength="190" autocomplete="email"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Opening hours and map')) ?></legend>
<?php
$field('company_hours', 'Opening hours', 'radky', 'One day or range of days per line: “Mo–Fr 8:00–17:00”, “Sa 9–12”, “Su closed”, lunch break “Tu 8–12, 13–17”. Empty = no opening hours.', 'rows="5" spellcheck="false"');
$field('company_map', 'Map link', 'url', 'Link to the place on Mapy.cz or Google Maps – the Company details element turns it into a “Show on map” link.');
$field('company_gps', 'Coordinates (optional)', 'text', 'Latitude and longitude, e.g. 50.0875, 14.4213 – a more precise location for maps and search engines.', 'maxlength="40"');
?>
</fieldset>
