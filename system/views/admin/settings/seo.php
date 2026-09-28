<?php /** Záložka SEO a GEO. */ ?>
<p class="hlaska"><?= e(t('Většinu věcí dělá systém sám: adresy, popisy, sitemapu, strukturovaná data i podklady pro AI vyhledávače. Tady rozhodujete jen o tom hlavním.')) ?></p>
<fieldset>
<legend><?= e(t('Viditelnost webu')) ?></legend>
<?php $field('indexing', 'Web smí být ve vyhledávačích', 'ano', 'Vypněte jen u webu ve výstavbě.'); ?>
<div class="radek">
	<label for="ai_crawlers"><?= e(t('AI vyhledávače a asistenti')) ?></label>
	<div><select id="ai_crawlers" name="ai_crawlers">
		<option value="povolit"<?= $values['ai_crawlers'] === 'povolit' ? ' selected' : '' ?>><?= e(t('povolit – obsah se může objevit v odpovědích AI s odkazem na web')) ?></option>
		<option value="zakazat"<?= $values['ai_crawlers'] === 'zakazat' ? ' selected' : '' ?>><?= e(t('zakázat – ChatGPT, Claude, Perplexity, Gemini a další')) ?></option>
	</select>
	<span class="napoveda"><?= e(t('Slušní roboti pravidlo respektují; nejde o technickou ochranu.')) ?></span></div>
</div>
<?php $field('share_image', 'Obrázek pro sdílení', 'text', 'Ukáže se na sociálních sítích u stránek bez vlastního obrázku. Ideálně 1200×630 px.', 'data-obrazek maxlength="255"'); ?>
</fieldset>
<details class="pokrocile"<?= $values['verification_google'] . $values['verification_bing'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Ověření vlastnictví webu (Google Search Console, Bing)')) ?></summary>
<?php
$field('verification_google', 'Google', 'text', 'Hodnota content z meta tagu google-site-verification.', 'maxlength="100"');
$field('verification_bing', 'Bing', 'text', 'Hodnota content z meta tagu msvalidate.01.', 'maxlength="64"');
?>
<p class="napoveda"><?= e(t('Do Search Console pak vložte adresu sitemapy:')) ?> <?= e($siteUrl) ?>sitemap.xml</p>
</details>
<details class="pokrocile">
<summary><?= e(t('Pro pokročilé')) ?></summary>
<?php
$field('schema_org', 'Strukturovaná data schema.org', 'ano');
$field('indexnow', 'Oznamovat nové novinky vyhledávačům (IndexNow)', 'ano', 'Bing, Seznam a Yandex je pak zaindexují během minut.');
$field('llms_txt', 'Soubor llms.txt', 'ano', 'Průvodce webem pro jazykové modely.');
$field('markdown_news', 'Čistá verze novinek (.md)', 'ano', 'Každá novinka i jako prostý text bez navigace – pro jazykové modely a AI vyhledávače.');
$field('robots_extra', 'Vlastní pravidla robots.txt', 'radky', '', 'spellcheck="false"');
?>
<p class="napoveda"><?= e(t('Co systém generuje:')) ?> <a href="<?= e($siteUrl) ?>robots.txt" target="_blank" rel="noopener"><?= e(t('robots.txt')) ?></a> · <a href="<?= e($siteUrl) ?>sitemap.xml" target="_blank" rel="noopener"><?= e(t('sitemap.xml')) ?></a> · <a href="<?= e($siteUrl) ?>llms.txt" target="_blank" rel="noopener"><?= e(t('llms.txt')) ?></a> · <a href="<?= e($siteUrl) ?>rss.xml" target="_blank" rel="noopener"><?= e(t('rss.xml')) ?></a> · <a href="<?= e($siteUrl) ?>feed.json" target="_blank" rel="noopener"><?= e(t('feed.json')) ?></a></p>
</details>
