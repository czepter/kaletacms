<?php
/**
 * Broken links in news.
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var string $csrf
 * @var list<array<string, mixed>> $links
 * @var int $checked
 * @var int $total
 * @var bool $isEnabled
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to the news list')) ?></a></p>
<?php if (!$isEnabled): ?>
<p class="hlaska"><?= e(t('Link checking is off (Settings → General → More options).')) ?></p>
<?php endif ?>
<p class="smltxt"><?= e(t('In the background, the system goes through published news – one every five minutes, each once a month – and checks whether its links still work. News items checked: %s of %s.', $checked, $total)) ?></p>
<?php if ($links === []): ?>
<p><?= e(t('No broken links found.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Novinka')) ?></th><th scope="col"><?= e(t('Link')) ?></th><th scope="col"><?= e(t('Problem')) ?></th><th scope="col"><?= e(t('Found')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($links as $o): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => (int) $o['idc']])) ?>"><?= e($o['titulek']) ?></a></td>
	<td style="word-break:break-all"><a href="<?= e($o['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(mb_strimwidth($o['url'], 0, 90, '…')) ?></a></td>
	<td><?= e((int) $o['stav'] === 0 ? t('server does not respond') : ((int) $o['stav'] === 404 ? t('page does not exist (404)') : t('error %s', (int) $o['stav']))) ?></td>
	<td class="cislo"><?= e(format_date($o['cas'])) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($module->url('links')) ?>"><?= $csrf ?><input type="hidden" name="idc" value="<?= (int) $o['idc'] ?>"><button class="navigace" type="submit"><?= e(t('Check again')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
