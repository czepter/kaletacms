<?php
/**
 * Přepínač jazykových verzí. Layout ho dostává hotový v proměnné $jazyky_html (prázdná, má-li web jediný jazyk).
 * Dva až tři jazyky = přepínač v jedné řadě, víc = tlačítko s nabídkou (Popover API, bez JavaScriptu). Barvy, zaoblení
 * a písmo bere z design systému (tokeny --ka-…), takže sedí ke vzhledu webu.
 *
 * Popisek „Language“ je záměrně anglicky (rozumí mu i návštěvník, který jazyku stránky nerozumí), proto lang="en".
 *
 * Prvek Přepínač jazyků (třeba v patičce) volí styl: „rada“, nebo „nabidka“ s celým názvem jazyka v tlačítku, a směr,
 * kam se nabídka otevře (v patičce nahoru).
 *
 * @var array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}> $jazyky
 * @var string|null $styl auto (do tří jazyků řada, víc nabídka) | rada | nabidka
 * @var string|null $smer dolu | nahoru
 * @var string|null $atributy atributy prvku z builderu (id, třídy)
 */
$aktivni = array_key_first(array_filter($jazyky, fn (array $j): bool => $j['aktivni'])) ?? array_key_first($jazyky);
$styl ??= 'auto';
$atributy ??= '';
?>
<?php if ($styl === 'rada' || ($styl === 'auto' && count($jazyky) <= 3)): ?>
<nav<?= Kaleta\Stavitel\Prvky\Text::sTridou($atributy, 'ka-jazyky') ?> lang="en" aria-label="Language">
<?php foreach ($jazyky as $kod => $j): ?>
	<a href="<?= e($j['url']) ?>" hreflang="<?= e($kod) ?>" lang="<?= e($kod) ?>" title="<?= e($j['nazev']) ?>"<?= $j['aktivni'] ? ' aria-current="true"' : '' ?>><?= e(strtoupper($kod)) ?></a>
<?php endforeach ?>
</nav>
<?php else: $id = 'ka-jazyky-' . bin2hex(random_bytes(3)); ?>
<nav<?= Kaleta\Stavitel\Prvky\Text::sTridou($atributy, 'ka-jazyky-vyber' . (($smer ?? '') === 'nahoru' ? ' ka-jazyky-vyber--nahoru' : '')) ?> lang="en" aria-label="Language">
	<button type="button" class="ka-jazyky-tl" popovertarget="<?= $id ?>" style="anchor-name: --<?= $id ?>" aria-label="Language: <?= e($jazyky[$aktivni]['nazev']) ?>">
		<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
		<span><?= e($styl === 'nabidka' ? $jazyky[$aktivni]['nazev'] : strtoupper((string) $aktivni)) ?></span><?php if ($styl === 'nabidka'): ?>
		<svg class="ka-jazyky-sipka" viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg><?php endif ?>
	</button>
	<ul id="<?= $id ?>" popover style="position-anchor: --<?= $id ?>">
<?php foreach ($jazyky as $kod => $j): ?>
		<li><a href="<?= e($j['url']) ?>" hreflang="<?= e($kod) ?>" lang="<?= e($kod) ?>"<?= $j['aktivni'] ? ' aria-current="true"' : '' ?>><span><?= e($j['nazev']) ?></span><small><?= e(strtoupper($kod)) ?></small></a></li>
<?php endforeach ?>
	</ul>
</nav>
<?php endif ?>
