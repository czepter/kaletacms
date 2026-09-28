<?php
/**
 * Site appearance: styles (ready-made sets of colours, fonts and corner rounding – DesignSystem::PRESETS) and the design system
 * in tabs (colours, dark mode, font and sizes, shapes, brand, import and export) with a live preview of the real home page.
 * Tabs are switched by image/admin.js (data-zalozky); without the script the whole form is visible at once.
 * The preview is handled by image/admin.js (data-vzhled): after every change it requests the token CSS (action nahled) and puts it into the iframe.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Appearance $module
 * @var string $csrf
 * @var array<string, array{nazev:string, popis:string}> $layouts
 * @var array<string, mixed> $ds
 * @var list<array{popis:string, pomer:float, ok:bool}> $contrasts
 * @var array<string, array{nazev:string, popis:string, ds:array<string, mixed>}> $presets
 * @var array<string, string> $values
 */
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\DesignSystem;

$px = fn (float $rem): string => (string) round($rem * 16);
$contrastsHtml = function (array $contrasts): string {
    $html = '';
    foreach ($contrasts as $k) {
        $html .= '<li class="' . ($k['ok'] ? 'ok' : 'spatne') . '"><span>' . e(t($k['popis'])) . '</span><strong>' . e(t('%s : 1', format_number($k['pomer']))) . '</strong></li>';
    }

    return $html;
};
// the style the current appearance is based on (colours and heading font as in the style)
$current = null;
foreach ($presets as $key => $p) {
    if ($p['ds']['barvy']['primarni'] === $ds['barvy']['primarni'] && $p['ds']['barvy']['sekundarni'] === $ds['barvy']['sekundarni'] && $p['ds']['pismo_titulky'] === $ds['pismo_titulky']) {
        $current = $key;
        break;
    }
}
$tabs = ['styl' => 'Styl', 'barvy' => 'Barvy', 'tmavy' => 'Tmavý režim', 'pismo' => 'Písmo a velikosti', 'tvary' => 'Tvary', 'znacka' => 'Logo a ikona', 'export' => 'Import a export'];
?>
<div class="vzhled" data-zalozky>
<div class="zalozky" role="tablist" aria-label="<?= e(t('Části vzhledu')) ?>">
<?php foreach ($tabs as $key => $name): ?>
	<button type="button" role="tab" id="zalozka-<?= $key ?>" aria-controls="panel-<?= $key ?>" aria-selected="<?= $key === 'styl' ? 'true' : 'false' ?>"<?= $key === 'styl' ? '' : ' tabindex="-1"' ?>><?= e(t($name)) ?></button>
<?php endforeach ?>
</div>
<form class="formular vzhled-formular" method="post" action="<?= e($module->url('save')) ?>" data-vzhled data-nahled-url="<?= e($module->url('preview')) ?>">
<?= $csrf ?>

<div role="tabpanel" id="panel-styl" aria-labelledby="zalozka-styl">
<fieldset>
<legend><?= e(t('Styly')) ?></legend>
<p class="napoveda"><?= e(t('Styl je hotová sada barev, písem, velikostí a zaoblení. Vyberte ho jedním klikem a v dalších záložkách dolaďte – obsah webu se nemění.')) ?></p>
<div class="vzhled-predvolby">
<?php foreach ($presets as $key => $p): ?>
	<button type="button" class="vzhled-predvolba" data-predvolba="<?= e((string) json_encode($p['ds'], JSON_UNESCAPED_SLASHES)) ?>">
		<span class="vzhled-vzorky"><?php foreach (['primarni', 'sekundarni', 'text', 'plocha'] as $b): ?><i style="background:<?= e($p['ds']['barvy'][$b]) ?>"></i><?php endforeach ?></span>
		<strong style="font-family:<?= e(SiteIdentity::TITLE_FONTS[$p['ds']['pismo_titulky']][2]) ?>"><?= e(t($p['nazev'])) ?></strong>
		<small><?= e(t($p['popis'])) ?></small>
