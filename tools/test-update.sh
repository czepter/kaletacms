#!/usr/bin/env bash
# Kaleta – update test: installs an older release (FROM, default the latest tag) and updates it through the admin
# to a package built from the working tree, signed with a throwaway key. Checks that the update applies, migrates the
# database, removes files the new version no longer has (renamed classes must not linger) and that the site and the
# admin still work. Same env as tools/test.sh: DB_HOST DB_PORT DB_NAME DB_USER DB_PASS PORT.
# The database DB_NAME is DROPPED and created again.
set -euo pipefail

KOREN="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_upd}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8097}"
FROM="${FROM:-$(git -C "$KOREN" describe --tags --abbrev=0 HEAD^)}" # the release before HEAD (also when HEAD is a release tag)
PRACE="$(mktemp -d)"; JAR="$PRACE/cookies.txt"; B="http://127.0.0.1:$PORT"; KPORT=$((PORT + 1)); CHYB=0
uklid() { for pid in "${SERVER_PID:-}" "${KANAL_PID:-}"; do [ -z "$pid" ] || kill "$pid" 2>/dev/null || true; done; rm -rf "$PRACE"; }
trap uklid EXIT

MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
ocekavej() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: got „$2“, expected „$3“"; CHYB=$((CHYB+1)); }; }
csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//'; }
over() { # over <label> <expected code> <path>
  local kod; kod=$(curl -s -b "$JAR" -c "$JAR" -A "Mozilla/5.0 test" -o "$PRACE/odpoved" -w '%{http_code}' "$B$2")
  if [ "$kod" != 200 ] || grep -qE 'Fatal error|Warning:|Deprecated:|Notice:' "$PRACE/odpoved"; then echo "  CHYBA  $1 ($2): code $kod"; CHYB=$((CHYB+1)); else echo "  ok     $1"; fi
}

echo "== install $FROM"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$PRACE/web" "$PRACE/kanal" && git -C "$KOREN" archive "$FROM" | tar -xf - -C "$PRACE/web"
mkdir -p "$PRACE/web/media" "$PRACE/web/storage/log" "$PRACE/web/storage/cache"
git -C "$KOREN" ls-tree -r --name-only "$FROM" > "$PRACE/stare-soubory.txt"
(cd "$PRACE/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$PRACE/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
HESLO="Test-$(date +%s)-heslo"
curl -s -o "$PRACE/odpoved" -X POST "$B/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Testovací firma" -d web=firemni -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$HESLO" --data-urlencode "password2=$HESLO" \
  -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani'
grep -q "Hotovo, web běží" "$PRACE/odpoved" || { echo "  CHYBA  install of $FROM failed"; sed 's/<[^>]*>//g' "$PRACE/odpoved" | grep -v '^\s*$' | head -20; exit 1; }
echo "  ok     $FROM installed ($(sed -n "s/^const KALETA_VERSION = '\(.*\)';/\1/p" "$PRACE/web/system/bootstrap.php"))"
curl -s -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$HESLO"
over "old version: home page" /
over "old version: admin" /admin.php

echo "== package of the working tree, signed with a throwaway key"
# same file selection as tools/vydani.php; the old site gets the file list of its release (system/soubory.json) as a real
# install from a release package would have, so the update knows which old files to remove
(cd "$KOREN" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do [ -e "$s" ] && printf '%s\n' "$s"; done) > "$PRACE/nove-soubory.txt"
cat > "$PRACE/balicek.php" <<'PHP'
<?php
[, $koren, $web, $kanal, $port] = $argv;
require $koren . '/system/src/Core/Podpis.php';
require $koren . '/system/src/Core/Integrita.php';
$vynechat = '#^(tools/|docs/|\.github/|\.claude/|CLAUDE\.md$|\.gitignore$|\.gitleaks\.toml$|\.git-blame-ignore-revs$)#';
$bezOtisku = '#^(media|storage)/|^install\.php$#';
$par = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($par);
$pub = base64_encode(sodium_crypto_sign_publickey($par)) . " test\n";
$seznam = fn (string $verze, array $otisky): string => json_encode(['verze' => $verze, 'soubory' => $otisky,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Integrita::kPodpisu($verze, $otisky), $sk))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// old site: trusts the throwaway key and knows the files of its release
file_put_contents($web . '/system/aktualizace.pub', $pub);
$otisky = [];
foreach (file($kanal . '/../stare-soubory.txt', FILE_IGNORE_NEW_LINES) as $s) {
    if (!preg_match($vynechat, $s) && !preg_match($bezOtisku, $s) && is_file($web . '/' . $s)) {
        $otisky[$s] = hash_file('sha256', $web . '/' . $s);
    }
}
ksort($otisky);
file_put_contents($web . '/system/soubory.json', $seznam('stara', $otisky));

// new package: the throwaway key stays trusted after the update, so the integrity check of the updated site can pass
$zip = new ZipArchive();
$zip->open($kanal . '/kaleta.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$otisky = [];
foreach (file($kanal . '/../nove-soubory.txt', FILE_IGNORE_NEW_LINES) as $s) {
    if (preg_match($vynechat, $s)) {
        continue;
    }
    $obsah = (string) file_get_contents($koren . '/' . $s);
    if ($s === 'system/aktualizace.pub') {
        $obsah = $pub . $obsah;
    }
    $zip->addFromString($s, $obsah);
    if (!preg_match($bezOtisku, $s)) {
        $otisky[$s] = hash('sha256', $obsah);
    }
}
ksort($otisky);
$zip->addFromString('system/soubory.json', $seznam('99.0.0', $otisky));
$zip->close();
$sha = hash_file('sha256', $kanal . '/kaleta.zip');
file_put_contents($kanal . '/aktualizace.json', json_encode(['verze' => '99.0.0', 'url' => "http://127.0.0.1:$port/kaleta.zip", 'sha256' => $sha, 'min_php' => '8.4', 'zmeny' => ['test'],
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Podpis::zpravaBalicku('99.0.0', $sha, false), $sk))]));
echo '  ok     ' . count($otisky) . " files in the package\n";
PHP
# a class file of the old release that the new one no longer has: the update must delete it
echo '<?php // removed in the new version' > "$PRACE/web/system/src/Core/ZrusenaTrida.php"; echo system/src/Core/ZrusenaTrida.php >> "$PRACE/stare-soubory.txt"
php "$PRACE/balicek.php" "$KOREN" "$PRACE/web" "$PRACE/kanal" "$KPORT"
(cd "$PRACE/kanal" && exec php -S "127.0.0.1:$KPORT" > /dev/null 2>&1) & KANAL_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$KPORT/aktualizace.json" && break; sleep 0.2; done

