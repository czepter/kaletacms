<?php
/** Záložka Zálohy a aktualizace. */
?>
<fieldset>
<legend><?= e(t('Aktualizace systému')) ?></legend>
<p><?= e(t('Nainstalovaná verze:')) ?> <strong><?= e($update['aktualni']) ?></strong></p>
<?php if (!$update['nastaveno']): ?>
<p class="hlaska"><?= e(t('Zdroj aktualizací zatím není nastaven. Novou verzi nahrajete přes FTP (přepište všechny soubory kromě config.php, media/ a storage/); databáze se upraví sama.')) ?></p>
<?php elseif ($update['chyba'] !== null): ?>
<p class="hlaska hlaska-chyba"><?= e($update['chyba']) ?></p>
<?php elseif ($update['nova'] !== null): ?>
<div class="hlaska hlaska-ok">
	<p><strong><?= e(t(!empty($update['nova']['bezpecnostni']) ? 'Bezpečnostní aktualizace: verze %s' : 'K dispozici je verze %s', (string) $update['nova']['verze'])) ?></strong><?= !empty($update['nova']['vydano']) ? ' (' . e(format_date((string) $update['nova']['vydano'])) . ')' : '' ?></p>
<?php if ($update['nova']['zmeny'] !== []): ?>
	<ul><?php foreach ($update['nova']['zmeny'] as $change): ?><li><?= e($change) ?></li><?php endforeach ?></ul>
<?php endif ?>
	<p><button class="tl" type="submit" formaction="<?= e($module->url('update')) ?>" data-potvrdit="<?= e(t('Aktualizovat systém? Nejprve se vytvoří záloha databáze. Web bude několik vteřin nedostupný.')) ?>"><?= e(t('Aktualizovat na %s', $update['nova']['verze'])) ?></button></p>
</div>
<p class="napoveda"><?= e(t('Před aktualizací se zazálohuje databáze. Balíček se přijme jen s platným podpisem vydavatele. Nepřepisuje se config.php, nahraná média ani vlastní PHP šablona.')) ?></p>
<?php else: ?>
<p><?= e(t('Máte aktuální verzi.')) ?><?= $update['overeno'] ? ' <small>' . e(t('Ověřeno %s.', format_date((new DateTimeImmutable())->setTimestamp((int) $update['overeno']), true))) . '</small>' : '' ?></p>
<?php endif ?>
<?php if ($update['nastaveno']): ?>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Zkontrolovat teď')) ?></button></p>
<?php endif ?>
<?php $field('auto_updates', 'Bezpečnostní aktualizace instalovat automaticky', 'ano', 'Doporučeno. Týká se jen vydání označených jako bezpečnostní; běžné verze instalujete sami. Systém se po novinkách dívá dvakrát denně, před instalací zálohuje databázi a o výsledku pošle e-mail na E-mail webu.'); ?>
<?php $field('update_url', 'Vlastní zdroj aktualizací', 'url', 'Nechte prázdné. Jinou adresu souboru aktualizace.json vyplňte jen tehdy, když si verze spravujete sami.', 'placeholder="https://"'); ?>
</fieldset>

<fieldset>
<legend><?= e(t('Zálohy databáze')) ?></legend>
<details class="pokrocile"<?= $values['remote_backup'] !== 'vypnuto' ? ' open' : '' ?>>
<summary><?= e(t('Kopie záloh mimo server')) ?><?= $values['remote_backup'] !== 'vypnuto' ? ' – ' . e(t('zapnuté')) : '' ?></summary>
<p class="napoveda"><?= e(t('Záloha na stejném serveru jako web nepomůže, když o hosting přijdete. Každá nová záloha databáze se proto může sama nahrát jinam. Média se tímto způsobem nekopírují – stahujte si je občas jako ZIP.')) ?></p>
<div class="radek"><label for="remote_backup"><?= e(t('Kam kopírovat')) ?></label><select id="remote_backup" name="remote_backup">
	<option value="vypnuto"><?= e(t('nikam')) ?></option>
	<option value="ftp"<?= $values['remote_backup'] === 'ftp' ? ' selected' : '' ?>><?= e(t('na FTP server se šifrováním FTPS (jiný hosting, domácí NAS)')) ?></option>
	<option value="s3"<?= $values['remote_backup'] === 's3' ? ' selected' : '' ?>><?= e(t('do úložiště S3 (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2)')) ?></option>
