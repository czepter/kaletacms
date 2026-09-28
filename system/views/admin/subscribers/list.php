<?php
/**
 * @var Kaleta\Admin\Modules\Subscribers $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $subscribers
 * @var int $total
 * @var int $confirmed
 * @var string $search
 * @var int $pageNumber
 * @var string $service connected mailing service (empty = none)
 * @var array{ceka: ?string, chyby: ?string} $queue
 */
use Kaleta\Core\Newsletter;

$admin = $app->auth()->isAdmin();
?>
<p class="smltxt"><?= e(t('Adresy z prvku Odběr novinek. Za odběratele se počítá, kdo přihlášení potvrdil odkazem v e-mailu. Rozesílejte svým nástrojem – export obsahuje i odkaz na odhlášení.')) ?></p>
<?php if ($service !== ''): ?>
<div class="hlaska">
	<p><?= e(t('Potvrzení odběratelé jdou automaticky do služby %s, odhlášení se z ní odebírají.', t(Newsletter::SERVICES[$service][0]))) ?>
	<?= (int) $queue['ceka'] > 0 ? e(t('Čeká na odeslání: %d.', (int) $queue['ceka'])) : '' ?> <?= (int) $queue['chyby'] > 0 ? '<strong>' . e(t('Nepovedlo se: %d.', (int) $queue['chyby'])) . '</strong>' : '' ?></p>
<?php if ($admin): ?>
	<p><form class="vradku" method="post" action="<?= e($module->url('sync')) ?>"><?= $csrf ?><button class="navigace" type="submit"><?= e(t('Poslat do služby všechny potvrzené')) ?></button></form>
	<?php if ((int) $queue['chyby'] > 0): ?><form class="vradku" method="post" action="<?= e($module->url('retry')) ?>"><?= $csrf ?><button class="navigace" type="submit"><?= e(t('Zkusit nepovedené znovu')) ?></button></form><?php endif ?>
	<a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e(t('Nastavení služby')) ?></a></p>
<?php endif ?>
</div>
<?php elseif ($admin): ?>
<p class="smltxt"><?= e(t('Používáte Brevo, MailerLite, Mailchimp, Ecomail nebo SmartEmailing? Po napojení v Rozšíření posílá web potvrzené odběratele rovnou do vašeho seznamu.')) ?> <a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e(t('Napojit službu')) ?></a></p>
<?php endif ?>
<form class="navigace-radek" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="module" value="subscribers">
	<input class="textpole" type="search" name="hledat" value="<?= e($search) ?>" placeholder="<?= e(t('Hledat e-mail')) ?>" aria-label="<?= e(t('Hledat e-mail')) ?>">
	<button class="navigace" type="submit"><?= e(t('Filtrovat')) ?></button>
<?php if ($confirmed > 0): ?>
	<a class="tl" href="<?= e($module->url('csv')) ?>"><?= e(t('Export potvrzených (CSV)')) ?> · <?= $confirmed ?></a>
<?php else: ?>
	<button class="tl" type="button" disabled><?= e(t('Export potvrzených (CSV)')) ?> · 0</button>
<?php endif ?>
</form>
<?php if ($subscribers === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'newsletter', 'heading' => t($search !== '' ? 'Nic nenalezeno.' : 'Zatím žádní odběratelé.'), 'text' => t('Vložte na web prvek Odběr novinek – třeba do patičky.')]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><?php if ($service !== ''): ?><th scope="col"><?= e(t('Služba')) ?></th><?php endif ?><th scope="col"><?= e(t('Přihlášen')) ?></th><th scope="col"><?= e(t('Stránka')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($subscribers as $o): ?>
<tr<?= (int) $o['stav'] === 1 ? '' : ' class="nevydany"' ?>>
	<td><?= e($o['email']) ?></td>
	<td><?= (int) $o['stav'] === 1 ? '<span class="stitek stitek-vydano">' . e(t('potvrzený')) . '</span>' : e(t('čeká na potvrzení')) ?></td>
<?php if ($service !== ''): ?>
	<td><?= match ((string) $o['sync']) {
        'ok' => '<span class="stitek stitek-vydano">' . e(t('odesláno')) . '</span>',
        'ceka' => '<span class="stitek">' . e(t('čeká')) . '</span>',
        'chyba' => '<span class="stitek stitek-koncept" title="' . e(t((string) $o['sync_chyba'])) . '">' . e(t('chyba')) . '</span> <span class="napoveda">' . e(mb_strimwidth(t((string) $o['sync_chyba']), 0, 80, '…')) . '</span>',
        default => '—',
    } ?></td>
<?php endif ?>
	<td class="cislo"><?= e(format_date($o['datum'], true)) ?></td>
	<td class="smltxt"><?= e($o['zdroj']) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Smazat adresu ze seznamu odběratelů?')) ?>"><?= $csrf ?><input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php if ($total > 100): ?>
<p class="navigace-radek">
<?php if ($pageNumber > 1): ?><a class="navigace" href="<?= e($module->url('', ['hledat' => $search, 'strana' => $pageNumber - 1])) ?>"><?= e(t('Předchozí')) ?></a><?php endif ?>
<?php if ($pageNumber * 100 < $total): ?><a class="navigace" href="<?= e($module->url('', ['hledat' => $search, 'strana' => $pageNumber + 1])) ?>"><?= e(t('Další')) ?></a><?php endif ?>
</p>
<?php endif ?>
<?php endif ?>
