<?php
/** The "Zálohy a aktualizace" (Backups and updates) tab. */
?>
<fieldset>
<legend><?= e(t('System update')) ?></legend>
<p><?= e(t('Installed version:')) ?> <strong><?= e($update['aktualni']) ?></strong></p>
<?php if (!$update['nastaveno']): ?>
<p class="hlaska"><?= e(t('No update source is set yet. Upload a new version via FTP (overwrite all files except config.php, media/ and storage/); the database will be adjusted automatically.')) ?></p>
<?php elseif ($update['chyba'] !== null): ?>
<p class="hlaska hlaska-chyba"><?= e($update['chyba']) ?></p>
<?php elseif ($update['nova'] !== null): ?>
<div class="hlaska hlaska-ok">
	<p><strong><?= e(t(!empty($update['nova']['bezpecnostni']) ? 'Security update: version %s' : 'Version %s is available', (string) $update['nova']['verze'])) ?></strong><?= !empty($update['nova']['vydano']) ? ' (' . e(format_date((string) $update['nova']['vydano'])) . ')' : '' ?></p>
<?php if ($update['nova']['zmeny'] !== []): ?>
	<ul><?php foreach ($update['nova']['zmeny'] as $change): ?><li><?= e($change) ?></li><?php endforeach ?></ul>
<?php endif ?>
	<p><button class="tl" type="submit" formaction="<?= e($module->url('update')) ?>" data-potvrdit="<?= e(t('Update the system? A database backup will be created first. The site will be unavailable for a few seconds.')) ?>"><?= e(t('Update to %s', $update['nova']['verze'])) ?></button></p>
</div>
<p class="napoveda"><?= e(t('The database is backed up before the update. The package is accepted only with a valid publisher signature. config.php, uploaded media and a custom PHP theme are not overwritten.')) ?></p>
<?php else: ?>
<p><?= e(t('You have the latest version.')) ?><?= $update['overeno'] ? ' <small>' . e(t('Checked %s.', format_date((new DateTimeImmutable())->setTimestamp((int) $update['overeno']), true))) . '</small>' : '' ?></p>
<?php endif ?>
<?php if ($update['nastaveno']): ?>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Check now')) ?></button></p>
<?php endif ?>
<?php $field('auto_updates', 'Install security updates automatically', 'ano', 'Recommended. Applies only to releases marked as security releases; you install regular versions yourself. The system checks for updates twice a day, backs up the database before installing and e-mails the result to the site e-mail.'); ?>
<?php $field('update_url', 'Custom update source', 'url', 'Leave empty. Enter a different address of the aktualizace.json file only if you manage versions yourself.', 'placeholder="https://"'); ?>
</fieldset>

<fieldset>
<legend><?= e(t('Database backups')) ?></legend>
<details class="pokrocile"<?= $values['remote_backup'] !== 'vypnuto' ? ' open' : '' ?>>
<summary><?= e(t('Off-site backup copies')) ?><?= $values['remote_backup'] !== 'vypnuto' ? ' – ' . e(t('zapnuté')) : '' ?></summary>
<p class="napoveda"><?= e(t('A backup on the same server as the site does not help if you lose the hosting. Each new database backup can therefore upload itself elsewhere. Media are not copied this way – download them as a ZIP from time to time.')) ?></p>
<div class="radek"><label for="remote_backup"><?= e(t('Copy to')) ?></label><select id="remote_backup" name="remote_backup">
	<option value="vypnuto"><?= e(t('nowhere')) ?></option>
	<option value="ftp"<?= $values['remote_backup'] === 'ftp' ? ' selected' : '' ?>><?= e(t('to an FTP server with FTPS encryption (another host, home NAS)')) ?></option>
	<option value="s3"<?= $values['remote_backup'] === 's3' ? ' selected' : '' ?>><?= e(t('S3 storage (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2)')) ?></option>
</select></div>
<?php
$field('backup_host', 'Server', 'text', 'FTP: ftp.example.com. S3: the storage endpoint, e.g. s3.eu-central-1.amazonaws.com or s3.eu-central-003.backblazeb2.com.', 'maxlength="150" autocomplete="off"');
$field('backup_user', 'User name / access key', 'text', '', 'maxlength="190" autocomplete="off"');
?>
<div class="radek"><label for="backup_password"><?= e(t('Password / secret key')) ?></label><div><input class="textpole siroke" type="password" id="backup_password" name="backup_password" value="" autocomplete="new-password" placeholder="<?= $values['backup_password'] !== '' ? e(t('saved – enter a new one only to change it')) : '' ?>">
<?php if ($values['backup_password'] !== ''): ?>
	<label><input type="checkbox" name="zaloha_heslo_smazat" value="1"> <?= e(t('Remove saved password')) ?></label>
<?php endif ?>
</div></div>
<?php
$field('backup_folder', 'Folder / bucket', 'text', 'FTP: folder for backups (created if missing). S3: bucket name, optionally bucket/folder.', 'maxlength="150"');
$field('backup_region', 'Region (S3 only)', 'text', 'For example eu-central-1. For Cloudflare R2 enter auto.', 'maxlength="40" style="width:180px"');
?>
<?php if ($remoteStatus !== ''): [$when, $how] = explode('|', $remoteStatus, 2) + [1 => '']; ?>
<p class="hlaska<?= $how === 'ok' ? ' hlaska-ok' : ' hlaska-chyba' ?>"><?= e($how === 'ok' ? t('The last copy was uploaded %s.', $when) : t('The last attempt %s failed: %s', $when, $how)) ?></p>
<?php endif ?>
<p class="napoveda"><?= e(t('Save the settings, then click “Create backup now” – the copy uploads right away and you will see whether the connection works.')) ?></p>
</details>
<?php $field('auto_backups', 'Automatic backup once a week', 'ano', 'Created when an administrator signs in and the last backup is older than a week. The last 10 backups are kept.'); ?>
<p><button class="tl" type="submit" formaction="<?= e($module->url('backup')) ?>"><?= e(t('Create backup now')) ?></button></p>
<?php if ($backups !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Vytvořena')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($backups as $z): ?>
<tr>
	<td><?= e($z['soubor']) ?></td>
	<td class="cislo"><?= e(format_date((new DateTimeImmutable())->setTimestamp((int) $z['cas']), true)) ?></td>
	<td class="cislo"><?= format_count($z['velikost'] / 1024) ?> kB</td>
	<td class="akce"><a href="<?= e($module->url('download_backup', ['soubor' => $z['soubor']])) ?>"><?= e(t('Download')) ?></a> · <button class="navigace" type="submit" formaction="<?= e($module->url('restore_backup')) ?>" name="soubor" value="<?= e($z['soubor']) ?>" data-potvrdit="<?= e(t('Restore the database from this backup? Everything added to the site since it was created (pages, news, enquiries, settings) will be lost. The current state is saved to a new backup first.')) ?>"><?= e(t('Restore')) ?></button> · <button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete_backup')) ?>" name="soubor" value="<?= e($z['soubor']) ?>" data-potvrdit="<?= e(t('Delete backup?')) ?>"><?= e(t('Smazat')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('media_backup')) ?>"><?= e(t('Download media backup (ZIP)')) ?></button></p>
<p class="napoveda"><?= e(t('The database backup contains pages, news, settings and users; uploaded images are in the media backup. Backups are stored in storage/zalohy/, which is not accessible from the web – download copies off the server too.')) ?></p>
</fieldset>
