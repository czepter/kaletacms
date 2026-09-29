<?php /** The "Měření" (Analytics) tab. */ ?>
<fieldset>
<legend><?= e(t('Traffic')) ?></legend>
<?php
$field('stats', 'Built-in statistics', 'ano', 'Visits, most-read news and traffic sources under Statistics. No cookies and no consent needed.');
$field('ga4_id', 'Google Analytics', 'text', 'The measurement ID in the form G-XXXXXXXXXX is enough. With a cookie bar on, it runs only after the visitor\'s consent (the Privacy and cookies tab).', 'placeholder="G-" maxlength="24"');
?>
</fieldset>
<details class="pokrocile"<?= $values['matomo_url'] . $values['plausible_domain'] . $values['head_code'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Other tools (Matomo, Plausible, custom code)')) ?></summary>
<?php
$field('matomo_url', 'Matomo – address', 'url', 'The address of your installation, e.g. https://statistiky.example.cz/', 'placeholder="https://"');
$field('matomo_id', 'Matomo – site ID', 'cislo', '', 'min="0"');
$field('plausible_domain', 'Plausible – domain', 'text', 'Uses no cookies, loads without consent.', 'placeholder="example.cz" maxlength="100"');
$field('head_code', 'Custom code in the head', 'kod', 'Inserted on every page regardless of consent – only for codes that do not store cookies.', 'spellcheck="false"');
?>
</details>