echo "== update through the admin"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('aktualizace_url','http://127.0.0.1:$KPORT/aktualizace.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'aktualizace_cache'"
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=config&zalozka=zalohy"; TOKEN=$(csrf)
curl -s -L -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -X POST "$B/admin.php?modul=config&akce=aktualizuj" -d "_csrf=$TOKEN"
if grep -q "99.0.0" "$PRACE/odpoved"; then echo "  ok     update installed"; else
  echo "  CHYBA  update failed:"; sed 's/<[^>]*>//g' "$PRACE/odpoved" | grep -i -m3 'aktualiz'; exit 1; fi
cmp -s "$KOREN/system/bootstrap.php" "$PRACE/web/system/bootstrap.php" && echo "  ok     new core in place" || { echo "  CHYBA  system/bootstrap.php is not the new one"; CHYB=$((CHYB+1)); }
POSLEDNI=$(ls "$KOREN"/system/sql/migrace/*.sql | sed 's/.*\/\([0-9]*\)-.*/\1/' | sort -n | tail -1 | sed 's/^0*//')
ZBYLE=$(comm -23 <(sort "$PRACE/stare-soubory.txt") <(sort "$PRACE/nove-soubory.txt") | grep -vE '^(tools/|docs/|\.github/|\.claude/|CLAUDE\.md$|\.gitignore$|\.gitleaks\.toml$|install\.php$|media/|storage/|image/ukazka/)' \
  | while read -r s; do [ -e "$PRACE/web/$s" ] && echo "$s"; done || true)
[ -z "$ZBYLE" ] && echo "  ok     files dropped since $FROM are gone" || { echo "  CHYBA  files of $FROM left behind:"; echo "$ZBYLE" | head -10; CHYB=$((CHYB+1)); }
INTEGRITA=$(cd "$PRACE/web" && php -r 'require "system/bootstrap.php"; $k = Kaleta\Core\Integrita::kontrola(); echo $k["stav"], " ", $k["info"];')
ocekavej "core files match the package (integrity check)" "${INTEGRITA%% *}" ok
[ "${INTEGRITA%% *}" = ok ] || echo "         $INTEGRITA"

echo "== updated site"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
for s in / /o-nas /sluzby /kontakt /novinky /sitemap.xml; do over "page $s" "$s"; done
grep -q "Testovací firma" <(curl -s "$B/") && echo "  ok     content kept" || { echo "  CHYBA  home page lost its content"; CHYB=$((CHYB+1)); }
# every admin module of the new version, with all extensions on
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('rozsireni','novinky,poptavky,newsletter,statistika,presmerovani,asistent,jazyky,api,claude') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
over "admin dashboard" /admin.php
# releases before 1.1 migrate on the first admin load after the update, later ones during the update itself
ocekavej "database migrated to $POSLEDNI" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'verze_db'")" "$POSLEDNI"
for m in $(cd "$PRACE/web" && php -r 'require "system/bootstrap.php"; foreach (Kaleta\Admin\Kernel::MODULY as $m) { echo $m::IDENT, "\n"; }'); do over "admin $m" "/admin.php?modul=$m"; done
for z in zakladni seo stav zalohy; do over "admin settings/$z" "/admin.php?modul=config&zalozka=$z"; done
grep -q 'name="password"' "$PRACE/odpoved" && { echo "  CHYBA  the update logged the admin out"; CHYB=$((CHYB+1)); } || echo "  ok     admin session survived"

if [ -s "$PRACE/web/storage/log/chyby.log" ]; then echo "== application error log:"; cat "$PRACE/web/storage/log/chyby.log"; CHYB=$((CHYB+1)); fi
if grep -qE 'Fatal|Warning|Deprecated' "$PRACE/server.log"; then echo "== server log:"; grep -E 'Fatal|Warning|Deprecated' "$PRACE/server.log" | head; CHYB=$((CHYB+1)); fi
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`"
echo; [ "$CHYB" -eq 0 ] && echo "UPDATE FROM $FROM OK" || { echo "ERRORS: $CHYB"; exit 1; }
