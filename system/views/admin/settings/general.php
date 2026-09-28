<?php /** Záložka Základní. Proměnné a funkce $field viz vypis.php. */ ?>
<fieldset>
<legend><?= e(t('Web')) ?></legend>
<?php
$field('site_name', 'Název webu', 'text', '', 'maxlength="150" required');
$field('site_url', 'Adresa webu', 'url', 'Například https://www.firma.cz, bez lomítka na konci. Skládají se z ní odkazy v e-mailech, RSS, mapě webu a oznámeních. Po přestěhování na jinou doménu ji změňte.', 'required placeholder="https://"');
$field('site_description', 'Popis webu', 'radky', 'Jedna až dvě věty – motto, popis pro vyhledávače a RSS.');
$field('site_email', 'E-mail webu', 'email', 'Chodí na něj upozornění systému.');
?>
<div class="radek">
	<label for="require_2fa"><?= e(t('Dvoufázové přihlášení')) ?></label>
	<div><select id="require_2fa" name="require_2fa">
<?php foreach (['' => 'dobrovolné', 'spravci' => 'povinné pro správce', 'vsichni' => 'povinné pro všechny uživatele'] as $k => $n): ?>
		<option value="<?= e($k) ?>"<?= ($values['require_2fa'] ?? '') === $k ? ' selected' : '' ?>><?= e(t($n)) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Kdo ho povinně má a ještě si ho nezapnul, se po přihlášení dostane jen do Můj účet, dokud ho nenastaví.')) ?></span></div>
</div>
<?php
?>
<?php if (($additionalLanguages = Kaleta\Core\Language::additional($app->settings())) !== []): ?>
<details class="pokrocile"<?= array_filter($additionalLanguages, fn (string $j): bool => ($values['nazev_webu_' . $j] ?? '') . ($values['popis_webu_' . $j] ?? '') !== '') !== [] ? ' open' : '' ?>>
<summary><?= e(t('Název a popis v dalších jazykových verzích')) ?></summary>
<p class="napoveda"><?= e(t('Prázdné pole znamená stejný text jako ve výchozím jazyce.')) ?></p>
<?php foreach ($additionalLanguages as $j): ?>
<div class="radek"><label for="nazev_webu_<?= e($j) ?>"><?= e(t('Název webu')) ?> (<?= e(strtoupper($j)) ?>)</label><div><input class="textpole siroke" type="text" id="nazev_webu_<?= e($j) ?>" name="nazev_webu_<?= e($j) ?>" value="<?= e($values['nazev_webu_' . $j] ?? '') ?>" maxlength="150" lang="<?= e($j) ?>"></div></div>
<div class="radek"><label for="popis_webu_<?= e($j) ?>"><?= e(t('Popis webu')) ?> (<?= e(strtoupper($j)) ?>)</label><div><textarea class="textbox radkovy" id="popis_webu_<?= e($j) ?>" name="popis_webu_<?= e($j) ?>" rows="2" cols="60" lang="<?= e($j) ?>"><?= e($values['popis_webu_' . $j] ?? '') ?></textarea></div></div>
<?php endforeach ?>
</details>
<?php endif ?>
<div class="radek">
	<label for="time_zone"><?= e(t('Časové pásmo')) ?></label>
	<div><select id="time_zone" name="time_zone">
<?php foreach (DateTimeZone::listIdentifiers() as $timeZone): ?>
		<option value="<?= e($timeZone) ?>"<?= $values['time_zone'] === $timeZone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $timeZone)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Podle něj se vydávají naplánované novinky a zobrazují data. Teď je %s.', format_date(new DateTimeImmutable(), true))) ?></span></div>
</div>
<div class="radek">
	<label for="site_language"><?= e(t('Jazyk webu')) ?></label>
	<div><select id="site_language" name="site_language">
<?php foreach (Kaleta\Core\Language::AVAILABLE as $code => [$languageName]): ?>
		<option value="<?= e($code) ?>"<?= $values['site_language'] === $code ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('V tomto jazyce jsou texty webu (Hledat, Novinky, Číst dál…) a web se tak hlásí vyhledávačům.')) ?></span></div>
</div>
<?php if (Kaleta\Core\Extensions::isEnabled($app->settings(), 'jazyky')): ?>
<div class="radek" id="additional_languages">
	<span class="popisek"><?= e(t('Další jazykové verze')) ?></span>
	<div class="volby">
		<div class="volby-jazyky">
<?php foreach (Kaleta\Core\Language::AVAILABLE as $code => [$languageName]): if ($code === $values['site_language']) { continue; } ?>
		<label><input type="checkbox" name="jazyky_dalsi[]" value="<?= e($code) ?>"<?= in_array($code, explode(',', $values['additional_languages']), true) ? ' checked' : '' ?>> <?= e($languageName) ?> <small>(/<?= e($code) ?>/)</small></label>