</select></div>
<?php
$field('backup_host', 'Server', 'text', 'FTP: ftp.example.cz. S3: adresa úložiště, např. s3.eu-central-1.amazonaws.com nebo s3.eu-central-003.backblazeb2.com.', 'maxlength="150" autocomplete="off"');
$field('backup_user', 'Jméno / přístupový klíč', 'text', '', 'maxlength="190" autocomplete="off"');
?>
<div class="radek"><label for="backup_password"><?= e(t('Heslo / tajný klíč')) ?></label><div><input class="textpole siroke" type="password" id="backup_password" name="backup_password" value="" autocomplete="new-password" placeholder="<?= $values['backup_password'] !== '' ? e(t('uloženo – nové vložte jen při změně')) : '' ?>">
<?php if ($values['backup_password'] !== ''): ?>
	<label><input type="checkbox" name="zaloha_heslo_smazat" value="1"> <?= e(t('Odebrat uložené heslo')) ?></label>
<?php endif ?>
</div></div>
<?php
$field('backup_folder', 'Složka / bucket', 'text', 'FTP: složka pro zálohy (vytvoří se). S3: název bucketu, případně bucket/složka.', 'maxlength="150"');
$field('backup_region', 'Region (jen S3)', 'text', 'Například eu-central-1. U Cloudflare R2 zadejte auto.', 'maxlength="40" style="width:180px"');
?>
<?php if ($remoteStatus !== ''): [$when, $how] = explode('|', $remoteStatus, 2) + [1 => '']; ?>
<p class="hlaska<?= $how === 'ok' ? ' hlaska-ok' : ' hlaska-chyba' ?>"><?= e($how === 'ok' ? t('Poslední kopie byla nahrána %s.', $when) : t('Poslední pokus %s selhal: %s', $when, $how)) ?></p>
<?php endif ?>
<p class="napoveda"><?= e(t('Nastavení uložte a pak klepněte na „Vytvořit zálohu teď“ – kopie se nahraje hned a uvidíte, jestli spojení funguje.')) ?></p>
</details>
<?php $field('auto_backups', 'Automatická záloha jednou týdně', 'ano', 'Vytvoří se při přihlášení administrátora, když je poslední záloha starší než týden. Uchovává se posledních 10 záloh.'); ?>
<p><button class="tl" type="submit" formaction="<?= e($module->url('backup')) ?>"><?= e(t('Vytvořit zálohu teď')) ?></button></p>
<?php if ($backups !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Soubor')) ?></th><th scope="col"><?= e(t('Vytvořena')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($backups as $z): ?>
<tr>
	<td><?= e($z['soubor']) ?></td>
	<td class="cislo"><?= e(format_date((new DateTimeImmutable())->setTimestamp((int) $z['cas']), true)) ?></td>
	<td class="cislo"><?= format_count($z['velikost'] / 1024) ?> kB</td>
	<td class="akce"><a href="<?= e($module->url('download_backup', ['soubor' => $z['soubor']])) ?>"><?= e(t('Stáhnout')) ?></a> · <button class="navigace" type="submit" formaction="<?= e($module->url('restore_backup')) ?>" name="soubor" value="<?= e($z['soubor']) ?>" data-potvrdit="<?= e(t('Obnovit databázi z této zálohy? Všechno, co na webu přibylo po jejím vytvoření (stránky, novinky, poptávky, nastavení), se ztratí. Současný stav se předtím uloží do nové zálohy.')) ?>"><?= e(t('Obnovit')) ?></button> · <button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete_backup')) ?>" name="soubor" value="<?= e($z['soubor']) ?>" data-potvrdit="<?= e(t('Smazat zálohu?')) ?>"><?= e(t('Smazat')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('media_backup')) ?>"><?= e(t('Stáhnout zálohu médií (ZIP)')) ?></button></p>
<p class="napoveda"><?= e(t('Záloha databáze obsahuje stránky, novinky, nastavení a uživatele; nahrané obrázky jsou v záloze médií. Zálohy leží ve složce storage/zalohy/, která není z webu přístupná – stahujte si je i mimo server.')) ?></p>
</fieldset>
