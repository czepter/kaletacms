<?php
/**
 * @var Kaleta\Admin\Modules\Pages $module
 * @var string $csrf
 * @var array<string, mixed> $page
 * @var array<string, string> $errors
 * @var bool $home  this is the home page of the site
 * @var ?bool $inMenu  the page is in the built menu (null = the menu is built automatically from v_menu)
 * @var bool $customMenu  the site has a built main menu
 * @var list<array{ids:int, titulek:string, seo_link:string}> $parents  possible parent pages
 * @var list<array{idr:int, datum:string, titulek:string, kdo:?string}> $versions  older versions of the text
 */
$segment = basename((string) $page['seo_link']);
$prefix = '';
foreach ($parents as $r) {
    if ((int) $r['ids'] === (int) ($page['nadrazena'] ?? 0)) {
        $prefix = $r['seo_link'] . '/';
    }
}
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět na přehled')) ?></a>
<?php if ($page['ids']): ?>
	<a class="navigace" href="<?= e($app->url(($page['jazyk'] ?? '') !== '' ? $page['jazyk'] . '/' . ($home ? '' : $page['seo_link']) : ($home ? '' : $page['seo_link'])) . ($page['zobrazit'] ? '' : '?stavba=koncept')) ?>" target="_blank" rel="noopener"><?= e(t($page['zobrazit'] ? 'Zobrazit na webu' : 'Náhled skryté stránky')) ?></a>
<?php endif ?></p>
<?php if (($page['stavba_koncept'] ?? null) !== null): ?>
<p class="hlaska hlaska-varovani"><?= e(t(($page['stavba'] ?? null) !== null ? 'V builderu jsou rozpracované změny, které ještě nejsou na webu.' : 'Stránku skládáte v builderu. Na webu je zatím text níže – po publikování v builderu ho nahradí stavba.')) ?>
	<a href="<?= e($module->url('builder', ['id' => (int) $page['ids']])) ?>"><?= e(t('Otevřít builder')) ?></a></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" data-koncept="stranka-<?= (int) $page['ids'] ?>">
<?= $csrf ?>
<input type="hidden" name="ids" value="<?= (int) $page['ids'] ?>">
<div class="radek pres-celou">
	<label for="titulek"><?= e(t('Název stránky')) ?></label>
	<input class="textpole siroke titulek-pole" type="text" id="titulek" name="titulek" value="<?= e($page['titulek']) ?>" maxlength="200" required><?= $error('titulek') ?>
</div>
<?php if (!$page['ids']): ?>
<div class="radek">
	<label for="sablona"><?= e(t('Začít podle šablony')) ?></label>
	<div><select id="sablona" name="sablona">
		<option value=""><?= e(t('prázdná stránka (text)')) ?></option>
<?php foreach (Kaleta\Builder\Library::PAGE_TEMPLATES as $key => [$name]): ?>
		<option value="<?= e($key) ?>"><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Šablona poskládá stránku z hotových sekcí s ukázkovými texty a otevře ji v builderu.')) ?></span></div>
</div>
<?php endif ?>
<?php if (($page['stavba'] ?? null) !== null): ?>
<div class="radek pres-celou">
	<p class="hlaska"><?= e(t('Obsah této stránky se skládá v builderu.')) ?> <a class="tl" href="<?= e($module->url('builder', ['id' => (int) $page['ids']])) ?>"><?= e(t('Otevřít builder')) ?></a></p>
	<input type="hidden" name="text" value="<?= e($page['text']) ?>">
</div>
<?php else: ?>
<div class="radek pres-celou">
	<label for="text"><?= e(t('Obsah')) ?></label>
	<textarea class="textbox vysoky" id="text" name="text" rows="18" data-editor><?= e($page['text']) ?></textarea>
<?php if ($page['ids']): ?>
	<span class="napoveda"><?= e(t('Chcete stránku poskládat ze sekcí, sloupců a tlačítek?')) ?> <a href="<?= e($module->url('builder', ['id' => (int) $page['ids']])) ?>"><?= e(t('Otevřít v builderu')) ?></a></span>
<?php endif ?>
</div>
<?php endif ?>
<div class="radek">
	<label for="nadrazena"><?= e(t('Nadřazená stránka')) ?></label>
	<div><select id="nadrazena" name="nadrazena">
		<option value="0"><?= e(t('— žádná (hlavní úroveň) —')) ?></option>
<?php foreach ($parents as $r): ?>
		<option value="<?= (int) $r['ids'] ?>"<?= (int) $r['ids'] === (int) ($page['nadrazena'] ?? 0) ? ' selected' : '' ?>><?= e(str_repeat('– ', substr_count($r['seo_link'], '/')) . $r['titulek']) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Podstránka má adresu pod nadřazenou (/sluzby/kuchyne) a ukáže se v jejích drobečcích.')) ?></span></div>
</div>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><span class="napoveda-inline">/<?= e($prefix) ?></span><input class="textpole" type="text" id="seo_link" name="seo_link" value="<?= e($segment) ?>" maxlength="110" placeholder="<?= e(t('vytvoří se z názvu, např. o-nas')) ?>"><?= $error('seo_link') ?></div>
</div>
<details class="pokrocile"<?= $page['popis'] !== '' || $page['seo_titulek'] !== '' || $page['obrazek'] !== '' || $page['noindex'] ? ' open' : '' ?>>
<summary><?= e(t('Vyhledávače a sdílení')) ?></summary>
<div class="radek">
	<label for="seo_titulek"><?= e(t('Titulek pro vyhledávače')) ?></label>
	<input class="textpole siroke" type="text" id="seo_titulek" name="seo_titulek" value="<?= e($page['seo_titulek']) ?>" maxlength="200" placeholder="<?= e(t('prázdné = název stránky')) ?>">
