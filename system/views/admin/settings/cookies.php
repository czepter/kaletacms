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
<fieldset>
<legend><?= e(t('Spam check for forms')) ?></legend>
<p class="napoveda"><?= e(t('Forms are protected without one: a signed time, a hidden trap field and a limit per address. Add a CAPTCHA only if spam still gets through. The provider receives the visitor’s address and browser details – name it in your privacy policy.')) ?></p>
<div class="radek">
	<label for="captcha_provider"><?= e(t('Extra spam check')) ?></label>
	<div><select id="captcha_provider" name="captcha_provider">
		<option value=""<?= $values['captcha_provider'] === '' ? ' selected' : '' ?>><?= e(t('none – the built-in protection only')) ?></option>
<?php foreach (Kaleta\Core\Captcha::PROVIDERS as $key => [$name]): ?>
		<option value="<?= e($key) ?>"<?= $values['captcha_provider'] === $key ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('hCaptcha and Cloudflare Turnstile suit EU sites; reCAPTCHA v3 is invisible and scores every visit.')) ?></span></div>
</div>
<?php $field('captcha_site_key', 'Site key', 'text', 'From the provider’s dashboard, for this site’s domain.', 'maxlength="100" autocomplete="off" spellcheck="false"'); ?>
<div class="radek">
	<label for="captcha_secret"><?= e(t('Secret key')) ?></label>
	<div><input class="textpole siroke" type="password" id="captcha_secret" name="captcha_secret" value="" autocomplete="new-password" placeholder="<?= $values['captcha_secret'] !== '' ? e(t('the key is saved – enter a new one only to change it')) : '' ?>">
	<span class="napoveda"><?= e(t('Stays on your site; Claude cannot read it.')) ?></span>
<?php if ($values['captcha_secret'] !== ''): ?>
	<label><input type="checkbox" name="captcha_secret_smazat" value="1"> <?= e(t('Remove the saved key')) ?></label>
<?php endif ?>
	</div>
</div>
<?php $field('captcha_fail_open', 'Accept forms when the provider is down', 'ano', 'The built-in protection still applies. Off = such forms are refused until the provider answers again.'); ?>
</fieldset>
<?php if ($consents !== []): ?>
<p class="napoveda"><?= e(t('Consents in the last 30 days:')) ?> <?= implode(' · ', array_map(fn (array $r): string => e($r['kategorie'] === 'nic' ? t('necessary only') : $r['kategorie']) . ' ' . (int) $r['pocet'] . '×', $consents)) ?></p>
<?php endif ?>
