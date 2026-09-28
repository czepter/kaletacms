<?php
/**
 * Nastavení pop-up okna: typ, spouštěč, četnost a pravidla zobrazení.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Popups $module
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array<string, mixed>> $pages
 * @var list<array<string, mixed>> $collection
 * @var array<string, string> $languages
 */
use Kaleta\Builder\Popups;

$rules = $p['pravidla'];
$selection = function (string $displayName, array $options, string $value, bool $toTranslate = true): string {
    $html = '<select id="' . e($displayName) . '" name="' . e($displayName) . '">';
    foreach ($options as $k => $n) {
        $html .= '<option value="' . e((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($toTranslate ? t(is_array($n) ? $n[0] : $n) : (is_array($n) ? $n[0] : $n)) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="navigace-radek"><a class="tl" href="<?= e($module->url('stavitel', ['id' => $p['idpp']])) ?>"><?= e(t('Upravit obsah v builderu')) ?></a>
	<form class="vradku" method="post" action="<?= e($module->url('prepni')) ?>"><?= $csrf ?><input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>"><input type="hidden" name="z" value="edit"><button class="navigace" type="submit"><?= e($p['aktivni'] ? t('Vypnout') : t('Zapnout')) ?></button></form>
	<?php if ($p['aktivni']): ?><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span><?php elseif ($p['stavba'] === null): ?><span class="stitek stitek-koncept"><?= e(t('nepublikované')) ?></span><?php else: ?><span class="stitek"><?= e(t('vypnuté')) ?></span><?php endif ?>
	<span class="napoveda"><?= e(t('Počet zobrazení')) ?>: <?= (int) $p['zobrazeni'] ?> · <?= e(t('Zavření')) ?>: <?= (int) $p['zavreni'] ?> · <?= e(t('Konverze')) ?>: <?= (int) $p['konverze'] ?></span></div>
<form class="formular" method="post" action="<?= e($module->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Název okna')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($p['nazev']) ?>" maxlength="100" required></div></div>
<div class="radek"><label for="adresa"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="adresa" name="adresa" value="<?= e($p['adresa']) ?>" maxlength="60" pattern="[a-z0-9][a-z0-9\-]*"><span class="napoveda"><?= e(t('Odkaz nebo tlačítko s adresou #popup-%s okno otevře kdykoli – i když se samo neukazuje.', $p['adresa'])) ?></span></div></div>
<div class="radek"><label for="typ"><?= e(t('Typ')) ?></label><div><?= $selection('typ', Popups::TYPES, $p['typ']) ?></div></div>
<fieldset>
<legend><?= e(t('Kdy se okno ukáže')) ?></legend>
<div class="radek"><label for="spoustec"><?= e(t('Spouštěč')) ?></label><div><?= $selection('spoustec', Popups::TRIGGERS, $p['spoustec']) ?></div></div>
<div class="radek"><label for="hodnota"><?= e(t('Hodnota spouštěče')) ?></label><div><input class="textpole" size="5" type="number" id="hodnota" name="hodnota" min="0" max="3600" value="<?= (int) $p['hodnota'] ?>"><span class="napoveda"><?= e(t('Sekundy u času a nečinnosti, procenta stránky u rolování, počet stránek u návštěvy.')) ?></span></div></div>
<div class="radek"><label for="cetnost"><?= e(t('Četnost')) ?></label><div><?= $selection('cetnost', Popups::FREQUENCIES, $p['cetnost']) ?> <span data-aktivni-kdyz="cetnost=dni"> <label for="dni" class="vradku"><?= e(t('počet dní')) ?></label> <input class="textpole" size="5" type="number" id="dni" name="dni" min="1" max="365" value="<?= (int) $p['dni'] ?>"></span><span class="napoveda"><?= e(t('Pamatuje si to prohlížeč návštěvníka (sessionStorage a localStorage), ne cookies.')) ?></span></div></div>
</fieldset>
<fieldset>
<legend><?= e(t('Kde se okno ukáže')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('Místa')) ?></span><div class="volby">
<label><input type="radio" name="kde" value="vse"<?= $rules['kde'] === 'vse' ? ' checked' : '' ?>> <?= e(t('na celém webu')) ?></label>
<label><input type="radio" name="kde" value="vybrane"<?= $rules['kde'] === 'vybrane' ? ' checked' : '' ?>> <?= e(t('jen na vybraných stránkách, v kolekcích nebo v novinkách')) ?></label>
</div></div>
<div data-aktivni-kdyz="kde=vybrane">
<div class="radek"><span class="popisek"><?= e(t('Stránky')) ?></span><div class="volby volby-seznam">
<?php foreach ($pages as $s): ?>
<label><input type="checkbox" name="stranky[]" value="<?= (int) $s['ids'] ?>"<?= in_array((int) $s['ids'], $rules['stranky'], true) ? ' checked' : '' ?>> <?= e(($s['jazyk'] !== '' ? strtoupper($s['jazyk']) . ' · ' : '') . $s['titulek']) ?></label>
<?php endforeach ?>
</div></div>
<?php if ($collection !== []): ?>
<div class="radek"><span class="popisek"><?= e(t('Stránky položek kolekcí')) ?></span><div class="volby">
<?php foreach ($collection as $k): ?>
<label><input type="checkbox" name="kolekce[]" value="<?= e($k['seo_link']) ?>"<?= in_array($k['seo_link'], $rules['kolekce'], true) ? ' checked' : '' ?>> <?= e($k['nazev']) ?> (/<?= e($k['seo_link']) ?>/…)</label>
<?php endforeach ?>
</div></div>
<?php endif ?>
<div class="radek"><span class="popisek"><?= e(t('Novinky')) ?></span><div class="volby"><label><input type="checkbox" name="novinky" value="1"<?= $rules['novinky'] ? ' checked' : '' ?>> <?= e(t('výpis novinek, kategorie a jednotlivé novinky')) ?></label></div></div>
</div>
<?php if ($languages !== []): ?>
<div class="radek"><label for="jazyk"><?= e(t('Jazyková verze')) ?></label><div><?= $selection('jazyk', ['' => t('všechny')] + $languages, $rules['jazyk'], false) ?></div></div>
<?php endif ?>
<div class="radek"><label for="od"><?= e(t('Období')) ?></label><div><input class="textpole" type="date" id="od" name="od" value="<?= e($rules['od']) ?>" aria-label="<?= e(t('od')) ?>"> – <input class="textpole" type="date" id="do" name="do" value="<?= e($rules['do']) ?>" aria-label="<?= e(t('do')) ?>"><span class="napoveda"><?= e(t('Prázdné = bez omezení. Mimo období se okno do stránky vůbec nevloží.')) ?></span></div></div>
<div class="radek"><label for="zarizeni"><?= e(t('Zařízení')) ?></label><div><?= $selection('zarizeni', Popups::DEVICES, $rules['zarizeni']) ?></div></div>
<div class="radek"><label for="utm"><?= e(t('Jen z kampaně')) ?></label><div><input class="textpole" id="utm" name="utm" value="<?= e($rules['utm']) ?>" maxlength="80"><span class="napoveda"><?= e(t('Text v parametrech utm_* adresy, se kterou návštěvník přišel (např. jaro nebo newsletter). Prázdné = všichni.')) ?></span></div></div>
<div class="radek"><label for="odkud"><?= e(t('Jen odkud přišel')) ?></label><div><input class="textpole" id="odkud" name="odkud" value="<?= e($rules['odkud']) ?>" maxlength="80"><span class="napoveda"><?= e(t('Část adresy webu, ze kterého návštěvník přišel (např. facebook.com). Prázdné = odkudkoli.')) ?></span></div></div>
</fieldset>
<div class="radek"><label for="poradi"><?= e(t('Pořadí')) ?></label><div><input class="textpole" size="5" type="number" id="poradi" name="poradi" value="<?= (int) $p['poradi'] ?>"><span class="napoveda"><?= e(t('Když by se ukázalo víc oken, přednost má menší číslo. Přes otevřené okno se další neotevře.')) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit nastavení')) ?>"></p>
</form>
<div class="navigace-radek akce-dole">
<a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Všechna pop-up okna')) ?></a>
<form class="vradku" method="post" action="<?= e($module->url('vynuluj')) ?>" data-potvrdit="<?= e(t('Vynulovat počitadla zobrazení, zavření a konverzí?')) ?>"><?= $csrf ?><input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>"><button class="navigace" type="submit"><?= e(t('Vynulovat počitadla')) ?></button></form>
<form class="vradku" method="post" action="<?= e($module->url('smaz')) ?>" data-potvrdit="<?= e(t('Smazat pop-up okno? Z webu zmizí hned.')) ?>"><?= $csrf ?><input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat okno')) ?></button></form>
</div>