<?php if ($key === $current): ?>		<span class="stitek stitek-vydano"><?= e(t('aktuální')) ?></span>
<?php endif ?>
	</button>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Vzhled podle vaší značky nemusíte skládat ručně: připojte Clauda a napište mu třeba „Nastav vzhled webu podle naší značky – hlavní barva #0E6E6E, titulky patkovým písmem, jemné zaoblení“. Barvy a písma uloží do design systému, výsledek uvidíte tady.')) ?> <a href="<?= e($app->url('admin.php?module=extensions#claude')) ?>"><?= e(t('Jak připojit Clauda')) ?></a></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-barvy" aria-labelledby="zalozka-barvy">
<fieldset>
<legend><?= e(t('Barvy')) ?></legend>
<div class="vzhled-barvy">
<?php foreach (DesignSystem::COLORS as $key => $name): ?>
	<label class="vzhled-barva">
		<input type="color" name="ds[barvy][<?= e($key) ?>]" value="<?= e($ds['barvy'][$key]) ?>">
		<span><?= e(t($name)) ?><small data-hex><?= e($ds['barvy'][$key]) ?></small></span>
	</label>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Odstíny (tlumený text, linky, jemná hlavní barva) a barva textu na tlačítkách se dopočítají samy.')) ?></p>
<h2 class="vzhled-podnadpis"><?= e(t('Čitelnost')) ?></h2>
<ul class="vzhled-kontrasty" data-kontrasty><?= $contrastsHtml($contrasts) ?></ul>
<p class="napoveda"><?= e(t('Text by měl mít kontrast aspoň 4,5 : 1 (WCAG AA). Červeně označené dvojice budou pro část návštěvníků špatně čitelné.')) ?></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-tmavy" aria-labelledby="zalozka-tmavy">
<fieldset>
<legend><?= e(t('Tmavý režim')) ?></legend>
<div class="volby">
	<label><input type="radio" name="dark_mode" value="vypnuto" data-prepni="tmave:0"<?= !in_array($values['dark_mode'], ['auto', 'tmavy'], true) ? ' checked' : '' ?>> <?= e(t('vypnutý – web je vždy světlý')) ?></label><br>
	<label><input type="radio" name="dark_mode" value="auto" data-prepni="tmave:1"<?= $values['dark_mode'] === 'auto' ? ' checked' : '' ?>> <?= e(t('podle zařízení návštěvníka')) ?></label><br>
	<label><input type="radio" name="dark_mode" value="tmavy" data-prepni="tmave:1"<?= $values['dark_mode'] === 'tmavy' ? ' checked' : '' ?>> <?= e(t('vždy tmavý')) ?></label>
</div>
<div class="vzhled-barvy" data-sekce="tmave"<?= !in_array($values['dark_mode'], ['auto', 'tmavy'], true) ? ' hidden' : '' ?>>
<?php foreach (['text' => 'Text', 'pozadi' => 'Pozadí', 'plocha' => 'Plocha'] as $key => $name): ?>
	<label class="vzhled-barva">
		<input type="color" name="ds[barvy_tmave][<?= e($key) ?>]" value="<?= e($ds['barvy_tmave'][$key]) ?>">
		<span><?= e(t($name)) ?><small data-hex><?= e($ds['barvy_tmave'][$key]) ?></small></span>
	</label>
<?php endforeach ?>
	<p class="napoveda"><?= e(t('Zkontrolujte logo: tmavé logo na průhledném pozadí by na tmavém webu zaniklo.')) ?></p>
</div>
<label class="vzhled-prepinac" data-sekce="tmave"<?= !in_array($values['dark_mode'], ['auto', 'tmavy'], true) ? ' hidden' : '' ?>><input type="checkbox" name="theme_switcher" value="1"<?= $values['theme_switcher'] === '1' ? ' checked' : '' ?>> <?= e(t('Přepínač pro návštěvníky – v záhlaví si zvolí světlý, tmavý nebo vzhled podle zařízení (volba se pamatuje v jejich prohlížeči)')) ?></label>
</fieldset>
</div>

