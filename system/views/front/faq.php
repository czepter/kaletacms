<?php
/**
 * Questions and answers below a news item.
 *
 * @var list<array{0:string, 1:string}> $faq
 */
if ($faq === []) {
    return;
}
?>
<section class="faq obal-uzky">
	<h2><?= e(t('Questions and answers')) ?></h2>
<?php foreach ($faq as [$otazka, $odpoved]): ?>
	<details>
		<summary><?= e($otazka) ?></summary>
		<p><?= nl2br(e($odpoved)) ?></p>
	</details>
<?php endforeach ?>
</section>
