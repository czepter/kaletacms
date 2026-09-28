<?php
/** The "Rozšíření" (Extensions) tab. */
use Kaleta\Core\Extensions;
?>
<p class="hlaska"><?= e(t('Rozšíření jsou volitelné části Kalety. Všechna jsou součástí systému a udržuje je tým Kaleta – nic se nestahuje ani neinstaluje. Vypnuté rozšíření zmizí z menu i z webu, jeho data zůstanou a po zapnutí se vrátí.')) ?></p>
<?php
// where an enabled extension is configured – each lives elsewhere in the admin, so the card leads straight to that place
$adminUrl = fn (string $query): string => $app->url('admin.php?' . $query);
$extensionSettings = [
    'novinky' => [[$adminUrl('module=news'), 'Novinky'], [$adminUrl('module=categories'), 'Kategorie'], [$adminUrl('module=tags'), 'Štítky']],
    'poptavky' => [[$adminUrl('module=enquiries'), 'Poptávky a doba uchování'], [$adminUrl('module=settings&tab=general#webhook_poptavky'), 'Webhook do CRM']],
    'newsletter' => [[$adminUrl('module=subscribers'), 'Odběratelé a export'], ['#newsletter', 'Napojení na mailingovou službu']],
    'statistika' => [[$adminUrl('module=stats'), 'Statistika'], [$adminUrl('module=settings&tab=analytics'), 'Měření']],
    'presmerovani' => [[$adminUrl('module=redirects'), 'Přesměrování']],
    'jazyky' => [[$adminUrl('module=settings&tab=general#jazyky_dalsi'), 'Výběr jazyků']],
    'api' => [[$app->url('api/stranky'), 'Ukázka odpovědi API']],
    'asistent' => [['#asistent', 'Poskytovatel, klíč a model']],
    'claude' => [['#claude', 'Jak připojit Clauda']],
];
?>
<div class="rozsireni-seznam">
<?php foreach (Extensions::CATALOG as $key => [$name, $description]): $isEnabled = in_array($key, $enabledExtensions, true); ?>
	<div class="rozsireni-karta">
		<input type="checkbox" id="rozsireni-<?= e($key) ?>" name="rozsireni[]" value="<?= e($key) ?>"<?= $isEnabled ? ' checked' : '' ?>>
		<span><label for="rozsireni-<?= e($key) ?>"><strong><?= e(t($name)) ?></strong><br><?= e(t($description)) ?></label>
<?php if ($isEnabled && isset($extensionSettings[$key])): ?>
			<span class="rozsireni-odkazy"><?php foreach ($extensionSettings[$key] as $i => [$url, $text]): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= e($url) ?>"><?= e(t($text)) ?></a><?php endforeach ?></span>
<?php endif ?>
		</span>
	</div>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Odkazy u rozšíření se objeví po jeho zapnutí a uložení.')) ?></p>
<p class="napoveda"><?= e(t('Vždy zapnuté jádro: Stránky, Kolekce, Média, Vzhled webu, Části webu, Menu, Komponenty, Pop-up okna, Uživatelé a role, Import a export, Protokol změn a Nastavení.')) ?></p>
<details class="pokrocile" id="asistent"<?= in_array('asistent', $enabledExtensions, true) ? ' open' : '' ?>>
<summary><?= e(t('AI asistent – poskytovatel, klíč a model')) ?></summary>
<input type="hidden" name="ai_poskytovatel_puvodni" value="<?= e($values['ai_provider']) ?>">
<div class="radek">
	<label for="ai_provider"><?= e(t('Poskytovatel')) ?></label>
	<div><select id="ai_provider" name="ai_provider">