</div>
<div class="radek">
	<label for="popis"><?= e(t('Popis pro vyhledávače')) ?></label>
	<div><input class="textpole siroke" type="text" id="popis" name="popis" value="<?= e($page['popis']) ?>" maxlength="300">
	<span class="napoveda"><?= e(t('Jedna až dvě věty, co na stránce návštěvník najde (do 160 znaků).')) ?></span></div>
</div>
<div class="radek">
	<label for="obrazek"><?= e(t('Obrázek pro sdílení')) ?></label>
	<div><input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($page['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('prázdné = výchozí obrázek z Nastavení')) ?>" data-obrazek>
	<span class="napoveda"><?= e(t('Ukáže se při sdílení odkazu na Facebooku, LinkedInu nebo v Teams (ideálně 1200 × 630 px).')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Možnosti')) ?></span>
	<div class="volby"><label><input type="checkbox" name="noindex" value="1"<?= $page['noindex'] ? ' checked' : '' ?>> <?= e(t('Skrýt před vyhledávači (noindex)')) ?></label></div>
</div>
</details>
<?= $app->view->render('admin/language_field', ['app' => $app, 'value' => (string) ($page['jazyk'] ?? ''), 'translationOf' => (int) ($page['preklad_z'] ?? 0), 'originals' => $app->db()->pairs("SELECT ids, titulek FROM {stranky} WHERE jazyk = '' AND smazano IS NULL ORDER BY titulek"), 'hint' => '']) ?>
<div class="radek">
	<span class="popisek"><?= e(t('Zobrazení')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="zobrazit" value="1"<?= $page['zobrazit'] ? ' checked' : '' ?>> <?= e(t('Zveřejnit stránku')) ?></label><?= $home ? ' <span class="stitek">' . e(t('úvodní stránka webu')) . '</span>' : '' ?><?= $error('zobrazit') ?><br>
		<span class="napoveda" data-aktivni-kdyz="zobrazit="><label for="zverejnit_od"><?= e(t('Skrytou stránku zveřejnit automaticky:')) ?></label> <input class="textpole" type="datetime-local" id="zverejnit_od" name="zverejnit_od" value="<?= e(($page['zverejnit_od'] ?? null) ? date('Y-m-d\TH:i', strtotime($page['zverejnit_od'])) : '') ?>"></span><br>
		<label><input type="checkbox" name="v_menu" value="1"<?= ($inMenu ?? (bool) $page['v_menu']) ? ' checked' : '' ?>> <?= e(t('Zobrazit v hlavní navigaci webu')) ?></label>
<?php if ($customMenu): ?>
		<span class="napoveda"><?= e(t('Web má sestavené menu – stránka se přidá na jeho konec. Pořadí a podmenu upravíte ve Vzhled → Menu.')) ?></span>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="poradi"><?= e(t('Pořadí v navigaci')) ?></label>
	<div><input class="textpole" type="number" id="poradi" name="poradi" value="<?= (int) $page['poradi'] ?>" min="0" max="65535">
	<span class="napoveda"><?= e(t('Menší číslo = dřív v seznamu stránek a v automatickém menu.')) ?></span></div>
</div>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Uložit')) ?></button><?php if (($page['stavba'] ?? null) === null): ?> <button class="navigace" type="submit" name="po_ulozeni" value="stavitel"><?= e(t('Uložit a otevřít v builderu')) ?></button><?php endif ?></p>
</form>
<?php if ($versions !== []): ?>
<details class="pokrocile">
<summary><?= e(t('Historie textu (%s)', count($versions))) ?></summary>
<ul class="revize">
<?php foreach ($versions as $v): ?>
	<li><?= e(format_date($v['datum'], true)) ?><?= $v['kdo'] ? ' · ' . e($v['kdo']) : '' ?> · <?= e($v['titulek']) ?>
		<form class="vradku" method="post" action="<?= e($module->url('restore_version')) ?>" data-potvrdit="<?= e(t('Obnovit tuto verzi textu? Současná podoba zůstane v historii.')) ?>"><?= $csrf ?><input type="hidden" name="idr" value="<?= (int) $v['idr'] ?>"><button class="navigace" type="submit"><?= e(t('Obnovit')) ?></button></form></li>
<?php endforeach ?>
</ul>
</details>
<?php endif ?>
<?php if ($page['ids']): ?>
<div class="navigace-radek akce-dole">
<a class="navigace" href="<?= e($module->url('export', ['id' => (int) $page['ids']])) ?>"><?= e(t('Stáhnout jako JSON')) ?></a>
<form class="vradku" method="post" action="<?= e($module->url('duplicate')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $page['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($page['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Duplikovat stránku')) ?></button></form>
<?php if (($page['stavba'] ?? null) !== null): ?>
<form class="vradku" method="post" action="<?= e($module->url('build_text')) ?>" data-potvrdit="<?= e(t('Vrátit stránku k obyčejnému textu? Stavba zůstane ve verzích a můžete se k ní vrátit.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $page['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Vrátit stránku k textu')) ?></button></form>
<?php endif ?>
</div>
<?php endif ?>
