<?php /** The "Soukromí a cookies" (Privacy and cookies) tab. */ ?>
<fieldset>
<legend><?= e(t('Cookie bar')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach ([
    'vestavena' => ['Built-in banner', 'Recommended. Shown only when there is something to consent to; tracking starts only after consent.'],
    'externi' => ['External service', 'Cookiebot, CookieYes, Usercentrics… You paste in their code.'],
    'zadna' => ['None', 'Tracking codes run immediately. Only if you handle consent another way.'],
] as $key => [$name, $description]): ?>
	<label class="karta-volba">
		<input type="radio" name="cookies_mode" value="<?= e($key) ?>"<?= $values['cookies_mode'] === $key ? ' checked' : '' ?>>
		<strong><?= e(t($name)) ?></strong>
		<span><?= e(t($description)) ?></span>
	</label>
<?php endforeach ?>
</div>
<?php
$field('cookies_text', 'Banner text', 'radky');
$field('cookies_policy_url', 'Link to the policy', 'text', 'E.g. /privacy-policy – create the page in the Pages section.', 'maxlength="255"');
?>
</fieldset>
<details class="pokrocile"<?= $values['cookies_mode'] === 'externi' || $values['marketing_code'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Codes and records')) ?></summary>
<?php
$field('cookies_external_code', 'External service code', 'kod', 'The script from your provider (for Cookiebot, the line with data-cbid). It loads first.', 'spellcheck="false"');
$field('marketing_code', 'Marketing codes', 'kod', 'Meta Pixel, Sklik retargeting, Google Ads… Runs only after marketing consent is given.', 'spellcheck="false"');
$field('lead_attribution', 'Remember where leads came from', 'ano', 'The first page of a visit, its campaign and the site that sent the visitor go with enquiries and newsletter sign-ups (Statistics → Campaigns and pages). Only for visitors who allow marketing in the cookie bar – turning it on adds that choice to the bar.');
$field('cookies_log', 'Log consents', 'ano', 'Time, a random identifier and the chosen categories – no IP address. Evidence in case of an audit.');
$field('cookies_log_months', 'Keep consent records (months)', 'cislo', 'Older records are deleted automatically. 0 = keep.', 'min="0" max="120"');
?>
</details>
<?php if ($consents !== []): ?>
<p class="napoveda"><?= e(t('Consents in the last 30 days:')) ?> <?= implode(' · ', array_map(fn (array $r): string => e($r['kategorie'] === 'nic' ? t('necessary only') : $r['kategorie']) . ' ' . (int) $r['pocet'] . '×', $consents)) ?></p>
<?php endif ?>
