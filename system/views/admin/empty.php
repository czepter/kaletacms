<?php
/**
 * Prázdný stav výpisu: ikona, co tu bude, a zřetelná první akce.
 * Použití: <?= $app->view->render('admin/prazdno', ['ikona' => 'clanek', 'nadpis' => '…', 'text' => '…', 'akce' => [$url, 'Popisek']]) ?>
 *
 * @var string $icon    klíč do sady views/admin/icons.php
 * @var string $heading   už přeložený text
 * @var string $text     už přeložený text (nepovinný)
 * @var array{0:string,1:string}|null $action  [adresa, přeložený popisek] – nepovinné
 */
$svg = require __DIR__ . '/icons.php';
?>
<div class="prazdny-stav">
	<div class="prazdny-stav-ikona"><?= $svg($icon) ?></div>
	<p class="prazdny-stav-nadpis"><?= e($heading) ?></p>
<?php if (($text ?? '') !== ''): ?>
	<p class="prazdny-stav-text"><?= e($text) ?></p>
<?php endif ?>
<?php if (!empty($action)): ?>
	<p><a class="tl" href="<?= e($action[0]) ?>"><?= e($action[1]) ?></a></p>
<?php endif ?>
</div>
