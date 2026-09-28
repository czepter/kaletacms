<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Menu $module
 * @var string $csrf
 * @var string $location  hlavni | paticka
 * @var string $language     the language column ('' = default)
 * @var bool $automatic the main menu is still built automatically
 * @var list<array<string, mixed>> $items
 * @var list<array{ids:int, titulek:string, skryta:bool}> $pages
 * @var array<string, string> $languages
 */
$choice = ['umisteni' => $location, 'jazyk' => $language];
?>
<nav class="zalozky" aria-label="<?= e(t('Menu')) ?>">
<?php foreach (Kaleta\Core\Menu::LOCATIONS as $key => $name): ?>
	<a href="<?= e($module->url('', ['umisteni' => $key, 'jazyk' => $language])) ?>"<?= $key === $location ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<?php if (count($languages) > 1): ?>
<p class="smltxt"><?= e(t('Jazyková verze:')) ?>
<?php foreach ($languages as $code => $name): ?>
	<a class="navigace<?= $code === $language ? ' aktivni' : '' ?>" href="<?= e($module->url('', ['umisteni' => $location, 'jazyk' => $code])) ?>"<?= $code === $language ? ' aria-current="true"' : '' ?>><?= e($name) ?></a>
<?php endforeach ?></p>
<?php endif ?>
<p class="smltxt"><?= e(t($location === 'hlavni'
    ? ($automatic ? 'Menu se zatím skládá samo ze stránek zaškrtnutých „v navigaci“. Když ho tady upravíte a uložíte, bude platit tahle podoba.' : 'Pořadí měníte přetažením nebo šipkami. Šipkou vpravo zařadíte položku do podmenu té nad ní.')
    : 'Odkazy v patičce webu (zásady ochrany soukromí, kontakt, kariéra…). Použije je výchozí patička i prvek Navigace nastavený na menu v patičce.')) ?></p>

<form method="post" action="<?= e($module->url('save', $choice)) ?>" class="menu-formular" data-menu>
<?= $csrf ?>
<input type="hidden" name="polozky" value="">
<script type="application/json" data-menu-data><?= json_encode(['polozky' => $items, 'stranky' => $pages], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<ol class="menu-editor" data-menu-seznam></ol>
<p class="napoveda" data-menu-prazdne hidden><?= e(t('Menu je prázdné – přidejte první položku.')) ?></p>
<fieldset class="menu-pridat">
	<legend><?= e(t('Přidat položku')) ?></legend>
	<label><?= e(t('Stránka')) ?>
		<select data-menu-stranka>
<?php foreach ($pages as $s): ?>
			<option value="<?= $s['ids'] ?>"><?= e($s['titulek']) ?><?= $s['skryta'] ? ' (' . e(t('skrytá')) . ')' : '' ?></option>
<?php endforeach ?>
		</select>
	</label>
	<button class="navigace" type="button" data-menu-pridej="stranka"><?= e(t('Přidat stránku')) ?></button>
	<button class="navigace" type="button" data-menu-pridej="odkaz"><?= e(t('Vlastní odkaz')) ?></button>
<?php if (Kaleta\Core\Extensions::isEnabled($app->settings(), 'novinky')): ?>
	<button class="navigace" type="button" data-menu-pridej="novinky"><?= e(t('Novinky')) ?></button>
<?php endif ?>
	<button class="navigace" type="button" data-menu-pridej="skupina" title="<?= e(t('Položka bez odkazu, která jen otevírá podmenu')) ?>"><?= e(t('Skupina')) ?></button>
</fieldset>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Uložit menu')) ?></button></p>
</form>
<?php if (!$automatic || $location !== 'hlavni'): ?>
<div class="navigace-radek akce-dole"><form class="vradku" method="post" action="<?= e($module->url('automatic', $choice)) ?>" data-potvrdit="<?= e(t($location === 'hlavni' ? 'Vrátit menu k automatickému skládání ze stránek? Vaše úpravy se zahodí.' : 'Vyprázdnit menu v patičce?')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t($location === 'hlavni' ? 'Vrátit na automatické menu' : 'Vyprázdnit menu')) ?></button></form></div>
<?php endif ?>
<script src="<?= e($app->url('image/menu.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
