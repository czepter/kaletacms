<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Stats $module
 * @var int $days
 * @var array<string, array{navstevy:int, zobrazeni:int}> $chart
 * @var bool $isEnabled
 * @var list<array<string, mixed>> $newsItems
 * @var list<array{cesta: string, pocet: int}> $pages
 * @var list<array<string, mixed>> $sources
 */
$max = max(1, ...array_column($chart, 'zobrazeni'));
$visits = array_sum(array_column($chart, 'navstevy'));
$views = array_sum(array_column($chart, 'zobrazeni'));
?>
<?php if (!$isEnabled): ?>
<p class="hlaska hlaska-chyba"><?= e(t('Analytics is turned off. Turn it on in Settings → Analytics.')) ?></p>
<?php endif ?>
<nav class="zalozky" aria-label="<?= e(t('Period')) ?>">
<?php foreach ([7, 30, 90] as $d): ?>
	<a href="<?= e($module->url('', ['dni' => $d])) ?>"<?= $days === $d ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t('%s days', $d)) ?></a>
<?php endforeach ?>
</nav>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= format_count($visits) ?></strong><span><?= e(t('Visits')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= format_count($views) ?></strong><span><?= e(t('Page views')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= $visits > 0 ? format_count($views / $visits, 1) : '0' ?></strong><span><?= e(t('Pages per visit')) ?></span></div>
</div>
<h2><?= e(t('Page views and visits by day')) ?></h2>
<div class="graf" role="img" aria-label="<?= e(t('Bar chart of page views by day')) ?>">
<?php foreach ($chart as $day => $h): ?>
<?php $dayLabel = t('%s: %s page views, %s visits', format_date($day), $h['zobrazeni'], $h['navstevy']); ?>
	<div class="graf-sloupec" data-tip="<?= e($dayLabel) ?>" aria-label="<?= e($dayLabel) ?>" tabindex="0"><i data-tip-kotva style="height:<?= round($h['zobrazeni'] / $max * 100, 1) ?>%"><b style="height:<?= $h['zobrazeni'] > 0 ? round($h['navstevy'] / $h['zobrazeni'] * 100, 1) : 0 ?>%"></b></i></div>
<?php endforeach ?>
</div>
<p class="smltxt"><?= e(format_date(array_key_first($chart))) ?> – <?= e(format_date(array_key_last($chart))) ?> · <?= e(t('the light part of each bar is page views, the dark part visits. Measurement uses no cookies and stores no IP addresses; bots are not counted.')) ?></p>

<div class="stat-tabulky">
<div>
<h2><?= e(t('Most visited pages')) ?></h2>
<?php if ($pages === []): ?><p><?= e(t('No data yet.')) ?></p><?php else: ?>
<div class="tab-obal"><table class="vypis"><tbody>
<?php foreach ($pages as $pageRow): ?>
<tr><td><a href="<?= e($pageRow['cesta']) ?>" target="_blank" rel="noopener"><?= e($pageRow['cesta']) ?></a></td><td class="cislo"><?= format_count((int) $pageRow['pocet']) ?>×</td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</div>
<div>
<h2><?= e(t('Most read news')) ?></h2>
<?php if ($newsItems === []): ?><p><?= e(t('No data yet.')) ?></p><?php else: ?>
<div class="tab-obal"><table class="vypis"><tbody>
<?php foreach ($newsItems as $c): ?>
<tr><td><a href="<?= e($app->url('admin.php?module=news&action=edit&id=' . (int) $c['idc'])) ?>"><?= e($c['titulek']) ?></a></td><td class="cislo"><?= format_count((int) $c['pocet']) ?>×</td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</div>
<div>
<h2><?= e(t('Where visitors come from')) ?></h2>
<?php if ($sources === []): ?><p><?= e(t('No data yet.')) ?></p><?php else: ?>
<div class="tab-obal"><table class="vypis"><tbody>
<?php foreach ($sources as $z): ?>
<tr><td><?= e($z['zdroj']) ?></td><td class="cislo"><?= format_count((int) $z['pocet']) ?>×</td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</div>
</div>