<?php endforeach ?>
		</div>
		<span class="napoveda"><?= e(t('Každá verze má své stránky, kategorie a novinky. Jazyk novinky určuje její kategorie. Překlad propojíte v editoru.')) ?></span>
<?php $translated = array_map(fn (string $code): string => Kaleta\Core\Language::AVAILABLE[$code][0], array_values(array_filter(array_keys(Kaleta\Core\Language::AVAILABLE), fn (string $code): bool => $code === 'cs' || is_file(KALETA_SYSTEM . '/jazyky/' . $code . '.php')))); ?>
		<span class="napoveda"><?= e(t('Texty pro návštěvníky (Hledat, Číst dál…) jsou přeložené do jazyků: %s. Ostatní jazyky je mají anglicky, datum ve svém tvaru. Obsah stránek a novinek píšete v jazyce verze.', implode(', ', $translated))) ?></span>
	</div>
</div>
<?php else: ?>
<?php foreach (array_filter(explode(',', $values['additional_languages'])) as $code): ?><input type="hidden" name="jazyky_dalsi[]" value="<?= e($code) ?>"><?php endforeach ?>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Úvodní stránka a novinky')) ?></legend>
<div class="radek">
	<label for="home_page"><?= e(t('Úvodní stránka webu')) ?></label>
	<div><select id="home_page" name="home_page">
		<option value="0"><?= e(t('– výpis novinek –')) ?></option>
<?php foreach ($pages as $pageId => $pageTitle): ?>
		<option value="<?= (int) $pageId ?>"<?= (int) $values['home_page'] === (int) $pageId ? ' selected' : '' ?>><?= e($pageTitle) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Stránka, která se ukáže na adrese webu. Novinky jsou vždy na %s.', substr($app->url('novinky'), strlen($app->request->basePath())))) ?></span></div>
</div>
<?php $field('news_per_page', 'Novinek na stránku', 'cislo', '', 'min="1" max="100"'); ?>
</fieldset>
<details class="pokrocile"<?= $values['maintenance'] === '1' ? ' open' : '' ?>>
<summary><?= e(t('Režim údržby')) ?><?= $values['maintenance'] === '1' ? ' – ' . e(t('ZAPNUTÝ')) : '' ?></summary>
<?php
$field('maintenance', 'Web je dočasně mimo provoz', 'ano', 'Návštěvníci uvidí jen oznámení níže. Přihlášení uživatelé vidí web normálně.');
$field('maintenance_text', 'Text oznámení', 'text', '', 'maxlength="300"');
?>
</details>
<details class="pokrocile">
<summary><?= e(t('Sociální sítě')) ?></summary>
<?php foreach (Kaleta\Admin\Modules\Settings::SOCIAL_NETWORKS as $key => $name) { $field($key, $name, 'url', '', 'placeholder="https://"'); } ?>
<p class="napoveda"><?= e(t('Vyplněné profily se zobrazí v patičce webu a předají se vyhledávačům.')) ?></p>
</details>
<details class="pokrocile">
<summary><?= e(t('Další možnosti')) ?></summary>
<?php
$field('footer_text', 'Text v patičce', 'text', 'Například obchodní firma a IČO.', 'maxlength="300"');
$field('share_buttons', 'Odkazy pro sdílení pod novinkou', 'ano', 'Facebook, X, LinkedIn, WhatsApp, e-mail a kopírování odkazu – bez cizích skriptů.');
$field('article_outline', 'Obsah novinky z mezititulků', 'ano', 'U novinek s aspoň třemi mezititulky se nad textem zobrazí klikací osnova.');
$field('related_news_auto', 'Související novinky', 'ano', 'Pod novinkou se nabídnou podobné podle štítků a kategorie.');
?>
<?php
$field('webhook_enquiries', 'Webhook nové poptávky', 'url', 'Kam poslat každou novou poptávku z formuláře (CRM, Make, Zapier, n8n, Slack). Dostane název formuláře, vyplněná pole a e-mail odesílatele.', 'placeholder="https://"');
$field('webhook_url', 'Webhook po vydání novinky', 'url', 'Adresa ze služby Make, Zapier, IFTTT nebo n8n. Po vydání novinky na ni systém pošle titulek, perex, adresu a obrázek – služba je pak sama sdílí na Facebook, X, Mastodon, do Slacku apod.', 'placeholder="https://"');
$field('link_check', 'Hledat nefunkční odkazy', 'ano', 'Na pozadí, jedna novinka za pět minut. Výsledek je v Novinky → Nefunkční odkazy.');
$field('page_cache', 'Cache stránek', 'ano', 'Hotové stránky se návštěvníkům podávají z paměti – web je rychlejší a vydrží nápor. Nechte zapnuté.');
?>
</details>