<?php foreach (Kaleta\Core\Assistant::PROVIDERS as $key => [$name, , $console]): ?>
		<option value="<?= e($key) ?>"<?= $values['ai_provider'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Klíč si vytvoříte u poskytovatele:')) ?>
<?php foreach (Kaleta\Core\Assistant::PROVIDERS as [$name, , $console]): ?>
		<a href="<?= e($console) ?>" target="_blank" rel="noopener"><?= e(t($name)) ?></a>
<?php endforeach ?>
		· <?= e(t('Platíte jen za skutečné použití, jeden návrh stojí řádově haléře. Klíč se ukládá jen na vašem webu.')) ?></span></div>
</div>
<div class="radek">
	<label for="ai_key"><?= e(t('Klíč API')) ?></label>
	<div><input class="textpole siroke" type="password" id="ai_key" name="ai_key" value="" autocomplete="off" placeholder="<?= $values['ai_key'] !== '' ? e(t('uložen klíč končící %s – nový vložte jen při změně', $values['ai_key'])) : '' ?>">
<?php if ($values['ai_key'] !== ''): ?>
	<label><input type="checkbox" name="ai_klic_smazat" value="1"> <?= e(t('Odebrat uložený klíč')) ?></label>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="ai_model"><?= e(t('Model')) ?></label>
	<div><input class="textpole" id="ai_model" name="ai_model" value="<?= e($values['ai_model']) ?>" list="ai_modely" maxlength="80" spellcheck="false">
	<datalist id="ai_modely">
<?php foreach (Kaleta\Core\Assistant::MODELS as $key => $name): ?>
		<option value="<?= e($key) ?>"><?= e(t($name)) ?></option>
<?php endforeach ?>
	</datalist>
	<span class="napoveda"><?= e(t('U Claude vyberte z nabídky (doporučený je Sonnet). U ostatních poskytovatelů napište přesný název modelu z jejich dokumentace – nabídka modelů se tam často mění.')) ?></span></div>
</div>
<p class="napoveda"><?= e(t('Asistent jen navrhuje – o každé změně rozhoduje člověk. Při použití se text odešle zvolenému poskytovateli; bez kliknutí na tlačítko asistenta se nikam nic neposílá.')) ?></p>
</details>
<details class="pokrocile" id="newsletter"<?= in_array('newsletter', $enabledExtensions, true) && $values['newsletter_service'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Newsletter – napojení na mailingovou službu')) ?></summary>
<p class="napoveda"><?= e(t('Po potvrzení odběru (double opt-in) přidá web adresu do seznamu ve vaší službě, po odhlášení ji odebere. Rozesílání, doručitelnost a odhlašování z e-mailů zůstávají u služby. Přenos běží na pozadí – návštěvník nečeká.')) ?></p>
<div class="radek">
	<label for="newsletter_service"><?= e(t('Služba')) ?></label>
	<div><select id="newsletter_service" name="newsletter_service">
		<option value=""><?= e(t('žádná – odběratele exportujete do CSV')) ?></option>
<?php foreach (Kaleta\Core\Newsletter::SERVICES as $key => [$name]): ?>
		<option value="<?= e($key) ?>"<?= $values['newsletter_service'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></div>
</div>
<div class="radek">
	<label for="newsletter_key"><?= e(t('Klíč API')) ?></label>
	<div><input class="textpole siroke" type="password" id="newsletter_key" name="newsletter_key" value="" autocomplete="off" placeholder="<?= $values['newsletter_key'] !== '' ? e(t('uložen klíč končící %s – nový vložte jen při změně', $values['newsletter_key'])) : '' ?>">
<?php if ($values['newsletter_key'] !== ''): ?>
	<label><input type="checkbox" name="newsletter_klic_smazat" value="1"> <?= e(t('Odebrat uložený klíč')) ?></label>
<?php endif ?>
	<span class="napoveda"><?= e(t('Klíč vytvoříte v účtu služby (API, integrace). U SmartEmailingu zadejte uživatelské jméno a klíč oddělené dvojtečkou. Klíč se ukládá jen na vašem webu a přes MCP se neukazuje.')) ?></span></div>
</div>
<div class="radek">
	<label for="newsletter_list"><?= e(t('Seznam')) ?></label>
	<div><input class="textpole" id="newsletter_list" name="newsletter_list" value="<?= e($values['newsletter_list']) ?>" maxlength="64" spellcheck="false">
	<span class="napoveda"><?= e(t('ID seznamu (Brevo, Ecomail, SmartEmailing), skupiny (MailerLite) nebo audience (Mailchimp) – najdete ho v nastavení seznamu ve službě.')) ?></span></div>
</div>
<div class="radek">
	<label for="newsletter_webhook"><?= e(t('Adresa webhooku')) ?></label>
	<div><input class="textpole siroke" type="url" id="newsletter_webhook" name="newsletter_webhook" value="<?= e($values['newsletter_webhook']) ?>" placeholder="https://hook.eu1.make.com/…">
	<span class="napoveda"><?= e(t('Jen u volby „Jiná služba přes webhook“: web pošle JSON s událostí novy_odberatel nebo odhlaseni_odberu a e-mailem.')) ?></span></div>
</div>
</details>
<?php if (in_array('claude', $enabledExtensions, true)): $mcpUrl = $app->request->origin() . $app->url('mcp'); ?>
<details class="pokrocile" id="claude" open>
<summary><?= e(t('Napojení na Claude – jak připojit')) ?></summary>
<ol class="navod">
	<li><?= e(t('V aplikaci Claude otevřete Nastavení → Konektory → Přidat vlastní konektor.')) ?></li>
	<li><?= e(t('Jako adresu zadejte:')) ?> <code class="totp-klic" style="font-size:13px"><?= e($mcpUrl) ?></code></li>
	<li><?= e(t('Claude vás pošle sem přihlásit a potvrdit přístup. Pracuje pak s právy vašeho účtu – stavby a novinky ukládá jako koncept.')) ?></li>
</ol>
<p class="napoveda"><?= e(t('V Claude Code stačí příkaz:')) ?> <code>claude mcp add --transport http kaleta <?= e($mcpUrl) ?></code>. <?= e(t('Připojené aplikace a osobní tokeny pro jiné nástroje najdete v')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('Můj účet')) ?></a>.
<?= e(t('Konektor potřebuje web na HTTPS v kořeni domény.')) ?></p>
</details>
<?php endif ?>