<div role="tabpanel" id="panel-pismo" aria-labelledby="zalozka-pismo">
<fieldset>
<legend><?= e(t('Písmo')) ?></legend>
<div class="radek">
	<label for="ds-pismo-titulky"><?= e(t('Titulky')) ?></label>
	<select id="ds-pismo-titulky" name="ds[pismo_titulky]">
<?php foreach (SiteIdentity::TITLE_FONTS as $key => [$name, $description]): if ($key === 'vychozi') { continue; } ?>
		<option value="<?= e($key) ?>"<?= $ds['pismo_titulky'] === $key ? ' selected' : '' ?>><?= e(t($name) . ' – ' . t($description)) ?></option>
<?php endforeach ?>
<?php foreach ($ds['vlastni_pisma'] as $i => $vp): ?>
		<option value="vlastni-<?= $i + 1 ?>"<?= $ds['pismo_titulky'] === 'vlastni-' . ($i + 1) ? ' selected' : '' ?>><?= e($vp['nazev'] . ' – ' . t('vlastní písmo')) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek">
	<label for="ds-pismo-text"><?= e(t('Text')) ?></label>
	<div><select id="ds-pismo-text" name="ds[pismo_text]">
<?php foreach (SiteIdentity::TEXT_FONTS as $key => [$name, $description]): if ($key === 'vychozi') { continue; } ?>
		<option value="<?= e($key) ?>"<?= $ds['pismo_text'] === $key ? ' selected' : '' ?>><?= e(t($name) . ' – ' . t($description)) ?></option>
<?php endforeach ?>
<?php foreach ($ds['vlastni_pisma'] as $i => $vp): ?>
		<option value="vlastni-<?= $i + 1 ?>"<?= $ds['pismo_text'] === 'vlastni-' . ($i + 1) ? ' selected' : '' ?>><?= e($vp['nazev'] . ' – ' . t('vlastní písmo')) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Systémová písma se nic nestahují. Vlastní písmo leží na vašem serveru – také nepotřebuje souhlas návštěvníka.')) ?></span></div>
</div>
<details class="pokrocile"<?= $ds['vlastni_pisma'] !== [] ? ' open' : '' ?>>
<summary><?= e(t('Vlastní písma značky (WOFF2)')) ?></summary>
<p class="napoveda"><?= e(t('Nahrajte soubory písma (.woff2) do Médií a vložte sem jejich adresu. Stačí jeden variabilní soubor, nebo běžný a tučný řez. Po uložení písmo vyberete výše.')) ?></p>
<?php for ($i = 0; $i < 3; $i++): $vp = $ds['vlastni_pisma'][$i] ?? ['nazev' => '', 'soubor' => '', 'tucny' => '']; ?>
<div class="radek">
	<span class="popisek"><?= e(t('Písmo %d', $i + 1)) ?></span>
	<div class="pole-vedle">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][nazev]" value="<?= e($vp['nazev']) ?>" maxlength="40" placeholder="<?= e(t('název, např. Bricolage Grotesque')) ?>" aria-label="<?= e(t('Název písma %d', $i + 1)) ?>">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][soubor]" value="<?= e($vp['soubor']) ?>" placeholder="media/…/pismo.woff2" aria-label="<?= e(t('Soubor písma %d', $i + 1)) ?>">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][tucny]" value="<?= e($vp['tucny']) ?>" placeholder="<?= e(t('tučný řez (nepovinné)')) ?>" aria-label="<?= e(t('Tučný řez písma %d', $i + 1)) ?>">
	</div>
</div>
<?php endfor ?>
</details>
</fieldset>

