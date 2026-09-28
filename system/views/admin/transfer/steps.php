<?php
/**
 * Ukazatel tří kroků importu (soubor → náhled → import).
 *
 * @var int $step  právě probíhající krok 1–3
 */
$steps = [1 => 'Soubor', 2 => 'Náhled', 3 => 'Import'];
?>
<ol class="prenos-kroky">
<?php foreach ($steps as $number => $name): ?>
	<li<?= $number === $step ? ' class="aktivni" aria-current="step"' : ($number < $step ? ' class="hotovy"' : '') ?>><span><?= $number ?></span> <?= e(t($name)) ?></li>
<?php endforeach ?>
</ol>
