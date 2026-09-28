<?php
/**
 * Nefunkční odkazy v novinkách.
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var string $csrf
 * @var list<array<string, mixed>> $links
 * @var int $checked
 * @var int $total
 * @var bool $isEnabled
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Zpět na přehled novinek')) ?></a></p>
<?php if (!$isEnabled): ?>
<p class="hlaska"><?= e(t('Kontrola odkazů je vypnutá (Nastavení → Základní → Další možnosti).')) ?></p>
<?php endif ?>
<p class="smltxt"><?= e(t('Systém na pozadí prochází vydané novinky – jednu za pět minut, každou jednou za měsíc – a zkouší, jestli odkazy v nich ještě fungují. Zkontrolováno novinek: %s z %s.', $checked, $total)) ?></p>
<?php if ($links === []): ?>
<p><?= e(t('Žádný nefunkční odkaz nebyl nalezen.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Novinka')) ?></th><th scope="col"><?= e(t('Odkaz')) ?></th><th scope="col"><?= e(t('Problém')) ?></th><th scope="col"><?= e(t('Zjištěno')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($links as $o): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => (int) $o['idc']])) ?>"><?= e($o['titulek']) ?></a></td>
	<td style="word-break:break-all"><a href="<?= e($o['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(mb_strimwidth($o['url'], 0, 90, '…')) ?></a></td>
	<td><?= e((int) $o['stav'] === 0 ? t('server neodpovídá') : ((int) $o['stav'] === 404 ? t('stránka neexistuje (404)') : t('chyba %s', (int) $o['stav']))) ?></td>
	<td class="cislo"><?= e(format_date($o['cas'])) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($module->url('odkazy')) ?>"><?= $csrf ?><input type="hidden" name="idc" value="<?= (int) $o['idc'] ?>"><button class="navigace" type="submit"><?= e(t('Zkontrolovat znovu')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
