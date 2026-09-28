<?php /** The "Měření" (Analytics) tab. */ ?>
<fieldset>
<legend><?= e(t('Návštěvnost')) ?></legend>
<?php
$field('stats', 'Vestavěná statistika', 'ano', 'Návštěvy, nejčtenější novinky a zdroje návštěv v sekci Statistika. Bez cookies a bez souhlasu.');
$field('ga4_id', 'Google Analytics', 'text', 'Stačí ID měření ve tvaru G-XXXXXXXXXX. Spouští se až po souhlasu návštěvníka (záložka Soukromí a cookies).', 'placeholder="G-" maxlength="24"');
?>
</fieldset>
<details class="pokrocile"<?= $values['matomo_url'] . $values['plausible_domain'] . $values['head_code'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Další nástroje (Matomo, Plausible, vlastní kód)')) ?></summary>
<?php
$field('matomo_url', 'Matomo – adresa', 'url', 'Adresa vaší instalace, např. https://statistiky.example.cz/', 'placeholder="https://"');
$field('matomo_id', 'Matomo – ID webu', 'cislo', '', 'min="0"');
$field('plausible_domain', 'Plausible – doména', 'text', 'Nepoužívá cookies, načítá se bez souhlasu.', 'placeholder="example.cz" maxlength="100"');
$field('head_code', 'Vlastní kód do hlavičky', 'kod', 'Vloží se na každou stránku bez ohledu na souhlas – jen pro kódy, které neukládají cookies.', 'spellcheck="false"');
?>
</details>