<fieldset>
<legend><?= e(t('Velikosti')) ?></legend>
<p class="napoveda"><?= e(t('Písmo i mezery rostou plynule s šířkou okna – od telefonu po velký monitor. Nadpisy jsou násobky základního písma.')) ?></p>
<div class="vzhled-mrizka">
	<label><span><?= e(t('Základní písmo na telefonu')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[zaklad_min]" value="<?= e($px($ds['zaklad_min'])) ?>" min="13" max="24" step="1"> px</span></label>
	<label><span><?= e(t('Základní písmo na monitoru')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[zaklad_max]" value="<?= e($px($ds['zaklad_max'])) ?>" min="13" max="25" step="1"> px</span></label>
<?php foreach (['pomer_min' => 'Nadpisy na telefonu', 'pomer_max' => 'Nadpisy na monitoru'] as $key => $labelText): ?>
	<label><span><?= e(t($labelText)) ?></span><select name="ds[<?= $key ?>]">
<?php foreach (DesignSystem::RATIOS as $value => $name): ?>
		<option value="<?= e($value) ?>"<?= abs((float) $value - $ds[$key]) < 0.001 ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></label>
<?php endforeach ?>
	<label><span><?= e(t('Šířka obsahu')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[sirka]" value="<?= e($px($ds['sirka'])) ?>" min="640" max="1920" step="16"> px</span></label>
	<label><span><?= e(t('Šířka textu (novinky a textové stránky)')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[sirka_textu]" value="<?= e($px($ds['sirka_textu'])) ?>" min="448" max="960" step="16"> px</span></label>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Typografické styly')) ?></legend>
<p class="napoveda"><?= e(t('Pojmenované styly textu, které v builderu vyberete u prvku (Styl → Typografie). Změna tady se projeví všude, kde styl je.')) ?></p>
<div class="tab-obal"><table class="vypis vzhled-typografie">
<thead><tr><th scope="col"><?= e(t('Styl')) ?></th><th scope="col"><?= e(t('Velikost (krok škály)')) ?></th><th scope="col"><?= e(t('Tloušťka')) ?></th></tr></thead>
<tbody>
<?php foreach (DesignSystem::TYPOGRAPHY as $key => [$name, $step, $weight, $lineHeight, $forHeadings]): $custom = $ds['typografie'][$key] ?? []; ?>
<tr>
	<th scope="row"><span style="font: var(--ka-typ-<?= e($key) ?>, inherit)<?= $key === 'nadtitulek' ? ';text-transform:uppercase;letter-spacing:.08em' : '' ?>"><?= e(t($name)) ?></span></th>
	<td><select name="ds[typografie][<?= e($key) ?>][krok]" aria-label="<?= e(t('Velikost: %s', t($name))) ?>">
<?php foreach (DesignSystem::STEPS as $k): ?>
		<option value="<?= e($k) ?>"<?= ($custom['krok'] ?? $step) === $k ? ' selected' : '' ?>><?= e($k === '0' ? t('0 – základní písmo') : $k) ?></option>
<?php endforeach ?>
	</select></td>
	<td><select name="ds[typografie][<?= e($key) ?>][tloustka]" aria-label="<?= e(t('Tloušťka: %s', t($name))) ?>">
<?php foreach (DesignSystem::FONT_WEIGHTS as $w => $weightName): ?>
		<option value="<?= $w ?>"<?= (int) ($custom['tloustka'] ?? $weight) === $w ? ' selected' : '' ?>><?= e(t($weightName)) ?></option>
<?php endforeach ?>
	</select></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
</fieldset>
</div>

<div role="tabpanel" id="panel-tvary" aria-labelledby="zalozka-tvary">
<fieldset>
<legend><?= e(t('Zaoblení rohů')) ?></legend>
<div class="vzhled-zaobleni">
<?php foreach (['0' => 'ostré', 's' => 'jemné', 'm' => 'střední', 'l' => 'velké', 'plne' => 'kulaté'] as $key => $name): ?>
	<label><input type="radio" name="ds[zaobleni]" value="<?= e($key) ?>"<?= $ds['zaobleni'] === $key ? ' checked' : '' ?>><i style="border-radius:<?= e($key === 'plne' ? '999px' : DesignSystem::RADII[$key]) ?>"></i><?= e(t($name)) ?></label>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Zaoblení dostanou tlačítka, karty, obrázky a pole formulářů na celém webu.')) ?></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-znacka" aria-labelledby="zalozka-znacka">
<fieldset>
<legend><?= e(t('Logo a ikona')) ?></legend>
<div class="radek"><label for="logo"><?= e(t('Logo')) ?></label><div><input class="textpole siroke" type="text" id="logo" name="logo" value="<?= e($values['logo']) ?>" maxlength="255" placeholder="<?= e(t('bez loga se v záhlaví zobrazí název webu')) ?>" data-obrazek><span class="napoveda"><?= e(t('Nejlépe PNG s průhledným pozadím, výška aspoň 120 px.')) ?></span></div></div>
<div class="radek"><label for="favicon"><?= e(t('Ikona webu')) ?></label><div><input class="textpole siroke" type="text" id="favicon" name="favicon" value="<?= e($values['favicon']) ?>" maxlength="255" data-obrazek><span class="napoveda"><?= e(t('Malý čtvercový obrázek na kartě prohlížeče a v záložkách. Stačí 256×256 px.')) ?></span></div></div>
</fieldset>

<?php if (count($layouts) > 1): ?>
<fieldset>
<legend><?= e(t('Šablona')) ?></legend>
<p class="napoveda"><?= e(t('Vlastní PHP šablony se už nevyvíjejí – vzhled webu nastavíte tady ve Vzhledu webu a v builderu. Stávající vlastní šablona dál funguje, doporučujeme ale přejít na výchozí.')) ?></p>
<div class="karty-volby karty-volby-text">
<?php foreach ($layouts as $folder => $l): ?>
	<label class="karta-volba">
		<input type="radio" name="layout" value="<?= e($folder) ?>"<?= $values['layout'] === $folder ? ' checked' : '' ?>>
		<strong><?= e($l['nazev']) ?></strong>
		<span><?= e($l['popis']) ?></span>
	</label>
<?php endforeach ?>
</div>
</fieldset>
<?php else: ?>
<input type="hidden" name="layout" value="<?= e((string) array_key_first($layouts)) ?>">
<?php endif ?>
</div>

<p class="tlacitka vzhled-ulozit"><input class="tl" type="submit" value="<?= e(t('Uložit vzhled')) ?>"> <span class="napoveda" data-neulozeno hidden><?= e(t('Náhled ukazuje neuložené změny.')) ?></span></p>
</form>

<div role="tabpanel" id="panel-export" aria-labelledby="zalozka-export" class="vzhled-export">
<fieldset>
<legend><?= e(t('Design tokeny (Figma, Tokens Studio)')) ?></legend>
<p class="napoveda"><?= e(t('Barvy, písma, velikosti a typografické styly ve formátu W3C Design Tokens (DTCG). Export Kalety se dá načíst zpět celý, z jiného nástroje se převezmou barvy.')) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('tokens')) ?>"><?= e(t('Stáhnout tokeny (.tokens.json)')) ?></a></p>
<form class="navigace-radek" method="post" action="<?= e($module->url('tokens_import')) ?>" enctype="multipart/form-data" data-potvrdit="<?= e(t('Načíst tokeny? Přepíší nastavení vzhledu výše.')) ?>">
	<?= $csrf ?>
	<input type="file" name="tokeny" accept=".json,application/json" required aria-label="<?= e(t('Soubor s tokeny')) ?>">
	<button class="navigace" type="submit"><?= e(t('Načíst tokeny')) ?></button>
</form>
</fieldset>
</div>

<aside class="vzhled-nahled">
	<div class="vzhled-nahled-lista">
		<span><?= e(t('Náhled úvodní stránky')) ?></span>
		<span class="vzhled-zarizeni" role="group" aria-label="<?= e(t('Zařízení')) ?>">
			<button type="button" data-zarizeni="pocitac" aria-pressed="true"><?= e(t('Počítač')) ?></button>
			<button type="button" data-zarizeni="mobil" aria-pressed="false"><?= e(t('Telefon')) ?></button>
		</span>
	</div>
	<div class="vzhled-ramec" data-ramec><iframe src="<?= e($app->url('') . '?nahled=vzhled') ?>" title="<?= e(t('Náhled úvodní stránky')) ?>" data-nahled></iframe></div>
</aside>
</div>
