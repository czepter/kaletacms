#!/usr/bin/env bash
# Kaleta - smoke test: a clean install into a temporary copy and a pass through the main pages.
# Runs locally and in GitHub Actions. Takes the database from environment variables:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test) DB_USER (root) DB_PASS (empty) PORT (8099) WEB (firemni | remeslo | poradenstvi)
# The database DB_NAME is DROPPED during the test and created again.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
WORK="$(mktemp -d)"; JAR="$WORK/cookies.txt"; B="http://127.0.0.1:$PORT"; ERRORS=0
cleanup() { for pid in "${SERVER_PID:-}" "${CHANNEL_PID:-}" "${SERVICE_PID:-}" "${SMTP_PID:-}"; do [ -z "$pid" ] || kill "$pid" 2>/dev/null || true; done; rm -rf "$WORK"; }
trap cleanup EXIT

echo "== syntaxe PHP"
find "$ROOT" -name '*.php' -not -path '*/.git/*' -not -path '*/dist/*' -print0 | xargs -0 -n1 php -l > /dev/null

echo "== čistá databáze a kopie projektu"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web") # soubory smazané a ještě nezapsané do gitu se nekopírují
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
(cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done

check() { # over <popis> <očekávaný kód> <adresa> [hledaný text]
  local code; code=$(curl -s -b "$JAR" -c "$JAR" -A "Mozilla/5.0 test" -o "$WORK/response" -w '%{http_code}' "$B$3")
  if [ "$code" != "$2" ] || grep -qE 'Fatal error|Warning:|Deprecated:|Notice:' "$WORK/response" || { [ -n "${4:-}" ] && ! grep -q "$4" "$WORK/response"; }; then
    echo "  CHYBA  $1 ($3): kód $code, čekal jsem $2${4:+, text „$4“}"; ERRORS=$((ERRORS+1))
  else echo "  ok     $1"; fi
}

LAST_MIGRATION=$(ls "$ROOT"/system/sql/migrace/*.sql | sed 's/.*\/\([0-9]*\)-.*/\1/' | sort -n | tail -1 | sed 's/^0*//')
grep -q "const KALETA_DB_VERSION = $LAST_MIGRATION;" "$ROOT/system/bootstrap.php" && echo "  ok     KALETA_DB_VERSION odpovídá poslední migraci ($LAST_MIGRATION)" || { echo "  CHYBA  KALETA_DB_VERSION v system/bootstrap.php neodpovídá poslední migraci ($LAST_MIGRATION)"; ERRORS=$((ERRORS+1)); }

echo "== jednotkové testy"
php "$ROOT/tools/unit-tests.php" || ERRORS=$((ERRORS+1))

csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//'; }
expect() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: dostal jsem „$2“, čekal jsem „$3“"; ERRORS=$((ERRORS+1)); }; }

echo "== instalace"
PASSWORD="Test-$(date +%s)-heslo"
curl -s -o "$WORK/response" -X POST "$B/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Testovací firma" -d "web=${WEB:-firemni}" -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
  -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani'
grep -q "Hotovo, web běží" "$WORK/response" || { echo "  CHYBA  instalace selhala"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
echo "  ok     instalace"
[ ! -f "$WORK/web/install.php" ] && echo "  ok     instalátor se po sobě smazal" || { echo "  CHYBA  install.php po instalaci zůstal na místě"; ERRORS=$((ERRORS+1)); }

echo "== web"
check "úvodní stránka" 200 / "Testovací firma"
check "úvodní stránka má navigaci stránek a novinek" 200 / 'href="/o-nas"'
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/uvod"); expect "úvodní stránka má jen jednu adresu" "$code" "301 $B/"
check "stránka" 200 /sluzby "Služby"
check "úvodní stránka je ze sekcí builderu" 200 / 'class="stavba"'
check "služby mají otázky a odpovědi i pro vyhledávače" 200 /sluzby '"FAQPage"'
check "výpis novinek" 200 /novinky "Vítejte v Kaletě"
check "novinka" 200 /novinky/vitejte-v-kalete "Vítejte"
check "kategorie" 200 /novinky/kategorie/aktuality
check "hledání najde novinku i stránku" 200 "/hledani?q=Kontakt" 'href="/kontakt"'
for u in /rss.xml /feed.json /sitemap.xml /robots.txt /llms.txt /novinky/vitejte-v-kalete.md; do check "$u" 200 "$u"; done
check "mapa webu obsahuje novinku" 200 /sitemap.xml "/novinky/vitejte-v-kalete"
check "llms.txt vyjmenuje stránky" 200 /llms.txt "## Stránky"
check "strukturovaná data novinky" 200 /novinky/vitejte-v-kalete '"BlogPosting"'
check "zásady z instalace jsou skryté, dokud je správce nedoplní" 404 /zasady-ochrany-osobnich-udaju
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'zasady-ochrany-osobnich-udaju'"
check "zásady ochrany osobních údajů po zveřejnění" 200 /zasady-ochrany-osobnich-udaju "Jaké údaje zpracováváme"
check "patička odkazuje na zásady" 200 /o-nas 'zasady-ochrany-osobnich-udaju'
check "neexistující stránka" 404 /tohle-neexistuje
check "system/ není přístupný" 403 /system/sql/schema.sql
check "config.php není přístupný" 403 /config.php
mkdir -p "$WORK/web/layout/vlastni" && echo '<?php echo "VLASTNI SABLONA";' > "$WORK/web/layout/vlastni/base.php"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni VALUES ('layout','vlastni')"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "themeless: the site uses the built-in frame, never a custom layout" 200 / "image/sablona.css"
grep -q "VLASTNI SABLONA" "$WORK/response" && { echo "  CHYBA  a custom layout was used"; ERRORS=$((ERRORS+1)); } || echo "  ok     a custom layout in layout/ is ignored"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='home_page'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "bez úvodní stránky je úvodem výpis novinek" 200 / "Vítejte v Kaletě"

echo "== administrace"
check "zapomenuté heslo – formulář" 200 "/admin.php?action=password" "Poslat odkaz"
check "zapomenuté heslo – neplatný odkaz" 400 "/admin.php?action=password&token=$(printf 'a%.0s' $(seq 1 64))" "Odkaz už neplatí"
check "bez přihlášení je jen login" 200 /admin.php "Heslo"
TOKEN=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin -d password=spatne-heslo-123); expect "špatné heslo odmítnuto" "$code" 401
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d user=admin --data-urlencode "password=$PASSWORD"); expect "POST bez CSRF odmítnut" "$code" 400
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$PASSWORD"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('extensions','novinky,poptavky,newsletter,statistika,presmerovani,asistent,jazyky,api,claude') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
check "přehled" 200 /admin.php "Přehled"
check "přehled: nadpis obrazovky je h1" 200 /admin.php "<h1>Přehled</h1>"
grep -q '<li class=""><a href="/admin.php?module=appearance">' "$WORK/response" && grep -q '<li class=""><a href="/admin.php?module=pages"><strong>Připravte stránky' "$WORK/response" && echo "  ok     první kroky nepočítají vzhled a stránky ze startovacího webu za hotové" || { echo "  CHYBA  první kroky odškrtnuté startovacím webem"; ERRORS=$((ERRORS+1)); }
check "administrace: nadpis h1 a hlavní menu v <nav>" 200 "/admin.php?module=pages" '<nav class="menu-obal" aria-label="Hlavní menu">'
for m in pages "pages&action=new" enquiries parts components "components&action=new" collections "collections&action=new" news "news&action=new" "news&action=links" categories "categories&action=new" tags media stats appearance users "users&action=new" redirects changelog transfer extensions; do check "modul $m" 200 "/admin.php?module=$m"; done
check "uživatelé se shrnutím oprávnění" 200 "/admin.php?module=users" "Smí všechno"
for z in general seo analytics cookies mail backups health; do check "nastavení/$z" 200 "/admin.php?module=settings&tab=$z"; done
check "nastavení: volba úvodní stránky" 200 "/admin.php?module=settings&tab=general" 'name="home_page"'
check "neznámý modul" 403 "/admin.php?module=neexistuje"
check "API: novinky" 200 /api/novinky '"novinky"'
check "API: stránky" 200 /api/stranky '/kontakt"'
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('additional_languages','en') ON DUPLICATE KEY UPDATE hodnota='en'"
check "anglická verze webu" 200 /en/ 'lang="en"'
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/en/novinky/vitejte-v-kalete"); expect "novinka jiné jazykové verze přesměruje" "$code" 301

# a failed news item validation must return the form with a message, not error 500
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=new"
TOKEN=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN" -d idc=0 -d titulek= -d tema=1)
[ "$code" = 200 ] && grep -q 'name="titulek"' "$WORK/response" && echo "  ok     chyba ve formuláři novinky vrátí formulář" || { echo "  CHYBA  validace novinky: kód $code"; ERRORS=$((ERRORS+1)); }

# news without a category (enabled only after installation): „Nová novinka“ (New news item) is not a dead end – a default category is created in the site language
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS kat_zaloha; CREATE TABLE kat_zaloha AS SELECT * FROM ka_kategorie; DELETE FROM ka_kategorie; UPDATE ka_nastaveni SET hodnota='en' WHERE promenna='site_language'"
check "nová novinka bez kategorie otevře editor" 200 "/admin.php?module=news&action=new" 'name="titulek"'
expect "výchozí kategorie založená v jazyce webu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', MAX(nazev)) FROM ka_kategorie")" "1:News"
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DELETE FROM ka_kategorie; INSERT INTO ka_kategorie SELECT * FROM kat_zaloha; DROP TABLE kat_zaloha; UPDATE ka_nastaveni SET hodnota='cs' WHERE promenna='site_language'"

# news author: sees only their own news items and does not publish
NEWS_ID=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky ORDER BY idc LIMIT 1")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Autor -d user=autor --data-urlencode "password=$PASSWORD" -d admin=0
JAR2="$WORK/jar2"
TOKEN2=$(curl -s -c "$JAR2" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN2" -d user=autor --data-urlencode "password=$PASSWORD"
code=$(curl -s -b "$JAR2" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=news")
[ "$code" = 200 ] && ! grep -q "action=edit&amp;id=$NEWS_ID\"" "$WORK/response" && echo "  ok     autor nevidí cizí novinky" || { echo "  CHYBA  autor – výpis: kód $code"; ERRORS=$((ERRORS+1)); }
expect "autor cizí novinku neotevře" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=news&action=edit&id=$NEWS_ID")" 404
expect "autor nemá přístup ke stránkám" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages")" 403
TOKEN2=$(curl -s -b "$JAR2" "$B/admin.php?module=news&action=new" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN2" -d idc=0 -d titulek=XSS-test -d tema=1 \
  --data-urlencode 'uvod=<p onmouseover="alert(1)">Perex</p><script>alert(2)</script>' --data-urlencode 'text=<p><img src=x onerror=alert(3)><a href="javascript:alert(4)">odkaz</a></p>'
expect "autor nevloží do novinky skript" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(uvod, text) REGEXP 'script|onerror|onmouseover|javascript' FROM ka_novinky WHERE titulek = 'XSS-test'")" "0"
check "editor vidí na přehledu novinky od autorů, které čekají na vydání" 200 /admin.php "Novinky od autorů čekají na vydání"
check "výpis novinek: filtr Čekají na vydání" 200 "/admin.php?module=news&stav=ke_vydani" "XSS-test"

echo "== firma"
check "nastavení/firma" 200 "/admin.php?module=settings&tab=company" 'name="company_hours"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Testovací firma s.r.o." -d company_type=HomeAndConstructionBusiness \
  -d company_id=12345678 -d company_vat_id=CZ12345678 --data-urlencode "company_street=Dlouhá 12" --data-urlencode "company_city=Praha" --data-urlencode "company_postcode=110 00" -d company_country=CZ \
  --data-urlencode "company_phone=+420 123 456 789" --data-urlencode "company_hours=Po–Pá 8:00–17:00
So 9–12" --data-urlencode "company_map=https://mapy.cz/s/abc" --data-urlencode "company_gps=50.0875, 14.4213"
expect "údaje firmy uloženy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_id'")" 12345678
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d zalozka=firma -d company_type=LocalBusiness -d company_country=CZ --data-urlencode "company_hours=kdykoli"
expect "nesrozumitelná otevírací doba odmítnuta" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%8:00%' AND hodnota NOT LIKE '%kdykoli%' FROM ka_nastaveni WHERE promenna = 'company_hours'")" 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Testovací firma s.r.o." -d company_type=HomeAndConstructionBusiness \
  -d company_id=12345678 -d company_vat_id=CZ12345678 --data-urlencode "company_street=Dlouhá 12" --data-urlencode "company_city=Praha" --data-urlencode "company_postcode=110 00" -d company_country=CZ \
  --data-urlencode "company_phone=+420 123 456 789" --data-urlencode "company_hours=Po–Pá 8:00–17:00" --data-urlencode "company_map=https://mapy.cz/s/abc" --data-urlencode "company_gps=50.0875, 14.4213"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q '"@type":"HomeAndConstructionBusiness"' "$WORK/response" && grep -q '"openingHoursSpecification"' "$WORK/response" && grep -q '"latitude":50.0875' "$WORK/response" && grep -q '"vatID":"CZ12345678"' "$WORK/response" \
  && echo "  ok     firma ve strukturovaných datech (LocalBusiness, otevírací doba, souřadnice)" || { echo "  CHYBA  firma ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kontakt"
grep -q 'Dlouhá 12<br>110 00 Praha' "$WORK/response" && grep -q 'href="tel:+420123456789"' "$WORK/response" && grep -q '<li>Po–Pá 8:00–17:00</li>' "$WORK/response" && grep -q 'IČO 12345678, DIČ CZ12345678' "$WORK/response" \
  && echo "  ok     kontakt vypisuje údaje firmy z Nastavení" || { echo "  CHYBA  údaje firmy na kontaktu"; ERRORS=$((ERRORS+1)); }

echo "== vzhled webu (design systém)"
check "vzhled s předvolbami a náhledem" 200 "/admin.php?module=appearance" 'data-predvolba'
TOKEN=$(csrf)
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=appearance&action=preview" -d "_csrf=$TOKEN" --data-urlencode 'ds[barvy][primarni]=#ff00aa' -d 'ds[zaklad_min]=18'
grep -q 'ka-barva-primarni: #ff00aa' "$WORK/response" && grep -q '"kontrasty"' "$WORK/response" && echo "  ok     živý náhled vrátí tokeny a kontrasty" || { echo "  CHYBA  náhled vzhledu"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=save" -d "_csrf=$TOKEN" -d layout=zakladni -d tmavy_rezim=vypnuto --data-urlencode 'ds[barvy][primarni]=#9a3412' --data-urlencode 'ds[barvy][text]=red;}body{' -d 'ds[pismo_titulky]=klasicke' -d 'ds[sirka]=1280'
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-barva-primarni: #9a3412' "$WORK/response" && grep -q 'ka-sirka: 80rem' "$WORK/response" && grep -q 'ka-pismo-titulky: Georgia' "$WORK/response" && echo "  ok     uložený vzhled je hned na webu" || { echo "  CHYBA  uložení vzhledu"; ERRORS=$((ERRORS+1)); }
grep -q 'body{' "$WORK/response" && { echo "  CHYBA  do CSS proniklo neplatné zadání barvy"; ERRORS=$((ERRORS+1)); } || echo "  ok     neplatná barva se nahradí výchozí"

echo "== builder stránek"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
check "builder se otevře a převede textovou stránku" 200 "/admin.php?module=pages&action=builder&id=$IDS" 'id="stavitel-data"'
TOKEN=$(csrf)
page_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDS" -d "_csrf=$TOKEN" "${@:2}"; }
BUILD='{"v":1,"deti":[{"id":"sek1","typ":"sekce","deti":[{"id":"nad1","typ":"nadpis","znacka":"h1","obsah":{"text":"Builder test"},"styl":{"zaklad":{"barva":"primarni"},"mobil":{"velikost_pisma":"2"}},"tridy":["karta"]},{"id":"faq1","typ":"faq","obsah":{"polozky":[{"otazka":"Kolik to stojí?","odpoved":"<p>Záleží na rozsahu.</p>"}]}},{"id":"txt1","typ":"text","obsah":{"html":"<h2>Jak to funguje</h2><p>Krok za krokem.</p><h2>Jak to funguje</h2><h3 id=\"vlastni\">Vlastní</h3>"}},{"id":"zly1","typ":"skript"}]}]}'
code=$(page_action stavba_uloz --data-urlencode "stavba=$BUILD")
[ "$code" = 200 ] && grep -q '"ok":true' "$WORK/response" && grep -q 'Neznámý typ prvku' "$WORK/response" && echo "  ok     uložení konceptu vrátí vyčištěnou stavbu a chyby" || { echo "  CHYBA  stavba_uloz: kód $code"; ERRORS=$((ERRORS+1)); }
expect "neplatný JSON stavby odmítnut" "$(page_action stavba_uloz -d 'stavba={nesmysl')" 400
expect "uložení z cizí verze odmítnuto (souběžná úprava)" "$(page_action stavba_uloz -d verze=0000000000000000 --data-urlencode "stavba=$BUILD")" 409
grep -q '"konflikt":true' "$WORK/response" && grep -q 'Builder test' "$WORK/response" && echo "  ok     konflikt vrátí novější verzi ze serveru" || { echo "  CHYBA  odpověď konfliktu"; ERRORS=$((ERRORS+1)); }
expect "publikování z cizí verze odmítnuto" "$(page_action stavba_publikuj -d verze=0000000000000000)" 409
expect "přepsání cizí verze na přání" "$(page_action stavba_uloz -d verze=0000000000000000 -d prepsat=1 --data-urlencode "stavba=$BUILD")" 200
expect "builder bez CSRF odmítnut" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_save&id=$IDS" --data-urlencode "stavba=$BUILD")" 400
expect "knihovna sekcí jen přes POST" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=build_section&id=$IDS&klic=faq")" 404
code=$(page_action "stavba_sekce&klic=vyhody"); [ "$code" = 200 ] && grep -q '"karta"' "$WORK/response" && echo "  ok     sekce z knihovny založí své třídy" || { echo "  CHYBA  stavba_sekce: kód $code"; ERRORS=$((ERRORS+1)); }
code=$(page_action stavba_trida -d nazev=karta --data-urlencode 'styl={"zaklad":{"pozadi":"plocha","odsazeni_y":"l"}}' --data-urlencode 'css=letter-spacing: 0.01em; background: url(x)')
[ "$code" = 200 ] && grep -q 'Nepovolená deklarace' "$WORK/response" && echo "  ok     třída uložena, nebezpečné CSS zahozeno" || { echo "  CHYBA  stavba_trida: kód $code"; ERRORS=$((ERRORS+1)); }
expect "neplatný název třídy odmítnut" "$(page_action stavba_trida -d 'nazev=Karta Velka')" 400
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q "Builder test" "$WORK/response" && echo "  ok     koncept není před publikováním na webu" || { echo "  CHYBA  koncept je na webu dřív, než se publikuje"; ERRORS=$((ERRORS+1)); }
check "náhled konceptu pro editor" 200 "/o-nas?stavba=koncept&editor=1" 'data-ka-id="nad1"'
check "náhled konceptu se neindexuje" 200 "/o-nas?stavba=koncept" 'noindex'
curl -s -o "$WORK/response" "$B/o-nas?stavba=koncept&editor=1"; ! grep -q "Builder test" "$WORK/response" && echo "  ok     náhled konceptu nevidí návštěvník" || { echo "  CHYBA  koncept vidí nepřihlášený"; ERRORS=$((ERRORS+1)); }
code=$(page_action stavba_sdilet -d dni=3); SHARED_LINK=$(php -r 'echo json_decode((string) file_get_contents($argv[1]))->odkaz ?? "";' "$WORK/response")
curl -s -o "$WORK/response" "$SHARED_LINK"
[ "$code" = 200 ] && [[ "$SHARED_LINK" == "$B/o-nas?stavba=koncept&nahled_klic="* ]] && grep -q "Builder test" "$WORK/response" && ! grep -q 'data-ka-id' "$WORK/response" && echo "  ok     sdílený odkaz ukáže koncept bez přihlášení a bez značek editoru" || { echo "  CHYBA  stavba_sdilet: kód $code, odkaz $SHARED_LINK"; ERRORS=$((ERRORS+1)); }
code=$(page_action stavba_publikuj); expect "publikování stavby" "$code" 200
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"
grep -q '<h1 id="s-nad1" class="karta">Builder test</h1>' "$WORK/response" && echo "  ok     publikovaná stavba na webu, jedna značka na prvek" || { echo "  CHYBA  stavba na webu"; ERRORS=$((ERRORS+1)); }
grep -q '<h2 id="jak-to-funguje">' "$WORK/response" && grep -q '<h2 id="jak-to-funguje-2">' "$WORK/response" && grep -q '<h3 id="vlastni">' "$WORK/response" && echo "  ok     mezititulky textu mají kotvy (jedinečné, vlastní id zůstane)" || { echo "  CHYBA  kotvy mezititulků v textu"; ERRORS=$((ERRORS+1)); }
grep -q 'data-ka-id' "$WORK/response" && { echo "  CHYBA  značky editoru na veřejném webu"; ERRORS=$((ERRORS+1)); } || echo "  ok     bez značek editoru na veřejném webu"
grep -q '@layer prvky' "$WORK/response" && grep -q '#s-nad1 { color: var(--ka-barva-primarni); }' "$WORK/response" && grep -q '.karta { background-color: var(--ka-barva-plocha)' "$WORK/response" && echo "  ok     CSS prvků a tříd ve vrstvách" || { echo "  CHYBA  CSS stavby"; ERRORS=$((ERRORS+1)); }
grep -q '"FAQPage"' "$WORK/response" && echo "  ok     otázky a odpovědi jako strukturovaná data" || { echo "  CHYBA  FAQPage chybí"; ERRORS=$((ERRORS+1)); }
check "hledání najde obsah stavby" 200 "/hledani?q=Builder+test" 'Nalezeno: 1'
page_action stavba_uloz --data-urlencode "stavba=${BUILD/Builder test/Druhá verze}" > /dev/null; page_action stavba_publikuj > /dev/null
expect "předchozí publikovaná verze je v historii" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = $IDS AND stavba LIKE '%Builder test%'")" 1
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idr FROM ka_stavba_revize WHERE ids = $IDS AND stavba LIKE '%Builder test%'")
page_action stavba_obnov -d "idr=$IDR" > /dev/null; grep -q 'Builder test' "$WORK/response" && echo "  ok     obnovení verze do konceptu" || { echo "  CHYBA  stavba_obnov"; ERRORS=$((ERRORS+1)); }
page_action stavba_zahod > /dev/null; grep -q 'Druhá verze' "$WORK/response" && echo "  ok     zahození změn vrátí publikovanou stavbu" || { echo "  CHYBA  stavba_zahod"; ERRORS=$((ERRORS+1)); }
expect "autor novinek do builderu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=builder&id=$IDS")" 403
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=build_text" -d "_csrf=$TOKEN" -d "ids=$IDS"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; grep -q "<h1>Druhá verze</h1>" "$WORK/response" && grep -q 'class="obal obsah"' "$WORK/response" && echo "  ok     návrat k textu zachová obsah stavby bez rozložení" || { echo "  CHYBA  stavba_text"; ERRORS=$((ERRORS+1)); }

echo "== Claude (MCP): builder"
API_TOKEN="kaleta_$(printf 'a%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$API_TOKEN")', NOW() FROM ka_uzivatele WHERE user = 'admin'"
# a grep that reads the whole input: „curl | grep -q“ with pipefail fails when grep exits before curl finishes writing (SIGPIPE)
contains() { grep "$@" > /dev/null; }
mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
grep -q '"name":"create_page"' "$WORK/response" && ! grep -q '"name":"vytvor_stranku"' "$WORK/response" && grep -q '"title":{' "$WORK/response" && echo "  ok     MCP: nástroje s anglickými názvy a parametry" || { echo "  CHYBA  MCP tools/list anglicky"; ERRORS=$((ERRORS+1)); }
mcp list_pages '{}' > "$WORK/response"; grep -q 'title\\":' "$WORK/response" && grep -q 'in_menu\\":' "$WORK/response" && echo "  ok     MCP: anglický nástroj vrací anglické klíče" || { echo "  CHYBA  MCP list_pages"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp seznam_stranek '{}' > "$WORK/response"; grep -q 'titulek\\":' "$WORK/response" && echo "  ok     MCP: český název funguje dál jako skrytý alias" || { echo "  CHYBA  MCP český alias"; ERRORS=$((ERRORS+1)); }
mcp get_page '{"id":99999}' > "$WORK/response"; grep -q 'The page does not exist. Use list_pages.' "$WORK/response" && echo "  ok     MCP: chyba anglického nástroje anglicky" || { echo "  CHYBA  MCP anglická chyba"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | grep -q 'build_from_html' && echo "  ok     MCP: pokyny serveru s anglickými názvy" || { echo "  CHYBA  MCP pokyny"; ERRORS=$((ERRORS+1)); }
mcp stavba_schema '{}' > "$WORK/response"; grep -q 'knihovna' "$WORK/response" && grep -q 'ka-mezera' "$WORK/response" && echo "  ok     MCP: schéma builderu" || { echo "  CHYBA  MCP stavba_schema"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_z_html '{"titulek":"Z HTML","html":"<style>.uvod-x { padding-block: var(--ka-mezera-2xl); } .uvod-x h1 { color: red }</style><header class=\"uvod-x\"><div class=\"container\"><h1>Stránka od Clauda</h1><p>Text <b>tučně</b>.</p><a class=\"btn\" href=\"/kontakt\">Kontakt</a></div></header><form><input></form>"}' > "$WORK/response"
grep -q 'koncept' "$WORK/response" && grep -q 'Formul' "$WORK/response" && grep -q 'vynech.*btn' "$WORK/response" && echo "  ok     MCP: HTML převedeno na koncept stavby s hlášením (i formulář)" || { echo "  CHYBA  MCP stavba_z_html"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDZ=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'z-html'")
expect "MCP: nová stránka zůstává skrytá a bez publikované stavby" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba IS NULL, '/', stavba_koncept LIKE '%od Clauda%') FROM ka_stranky WHERE ids = $IDZ")" "0/1/1"
expect "MCP: třída z <style> uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT css FROM ka_tridy WHERE nazev = 'uvod-x'")" "padding-block: var(--ka-mezera-2xl);"
mcp vloz_sekci "{\"id\":$IDZ,\"sekce\":\"faq\"}" > /dev/null
mcp publikuj_stavbu "{\"id\":$IDZ}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $IDZ"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
grep -q '<h1>Stránka od Clauda</h1>' "$WORK/response" && grep -q 'class="uvod-x"' "$WORK/response" && ! grep -q 'container' "$WORK/response" && grep -q '"FAQPage"' "$WORK/response" && echo "  ok     MCP: publikovaná stránka od Clauda na webu" || { echo "  CHYBA  MCP publikování"; ERRORS=$((ERRORS+1)); }
mcp uprav_design_system '{"ds":{"barvy":{"primarni":"#0f766e"},"zaobleni":"l"}}' > "$WORK/response"; grep -q 'citelnost' "$WORK/response" && echo "  ok     MCP: úprava design systému" || { echo "  CHYBA  MCP uprav_design_system"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "design systém z MCP je na webu" 200 / 'ka-barva-primarni: #0f766e'
check "design systém z MCP zachoval ostatní barvy" 200 / 'ka-barva-plocha: #f5f6f8'
mcp uprav_nastaveni '{"nastaveni":{"tmavy_rezim":"tmavy","tmavy_prepinac":"1"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; grep -q 'data-tmavy data-tema="tmavy"' "$WORK/response" && grep -q 'data-tema-volba="svetly"' "$WORK/response" && grep -q 'localStorage.getItem(.ka-tema.)' "$WORK/response" && grep -q 'data-tema=\\"tmavy\\"\]\|data-tema="tmavy"\] {' "$WORK/response" \
    && echo "  ok     tmavý vzhled vždy a přepínač vzhledu pro návštěvníky (i přes MCP)" || { echo "  CHYBA  tmavý režim a přepínač vzhledu"; ERRORS=$((ERRORS+1)); }
mcp uprav_nastaveni '{"nastaveni":{"tmavy_rezim":"vypnuto","tmavy_prepinac":"0"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== Claude (MCP): stavba webu bez administrace"
mcp stavba_z_html '{"titulek":"Mrizka","html":"<style>.mriz-t { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .kar-t:hover { box-shadow: var(--ka-stin-m) } @media (max-width: 767px) { .mriz-t { grid-template-columns: 1fr } }</style><section><div class=\"mriz-t\"><div class=\"kar-t\"><h3>Jedna</h3></div><div class=\"kar-t\"><h3>Dva</h3></div></div></section>"}' > "$WORK/response"
IDM2=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'mrizka'")
expect "MCP: @media a :hover z <style> jako stavy třídy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT styl FROM ka_tridy WHERE nazev = 'mriz-t'), (SELECT styl FROM ka_tridy WHERE nazev = 'kar-t'))")" '{"mobil":{"sloupce":"1"}}{"hover":{"stin":"m"}}'
mcp stavba_nacti "{\"id\":$IDM2}" > "$WORK/response"
grep -q 'mriz-t' "$WORK/response" && ! grep -q 'zobrazeni' "$WORK/response" && ! grep -q '\\"odkaz\\":\\"\\"' "$WORK/response" && echo "  ok     MCP: stavba_nacti bez výchozích hodnot, prvek s třídou bez výchozího stylu" || { echo "  CHYBA  MCP stavba_nacti kompaktní"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDH3=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["stavba"]["deti"][0]["deti"][0]["deti"][0]["deti"][0]["id"];' "$WORK/response")
mcp stavba_uprav "{\"id\":$IDM2,\"operace\":[{\"op\":\"uprav\",\"id\":\"$IDH3\",\"obsah\":{\"text\":\"Opraveno\"}},{\"op\":\"smaz\",\"id\":\"neni\"}]}" > "$WORK/response"
grep -q 'chyby_operaci\\":{\\"op\[1\]' "$WORK/response" && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%Opraveno%' FROM ka_stranky WHERE ids = $IDM2")" = 1 ] \
    && echo "  ok     MCP: dílčí úprava prvku podle id (chybná operace nahlášena)" || { echo "  CHYBA  MCP stavba_uprav"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
CHECK_RESULT=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo implode("|", array_column($j["kontrola"] ?? [], "zprava"));' "$WORK/response")
[[ "$CHECK_RESULT" == *"(h1)"* ]] && echo "  ok     MCP: zápis stavby vrátí kontrolu před publikováním (stránka bez h1)" || { echo "  CHYBA  MCP kontrola: $CHECK_RESULT"; ERRORS=$((ERRORS+1)); }
PREVIEW=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["nahled"];' "$WORK/response")
curl -s -o "$WORK/response" -w '%{http_code}' "$PREVIEW" > "$WORK/kod"; grep -q 'Opraveno' "$WORK/response" && grep -q 'noindex' "$WORK/response" && [ "$(cat "$WORK/kod")" = 200 ] \
    && echo "  ok     podepsaný náhled konceptu skryté stránky bez přihlášení" || { echo "  CHYBA  podepsaný náhled ($(cat "$WORK/kod"))"; ERRORS=$((ERRORS+1)); }
expect "náhled s cizím nebo pozměněným klíčem nejde" "$(curl -s -o /dev/null -w '%{http_code}' "${PREVIEW%?}x")" 404
expect "klíč náhledu jedné stránky neotevře jinou" "$(curl -s -o /dev/null -w '%{http_code}' "$B/z-html?stavba=koncept&nahled_klic=${PREVIEW##*nahled_klic=}" | tr -d '\n'; curl -s "$B/z-html?stavba=koncept&nahled_klic=${PREVIEW##*nahled_klic=}" | grep -c 'Opraveno')" "2000"
mcp nahled_odkaz '{"cast":"paticka"}' > "$WORK/response"; grep -q 'cast=paticka&stavba=koncept&nahled_klic=' "$WORK/response" && echo "  ok     MCP: odkaz na náhled části webu" || { echo "  CHYBA  MCP nahled_odkaz"; ERRORS=$((ERRORS+1)); }
mcp uloz_tridy '{"css":".stitek-t { padding: var(--ka-mezera-2xs) var(--ka-mezera-s); border-radius: var(--ka-zaobleni) } @media (max-width: 1023px) { .stitek-t { font-size: var(--ka-krok--1) } }"}' > "$WORK/response"
mcp uloz_tridy '{"css":".stitek-t:hover { background-color: #ffe3dc }"}' > /dev/null
mcp seznam_trid '{"nazev":"stitek-t"}' > "$WORK/response"; grep -q 'velikost_pisma\\":\\"-1' "$WORK/response" && grep -q 'hover' "$WORK/response" && grep -q 'border-radius' "$WORK/response" && echo "  ok     MCP: sdílená třída z CSS i se stavem tablet" || { echo "  CHYBA  MCP uloz_tridy"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
PNG=$(php -r '$i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 255, 79, 46)); ob_start(); imagepng($i); echo base64_encode(ob_get_clean());')
mcp nahraj_soubor "{\"nazev\":\"tym-foto.png\",\"data\":\"$PNG\",\"popis\":\"Tym v dilne\"}" > "$WORK/response"
MEDIUM=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["adresa"] ?? "";' "$WORK/response")
[ -n "$MEDIUM" ] && [ -f "$WORK/web/$MEDIUM" ] && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE obr_poloha = '$MEDIUM'")" = "Tym v dilne" ] \
    && echo "  ok     MCP: obrázek nahraný v base64 je v Médiích" || { echo "  CHYBA  MCP nahraj_soubor (obrázek)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nahraj_soubor "{\"nazev\":\"pismo.woff2\",\"data\":\"$(base64 < image/pisma/bricolage-grotesque-latin.woff2 | tr -d '\n')\"}" > "$WORK/response"
grep -q 'vlastni_pisma' "$WORK/response" && grep -q 'pismo-[a-f0-9]*\.woff2' "$WORK/response" && echo "  ok     MCP: písmo WOFF2 do Médií s návodem pro design system" || { echo "  CHYBA  MCP nahraj_soubor (písmo)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nahraj_soubor '{"nazev":"skript.php","data":"PD9waHAgZWNobyAxOw=="}' > "$WORK/response"; grep -q 'isError' "$WORK/response" && ! ls "$WORK"/web/media/*/*/skript* > /dev/null 2>&1 && echo "  ok     MCP: PHP ani jiný spustitelný soubor nahrát nejde" || { echo "  CHYBA  MCP nahraj_soubor pustil PHP"; ERRORS=$((ERRORS+1)); }
mcp uprav_nastaveni '{"nastaveni":{"text_paticky":"Paticka od Clauda","email_webu":"utocnik@example.com","firma_ico":"abc"}}' > "$WORK/response"
expect "MCP: nastavení webu – povolené se uloží, e-mail a neplatné IČO ne" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'footer_text'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'), '') <> 'utocnik@example.com', '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_id'), '') <> 'abc')")" "Paticka od Clauda|1|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'spravce@example.cz' WHERE promenna = 'site_email'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s "$B/" | contains 'spravce@example.cz' && { echo "  CHYBA  e-mail webu je vidět na webu"; ERRORS=$((ERRORS+1)); } || echo "  ok     e-mail webu (poptávky, upozornění) se na webu neukazuje"
mcp uprav_nastaveni '{"nastaveni":{"firma_email":"info@example.cz"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "veřejný e-mail firmy v patičce" 200 / "info@example.cz"
mcp uprav_nastaveni '{"nastaveni":{"logo_webu":"image/kaleta-logo.svg","favicon":"../config.php"}}' > "$WORK/response"
expect "MCP: logo webu ze systémových souborů, cesta mimo media/ a image/ neprojde" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'favicon'), ''))")" "image/kaleta-logo.svg|"
mcp uloz_presmerovani '{"z":"/stary-web/sluzby","na":"/z-html"}' > /dev/null
expect "MCP: přesměrování staré adresy" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/stary-web/sluzby")" "301 $B/z-html"
mcp smaz_stranku "{\"id\":$IDM2}" > /dev/null
expect "MCP: stránka do koše" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NOT NULL FROM ka_stranky WHERE ids = $IDM2")" 1
mcp smaz_stranku "{\"id\":$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")}" | grep -q 'isError' && echo "  ok     MCP: úvodní stránku smazat nejde" || { echo "  CHYBA  MCP smazal úvodní stránku"; ERRORS=$((ERRORS+1)); }
mcp vytvor_sablonu '{"nazev":"test-kopie"}' > "$WORK/response"; mcp copy_theme '{"name":"test-kopie2"}' >> "$WORK/response"
[ "$(grep -o 'isError' "$WORK/response" | wc -l | tr -d ' ')" = 2 ] && [ ! -d "$WORK/web/layout/test-kopie" ] && [ ! -d "$WORK/web/layout/test-kopie2" ] && echo "  ok     MCP: vlastní šablony už přes napojení nevznikají" || { echo "  CHYBA  MCP šablony"; ERRORS=$((ERRORS+1)); }
mcp vytvor_kategorii '{"nazev":"Kategorie XSS","popis":"<p>Úvod</p><script>alert(1)</script><img src=x onerror=alert(2)>"}' > /dev/null
check "MCP: popis kategorie se vyčistí" 200 "/novinky/kategorie/kategorie-xss" "Úvod"
! grep -qE '<script>alert|onerror' "$WORK/response" && echo "  ok     MCP: v popisu kategorie nezůstal skript" || { echo "  CHYBA  popis kategorie pustil skript"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_kategorie SET popis = '<p>Stary popis</p><script>alert(3)</script>' WHERE seo_link = 'kategorie-xss'"
check "Uložený starý popis kategorie" 200 "/novinky/kategorie/kategorie-xss" "Stary popis"
! grep -q '<script>alert(3)' "$WORK/response" && echo "  ok     Výpis čistí i dřív uložený popis kategorie" || { echo "  CHYBA  výpis kategorie vypsal skript"; ERRORS=$((ERRORS+1)); }

echo "== části webu v builderu"
check "části webu" 200 "/admin.php?module=parts" "Záhlaví"
check "záhlaví se otevře v builderu s koncept podle šablony" 200 "/admin.php?module=parts&action=builder&typ=hlavicka&jazyk=" 'id="stavitel-data"'
TOKEN=$(csrf)
part_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=$1&typ=$2&jazyk=" -d "_csrf=$TOKEN" "${@:3}"; }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'header class="hlavicka"' "$WORK/response" && ! grep -q 'ka-nav' "$WORK/response" && echo "  ok     nepublikované záhlaví kreslí šablona" || { echo "  CHYBA  nepublikované záhlaví je na webu"; ERRORS=$((ERRORS+1)); }
check "náhled konceptu záhlaví pro editor" 200 "/o-nas?cast=hlavicka&stavba=koncept&editor=1" 'data-ka-typ="navigace"'
curl -s -o "$WORK/response" "$B/o-nas?cast=hlavicka&stavba=koncept&editor=1"; ! grep -q 'data-ka-typ' "$WORK/response" && echo "  ok     náhled části nevidí návštěvník" || { echo "  CHYBA  koncept části vidí nepřihlášený"; ERRORS=$((ERRORS+1)); }
expect "publikování záhlaví" "$(part_action stavba_publikuj hlavicka)" 200
curl -s -o "$WORK/response" "$B/o-nas"
grep -q 'class="ka-nav"' "$WORK/response" && ! grep -q 'header class="hlavicka"' "$WORK/response" && grep -q 'href="/o-nas" aria-current="page"' "$WORK/response" && echo "  ok     záhlaví z builderu na webu s aktivní položkou menu" || { echo "  CHYBA  záhlaví z builderu"; ERRORS=$((ERRORS+1)); }
[ "$(grep -o '<style>' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] && [ "$(grep -o '@layer stavitel {' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] && echo "  ok     stránka a části webu mají jedno CSS" || { echo "  CHYBA  CSS částí webu se opakuje"; ERRORS=$((ERRORS+1)); }
WRAPPER='{"v":1,"deti":[{"id":"obs1","typ":"obsah"},{"id":"sek9","typ":"sekce","deti":[{"id":"nad9","typ":"nadpis","obsah":{"text":"Pod článkem"}}]}]}'
check "obálka novinky v builderu" 200 "/admin.php?module=parts&action=builder&typ=novinka&jazyk=" 'id="stavitel-data"'
part_action stavba_uloz novinka --data-urlencode "stavba=$WRAPPER" > /dev/null; part_action stavba_publikuj novinka > /dev/null
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete"; grep -q 'Pod článkem' "$WORK/response" && grep -q '<main id="obsah" class="stavba">' "$WORK/response" && grep -q 'class="obal obsah"' "$WORK/response" && grep -q 'Vítejte' "$WORK/response" && echo "  ok     obálka kolem novinky" || { echo "  CHYBA  obálka novinky"; ERRORS=$((ERRORS+1)); }
part_action stavba_uloz hlavicka --data-urlencode 'stavba={"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"logo"}]}]}' > /dev/null; part_action stavba_publikuj hlavicka > /dev/null
expect "předchozí záhlaví je ve verzích" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'hlavicka:'")" 1
part_action sablona hlavicka > /dev/null
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'header class="hlavicka"' "$WORK/response" && echo "  ok     vrácení záhlaví na šablonu" || { echo "  CHYBA  vrácení na šablonu"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]},"publikovat":true}' > "$WORK/response"
grep -q 'publikováno' "$WORK/response" && echo "  ok     MCP: patička ze stavby" || { echo "  CHYBA  MCP patička"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; grep -q "<p class=\"ka-udaj\">&copy; $(date +%Y) Testovací firma</p>" "$WORK/response" && ! grep -q 'footer class="paticka"' "$WORK/response" && echo "  ok     patička z MCP na webu" || { echo "  CHYBA  patička z MCP na webu"; ERRORS=$((ERRORS+1)); }
expect "autor novinek k částem webu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=parts")" 403

echo "== formuláře a poptávky"
curl -s -o "$WORK/formular.html" "$B/kontakt"
grep -q 'class="ka-formular"' "$WORK/formular.html" && grep -q 'name="as_podpis"' "$WORK/formular.html" && echo "  ok     kontakt má poptávkový formulář" || { echo "  CHYBA  formulář na kontaktu"; ERRORS=$((ERRORS+1)); }
field_value() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/formular.html" | head -1 | sed 's/.*value="//;s/"$//'; }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
submit_form() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" "$@"; }
# too fast a submit (autofill): its own code and the message „počkejte chvilku“ (wait a moment), not „nepodařilo se ověřit“ (could not verify)
NOW=$(date +%s); FAST_SIGNATURE=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$FORM_SOURCE|$FORM_ELEMENT|$NOW" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")")
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$NOW" -d "as_podpis=$FAST_SIGNATURE" -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *vysledek=rychle*) echo "  ok     příliš rychlé odeslání má vlastní výsledek";; *) echo "  CHYBA  příliš rychlé odeslání formuláře"; ERRORS=$((ERRORS+1));; esac
check "hlášení po příliš rychlém odeslání radí počkat" 200 "/kontakt?formular=$FORM_ELEMENT&vysledek=rychle" "Počkejte prosím chvilku a odešlete ho znovu"
grep -q 'type="text" autocomplete="name"' "$WORK/formular.html" && grep -q 'type="tel" autocomplete="tel" maxlength="30" pattern="' "$WORK/formular.html" && echo "  ok     jméno s automatickým vyplněním, telefon s kontrolou v prohlížeči" || { echo "  CHYBA  autocomplete jména nebo vzor telefonu"; ERRORS=$((ERRORS+1)); }
grep -q 'name="as_cas" value="[0-9]*" data-cekat="4"' "$WORK/formular.html" && echo "  ok     formulář nese minimální dobu pro odložené odeslání" || { echo "  CHYBA  data-cekat u formuláře"; ERRORS=$((ERRORS+1)); }
sleep 4
location=$(submit_form -H "Referer: $B/kontakt?utm_source=newsletter&utm_medium=email&utm_campaign=jaro" -d p0=Jana --data-urlencode p1=jana@example.cz -d p2= --data-urlencode "p3=Chci kuchyň na míru." -d p4=1)
case "$location" in *"/kontakt?formular=$FORM_ELEMENT&vysledek=ok#"*"$FORM_ELEMENT") echo "  ok     odeslání formuláře";; *) echo "  CHYBA  odeslání formuláře: $location"; ERRORS=$((ERRORS+1));; esac
expect "poptávka uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), '/', MAX(email), '/', MAX(stav)) FROM ka_poptavky")" "1/jana@example.cz/0"
expect "poptávka nese kampaň z utm_* stránky s formulářem" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT kampan FROM ka_poptavky")" "utm_source=newsletter&utm_medium=email&utm_campaign=jaro"
case "$(submit_form -d p0=Jana -d p1=neni-email -d p3=x -d p4=1)" in *vysledek=pole\&pole=1*) echo "  ok     neplatný e-mail odmítnut s číslem pole";; *) echo "  CHYBA  validace e-mailu"; ERRORS=$((ERRORS+1));; esac
curl -s -o "$WORK/response" "$B/kontakt?formular=$FORM_ELEMENT&vysledek=pole&pole=1"
grep -q 'aria-invalid="true" aria-describedby="f-'"$FORM_ELEMENT"'-1-chyba"' "$WORK/response" && grep -q 'data-obnovit' "$WORK/response" && echo "  ok     chybné pole je označené a vyplněné hodnoty se obnoví" || { echo "  CHYBA  označení chybného pole"; ERRORS=$((ERRORS+1)); }
case "$(submit_form -d p0=Jana --data-urlencode p1=jana@example.cz -d p3=x)" in *vysledek=pole*) echo "  ok     chybějící souhlas odmítnut";; *) echo "  CHYBA  povinný souhlas"; ERRORS=$((ERRORS+1));; esac
submit_form -d p0=Robot --data-urlencode p1=r@example.cz -d p3=spam -d p4=1 -d web_adresa=http://spam.example > /dev/null
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$FORM_TIME" -d as_podpis=podvrh -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *vysledek=overeni*) echo "  ok     podvržený podpis odmítnut";; *) echo "  CHYBA  podpis formuláře"; ERRORS=$((ERRORS+1));; esac
case "$(submit_form -d zdroj=stranka:999 -d p0=A)" in *formular=*) echo "  CHYBA  neexistující formulář přijat"; ERRORS=$((ERRORS+1));; *) echo "  ok     neexistující formulář nic neuloží";; esac
expect "robot ani chyby poptávku nepřidaly" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_poptavky")" 1
IDP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_poptavky")
check "poptávky v administraci" 200 "/admin.php?module=enquiries" "jana@example.cz"
check "detail poptávky" 200 "/admin.php?module=enquiries&action=detail&id=$IDP" "Chci kuchyň na míru."
grep -q '>Tester</option>' "$WORK/response" && ! grep -q '>Autor</option>' "$WORK/response" && echo "  ok     poptávku vyřizuje jen ten, kdo má přístup k Poptávkám" || { echo "  CHYBA  výběr Vyřizuje nabízí uživatele bez přístupu k Poptávkám"; ERRORS=$((ERRORS+1)); }
expect "otevřená poptávka je přečtená" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_poptavky")" 1
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=csv"; grep -q 'Chci kuchyň na míru.' "$WORK/response" && echo "  ok     export poptávek do CSV" || { echo "  CHYBA  CSV poptávek"; ERRORS=$((ERRORS+1)); }
check "poděkování po odeslání (na místě formuláře)" 200 "/kontakt?formular=$FORM_ELEMENT&vysledek=ok" 'class="ka-formular-hotovo"'

echo "== kolekce"
check "kolekce" 200 "/admin.php?module=collections" "Kolekce"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save" -d "_csrf=$TOKEN" -d idk=0 --data-urlencode "nazev=Tým" -d detail=1 \
  --data-urlencode "pole[0][popisek]=Funkce" -d "pole[0][typ]=text" --data-urlencode "pole[1][popisek]=Foto" -d "pole[1][typ]=obrazek" --data-urlencode "pole[2][popisek]=Medailonek" -d "pole[2][typ]=html"
IDK=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'")
expect "kolekce založena s poli" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT pole LIKE '%\"funkce\"%' AND pole LIKE '%\"medailonek\"%' FROM ka_kolekce WHERE idk = $IDK")" 1
save_item() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$IDK" -d idp=0 "$@"; }
save_item --data-urlencode "nazev=Jana Nováková" --data-urlencode "data[funkce]=Jednatelka" --data-urlencode "data[medailonek]=<p>Dvacet let <b>v oboru</b>.</p><script>x</script>" -d poradi=1 -d zobrazit=1
save_item --data-urlencode "nazev=Skrytý Člen" --data-urlencode "data[funkce]=Tajný" -d poradi=2
check "položky kolekce" 200 "/admin.php?module=collections&action=items&id=$IDK" "Jana Nováková"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"smy1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\"},\"deti\":[{\"id\":\"kar1\",\"typ\":\"kontejner\",\"styl\":{\"zaklad\":{\"pozadi\":\"plocha\"}},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>{{funkce}}</p>{{medailonek}}\"}},{\"typ\":\"tlacitko\",\"obsah\":{\"text\":\"Profil\",\"odkaz\":\"{{url}}\"}}]}]}]}]}}" > "$WORK/response"
grep -q 'publikováno' "$WORK/response" || { echo "  CHYBA  MCP stránka s výpisem kolekce"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
grep -q '<h3>Jana Nováková</h3>' "$WORK/response" && grep -q '<p>Jednatelka</p>' "$WORK/response" && grep -q '^<p>Dvacet let <b>v oboru</b>.</p>' "$WORK/response" && grep -q 'href="/tym/jana-novakova"' "$WORK/response" && ! grep -q 'Skrytý' "$WORK/response" \
  && echo "  ok     výpis kolekce na stránce (jen zveřejněné položky, hodnoty dosazené)" || { echo "  CHYBA  výpis kolekce"; ERRORS=$((ERRORS+1)); }
grep -q 'class="s-kar1"' "$WORK/response" && ! grep -q 'id="s-kar1"' "$WORK/response" && grep -q '\.s-kar1 { background-color' "$WORK/response" && ! grep -q '<script>x' "$WORK/response" \
  && echo "  ok     opakované prvky mají styl přes třídu, ne duplicitní id" || { echo "  CHYBA  styl ve výpisu kolekce"; ERRORS=$((ERRORS+1)); }
check "detail položky kolekce" 200 /tym/jana-novakova "Jednatelka"
check "detail má nadpis položky" 200 /tym/jana-novakova "<h1>Jana Nováková</h1>"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/tym/skryty-clen"); expect "skrytá položka nemá detail" "$code" 404
check "mapa webu obsahuje detail položky" 200 /sitemap.xml "/tym/jana-novakova"
check "šablona detailu v builderu" 200 "/admin.php?module=collections&action=builder&id=$IDK" 'id="stavitel-data"'
mcp seznam_kolekci '{}' > "$WORK/response"; grep -q 'kolekce\\":\\"tym' "$WORK/response" && grep -q 'medailonek' "$WORK/response" && echo "  ok     MCP: seznam kolekcí s poli" || { echo "  CHYBA  MCP seznam_kolekci"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Petr Svoboda","data":{"funkce":"Mistr truhlář"},"zobrazit":true}' > /dev/null
mcp save_collection_item '{"collection":"tym","name":"Text JSON","values":"{\"funkce\":\"Z textu\"}"}' > "$WORK/response"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Z textu"}' | grep -q 'Text JSON' && echo "  ok     MCP: data poslaná jako text JSON se uloží" || { echo "  CHYBA  MCP data jako text JSON"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp uloz_menu '{"umisteni":"hlavni","polozky":"nejde precist"}' | grep -q 'musí být seznam' && echo "  ok     MCP: nečitelné položky menu jsou chyba, menu se nevrátí na automatické" || { echo "  CHYBA  MCP nečitelné položky menu"; ERRORS=$((ERRORS+1)); }
mcp save_classes '{"classes":[{"name":"x"}]}' | grep -q 'unknown_parameters' && echo "  ok     MCP: neznámý parametr je ve výsledku, ne tiše vynechaný" || { echo "  CHYBA  MCP neznámé parametry"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Spatna data","data":"funkce=x"}' | grep -q 'musí být objekt' && echo "  ok     MCP: nečitelná data položky jsou chyba, ne tiché vynechání" || { echo "  CHYBA  MCP nečitelná data položky"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zdenek Zeman","adresa":"zdenek","zobrazit":true}' > "$WORK/response"
grep -q 'tym\\/zdenek' "$WORK/response" && echo "  ok     MCP: vlastní adresa položky" || { echo "  CHYBA  MCP adresa položky"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"kolekce":"tym","stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profil: {{nazev}}"}}]}]}}' > "$WORK/response"
PREVIEW=$(php -r '$o = json_decode(file_get_contents($argv[1]), true); echo json_decode($o["result"]["content"][0]["text"] ?? "{}", true)["nahled"] ?? "";' "$WORK/response")
[ -n "$PREVIEW" ] && curl -s "$PREVIEW" | grep -q 'Profil: ' && echo "  ok     MCP: šablona detailu kolekce jako koncept s podepsaným náhledem" || { echo "  CHYBA  MCP šablona detailu kolekce"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s "$B/tym/zdenek" | contains 'Profil: ' && { echo "  CHYBA  koncept šablony kolekce je vidět bez publikování"; ERRORS=$((ERRORS+1)); } || echo "  ok     koncept šablony kolekce návštěvník nevidí"
mcp publikuj_stavbu '{"kolekce":"tym"}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "MCP: publikovaná šablona detailu kolekce" 200 /tym/zdenek "Profil: Zdenek Zeman"
check "llms.txt vyjmenuje položky kolekcí s detailem" 200 /llms.txt "/tym/zdenek"
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zuzana Zelena","data":{"funkce":"Jednatelka"},"zobrazit":true}' > /dev/null
mcp stavba_uloz '{"kolekce":"tym","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profil: {{nazev}}"}},{"typ":"kolekce","obsah":{"kolekce":"tym","filtr_pole":"funkce","filtr_hodnota":"{{funkce}}","bez_aktualni":true},"deti":[{"typ":"nadpis","znacka":"h3","obsah":{"text":"Kolega: {{nazev}}"}}]}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/tym/jana-novakova"
grep -q 'Kolega: Zuzana Zelena' "$WORK/response" && ! grep -q 'Kolega: Jana' "$WORK/response" && ! grep -q 'Kolega: Petr' "$WORK/response" && ! curl -s "$B/tym/petr-svoboda" | contains 'Kolega: Zuzana' \
    && echo "  ok     související položky: filtr podle pole zobrazené položky, bez ní samotné" || { echo "  CHYBA  související položky kolekce"; ERRORS=$((ERRORS+1)); }
# a collection in several languages: an item's translation has the same slug, another language its own item template, breadcrumbs lead to the translation of the hub page
mcp vytvor_stranku '{"titulek":"Náš tým","adresa":"tym","text":"<p>Tým</p>","zobrazit":true}' > /dev/null; IDTYM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'tym'")
mcp vytvor_stranku "{\"titulek\":\"Our team\",\"adresa\":\"team\",\"jazyk\":\"en\",\"preklad_z\":$IDTYM,\"text\":\"<p>Team</p>\",\"zobrazit\":true}" > /dev/null
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zdenek Zeman EN","adresa":"zdenek","jazyk":"en","data":{"funkce":"Workshop lead"},"zobrazit":true}' > "$WORK/response"
grep -q 'en\\/tym\\/zdenek\\"' "$WORK/response" && mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Druhy Zdenek","adresa":"zdenek","jazyk":"en"}' | grep -q 'tym\\/zdenek-2' \
  && echo "  ok     adresa položky je jedinečná v jazyce (překlad smí mít stejnou)" || { echo "  CHYBA  adresa položky v jiném jazyce"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"kolekce":"tym","jazyk":"en","stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"drobecky"},{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profile: {{nazev}}"}}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s "$B/en/tym/zdenek" | contains 'Profil: Zdenek Zeman EN' && echo "  ok     jazyk bez vlastní šablony použije šablonu výchozího jazyka, koncept je skrytý" || { echo "  CHYBA  šablona detailu jazyka bez publikování"; ERRORS=$((ERRORS+1)); }
mcp publikuj_stavbu '{"kolekce":"tym","jazyk":"en"}' > /dev/null; mcp stavba_uloz '{"kolekce":"tym","jazyk":"en","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"drobecky"},{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profile: {{nazev}}"}}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/en/tym/zdenek"
grep -q '<h1>Profile: Zdenek Zeman EN</h1>' "$WORK/response" && grep -q 'href="/en/team">Our team</a>' "$WORK/response" && grep -q 'hreflang="cs" href="[^"]*/tym/zdenek"' "$WORK/response" \
  && curl -s "$B/tym/zdenek" | contains 'Profil: Zdenek Zeman<' && curl -s "$B/tym/zdenek" | contains 'hreflang="en" href="[^"]*/en/tym/zdenek"' \
  && echo "  ok     šablona detailu v jazyce, drobečky přes překlad rozcestníku, hreflang mezi překlady položky" || { echo "  CHYBA  kolekce ve více jazycích"; grep -o '<nav class="ka-drobecky.\{0,300\}' "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -q 'class="logo"[^>]*><img src="/image/kaleta-logo.svg"' "$WORK/response" && ! grep -q 'src="/en/image/' "$WORK/response" && echo "  ok     logo a obrázky šablony na jazykové verzi bez předpony jazyka" || { echo "  CHYBA  adresa loga s předponou jazyka"; ERRORS=$((ERRORS+1)); }
expect "verze šablony jazyka zvlášť" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'kolekce:$IDK:en'")" 1
mcp seznam_stranek '{}' | grep -q 'en\\/team' && echo "  ok     MCP: seznam stránek ukazuje adresu s předponou jazyka" || { echo "  CHYBA  MCP adresa stránky jazykové verze"; ERRORS=$((ERRORS+1)); }
check "šablona detailu jazyka v builderu" 200 "/admin.php?module=collections&action=builder&id=$IDK&jazyk=en" 'en\/tym\/zdenek'
# translation via MCP: the page as a copy of the original's build, texts by id, the language's header and footer start as a copy of the default one
mcp vytvor_stranku '{"titulek":"Bez originalu","adresa":"bez-originalu","kopie_stavby":true}' | grep -q 'potřebuje preklad_z' \
  && expect "kopie stavby bez originálu stránku nezaloží" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'bez-originalu'")" 0 || { echo "  CHYBA  kopie stavby bez preklad_z"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku "{\"titulek\":\"From HTML\",\"adresa\":\"from-html\",\"jazyk\":\"en\",\"preklad_z\":$IDZ,\"kopie_stavby\":true}" > /dev/null
IDZEN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'from-html'")
expect "překlad stránky začíná kopií stavby originálu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT n.stavba_koncept = COALESCE(o.stavba_koncept, o.stavba) FROM ka_stranky n JOIN ka_stranky o ON o.ids = n.preklad_z WHERE n.ids = $IDZEN")" 1
mcp get_build "{\"id\":$IDZEN,\"texts_only\":true}" > "$WORK/response"
grep -q 'texts' "$WORK/response" && grep -q '{{nazev}}' "$WORK/response" && ! grep -q '\\"build\\"' "$WORK/response" && ! grep -q 'kolekce\\":\\"tym' "$WORK/response" \
  && echo "  ok     MCP: jen texty stavby pro překlad (bez struktury a technických polí)" || { echo "  CHYBA  MCP texty stavby"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Klic navic","data":{"funkce":"x","nazev":"Jinak"}}' | grep -q 'nezname_klice.*nazev' \
  && echo "  ok     MCP: klíč, který kolekce nemá, je ve výsledku" || { echo "  CHYBA  MCP neznámý klíč položky"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku '{"titulek":"Skryta textem","adresa":"skryta-textem","zobrazit":"false"}' > /dev/null
expect "MCP: zobrazit poslané jako text „false“ nechá stránku skrytou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'skryta-textem'")" 0
mcp stavba_nacti '{"cast":"paticka","jazyk":"en"}' > /dev/null
expect "MCP: čtení části, která ještě není, nic nezaloží" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_casti WHERE typ = 'paticka' AND jazyk = 'en'")" 0
mcp stavba_uprav '{"cast":"paticka","jazyk":"en","operace":[]}' > /dev/null
expect "patička nového jazyka začíná kopií patičky výchozího jazyka" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT e.stavba_koncept = COALESCE(c.stavba_koncept, c.stavba) FROM ka_casti e JOIN ka_casti c ON c.typ = e.typ AND c.jazyk = '' AND c.varianta = '' WHERE e.typ = 'paticka' AND e.jazyk = 'en' AND e.varianta = ''")" 1
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('tasks_token', 'testtoken123'); INSERT INTO ka_souhlasy (id_souhlasu, cas, kategorie) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', NOW() - INTERVAL 40 MONTH, 'nic'), ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', NOW(), 'nic')"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "úklid maže staré záznamy o souhlasech s cookies" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(LEFT(id_souhlasu, 1) ORDER BY id_souhlasu) FROM ka_souhlasy WHERE id_souhlasu IN ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')")" "b"
echo 'ALTER TABLE ka_neexistuje ADD COLUMN x INT;' > "$WORK/web/system/sql/migrace/0099-rozbita.sql"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '19' WHERE promenna = 'db_version'"; rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/web/storage/cache/migrace-chyba"
check "nepovedená migrace neshodí web" 200 /
grep -q 'Migrace databáze se nepovedla' "$WORK/web/storage/log/chyby.log" && echo "  ok     nepovedená migrace je v protokolu chyb" || { echo "  CHYBA  nepovedená migrace chybí v protokolu"; ERRORS=$((ERRORS+1)); }
check "nepovedená migrace nezamkne administraci" 200 "/admin.php" "Aktualizace databáze se nepovedla"
rm -f "$WORK/web/system/sql/migrace/0099-rozbita.sql"; sed -i.bak "/Migrace databáze se nepovedla/d" "$WORK/web/storage/log/chyby.log"; rm -f "$WORK/web/storage/log/chyby.log.bak"
expect "migrace, které prošly, zůstanou provedené" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" "$LAST_MIGRATION"
mcp uprav_kolekci '{"kolekce":"tym","nazev":"Nas tym"}' > "$WORK/response"
grep -q 'Nas tym' "$WORK/response" && grep -q 'medailonek' "$WORK/response" && echo "  ok     MCP: úprava kolekce ponechá pole" || { echo "  CHYBA  MCP uprav_kolekci"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "MCP: nová položka je ve výpisu" 200 /z-html "Mistr truhlář"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"vyp1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\",\"pocet\":1,\"razeni\":\"nazev\",\"filtr_pole\":\"funkce\",\"filtry\":true,\"strankovani\":true},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}}]}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
[ "$(grep -o '<h3>[^<]*</h3>' "$WORK/response" | tr -d '\n')" = "<h3>Jana Nováková</h3>" ] && grep -q 'href="/z-html?s-vyp1=2"' "$WORK/response" && grep -q 'href="/z-html" aria-current="true">Vše' "$WORK/response" && grep -q 'f-vyp1=Mistr' "$WORK/response" \
  && echo "  ok     výpis kolekce: řazení, stránkování a tlačítka filtru" || { echo "  CHYBA  stránkování výpisu kolekce"; ERRORS=$((ERRORS+1)); }
check "výpis kolekce: druhá strana" 200 "/z-html?s-vyp1=2" "<h3>Petr Svoboda</h3>"
curl -s -o "$WORK/response" "$B/z-html?f-vyp1=Mistr+truhl%C3%A1%C5%99"; grep -q '<h3>Petr Svoboda</h3>' "$WORK/response" && ! grep -q '<h3>Jana' "$WORK/response" && grep -q 'aria-current="true">Mistr truhlář' "$WORK/response" \
  && echo "  ok     výpis kolekce: filtr návštěvníka" || { echo "  CHYBA  filtr výpisu kolekce"; ERRORS=$((ERRORS+1)); }

echo "== komponenty"
check "komponenty" 200 "/admin.php?module=components" "Komponenty"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=components&action=save" -d "_csrf=$TOKEN" -d idm=0 --data-urlencode "nazev=Karta služby" \
  --data-urlencode "vlastnosti[0][popisek]=Nadpis" -d "vlastnosti[0][typ]=text" --data-urlencode "vlastnosti[0][vychozi]=Výchozí nadpis" --data-urlencode "vlastnosti[1][popisek]=Odkaz" -d "vlastnosti[1][typ]=odkaz" -d "vlastnosti[1][vychozi]=/kontakt"
IDM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty ORDER BY idm DESC LIMIT 1")
component_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=$1&id=$IDM" -d "_csrf=$TOKEN" "${@:2}"; }
check "komponenta v builderu" 200 "/admin.php?module=components&action=builder&id=$IDM" 'id="stavitel-data"'
component_action stavba_uloz --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kna1","typ":"nadpis","znacka":"h3","obsah":{"text":"{{nadpis}}"},"styl":{"zaklad":{"barva":"primarni"}}},{"typ":"tlacitko","obsah":{"text":"Více","odkaz":"{{odkaz}}"}},{"typ":"komponenta","obsah":{"komponenta":"'"$IDM"'"}}]}]}' > /dev/null
expect "publikování komponenty" "$(component_action stavba_publikuj)" 200
check "náhled komponenty pro editor" 200 "/_komponenta/$IDM?stavba=koncept&editor=1" "Výchozí nadpis"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\",\"hodnoty\":{\"nadpis\":\"První <b>karta</b>\",\"odkaz\":\"javascript:alert(1)\"}}},{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\"}}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" -w '' "$B/z-html"
grep -q '<h3 class="s-kna1">První karta</h3>' "$WORK/response" && grep -q '<h3 class="s-kna1">Výchozí nadpis</h3>' "$WORK/response" && [ "$(grep -o 'href="/kontakt"' "$WORK/response" | wc -l | tr -d ' ')" -ge 1 ] && ! grep -q 'javascript:' "$WORK/response" \
  && echo "  ok     komponenta na stránce: vlastní i výchozí hodnoty, bez značek, nebezpečný odkaz pryč" || { echo "  CHYBA  komponenta na stránce"; ERRORS=$((ERRORS+1)); }
! grep -q 'id="s-kna1"' "$WORK/response" && ! grep -q 'data-ka-id' "$WORK/response" && [ "$(grep -o '\.s-kna1 {' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] \
  && echo "  ok     komponenta dvakrát na stránce: styl jednou, bez duplicitního id" || { echo "  CHYBA  styl komponenty"; ERRORS=$((ERRORS+1)); }
check "komponenty ukazují počet použití" 200 "/admin.php?module=components" "1×"
grep -q 'data-potvrdit="Komponentu „Karta služby“ používá: stránka „' "$WORK/response" && echo "  ok     potvrzení smazání komponenty vyjmenuje, kde je použitá" || { echo "  CHYBA  potvrzení smazání komponenty"; ERRORS=$((ERRORS+1)); }
# a form inside a component: the submit must find it (it used to be searched only in the page build)
component_action stavba_uloz --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kfo1","typ":"formular","obsah":{"nazev":"Poptávka z komponenty"}}]}]}' > /dev/null; component_action stavba_publikuj > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/formular.html" "$B/z-html"
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$(field_value zdroj)" -d prvek=kfo1 -d zpet=/z-html -d "as_cas=$(field_value as_cas)" -d "as_podpis=$(field_value as_podpis)")
case "$location" in *"formular=kfo1"*) echo "  ok     formulář v komponentě se odešle";; *) echo "  CHYBA  formulář v komponentě: $location"; ERRORS=$((ERRORS+1));; esac
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=from_element" -d "_csrf=$TOKEN" --data-urlencode "nazev=Výzva" --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Zavolejte nám"}}]}')
[ "$code" = 200 ] && grep -q '"ok":true' "$WORK/response" && echo "  ok     uložení prvku jako komponenty" || { echo "  CHYBA  z_prvku: $code"; ERRORS=$((ERRORS+1)); }

check "náhled hotové sekce pro panel builderu" 200 /_sekce/cenik "Vyberte si balíček"
expect "náhled sekce jen pro přihlášené" "$(curl -s -o /dev/null -w '%{http_code}' "$B/_sekce/cenik")" 404

echo "== varianty záhlaví"
check "formulář varianty" 200 "/admin.php?module=parts&action=variant&typ=hlavicka&jazyk=" 'Název varianty'
TOKEN=$(csrf)
location=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=parts&action=save_variant&typ=hlavicka&jazyk=" -d "_csrf=$TOKEN" --data-urlencode "nazev=Landing page" -d "stranky[]=$IDZ")
case "$location" in *"varianta=landing-page"*) echo "  ok     varianta založena a otevřena v builderu";; *) echo "  CHYBA  založení varianty: $location"; ERRORS=$((ERRORS+1));; esac
variant_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=$1&typ=hlavicka&jazyk=&varianta=landing-page" -d "_csrf=$TOKEN" "${@:2}"; }
variant_action stavba_uloz --data-urlencode 'stavba={"v":1,"deti":[]}' > /dev/null
expect "publikování varianty" "$(variant_action stavba_publikuj)" 200
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"; ! grep -q 'header class="hlavicka"' "$WORK/response" && ! grep -q 'ka-nav' "$WORK/response" && echo "  ok     stránka s prázdnou variantou je bez záhlaví" || { echo "  CHYBA  varianta záhlaví na stránce"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kontakt"; grep -q 'header class="hlavicka"' "$WORK/response" && echo "  ok     ostatní stránky mají výchozí záhlaví" || { echo "  CHYBA  varianta se projevila i jinde"; ERRORS=$((ERRORS+1)); }
check "varianta v seznamu částí" 200 "/admin.php?module=parts" "Landing page"

echo "== Claude (MCP): varianty, verze, stránky a poptávky jako v administraci"
# a value from an MCP response: mcpv key [key…] (arrays and objects as JSON)
mcp_value() { php -r '$v = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); foreach (array_slice($argv, 2) as $k) { $v = $v[$k] ?? null; } echo is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' "$WORK/response" "$@"; }
mcp seznam_casti '{}' > "$WORK/response"; [[ "$(mcp_value)" == *'"varianta":"landing-page"'* ]] && echo "  ok     MCP: seznam částí webu s variantami" || { echo "  CHYBA  MCP seznam_casti"; ERRORS=$((ERRORS+1)); }
mcp uloz_variantu "{\"cast\":\"paticka\",\"nazev\":\"Kampaň\",\"stranky\":[$IDZ]}" > "$WORK/response"; VARIANT=$(mcp_value varianta)
expect "MCP: varianta patičky založena" "$VARIANT|$(mcp_value stranky)" "kampan|[$IDZ]"
mcp stavba_uloz "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\",\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"znacka\":\"footer\",\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"p\",\"obsah\":{\"text\":\"Paticka kampane\"}}]}]}}" > "$WORK/response"
VARIANT_PREVIEW=$(mcp_value nahled); curl -s -o "$WORK/response" "$VARIANT_PREVIEW"
[[ "$VARIANT_PREVIEW" == *"varianta=$VARIANT"* ]] && grep -q 'Paticka kampane' "$WORK/response" && echo "  ok     MCP: podepsaný náhled konceptu varianty" || { echo "  CHYBA  náhled varianty: $VARIANT_PREVIEW"; ERRORS=$((ERRORS+1)); }
mcp publikuj_stavbu "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\"}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"; grep -q 'Paticka kampane' "$WORK/response" && ! curl -s "$B/kontakt" | contains 'Paticka kampane' && echo "  ok     MCP: publikovaná varianta patičky jen na vybrané stránce" || { echo "  CHYBA  varianta patičky z MCP"; ERRORS=$((ERRORS+1)); }
mcp uloz_variantu "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\",\"smazat\":true}" > /dev/null
expect "MCP: varianta smazána" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_casti WHERE varianta = '$VARIANT'")" 0
mcp stavba_z_html '{"titulek":"Verze test","html":"<section><h1>Verze A</h1></section>","publikovat":true}' > /dev/null
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'verze-test'")
mcp stavba_z_html "{\"id\":$IDV,\"html\":\"<section><h1>Verze B</h1></section>\",\"publikovat\":true}" > /dev/null
mcp stavba_verze "{\"id\":$IDV}" > "$WORK/response"; IDR=$(mcp_value verze 0 idr)
mcp obnov_verzi "{\"id\":$IDV,\"idr\":$IDR}" > /dev/null
expect "MCP: starší verze v konceptu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(stavba_koncept LIKE '%Verze A%', stavba LIKE '%Verze B%') FROM ka_stranky WHERE ids = $IDV")" 11
mcp zahod_koncept "{\"id\":$IDV}" > /dev/null
expect "MCP: koncept zahozen" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept IS NULL FROM ka_stranky WHERE ids = $IDV")" 1
mcp vytvor_stranku "{\"titulek\":\"Podstranka MCP\",\"nadrazena\":$IDS,\"zverejnit_od\":\"2099-01-01 10:00\"}" > "$WORK/response"
expect "MCP: podstránka s plánovaným zveřejněním" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(seo_link, '|', zverejnit_od, '|', zobrazit) FROM ka_stranky WHERE titulek = 'Podstranka MCP'")" "o-nas/podstranka-mcp|2099-01-01 10:00:00|0"
mcp seznam_poptavek '{"stav":"vse"}' > "$WORK/response"
expect "MCP: poptávky s kampaní" "$(mcp_value 0 email)|$(mcp_value 0 kampan)" "jana@example.cz|newsletter / email / jaro"

echo "== Claude (MCP): trash, deleting and the rest of the admin (1.6)"
sq() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
php -r '$t = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["tools"], "annotations", "name"); exit($t["list_pages"]["readOnlyHint"] === true && $t["trash_page"]["destructiveHint"] === true && $t["create_page"]["readOnlyHint"] === false && $t["delete_collection"]["destructiveHint"] === true ? 0 : 1);' "$WORK/response" \
  && echo "  ok     MCP: tools carry annotations (read-only, destructive)" || { echo "  CHYBA  MCP annotations"; ERRORS=$((ERRORS+1)); }
mcp site_info '{}' > "$WORK/response"
expect "MCP: site_info lists extensions and languages" "$(mcp_value extensions | grep -c novinky)|$(mcp_value languages default)" "1|cs"
mcp builder_schema '{}' > "$WORK/response"
expect "MCP: builder_schema in the English vocabulary" "$(mcp_value elements heading | grep -c 'content: text')|$(mcp_value style gap | grep -c gap)|$(mcp_value states 2)" "1|1|mobile"
mcp builder_schema '{"elements":["form"]}' > "$WORK/response"
expect "MCP: full definition of an element by its English type" "$(mcp_value elements 0 type)|$(mcp_value elements 0 fields fields item_fields type options 5)" "form|radio"
VPAGE=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL ORDER BY ids LIMIT 1")
mcp save_build "{\"id\":$VPAGE,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Go\",\"variant\":\"outline\",\"icon\":\"arrow\"},\"style\":{\"mobile\":{\"gap\":\"s\",\"background\":\"primary-soft\"}}}]}]}}" > /dev/null
expect "MCP: an English build is stored in the Czech keys" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].typ')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.varianta')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.mobil.pozadi')) FROM ka_stranky WHERE ids = $VPAGE" | tr '\t' '|')" "tlacitko|obrys|primarni-jemna"
mcp get_build "{\"id\":$VPAGE}" > "$WORK/response"
expect "MCP: get_build answers in English" "$(mcp_value build children 0 children 0 type)|$(mcp_value build children 0 children 0 content variant)|$(mcp_value build children 0 children 0 style mobile background)" "button|outline|primary-soft"
BUTTON=$(mcp_value build children 0 children 0 id)
mcp edit_build "{\"id\":$VPAGE,\"operations\":[{\"op\":\"update\",\"id\":\"$BUTTON\",\"content\":{\"new_window\":true},\"style\":{\"base\":{\"radius\":\"full\"}}}]}" > /dev/null
expect "MCP: edit_build takes English content and style" "$(sq "SELECT CONCAT(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.nove_okno'), '|', JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.zaklad.zaobleni'))) FROM ka_stranky WHERE ids = $VPAGE")" "true|plne"
mcp discard_draft "{\"id\":$VPAGE}" > /dev/null
mcp create_collection '{"name":"Kos test","fields":[{"label":"Popis","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"kos-test","name":"Polozka","visible":true}' > "$WORK/response"; ITEM=$(mcp_value id)
mcp delete_collection_item "{\"collection\":\"kos-test\",\"id\":$ITEM}" > /dev/null
expect "MCP: a collection item goes to the trash, hidden" "$(sq "SELECT CONCAT(smazano IS NOT NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "10"
check "collection trash in the admin" 200 "/admin.php?module=collections&action=items&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'kos-test'")&stav=kos" "Polozka"
mcp list_trash '{}' > "$WORK/response"
expect "MCP: list_trash shows the item" "$(mcp_value collection_items 0 name)" "Polozka"
mcp restore_from_trash "{\"type\":\"collection_item\",\"id\":$ITEM}" > /dev/null
expect "MCP: restored from the trash as hidden" "$(sq "SELECT CONCAT(smazano IS NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "10"
mcp delete_collection '{"collection":"kos-test"}' > /dev/null
expect "MCP: delete_collection removes it with its items" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'kos-test'")|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "0|0"
CATEGORY=$(sq "SELECT nazev FROM ka_kategorie WHERE jazyk = '' ORDER BY idt LIMIT 1")
mcp create_news "{\"title\":\"Do kose\",\"category\":\"$CATEGORY\"}" > "$WORK/response"; NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Do kose'")
mcp trash_news "{\"id\":$NEWS}" > /dev/null
expect "MCP: trash_news" "$(sq "SELECT smazano IS NOT NULL FROM ka_novinky WHERE idc = $NEWS")" "1"
mcp restore_from_trash "{\"type\":\"news\",\"id\":$NEWS}" > /dev/null
expect "MCP: a news item back from the trash as a draft" "$(sq "SELECT CONCAT(smazano IS NULL, visible) FROM ka_novinky WHERE idc = $NEWS")" "10"
mcp create_category '{"name":"Docasna"}' > /dev/null; CAT=$(sq "SELECT idt FROM ka_kategorie WHERE nazev = 'Docasna'")
mcp update_category "{\"id\":$CAT,\"name\":\"Docasna 2\",\"slug\":\"docasna-2\"}" > /dev/null
expect "MCP: update_category renames and redirects the old address" "$(sq "SELECT CONCAT(nazev, '|', seo_link) FROM ka_kategorie WHERE idt = $CAT")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '%kategorie/docasna'")" "Docasna 2|docasna-2|1"
mcp delete_category "{\"id\":$(sq "SELECT tema FROM ka_novinky WHERE idc = $NEWS")}" | contains 'still has news items' && echo "  ok     MCP: a category with news items is not deleted" || { echo "  CHYBA  MCP: delete_category of a used category"; ERRORS=$((ERRORS+1)); }
mcp delete_category "{\"id\":$CAT}" > /dev/null
expect "MCP: delete_category" "$(sq "SELECT COUNT(*) FROM ka_kategorie WHERE idt = $CAT")" "0"
mcp save_component '{"name":"Karta","properties":[{"klic":"titulek","popisek":"Titulek","typ":"text","vychozi":"Ahoj"}]}' > "$WORK/response"; COMP=$(mcp_value id)
mcp save_build "{\"component\":$COMP,\"build\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"{{titulek}}\"}}]}]}}" > /dev/null
mcp publish_build "{\"component\":$COMP}" > /dev/null
expect "MCP: a component built and published through the component target" "$(sq "SELECT stavba LIKE '%{{titulek}}%' AND stavba_koncept IS NULL FROM ka_komponenty WHERE idm = $COMP")" "1"
mcp list_components '{}' > "$WORK/response"
expect "MCP: list_components" "$(mcp_value 0 name)|$(mcp_value 0 published)" "Karta|1"
mcp delete_component "{\"id\":$COMP}" > /dev/null
expect "MCP: delete_component" "$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE idm = $COMP")" "0"
PAGE=$(sq "SELECT ids FROM ka_stranky WHERE stavba IS NOT NULL AND smazano IS NULL ORDER BY ids LIMIT 1")
ELEMENT=$(sq "SELECT COALESCE(stavba_koncept, stavba) FROM ka_stranky WHERE ids = $PAGE" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["deti"][0]["id"];')
mcp save_section "{\"id\":$PAGE,\"element\":\"$ELEMENT\",\"name\":\"Moje sekce z MCP\"}" > "$WORK/response"; SECTION=$(mcp_value id)
mcp builder_schema '{}' | contains 'Moje sekce z MCP' && echo "  ok     MCP: saved sections in builder_schema" || { echo "  CHYBA  MCP: saved sections missing in builder_schema"; ERRORS=$((ERRORS+1)); }
BEFORE=$(sq "SELECT JSON_LENGTH(COALESCE(stavba_koncept, stavba), '$.deti') FROM ka_stranky WHERE ids = $PAGE")
mcp insert_section "{\"id\":$PAGE,\"saved_section\":$SECTION}" > /dev/null
expect "MCP: insert_section with a saved section adds it with new ids" "$(sq "SELECT JSON_LENGTH(stavba_koncept, '$.deti') FROM ka_stranky WHERE ids = $PAGE")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, CONCAT('$.deti[', JSON_LENGTH(stavba_koncept, '$.deti') - 1, '].id'))) <> '$ELEMENT' FROM ka_stranky WHERE ids = $PAGE")" "$((BEFORE + 1))|1"
mcp discard_draft "{\"id\":$PAGE}" > /dev/null; mcp delete_section "{\"id\":$SECTION}" > /dev/null
expect "MCP: delete_section" "$(sq "SELECT COUNT(*) FROM ka_sekce WHERE idx = $SECTION")" "0"
mcp save_popup '{"template":"announcement_bar","name":"Na smazani"}' > "$WORK/response"; POPUP=$(sq "SELECT idpp FROM ka_popupy WHERE nazev = 'Na smazani'")
mcp delete_popup "{\"id\":$POPUP}" > /dev/null
expect "MCP: delete_popup" "$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $POPUP")" "0"
PNG=$(php -r 'ob_start(); imagepng(imagecreatetruecolor(8, 8)); echo base64_encode(ob_get_clean());')
mcp upload_file "{\"filename\":\"mcp-smazat.png\",\"data\":\"$PNG\"}" > /dev/null; MEDIA=$(sq "SELECT ido FROM ka_media ORDER BY ido DESC LIMIT 1")
mcp update_media "{\"id\":$MEDIA,\"alt\":\"Cerny ctverec\",\"caption\":\"Popisek\"}" > /dev/null
expect "MCP: update_media" "$(sq "SELECT CONCAT(nazev, '|', popis) FROM ka_media WHERE ido = $MEDIA")" "Cerny ctverec|Popisek"
FILE=$(sq "SELECT obr_poloha FROM ka_media WHERE ido = $MEDIA")
mcp delete_media "{\"id\":$MEDIA}" > /dev/null
expect "MCP: delete_media removes the record and the file" "$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $MEDIA")|$([ -e "$WORK/web/$FILE" ] && echo file || echo gone)" "0|gone"
USED=$(sq "SELECT ido FROM ka_media m WHERE EXISTS (SELECT 1 FROM ka_stranky s WHERE CONCAT_WS(' ', s.stavba, s.stavba_koncept, s.text) LIKE CONCAT('%', REPLACE(m.obr_poloha, '/', '%'), '%')) LIMIT 1")
[ -z "$USED" ] || { mcp delete_media "{\"id\":$USED}" | contains 'still used on the site' && echo "  ok     MCP: a file in use is not deleted" || { echo "  CHYBA  MCP: delete_media deleted a file in use"; ERRORS=$((ERRORS+1)); }; }
READS=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'"); mcp list_enquiries '{}' > /dev/null
expect "MCP: every enquiry read is in the change log" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'")" "$((READS + 1))"
sq "INSERT INTO ka_poptavky (datum, email, data) VALUES (NOW(), 'mcp@example.cz', '[]')"; ENQUIRY=$(sq "SELECT MAX(idp) FROM ka_poptavky")
mcp update_enquiry "{\"id\":$ENQUIRY,\"status\":\"resolved\",\"note\":\"Vyrizeno pres Clauda\"}" > /dev/null
expect "MCP: update_enquiry" "$(sq "SELECT CONCAT(stav, '|', poznamka) FROM ka_poptavky WHERE idp = $ENQUIRY")" "2|Vyrizeno pres Clauda"
mcp delete_enquiry "{\"id\":$ENQUIRY}" > /dev/null
expect "MCP: delete_enquiry" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $ENQUIRY")" "0"

echo "== pop-up okna"
check "pop-up okna v administraci" 200 "/admin.php?module=popups" "Zatím žádná pop-up okna"
check "nové okno ze vzoru" 200 "/admin.php?module=popups&action=new" 'name="vzor" value="newsletter"'
mcp uloz_popup '{"vzor":"prazdny","nazev":"Akce okno"}' > "$WORK/response"; IDPP=$(mcp_value id)
expect "MCP: okno založené vypnuté a nepublikované" "$(mcp_value adresa)|$(mcp_value aktivni)|$(mcp_value publikovano)|$(mcp_value spoustec)" "akce-okno|||klik"
mcp uloz_popup "{\"id\":$IDPP,\"aktivni\":true}" | grep -q 'nejdřív publikuj' && echo "  ok     MCP: nepublikované okno nejde zapnout" || { echo "  CHYBA  zapnutí nepublikovaného okna"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz "{\"popup\":$IDPP,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h2\",\"obsah\":{\"text\":\"Okno akce\"}},{\"id\":\"ab12cd3\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Z okna\",\"pole\":[{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true}]}}]}}" > "$WORK/response"
mcp save_popup "{\"id\":$IDPP,\"type\":\"slide_in\",\"trigger\":\"time\",\"value\":3,\"frequency\":\"until_closed\",\"rules\":{\"device\":\"phone\"},\"active\":true}" > "$WORK/response"
expect "MCP anglicky: typ, spouštěč, četnost a pravidla" "$(mcp_value type)|$(mcp_value trigger)|$(mcp_value frequency)|$(mcp_value rules device)|$(mcp_value active)" "slide_in|time|until_closed|phone|1"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q "data-popup=\"$IDPP\"" "$WORK/response" && grep -q 'class="ka-popup ka-popup--panel" popover="manual" role="region"' "$WORK/response" && grep -q 'data-spoustec="cas" data-hodnota="3" data-cetnost="zavreni"' "$WORK/response" \
  && grep -q 'data-zarizeni="telefon"' "$WORK/response" && grep -q 'Okno akce' "$WORK/response" && grep -q 'image/web\.js' "$WORK/response" \
  && echo "  ok     zapnuté okno na webu se spouštěčem, pravidly prohlížeče a skriptem" || { echo "  CHYBA  pop-up okno na webu"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"kde\":\"vybrane\",\"stranky\":[$IDS]}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
! curl -s "$B/" | contains "data-popup=\"$IDPP\"" && curl -s "$B/o-nas" | contains "data-popup=\"$IDPP\"" && echo "  ok     okno jen na vybrané stránce" || { echo "  CHYBA  pravidlo vybraných stránek"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"od\":\"2099-01-01\"}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
! curl -s "$B/o-nas" | contains "data-popup=\"$IDPP\"" && echo "  ok     okno mimo období se do stránky nevloží" || { echo "  CHYBA  období okna"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"od\":\"\",\"kde\":\"vse\"}}" > "$WORK/response"; POPUP_PREVIEW=$(mcp_value nahled); rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=zobrazeni; curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=konverze; curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=nic
expect "počitadla okna bez cookies" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazeni, '/', zavreni, '/', konverze) FROM ka_popupy WHERE idpp = $IDPP")" "1/0/1"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/_popup/$IDPP?stavba=koncept"); expect "koncept okna bez přihlášení není" "$code" 404
curl -s -o "$WORK/response" "$POPUP_PREVIEW"; grep -q 'data-otevrit="1"' "$WORK/response" && grep -q 'noindex' "$WORK/response" && echo "  ok     podepsaný náhled okno rovnou otevře" || { echo "  CHYBA  náhled okna: $POPUP_PREVIEW"; ERRORS=$((ERRORS+1)); }
check "okno v builderu" 200 "/admin.php?module=popups&action=builder&id=$IDPP" 'id="stavitel-data"'
check "plátno okna v builderu" 200 "/_popup/$IDPP?stavba=koncept&editor=1" 'ka-popup--editor'
curl -s -b "$JAR" "$B/o-nas" | grep -q "data-popup=\"$IDPP\"" && ! curl -s -b "$JAR" "$B/o-nas?stavba=koncept&editor=1" | grep -q "data-popup=" \
  && echo "  ok     plátno builderu stránky je bez pop-up oken webu" || { echo "  CHYBA  pop-up okno v plátně builderu"; ERRORS=$((ERRORS+1)); }
curl -s "$B/" | sed -n '/data-popup=/,$p' > "$WORK/formular.html" # jen okno – stránka může mít vlastní formulář
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
expect "formulář v okně má zdroj okna" "$FORM_SOURCE|$FORM_ELEMENT" "popup:$IDPP|ab12cd3"
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/ -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p0=okno@example.cz)
case "$location" in *"vysledek=ok"*) echo "  ok     formulář v okně odeslán";; *) echo "  CHYBA  formulář v okně: $location"; ERRORS=$((ERRORS+1));; esac
expect "poptávka z okna uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(formular, '|', zdroj) FROM ka_poptavky WHERE email = 'okno@example.cz'")" "Z okna|popup:$IDPP"
mcp seznam_popupu '{}' > "$WORK/response"; expect "MCP: seznam oken s počitadly" "$(mcp_value 0 nazev)|$(mcp_value 0 zobrazeni)|$(mcp_value 0 konverze)" "Akce okno|1|1"
mcp uloz_popup "{\"id\":$IDPP,\"aktivni\":false}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html # další testy počítají se stránkami bez okna

# editing right on the site: the link and the form only for signed-in users with the permission
check "úprava na místě – odkaz" 200 /novinky/vitejte-v-kalete "ka-upravit-zde"
check "úprava na místě – formulář" 200 "/novinky/vitejte-v-kalete?upravit=text" "ka-upravit-text"
check "úprava stránky na místě" 200 "/o-nas?upravit=text" "ka-upravit-text"
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete?upravit=text"; grep -q "ka-upravit" "$WORK/response" && { echo "  CHYBA  úprava na místě je vidět bez přihlášení"; ERRORS=$((ERRORS+1)); } || echo "  ok     úprava na místě bez přihlášení není"

echo "== import z WordPressu a export"
check "import a export" 200 "/admin.php?module=transfer" "WordPress"
TOKEN=$(csrf)
wp_batch() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=transfer&action=progress&soubor=wordpress-sample.xml" -d "_csrf=$TOKEN"; }
wp_import() { # náhled (čtení souboru) → volby → import; ukázkový soubor se vejde do jedné dávky
  wp_batch
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=run" -d "_csrf=$TOKEN" -d soubor=wordpress-sample.xml -d koncepty=1 -d stranky=1 -d stavitel=1 -d presmerovani=1 -d rubrika=0
  wp_batch
}
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-sample.xml"
wp_batch
check "import z WordPressu – náhled upozorní na nepřevoditelný typ" 200 "/admin.php?module=transfer&action=preview&soubor=wordpress-sample.xml" "nav_menu_item"
wp_import
grep -q "Import obsahu je hotový" "$WORK/response" && echo "  ok     import z WordPressu doběhl" || { echo "  CHYBA  import z WordPressu nedoběhl"; ERRORS=$((ERRORS+1)); }
check "importovaná novinka" 200 /novinky/lavka-pres-bystrinu "Lávka přes Bystřinu"
check "importovaná novinka – galerie a video" 200 /novinky/lavka-pres-bystrinu 'class="galerie"'
check "importovaná stránka" 200 /o-zpravodaji "Kontakt"
check "importovaná stránka je rovnou v builderu" 200 /o-zpravodaji '<main id="obsah" class="stavba">'
check "importovaná stránka má nadpis z WordPressu" 200 /o-zpravodaji '<h1>O zpravodaji</h1>'
curl -s -o "$WORK/response" "$B/o-zpravodaji"; grep -q 'wp-block' "$WORK/response" && { echo "  CHYBA  třídy WordPressu ve stavbě"; ERRORS=$((ERRORS+1)); } || echo "  ok     třídy WordPressu bez stylu vynechány"
curl -s -o "$WORK/response" "$B/novinky/lavka-pres-bystrinu"; grep -qE "podvrh|onclick|kontaktni-formular|posta\.example" "$WORK/response" && { echo "  CHYBA  importovaná novinka obsahuje skript, zkratku doplňku nebo e-mail komentujícího"; ERRORS=$((ERRORS+1)); } || echo "  ok     importovaný obsah je vyčištěný"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2026/05/lavka-pres-bystrinu/"); expect "stará adresa WordPressu přesměruje na novinku" "$code" "301 $B/novinky/lavka-pres-bystrinu"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/?p=102"); expect "stará adresa /?p=102 přesměruje" "$code" 301
# a second import of the same file must not duplicate anything
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=select" -d "_csrf=$TOKEN" -d soubor=wordpress-sample.xml
wp_import
COUNTS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'lavka-pres-bystrinu%' OR seo_link LIKE 'slavnosti-syra%' OR seo_link LIKE 'rozpocet-obce%'), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'o-zpravodaji%'))")
expect "opakovaný import nic nezdvojil (novinky/stránky)" "$COUNTS" "4/1"
check "složka importu není přístupná z webu" 403 /storage/import/wordpress-sample.xml
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$TOKEN"
check "export webu je v seznamu" 200 "/admin.php?module=transfer" "action=download"
EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.[a-z]*' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/export" "$B/admin.php?module=transfer&action=download&soubor=$EXPORT"
if [ "${EXPORT##*.}" = zip ]; then unzip -p "$WORK/export" obsah.json > "$WORK/obsah.json" 2>/dev/null || true; else cp "$WORK/export" "$WORK/obsah.json"; fi
grep -q '"format":"kaleta-export"' "$WORK/obsah.json" && grep -q '"novinky"' "$WORK/obsah.json" && ! grep -qE '"password"|smtp_heslo|tajny_klic|ai_klic' "$WORK/obsah.json" && echo "  ok     export obsahuje data a žádná tajemství" || { echo "  CHYBA  export"; ERRORS=$((ERRORS+1)); }
grep -q '"kolekce_polozky":\[' "$WORK/obsah.json" && grep -q 'Jana Nováková' "$WORK/obsah.json" && grep -q '"tridy":\[' "$WORK/obsah.json" && grep -q '"casti":\[' "$WORK/obsah.json" && ! grep -q 'Chci kuchyň' "$WORK/obsah.json" \
  && grep -q '"adresa":"akce-okno"' "$WORK/obsah.json" && ! grep -q '"zobrazeni":' "$WORK/obsah.json" \
  && echo "  ok     export obsahuje builder, kolekce a pop-up okna, poptávky ani počitadla ne" || { echo "  CHYBA  export builderu a kolekcí"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/admin.php?module=transfer&action=download&soubor=$EXPORT"; grep -q "Heslo" "$WORK/response" && echo "  ok     export jen pro přihlášeného správce" || { echo "  CHYBA  export jde stáhnout bez přihlášení"; ERRORS=$((ERRORS+1)); }

echo "== koš novinek"
IDC=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky WHERE seo_link = 'vitejte-v-kalete'")
check "výpis novinek" 200 "/admin.php?module=news" "Smazat označené"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=delete" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
check "novinka v koši není na webu" 404 /novinky/vitejte-v-kalete
check "novinka v koši není ani v náhledu" 404 "/novinky/vitejte-v-kalete?nahled=1"
check "záložka Koš" 200 "/admin.php?module=news&stav=kos" "Vítejte"
check "novinka v koši nejde upravit" 404 "/admin.php?module=news&action=edit&id=$IDC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=restore" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
expect "obnovená novinka se vrátí jako koncept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(visible, '/', smazano IS NULL) FROM ka_novinky WHERE idc = $IDC")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_novinky SET visible = 1, smazano = NOW() - INTERVAL 31 DAY WHERE idc = $IDC"
check "vstup do administrace vysype starý koš" 200 /admin.php "Přehled"
expect "novinka starší 30 dní v koši je smazaná natrvalo" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE idc = $IDC")" "0"

echo "== přesměrování po změně adresy kategorie a stránky"
check "formulář kategorie" 200 "/admin.php?module=categories" "Kategorie"
TOKEN=$(csrf)
IDT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM ka_kategorie WHERE seo_link = 'aktuality'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$TOKEN" -d "idt=$IDT" -d nazev=Aktuality -d seo_link=aktuality-firmy -d hodnost=100
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/novinky/kategorie/aktuality"); expect "stará adresa kategorie přesměruje na novou" "$code" "301 $B/novinky/kategorie/aktuality-firmy"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakt'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/kontakt"); expect "stará adresa stránky přesměruje na novou" "$code" "301 $B/kontakty"

echo "== stránky: SEO, koš, duplikace"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakty'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>" \
  --data-urlencode "seo_titulek=Kontakt na truhlárnu" -d obrazek=media/2026/01/sdileni.jpg -d noindex=1
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/kontakty"
grep -q '<title>Kontakt na truhlárnu' "$WORK/response" && grep -q 'og:image" content="http[^"]*/media/2026/01/sdileni.jpg"' "$WORK/response" && grep -q 'noindex, follow' "$WORK/response" \
  && echo "  ok     stránka: vlastní titulek, úplná adresa obrázku pro sdílení, noindex" || { echo "  CHYBA  SEO stránky"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=duplicate" -d "_csrf=$TOKEN" -d "ids=$IDS"
expect "duplikát stránky je skrytý a má volnou adresu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', seo_link) FROM ka_stranky ORDER BY ids DESC LIMIT 1")" "0/kontakty-kopie"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$TOKEN" -d "ids=$IDS"
check "stránka v koši není na webu" 404 /kontakty
check "záložka Koš u stránek" 200 "/admin.php?module=pages&stav=kos" "Kontakt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=restore" -d "_csrf=$TOKEN" -d "ids=$IDS"
expect "obnovená stránka je skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', smazano IS NULL) FROM ka_stranky WHERE ids = $IDS")" "0/1"
IDU=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$IDU' WHERE promenna='home_page'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$TOKEN" -d "ids=$IDU"
expect "úvodní stránku nejde smazat" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NULL FROM ka_stranky WHERE ids = $IDU")" "1"
# a language version in progress (without a published translation of the home page) is not offered in the switcher, hreflang or the sitemap
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1, smazano = NULL WHERE ids = $IDU"; rm -f "$WORK"/web/storage/cache/stranky/*.html
# the pages are saved first: „curl | grep -q“ with pipefail fails when grep exits before curl finishes writing (SIGPIPE)
curl -s -o "$WORK/response" "$B/"; curl -s -o "$WORK/mapa.xml" "$B/sitemap.xml"
! grep -q 'hreflang="en"' "$WORK/response" && ! grep -q '/en/</loc>' "$WORK/mapa.xml" \
  && echo "  ok     jazyk bez zveřejněného překladu úvodu se návštěvníkům nenabízí" || { echo "  CHYBA  rozpracovaný jazyk v přepínači nebo mapě webu"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku "{\"titulek\":\"About home\",\"adresa\":\"about-home\",\"jazyk\":\"en\",\"preklad_z\":$IDU,\"text\":\"<p>Home</p>\",\"zobrazit\":1}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; curl -s -o "$WORK/mapa.xml" "$B/sitemap.xml"
grep -q 'hreflang="en"' "$WORK/response" && grep -q '/en/</loc>' "$WORK/mapa.xml" \
  && echo "  ok     se zveřejněným překladem úvodu se jazyk nabízí" || { echo "  CHYBA  hotový jazyk chybí v přepínači nebo mapě webu"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}},{"typ":"jazyky"}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-jazyky-vyber--nahoru ka-jazyky-prvek' "$WORK/response" && grep -q 'hreflang="en" lang="en"' "$WORK/response" && grep -q 'image/web.js' "$WORK/response" \
  && echo "  ok     prvek Přepínač jazyků v patičce (nabídka nahoru) a web.js pro jazyk prohlížeče" || { echo "  CHYBA  prvek Přepínač jazyků"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"hlavicka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"navigace","obsah":{"jazyky":false}},{"typ":"navigace","obsah":{"menu":"paticka"}}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
expect "Navigace s vypnutým přepínačem jazyků ho nemá, ostatní navigace ano" "$(grep -o '<nav class="ka-jazyky"' "$WORK/response" | wc -l | tr -d ' ')" 1
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_casti WHERE typ = 'hlavicka'"
mcp stavba_uloz '{"cast":"paticka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]}}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_stranky WHERE seo_link = 'about-home'"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='home_page'"

echo "== podstránky, plán, historie, šablony, export"
save_page() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" "$@"; }
save_page -d ids=0 --data-urlencode "titulek=Služby firmy" -d seo_link=sluzby-firmy -d zobrazit=1 -d v_menu=0 -d "text=<p>S</p>" > /dev/null
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby-firmy'")
save_page -d ids=0 --data-urlencode "titulek=Kuchyně" -d "nadrazena=$IDR" -d zobrazit=1 -d v_menu=0 -d "text=<p>Kuchyně na míru</p>" > /dev/null
expect "podstránka má adresu pod nadřazenou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "sluzby-firmy/kuchyne"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "podstránka na webu" 200 /sluzby-firmy/kuchyne "Kuchyně na míru"
save_page -d "ids=$IDR" --data-urlencode "titulek=Služby firmy" -d seo_link=nase-sluzby -d zobrazit=1 -d v_menu=0 -d "text=<p>S2</p>" > /dev/null
expect "změna adresy nadřazené posune podstránku" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "nase-sluzby/kuchyne"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/sluzby-firmy/kuchyne"); expect "stará adresa podstránky přesměruje" "$code" "301 $B/nase-sluzby/kuchyne"
expect "změna textu uloží předchozí verzi" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT text FROM ka_stranky_revize WHERE ids = $IDR ORDER BY idr DESC LIMIT 1")" "<p>S</p>"
save_page -d ids=0 --data-urlencode "titulek=Akce" -d v_menu=0 -d "text=<p>A</p>" -d "zverejnit_od=$(date -v+1d '+%Y-%m-%dT%H:%M' 2>/dev/null || date -d '+1 day' '+%Y-%m-%dT%H:%M')" > /dev/null
expect "naplánovaná stránka čeká skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE seo_link = 'akce'")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zverejnit_od = NOW() - INTERVAL 1 MINUTE WHERE seo_link = 'akce'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'notification_check'"
curl -s -o /dev/null "$B/novinky?x=$RANDOM"; sleep 1
expect "naplánovaná stránka se v čase sama zveřejní" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'akce'")" "1"
location=$(save_page -d ids=0 --data-urlencode "titulek=Nabídka" -d sablona=landing -d zobrazit=0 -d v_menu=0 -d text=)
case "$location" in *action=builder*) echo "  ok     nová stránka ze šablony jde rovnou do builderu";; *) echo "  CHYBA  šablona stránky: $location"; ERRORS=$((ERRORS+1));; esac
expect "šablona složí koncept ze sekcí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%\"typ\":\"sekce\"%' FROM ka_stranky WHERE seo_link = 'nabidka'")" "1"
IDN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nabidka'")
curl -s -b "$JAR" -o "$WORK/stranka.json" "$B/admin.php?module=pages&action=export&id=$IDN"
grep -q '"format": "kaleta-stranka"' "$WORK/stranka.json" && echo "  ok     export stránky do JSON" || { echo "  CHYBA  export stránky"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/stranka.json;type=application/json"
expect "import stránky vytvoří skrytou kopii se stavbou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba_koncept IS NOT NULL) FROM ka_stranky WHERE seo_link = 'nabidka-2'")" "0/1"

echo "== builder: vlastní CSS, atributy, animace, moje sekce, přejmenování třídy"
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nase-sluzby'")
version_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDV" -d "_csrf=$TOKEN" "${@:2}"; }
version_action stavba_uloz --data-urlencode 'stavba={"v":1,"deti":[{"id":"sv1","typ":"sekce","tridy":["karta"],"css":"backdrop-filter: blur(4px); background: url(x)","atributy":{"data-sledovat":"cta","onclick":"x"},"styl":{"zaklad":{"animace":"ka-vyjet","prechod":"linear-gradient(135deg, var(--ka-barva-primarni), var(--ka-barva-sekundarni))","okraj_vlevo":"auto"},"aktivni":{"pruhlednost":"0.8"}},"deti":[{"typ":"nadpis","obsah":{"text":"Test"}}]}]}' > /dev/null
grep -q 'Nepovolená deklarace' "$WORK/response" && grep -q 'Atribut může být jen' "$WORK/response" && echo "  ok     vlastní CSS a atributy prvku se čistí" || { echo "  CHYBA  čištění CSS a atributů"; ERRORS=$((ERRORS+1)); }
version_action stavba_publikuj > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/nase-sluzby"
grep -q 'data-sledovat="cta"' "$WORK/response" && ! grep -q 'onclick="x"' "$WORK/response" && grep -q 'backdrop-filter: blur(4px)' "$WORK/response" && grep -q 'animation-timeline: view()' "$WORK/response" \
  && grep -q '@keyframes ka-vyjet' "$WORK/response" && grep -q ':active {' "$WORK/response" && grep -q 'margin-inline-start: auto' "$WORK/response" \
  && echo "  ok     vlastní CSS, atributy, animace, stisknutí a okraj na webu" || { echo "  CHYBA  nové vlastnosti stylu na webu"; ERRORS=$((ERRORS+1)); }
expect "uložení do mých sekcí" "$(version_action stavba_uloz_sekci --data-urlencode 'nazev=Moje karta' --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Z knihovny"}}]}')" 200
grep -q '"nazev":"Moje karta"' "$WORK/response" && echo "  ok     moje sekce v seznamu" || { echo "  CHYBA  moje sekce"; ERRORS=$((ERRORS+1)); }
version_action stavba_trida -d nazev=karta -d pouziti=1 > /dev/null; grep -q 'Služby firmy' "$WORK/response" && echo "  ok     přehled použití třídy" || { echo "  CHYBA  použití třídy"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "přejmenování třídy" "$(version_action stavba_trida -d nazev=karta -d novy_nazev=karta-sluzby)" 200
expect "přejmenovaná třída ve stavbách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba LIKE '%\"karta-sluzby\"%' AND stavba NOT LIKE '%\"karta\"%' FROM ka_stranky WHERE ids = $IDV")" "1"

echo "== média, přesměrování, poptávky, uživatelé, písma"
php -r '$i = imagecreatetruecolor(1600, 900); imagefill($i, 0, 0, imagecolorallocate($i, 200, 80, 40)); imagejpeg($i, "'"$WORK"'/foto.jpg");'
printf '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10" onload="alert(1)"><script>alert(2)</script><rect width="20" height="10" fill="red"/></svg>' > "$WORK/logo.svg"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=upload" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/foto.jpg;type=image/jpeg" -F "soubory[]=@$WORK/logo.svg;type=image/svg+xml"
SVG=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE obr_poloha LIKE '%.svg' ORDER BY ido DESC LIMIT 1")
[ -n "$SVG" ] && ! grep -q 'onload\|<script' "$WORK/web/$SVG" && grep -q '<rect' "$WORK/web/$SVG" && echo "  ok     SVG nahrané a vyčištěné" || { echo "  CHYBA  SVG v Médiích"; ERRORS=$((ERRORS+1)); }
# a file over upload_max_filesize (but under post_max_size): a clear message with the limit in MB, not the php.ini shorthand
UPLOAD_LIMIT=$(php -r '$b = fn ($v) => (int) $v * (["k" => 1024, "m" => 1048576, "g" => 1073741824][strtolower(substr(trim($v), -1))] ?? 1); echo $b(ini_get("upload_max_filesize")), " ", $b(ini_get("post_max_size"));')
if [ "${UPLOAD_LIMIT% *}" -gt 0 ] && [ $(( ${UPLOAD_LIMIT% *} + 4096 )) -lt "${UPLOAD_LIMIT#* }" ]; then
  head -c $(( ${UPLOAD_LIMIT% *} + 1024 )) /dev/zero > "$WORK/velky.zip"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=media&action=upload&format=json" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/velky.zip"
  grep -q 'nejvýš [0-9,]* MB' "$WORK/response" && echo "  ok     soubor nad limit serveru: hláška s limitem v MB" || { echo "  CHYBA  hláška o limitu nahrávání"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
fi
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
PHOTO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE ido = $IDO")
expect "nahraný obrázek nedostane popis (alt) ze jména souboru" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT('[', nazev, ']') FROM ka_media WHERE ido = $IDO")" "[]"
php -r '$i = imagecreatetruecolor(800, 800); imagefill($i, 0, 0, imagecolorallocate($i, 20, 120, 200)); imagejpeg($i, "'"$WORK"'/nova.jpg");'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=replace" -F "_csrf=$TOKEN" -F "ido=$IDO" -F "soubor=@$WORK/nova.jpg;type=image/jpeg"
expect "náhrada souboru zachová adresu a změní rozměry" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(obr_poloha, ' ', obr_width, 'x', obr_height) FROM ka_media WHERE ido = $IDO")" "$PHOTO 800x800"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=save" -d "_csrf=$TOKEN" -d "ido=$IDO" -d nazev=Foto -d ohnisko_x=20 -d ohnisko_y=80
expect "ohnisko ořezu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ohnisko FROM ka_media WHERE ido = $IDO")" "20% 80%"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" -d z_adresy=/akce-leto -d na_adresu=/kontakty -d typ=302
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/akce-leto"); expect "dočasné přesměrování 302" "$code" "302"
check "hledání v přesměrováních" 200 "/admin.php?module=redirects&hledat=akce-leto" "akce-leto"
check "protokol s filtrem" 200 "/admin.php?module=changelog&kde=stranky" "Protokol"
IDU2=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d user=pozvany --data-urlencode email=pozvany@example.cz -d admin=2 -d pozvat=1)
expect "pozvaný uživatel má odkaz na heslo s delší platností" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obnova_otisk <> '' AND obnova_cas > NOW() FROM ka_uzivatele WHERE user = 'pozvany'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d idr=0 -d nazev=Obchodník -d uroven=0 -d 'moduly[]=enquiries' -d 'moduly[]=collections'
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT MAX(idr) FROM ka_role")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d user=obchodnik --data-urlencode "password=$PASSWORD" -d "admin=r$IDR"
expect "vlastní role dá uživateli své sekce" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(p.ident_modulu ORDER BY p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' AND u.role = $IDR")" "collections,enquiries"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d "idr=$IDR" -d nazev=Obchodník -d uroven=1 -d 'moduly[]=enquiries'
expect "změna role se přenese na členy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(u.admin, ':', GROUP_CONCAT(p.ident_modulu)) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' GROUP BY u.idu")" "1:enquiries"
check "přehled rolí" 200 "/admin.php?module=roles" "Obchodník"
check "uživatelé ukazují vlastní roli" 200 "/admin.php?module=users" "Obchodník"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('require_2fa', 'spravci') ON DUPLICATE KEY UPDATE hodnota = 'spravci'"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?module=pages"); case "$code" in "302 "*action=account*) echo "  ok     povinné dvoufázové přihlášení pustí jen do Můj účet";; *) echo "  CHYBA  vynucení 2FA: $code"; ERRORS=$((ERRORS+1));; esac
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'require_2fa'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$TOKEN" -d co=totp_start
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
grep -q '<svg class="qr"' "$WORK/response" && grep -q 'class="totp-klic"' "$WORK/response" && echo "  ok     zapnutí 2FA ukáže QR kód i klíč k ručnímu zadání" || { echo "  CHYBA  QR kód při zapínání 2FA"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = JSON_SET(IF(hodnota = '' OR hodnota IS NULL, '{}', hodnota), '$.vlastni_pisma', JSON_ARRAY(JSON_OBJECT('nazev', 'Znacka Sans', 'soubor', 'media/2026/01/znacka.woff2', 'tucny', '')), '$.pismo_titulky', 'vlastni-1') WHERE promenna = 'design_system'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/kontakty"
grep -q '@font-face { font-family: "Znacka Sans"; src: url("/media/2026/01/znacka.woff2")' "$WORK/response" && grep -q -- '--ka-pismo-titulky: "Znacka Sans"' "$WORK/response" && echo "  ok     vlastní písmo z Médií" || { echo "  CHYBA  vlastní písmo"; ERRORS=$((ERRORS+1)); }
expect "statistika po stránkách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) > 0 FROM ka_stat_stranky")" "1"
curl -s -o "$WORK/response" "$B/kontakty"; grep -q 'image/web.js' "$WORK/response" && echo "  CHYBA  web.js i na stránce, která ho nepotřebuje" && ERRORS=$((ERRORS+1)) || echo "  ok     web.js jen tam, kde je potřeba"

echo "== menu"
check "editor menu" 200 "/admin.php?module=menu" 'data-menu-seznam'
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
MENU='[{"typ":"stranka","ids":'$IDO',"text":"O firmě","deti":[{"typ":"odkaz","text":"Kariéra","url":"https://example.cz/kariera","nove_okno":true}]},{"typ":"novinky"},{"typ":"odkaz","text":"Zlý","url":"javascript:alert(1)"}]'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&umisteni=hlavni" -d "_csrf=$TOKEN" --data-urlencode "polozky=$MENU"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&umisteni=paticka" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"odkaz","text":"Zásady ochrany soukromí","url":"/zasady"}]'
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/novinky"
grep -q '<li class="podmenu"><a href="[^"]*/o-nas">O firmě</a><ul><li><a href="https://example.cz/kariera" target="_blank" rel="noopener">Kariéra</a>' "$WORK/response" && grep -q 'aria-current="page">Novinky' "$WORK/response" && ! grep -q 'javascript:' "$WORK/response" \
  && echo "  ok     menu s podmenu na webu, nebezpečný odkaz vypadl" || { echo "  CHYBA  menu na webu"; ERRORS=$((ERRORS+1)); }
grep -q 'image/web\.js' "$WORK/response" && echo "  ok     stránka s podmenu načte web.js (Esc podmenu zavře)" || { echo "  CHYBA  stránka s podmenu bez web.js"; ERRORS=$((ERRORS+1)); }
mcp nacti_menu '{"umisteni":"paticka"}' > "$WORK/response"; grep -q 'Zásady ochrany soukromí' "$WORK/response" && echo "  ok     menu v patičce (MCP)" || { echo "  CHYBA  menu v patičce"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
expect "zaškrtnutá stránka se přidá na konec sestaveného menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT polozky LIKE '%\"ids\":$IDS%' FROM ka_menu WHERE umisteni = 'hlavni'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=automatic&umisteni=hlavni" -d "_csrf=$TOKEN"
expect "návrat k automatickému menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni'")" "0"

echo "== ikony, manifest, cache"
expect "favicon.ico bez ikony nevygeneruje stránku 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/favicon.ico")" 204
curl -s -o "$WORK/response" "$B/manifest.webmanifest"; grep -q '"start_url"' "$WORK/response" && echo "  ok     manifest webu" || { echo "  CHYBA  manifest"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null "$B/novinky"
expect "odkaz s utm parametry jde z cache" "$(curl -s -o /dev/null -D - "$B/novinky?utm_source=newsletter&fbclid=x" | grep -ci '^x-cache: kaleta')" 1
ETAG=$(curl -s -o /dev/null -D - "$B/novinky" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')
expect "stránka z cache odpoví 304 na shodný ETag" "$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$B/novinky")" 304

echo "== nové prvky builderu"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[
{\"typ\":\"drobecky\"},
{\"typ\":\"ikona\",\"obsah\":{\"ikona\":\"telefon\",\"tvar\":\"kruh\"}},
{\"typ\":\"galerie\",\"obsah\":{\"fotky\":[{\"src\":\"media/2026/01/a.jpg\",\"alt\":\"Dílna\"},{\"src\":\"media/2026/01/b.jpg\",\"alt\":\"\"}]}},
{\"typ\":\"zalozky\",\"obsah\":{\"karty\":[{\"nazev\":\"Základ\",\"obsah\":\"<p>A</p>\"},{\"nazev\":\"Plus\",\"obsah\":\"<p>B</p>\"}]}},
{\"typ\":\"karusel\",\"obsah\":{\"naraz\":\"2\"},\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Snímek</p>\"}}]},
{\"typ\":\"mapa\",\"obsah\":{\"adresa\":\"Brno, Náměstí Svobody\"}},
{\"typ\":\"okno\",\"kotva\":\"nabidka\",\"obsah\":{\"samo\":\"5\"},\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Akce\"}}]},
{\"typ\":\"faq\",\"obsah\":{\"jedna\":true,\"faq\":false,\"polozky\":[{\"otazka\":\"Co?\",\"odpoved\":\"<p>To.</p>\"}]}}
]}]}}" > "$WORK/response"
grep -q 'chyby\\":\[\]' "$WORK/response" && echo "  ok     nové prvky projdou validátorem" || { echo "  CHYBA  validace nových prvků"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
for pattern in 'class="ka-drobecky"' 'aria-current="page">Z HTML' 'class="ka-ikona ka-ikona--kruh" aria-hidden="true"><svg' 'class="ka-galerie"' 'alt="Dílna"' 'role="tablist"' 'aria-controls="zp-' 'data-karusel' '--ka-naraz:2' 'data-vlozit="https://maps.google.com/maps?q=Brno' 'id="nabidka"' 'popover role="dialog" aria-label="Vyskakovací okno" data-samo="5"' 'name="faq-'; do
  grep -qF -- "$pattern" "$WORK/response" || { echo "  CHYBA  nový prvek na webu: chybí $pattern"; ERRORS=$((ERRORS+1)); }
done
grep -q '"BreadcrumbList"' "$WORK/response" && ! grep -q '"FAQPage"' "$WORK/response" && echo "  ok     nové prvky na webu, drobečky i pro vyhledávače, akordeon bez FAQPage" || { echo "  CHYBA  strukturovaná data stránky"; ERRORS=$((ERRORS+1)); }

echo "== další prvky: počítadlo, průběh, hodnocení, odpočet, sítě, hledání, nahoru, odběr, podmínky"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('social_instagram','https://instagram.com/firma') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"obsah\":{\"video\":\"media/2026/01/pozadi.mp4\"},\"deti\":[
{\"typ\":\"pocitadlo\",\"obsah\":{\"cislo\":1200,\"za\":\"+\"}},
{\"typ\":\"prubeh\",\"obsah\":{\"polozky\":[{\"nazev\":\"Termíny\",\"hodnota\":96}]}},
{\"typ\":\"hodnoceni\",\"obsah\":{\"hodnota\":\"4,5\"}},
{\"typ\":\"odpocet\",\"obsah\":{\"cil\":\"2099-01-01 09:00\"}},
{\"typ\":\"socialni\"},{\"typ\":\"hledani\"},{\"typ\":\"nahoru\"},{\"typ\":\"newsletter\"},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Jen pro redakci\"},\"podminky\":{\"prihlaseni\":\"ano\"}},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Stará akce\"},\"podminky\":{\"do\":\"2000-01-01\"}},
{\"typ\":\"video\",\"obsah\":{\"url\":\"media/2026/01/film.mp4\",\"plakat\":\"media/2026/01/plakat.jpg\"}}
]}]}}" > "$WORK/response"
grep -q 'chyby\\":\[\]' "$WORK/response" && echo "  ok     další prvky projdou validátorem" || { echo "  CHYBA  validace dalších prvků"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
for pattern in 'data-pocitadlo="1200">1' '<meter min="0" max="100"' 'aria-label="Hodnocení 4,5 z 5' 'data-odpocet="2099-01-01T09:00' 'class="ka-socialni"' 'aria-label="Instagram"' 'role="search"' 'class="ka-nahoru"' 'class="ka-newsletter"' 'name="as_podpis"' 'class="ka-video-pozadi"' 'poster="/media/2026/01/plakat.jpg"' 'image/web.js'; do
  grep -qF -- "$pattern" "$WORK/response" || { echo "  CHYBA  další prvek na webu: chybí $pattern"; ERRORS=$((ERRORS+1)); }
done
! grep -q 'Jen pro redakci\|Stará akce' "$WORK/response" && echo "  ok     podmínky zobrazení skryjí prvek nepřihlášenému i po datu" || { echo "  CHYBA  podmínky zobrazení"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/z-html"; ls "$WORK"/web/storage/cache/stranky/*.html >/dev/null 2>&1 && { echo "  CHYBA  stránka s podmínkou zobrazení šla do cache"; ERRORS=$((ERRORS+1)); } || echo "  ok     stránka s podmínkou zobrazení se necachuje"
curl -s -b "$JAR" -o "$WORK/response" "$B/z-html"; grep -q 'Jen pro redakci' "$WORK/response" && echo "  ok     přihlášený vidí prvek jen pro redakci" || { echo "  CHYBA  prvek pro přihlášené"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/z-html"
NEWSLETTER_FORM=$(tr '\n' ' ' < "$WORK/response" | grep -o 'class="ka-newsletter".*' | sed 's#</form>.*##')
NL_SIGNATURE=$(echo "$NEWSLETTER_FORM" | grep -o 'name="as_podpis" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'); NL_TIME=$(echo "$NEWSLETTER_FORM" | grep -o 'name="as_cas" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
sleep 5
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/odber" -d "email=Odber@Example.cz" -d zpet=/z-html -d kotva=x -d "as_podpis=$NL_SIGNATURE" -d "as_cas=$NL_TIME" -d web_adresa=)
case "$code" in "303 "*"/z-html?odber=ok#x") echo "  ok     přihlášení k odběru";; *) echo "  CHYBA  přihlášení k odběru: $code"; ERRORS=$((ERRORS+1));; esac
SUB_TOKEN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT token FROM ka_odberatele WHERE email = 'odber@example.cz' AND stav = 0")
check "odkaz z e-mailu jen nabídne potvrzení" 200 "/odber?potvrdit=$SUB_TOKEN" "Potvrdit odběr"
expect "otevření odkazu (skener pošty) odběr nepotvrdí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "0"
curl -s -o "$WORK/response" -X POST "$B/odber?potvrdit=$SUB_TOKEN"; grep -q "Odběr je potvrzený" "$WORK/response" && echo "  ok     potvrzení odběru tlačítkem" || { echo "  CHYBA  potvrzení odběru"; ERRORS=$((ERRORS+1)); }
expect "odběratel je potvrzený" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "1"
check "odběratelé v administraci" 200 "/admin.php?module=subscribers" "odber@example.cz"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers&action=csv"; grep -q "odber@example.cz;.*odber?odhlasit=$SUB_TOKEN" "$WORK/response" && echo "  ok     export odběratelů s odkazem na odhlášení" || { echo "  CHYBA  export odběratelů"; head -3 "$WORK/response"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'newsletter,', '') WHERE promenna = 'extensions'"
check "odhlášení jde i s vypnutým Newsletterem" 200 "/odber?odhlasit=$SUB_TOKEN" "Odhlásit odběr"
curl -s -o "$WORK/response" -X POST "$B/odber?odhlasit=$SUB_TOKEN"; grep -q "Odhlášeno" "$WORK/response" && echo "  ok     odhlášení tlačítkem" || { echo "  CHYBA  odhlášení"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'poptavky,', 'poptavky,newsletter,') WHERE promenna = 'extensions'"
expect "odhlášený je smazaný" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_odberatele")" "0"

echo "== odběratelé do mailingové služby (falešný server)"
SERVICE_PORT=$((PORT + 2)); mkdir -p "$WORK/sluzba"
cat > "$WORK/sluzba/router.php" <<'PHP'
<?php
$log = __DIR__ . '/pozadavky.log';
if ($_SERVER['REQUEST_URI'] === '/_log') { header('Content-Type: text/plain'); @readfile($log); return true; }
$h = array_change_key_case(getallheaders());
file_put_contents($log, $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . ($h['api-key'] ?? $h['authorization'] ?? $h['key'] ?? '-') . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
if (str_contains($_SERVER['REQUEST_URI'], 'chyba')) { http_response_code(500); echo '{"message":"Invalid list"}'; return true; }
http_response_code(201); header('Content-Type: application/json'); echo '{}'; return true;
PHP
(cd "$WORK/sluzba" && exec php -S "127.0.0.1:$SERVICE_PORT" router.php > /dev/null 2>&1) & SERVICE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$SERVICE_PORT/_log" && break; sleep 0.2; done
set_service() { "${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('newsletter_service','$1'),('newsletter_key','$2'),('newsletter_list','$3'),('newsletter_webhook','$4'),('newsletter_test_url','http://127.0.0.1:$SERVICE_PORT'); DELETE FROM ka_odber_fronta; DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES ('sluzba@example.cz', 1, '$(php -r 'echo bin2hex(random_bytes(16));')', NOW(), NOW())"; : > "$WORK/sluzba/pozadavky.log"; }
subscriber_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=subscribers&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
last_request() { tail -1 "$WORK/sluzba/pozadavky.log"; }
set_service brevo brevo-klic 7 ''; subscriber_action synchronizuj
case "$(last_request)" in 'POST /brevo/v3/contacts brevo-klic {"email":"sluzba@example.cz","listIds":[7],"updateEnabled":true}') echo "  ok     Brevo: přidání do seznamu";; *) echo "  CHYBA  Brevo: $(last_request)"; ERRORS=$((ERRORS+1));; esac
expect "odběratel ve službě" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(sync, '/', (SELECT COUNT(*) FROM ka_odber_fronta)) FROM ka_odberatele")" "ok/0"
check "stav služby u odběratelů" 200 "/admin.php?module=subscribers" "odesláno"
IDOD=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_odberatele"); subscriber_action smaz -d "ido=$IDOD"; subscriber_action znovu
case "$(last_request)" in 'POST /brevo/v3/contacts/lists/7/contacts/remove brevo-klic {"emails":["sluzba@example.cz"]}') echo "  ok     Brevo: smazaný odběratel odebrán ze seznamu";; *) echo "  CHYBA  Brevo odebrání: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service mailchimp 'abc123-us21' 'aud1' ''; subscriber_action synchronizuj
case "$(last_request)" in "PUT /mailchimp/3.0/lists/aud1/members/$(php -r 'echo md5("sluzba@example.cz");') Basic $(printf 'kaleta:abc123-us21' | base64) "*'"status":"subscribed"'*) echo "  ok     Mailchimp: člen audience";; *) echo "  CHYBA  Mailchimp: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service mailerlite ml-klic 99 ''; subscriber_action synchronizuj
case "$(last_request)" in 'POST /mailerlite/api/subscribers Bearer ml-klic {"email":"sluzba@example.cz","groups":["99"],"status":"active"}') echo "  ok     MailerLite: odběratel ve skupině";; *) echo "  CHYBA  MailerLite: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service smartemailing 'jmeno:klic' 5 ''; subscriber_action synchronizuj
case "$(last_request)" in "POST /smartemailing/api/v3/import Basic $(printf 'jmeno:klic' | base64) "*'"contactlists":[{"id":5,"status":"confirmed"}]'*) echo "  ok     SmartEmailing: import do seznamu";; *) echo "  CHYBA  SmartEmailing: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service webhook '' '' 'https://hook.example.com/odber'; subscriber_action synchronizuj
case "$(last_request)" in 'POST /webhook/odber - {"udalost":"novy_odberatel",'*'"email":"sluzba@example.cz"'*) echo "  ok     webhook: nový odběratel";; *) echo "  CHYBA  webhook: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service ecomail eco-klic chyba ''; subscriber_action synchronizuj
expect "nepovedený přenos čeká na další pokus s chybou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(pokusy, '|', chyba LIKE 'HTTP 500%', '|', dalsi > NOW()) FROM ka_odber_fronta")" "1|1|1"
case "$(last_request)" in 'POST /ecomail/lists/chyba/subscribe eco-klic '*'"skip_confirmation":true'*) echo "  ok     Ecomail: přihlášení do seznamu";; *) echo "  CHYBA  Ecomail: $(last_request)"; ERRORS=$((ERRORS+1));; esac
mcp uprav_nastaveni '{}' | grep -q 'newsletter_klic\|eco-klic' && { echo "  CHYBA  MCP ukazuje klíč mailingové služby"; ERRORS=$((ERRORS+1)); } || echo "  ok     klíč mailingové služby MCP neukazuje"
kill "$SERVICE_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna LIKE 'newsletter\_%'; DELETE FROM ka_odber_fronta; DELETE FROM ka_odberatele"

echo "== newsletters (fake SMTP server)"
SMTP_PORT=$((PORT + 3)); mkdir -p "$WORK/smtp"
php "$ROOT/tools/fake-smtp.php" "$SMTP_PORT" "$WORK/smtp" > /dev/null 2>&1 & SMTP_PID=$!
db() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
tok() { php -r 'echo bin2hex(random_bytes(16));'; }
# eml <file>: headers, the decoded subject and the decoded text and HTML parts of a captured message
eml() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); echo $h, "\n"; preg_match("/^Subject: (.*)$/m", $h, $s); echo "Subject-Decoded: ", mb_decode_mimeheader(trim($s[1] ?? "")), "\n";
  preg_match_all("/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p); foreach ($p[1] as $x) { echo base64_decode($x), "\n"; }' "$1"; }
mail_to() { grep -l "^X-Rcpt-To: $1" "$WORK"/smtp/*.eml 2>/dev/null | tail -1; }
newsletter_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=newsletters"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
ANNA=$(tok); PETR=$(tok)
db "UPDATE ka_uzivatele SET email = 'admin@example.cz' WHERE user = 'admin'; DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES
  ('anna@example.cz', 1, '$ANNA', NOW(), NOW()), ('petr@example.cz', 1, '$PETR', NOW(), NOW()), ('odmitnout@example.cz', 1, '$(tok)', NOW(), NOW()), ('ceka@example.cz', 0, '$(tok)', NOW(), NULL);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('tasks_last_run', '0')"
check "newsletters: empty list" 200 "/admin.php?module=newsletters" "Napsat newsletter"
check "newsletters: new draft form" 200 "/admin.php?module=newsletters&action=new" 'name="subject"'
newsletter_action save -d id=0 --data-urlencode "subject=Jarní novinky" --data-urlencode "preheader=Co je nového" --data-urlencode $'intro=Dobrý den,\n\nposíláme novinky. Více na https://example.cz/akce' \
  -d news_mode=latest -d news_count=2 --data-urlencode "button_label=Všechny novinky" -d button_url=/novinky
NL=$(db "SELECT id FROM ka_newsletters ORDER BY id DESC LIMIT 1")
expect "newsletter draft saved" "$(db "SELECT CONCAT(status, '|', subject, '|', news_count) FROM ka_newsletters WHERE id = $NL")" "draft|Jarní novinky|2"
check "newsletter: e-mail preview" 200 "/admin.php?module=newsletters&action=preview&id=$NL" "utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=jarni-novinky"
expect "preview: 2 news items, linked address, button and unsubscribe" "$(grep -c 'Číst dál' "$WORK/response")|$(grep -c 'href="https://example.cz/akce"' "$WORK/response")|$(grep -c 'Všechny novinky' "$WORK/response")|$(grep -c 'Odhlásit odběr' "$WORK/response")" "2|1|1|1"
newsletter_action send -d "id=$NL" -d when=now
expect "no sending without an SMTP server" "$(db "SELECT status FROM ka_newsletters WHERE id = $NL")" "draft"
db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')"
newsletter_action send -d "id=$NL" -d when=now
expect "no sending while cron does not run" "$(db "SELECT status FROM ka_newsletters WHERE id = $NL")" "draft"
check "newsletter form tells why it cannot send" 200 "/admin.php?module=newsletters&action=edit&id=$NL" "Cron za posledních 30 minut"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
newsletter_action test -d "id=$NL"
F=$(mail_to admin@example.cz); [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "test e-mail to the signed-in user" "$(grep -c '^Subject-Decoded: \[Zkouška\] Jarní novinky$' "$WORK/eml.txt" 2>/dev/null)" "1"
newsletter_action send -d "id=$NL" -d when=now
expect "sending started for confirmed subscribers only" "$(db "SELECT CONCAT(status, '|', recipients, '|', html LIKE '%{{unsubscribe}}%') FROM ka_newsletters WHERE id = $NL")" "sending|3|1"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "cron sends a batch: 2 delivered, the refused one waits for a retry" "$(db "SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', (SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $NL AND next_attempt > NOW())) FROM ka_newsletters WHERE id = $NL")" "sending|2|0|1"
F=$(mail_to anna@example.cz); [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "subscriber e-mail: one-click unsubscribe with the own link, no one else's" "$(grep -c "^List-Unsubscribe: <http://127.0.0.1:$PORT/odber?odhlasit=$ANNA>" "$WORK/eml.txt")|$(grep -c '^List-Unsubscribe-Post: List-Unsubscribe=One-Click' "$WORK/eml.txt")|$(grep -c "odhlasit=$ANNA" "$WORK/eml.txt")|$(grep -c "$PETR" "$WORK/eml.txt")" "1|1|3|0"
expect "subscriber e-mail: subject, text part and HTML part" "$(grep -c '^Subject-Decoded: Jarní novinky$' "$WORK/eml.txt")|$(grep -c '^Všechny novinky: http' "$WORK/eml.txt")|$(grep -c '<h1 ' "$WORK/eml.txt")" "1|1|1"
expect "newsletter recipients are not in the mail log" "$(db "SELECT COUNT(*) FROM ka_posta WHERE komu IN ('anna@example.cz', 'petr@example.cz')")" "0"
db "UPDATE ka_newsletter_queue SET next_attempt = NOW() WHERE next_attempt IS NOT NULL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
db "UPDATE ka_newsletter_queue SET next_attempt = NOW() WHERE next_attempt IS NOT NULL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a refused address is given up after three attempts, the newsletter is sent" "$(db "SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', finished_at IS NOT NULL) FROM ka_newsletters WHERE id = $NL")" "sent|2|1|1"
check "newsletters: list with counts" 200 "/admin.php?module=newsletters" "Odesláno"
check "a sent newsletter is read-only" 200 "/admin.php?module=newsletters&action=edit&id=$NL" "Příjemci"
contains -c 'name="subject"' "$WORK/response" && { echo "  CHYBA  a sent newsletter can still be edited"; ERRORS=$((ERRORS+1)); } || echo "  ok     a sent newsletter has no form"
curl -s -o /dev/null -X POST "$B/odber?odhlasit=$ANNA" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "one-click unsubscribe from the mail client (RFC 8058)" "$(db "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'anna@example.cz'")" "0"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | contains '"name":"draft_newsletter"' && echo "  ok     MCP: newsletter tools listed" || { echo "  CHYBA  MCP: newsletter tools missing"; ERRORS=$((ERRORS+1)); }
db "DELETE FROM ka_odberatele WHERE email LIKE 'odmitnout%'"
mcp draft_newsletter '{"subject":"Novinky přes Clauda","intro":"Ahoj,\n\nkrátká zpráva.","news_mode":"none","button_label":"Kontakt","button_url":"/kontakt"}' > "$WORK/response"
NL2=$(mcp_value id)
expect "MCP: draft_newsletter returns the text version" "$(mcp_value status)|$(mcp_value text | grep -c '^Kontakt: http://127.0.0.1')" "draft|1"
mcp send_test_newsletter "{\"id\":$NL2}" > "$WORK/response"
expect "MCP: test goes to the connected user" "$(mcp_value sent_to)" "admin@example.cz"
mcp send_newsletter "{\"id\":$NL2,\"at\":\"2099-01-01 08:00\"}" > "$WORK/response"
expect "MCP: send_newsletter schedules" "$(mcp_value status)|$(mcp_value scheduled_at)" "scheduled|2099-01-01 08:00"
db "UPDATE ka_newsletters SET scheduled_at = NOW() - INTERVAL 1 MINUTE WHERE id = $NL2"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a due scheduled newsletter goes out on the next cron call" "$(db "SELECT CONCAT(status, '|', recipients, '|', sent_count) FROM ka_newsletters WHERE id = $NL2")" "sent|1|1"
mcp list_newsletters '{}' > "$WORK/response"
expect "MCP: list_newsletters with subscribers and no sending problem" "$(mcp_value confirmed_subscribers)|$(mcp_value sending_problem)|$(mcp_value newsletters 0 status)" "1|null|sent"
mcp delete_newsletter "{\"id\":$NL2}" > /dev/null
expect "MCP: delete_newsletter" "$(db "SELECT COUNT(*) FROM ka_newsletters WHERE id = $NL2")" "0"
db "UPDATE ka_newsletters SET finished_at = NOW() - INTERVAL 2 DAY WHERE id = $NL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "recipients are kept only a day after sending" "$(db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $NL")|$(db "SELECT sent_count FROM ka_newsletters WHERE id = $NL")" "0|2"
check "health: cron check" 200 "/admin.php?module=settings&tab=health" "Cron"
grep -q "vlastni ve složce layout/" "$WORK/response" && echo "  ok     health: a leftover custom layout is reported" || { echo "  CHYBA  health: leftover custom layout not reported"; ERRORS=$((ERRORS+1)); }
kill "$SMTP_PID" 2>/dev/null || true
db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''); DELETE FROM ka_odberatele; DELETE FROM ka_newsletters; DELETE FROM ka_newsletter_queue"

echo "== média, tokeny DTCG, kolekce přes MCP"
IDOM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
check "média: hledání a řazení" 200 "/admin.php?module=media&hledat=jpg&razeni=velikost" 'data-popis-media='
reply=$(curl -s -b "$JAR" -c "$JAR" -X POST "$B/admin.php?module=media&action=save_caption" -d "_csrf=$TOKEN" -d "ido=$IDOM" --data-urlencode "popis=Dilna zevnitr")
expect "popis obrázku bez znovunačtení" "$reply|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE ido = $IDOM")" '{"ok":true}|Dilna zevnitr'
curl -s -b "$JAR" -o "$WORK/tokeny.json" "$B/admin.php?module=appearance&action=tokens"
grep -q '"\$type": "color"' "$WORK/tokeny.json" && grep -q '"cz.kaleta"' "$WORK/tokeny.json" && echo "  ok     export tokenů DTCG" || { echo "  CHYBA  export tokenů"; ERRORS=$((ERRORS+1)); }
printf '{"color":{"primary":{"$type":"color","$value":"#aa3300"}}}' > "$WORK/cizi.tokens.json"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=tokens_import" -F "_csrf=$TOKEN" -F "tokeny=@$WORK/cizi.tokens.json"
expect "import barev z cizích tokenů" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")" "#aa3300"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=tokens_import" -F "_csrf=$TOKEN" -F "tokeny=@$WORK/tokeny.json"
expect "import vlastního exportu vrátí vzhled" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) <> '#aa3300' FROM ka_nastaveni WHERE promenna = 'design_system'")" "1"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Mistr truhlář"}' > "$WORK/response"
grep -q 'Petr Svoboda' "$WORK/response" && grep -q 'celkem\\":1' "$WORK/response" && echo "  ok     kolekce přes MCP: filtr podle pole" || { echo "  CHYBA  kolekce přes MCP s filtrem"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDPS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_kolekce_polozky WHERE nazev = 'Petr Svoboda'")
mcp uloz_polozku_kolekce "{\"kolekce\":\"tym\",\"id\":$IDPS,\"data\":{\"funkce\":\"Vedouci dilny\"}}" > /dev/null
expect "kolekce přes MCP: úprava položky bez názvu název zachová" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(nazev, '|', data LIKE '%Vedouci dilny%') FROM ka_kolekce_polozky WHERE idp = $IDPS")" "Petr Svoboda|1"

echo "== záloha a obnova databáze"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$TOKEN"
BACKUP=$(ls -t "$WORK"/web/storage/zalohy/ 2>/dev/null | grep -v predobnovou | head -1 || true)
[ -n "$BACKUP" ] && echo "  ok     záloha vytvořena" || { echo "  CHYBA  záloha nevznikla"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Po zaloze' WHERE promenna = 'site_name'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$BACKUP"
expect "obnova vrátí stav ze zálohy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota <> 'Po zaloze' FROM ka_nastaveni WHERE promenna = 'site_name'")" "1"
BROKEN_BACKUP="kaleta-poskozena.sql"; [[ "$BACKUP" == *.gz ]] && BROKEN_BACKUP="kaleta-poskozena.sql.gz"
if [[ "$BACKUP" == *.gz ]]; then { gzip -dc "$WORK/web/storage/zalohy/$BACKUP" | head -c 4000 || true; } | gzip > "$WORK/web/storage/zalohy/$BROKEN_BACKUP"; else head -c 4000 "$WORK/web/storage/zalohy/$BACKUP" > "$WORK/web/storage/zalohy/$BROKEN_BACKUP"; fi
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Pred poskozenou' WHERE promenna = 'site_name'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$BROKEN_BACKUP"
expect "poškozená záloha databázi nezmění" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" "Pred poskozenou"

echo "== role přes MCP, obnova hesla, zámek účtu"
SUB_TOKEN2="kaleta_$(printf 'b%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$SUB_TOKEN2")', NOW() FROM ka_uzivatele WHERE user = 'obchodnik'"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $SUB_TOKEN2" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"vytvor_novinku","arguments":{"titulek":"Od obchodnika","kategorie":"aktuality"}}}' > "$WORK/response"
grep -q 'nemáš přístup' "$WORK/response" && expect "vlastní role bez Novinek nezaloží novinku ani přes MCP" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Od obchodnika'")" "0" || { echo "  CHYBA  MCP bez kontroly sekce Novinky"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FAKE_REFRESH="$(printf 'c%.0s' $(seq 1 64))"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET obnova_otisk = '$(php -r 'echo hash("sha256", $argv[1]);' "$FAKE_REFRESH")', obnova_cas = NOW() + INTERVAL 1 DAY WHERE user = 'obchodnik'"
JAR3="$WORK/jar3"
TOKEN3=$(curl -s -c "$JAR3" "$B/admin.php?action=password&token=$FAKE_REFRESH" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
curl -s -b "$JAR3" -c "$JAR3" -o /dev/null -X POST "$B/admin.php?action=password" -d "_csrf=$TOKEN3" -d "token=$FAKE_REFRESH" --data-urlencode "password=Nove-heslo-123" --data-urlencode "password2=Nove-heslo-123"
expect "obnova hesla zruší tokeny napojení" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny t JOIN ka_uzivatele u ON u.idu = t.idu WHERE u.user = 'obchodnik'")" "0"
JAR4="$WORK/jar4"
TOKEN4=$(curl -s -c "$JAR4" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
for i in $(seq 1 10); do curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik -d password=spatne-heslo-xyz; done
code=$(curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password=Nove-heslo-123")
expect "po 10 chybách je účet dočasně zamčený i pro správné heslo" "$code|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zamceno_do > NOW() FROM ka_uzivatele WHERE user = 'obchodnik'")" "401|1"

echo "== OAuth pro konektor Claude"
curl -s -o "$WORK/response" -D "$WORK/hlavicky" -X POST "$B/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize"}'
grep -qi 'www-authenticate: Bearer resource_metadata="http://127.0.0.1:[0-9]*/.well-known/oauth-protected-resource"' "$WORK/hlavicky" && echo "  ok     MCP bez tokenu odkáže na metadata OAuth" || { echo "  CHYBA  WWW-Authenticate u MCP"; ERRORS=$((ERRORS+1)); }
check "metadata chráněného zdroje" 200 "/.well-known/oauth-protected-resource" '"authorization_servers"'
check "metadata autorizačního serveru" 200 "/.well-known/oauth-authorization-server" '"code_challenge_methods_supported":\["S256"\]'
REDIRECT_URI="https://claude.ai/api/mcp/auth_callback"
curl -s -o "$WORK/response" -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"Claude\",\"redirect_uris\":[\"$REDIRECT_URI\"],\"token_endpoint_auth_method\":\"none\"}"
CLIENT=$(grep -o '"client_id":"[a-f0-9]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$CLIENT" ] && echo "  ok     dynamická registrace klienta" || { echo "  CHYBA  registrace klienta"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "registrace odmítne http adresu návratu" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["http://zly.example/cb"]}')" 400
VERIFIER="$(printf 'v%.0s' $(seq 1 50))"; CHALLENGE=$(printf %s "$VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
expect "cizí adresa návratu se nepřesměruje" "$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=https://zly.example/&code_challenge=$CHALLENGE&code_challenge_method=S256")" 400
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=xyz&scope=mcp")
case "$code" in "302 "*action=oauth) echo "  ok     přihlášení vede na souhlas v administraci";; *) echo "  CHYBA  authorize: $code"; ERRORS=$((ERRORS+1));; esac
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/hlavicky" "$B/admin.php?action=oauth"; grep -q 'Povolit přístup' "$WORK/response" && echo "  ok     stránka souhlasu" || { echo "  CHYBA  stránka souhlasu"; ERRORS=$((ERRORS+1)); }
grep -qi "form-action 'self' https://claude.ai;" "$WORK/hlavicky" && ! grep -qi "x-kaleta-form-action" "$WORK/hlavicky" && echo "  ok     CSP souhlasu povolí návrat do aplikace (form-action)" || { echo "  CHYBA  CSP form-action na stránce souhlasu"; grep -i "content-security" "$WORK/hlavicky"; ERRORS=$((ERRORS+1)); }
OAUTH_CSRF=$(csrf)
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d povolit=1)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
case "$REDIRECT" in "$REDIRECT_URI?code="*"state=xyz"*) echo "  ok     souhlas vrátí kód a state do aplikace";; *) echo "  CHYBA  návrat po souhlasu: $REDIRECT"; ERRORS=$((ERRORS+1));; esac
expect "špatný code_verifier (PKCE) neprojde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d code_verifier=spatny-overovac-spatny-overovac-spatny-overovac)" 400
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=abc" && curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d povolit=1)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
ACCESS_TOKEN=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true); REFRESH_TOKEN=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$ACCESS_TOKEN" ] && [ -n "$REFRESH_TOKEN" ] && echo "  ok     výměna kódu za tokeny (PKCE)" || { echo "  CHYBA  token endpoint"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "kód jde použít jen jednou" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER")" 400
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $ACCESS_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
grep -q 'builder_schema' "$WORK/response" && echo "  ok     MCP s přístupovým tokenem z OAuth" || { echo "  CHYBA  MCP s tokenem OAuth"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "obnovovací token nejde použít k MCP" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $REFRESH_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" 401
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_TOKEN" -d "client_id=$CLIENT"
grep -q '"access_token"' "$WORK/response" && echo "  ok     obnova tokenu" || { echo "  CHYBA  obnova tokenu"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "obnovovací token se po použití vymění" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_TOKEN" -d "client_id=$CLIENT")" 400
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" # limit přihlášení z IP vyčerpal test zámku účtu
JAR5="$WORK/jar5"
curl -s -c "$JAR5" -b "$JAR5" -o /dev/null "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=nove"
TOKEN5=$(curl -s -b "$JAR5" -c "$JAR5" "$B/admin.php?action=oauth" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
code=$(curl -s -b "$JAR5" -c "$JAR5" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$TOKEN5" -d user=admin --data-urlencode "password=$PASSWORD")
case "$code" in *action=oauth) echo "  ok     nepřihlášený se po přihlášení vrátí na souhlas";; *) echo "  CHYBA  návrat na souhlas po přihlášení: $code"; ERRORS=$((ERRORS+1));; esac
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; grep -q 'Připojené aplikace' "$WORK/response" && echo "  ok     připojená aplikace v Můj účet" || { echo "  CHYBA  připojené aplikace"; ERRORS=$((ERRORS+1)); }
OAUTH_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$OAUTH_CSRF" -d "odpojit_klient=$CLIENT"
expect "odpojení aplikace smaže její tokeny" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT'")" "0"

echo "== dvoufázové přihlášení (TOTP a záložní kódy)"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; UPDATE ka_uzivatele SET totp_tajemstvi = 'JBSWY3DPEHPK3PXP', totp_zalozni = '[\"$(php -r 'echo hash("sha256", "abcde-12345");')\"]' WHERE user = 'autor'"
sign_in_2fa() { # prihlas2fa <jar> → vrátí kód odpovědi na zadání druhého kroku <kod>
  local jar="$1" t; t=$(curl -s -c "$jar" -b "$jar" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$t" -d user=autor --data-urlencode "password=$PASSWORD"
  grep -q 'name="kod"' "$WORK/response" || echo "bez-druheho-kroku"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=kod -d "kod=$2"
}
expect "špatný kód z aplikace neprojde" "$(sign_in_2fa "$WORK/jar6" 000000)" "401"
TOTP_CODE=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::code("JBSWY3DPEHPK3PXP", intdiv(time(), 30));' "$ROOT")
expect "přihlášení s kódem z aplikace (TOTP)" "$(sign_in_2fa "$WORK/jar7" "$TOTP_CODE")" "302"
expect "záložní kód projde" "$(sign_in_2fa "$WORK/jar8" abcde-12345)" "302"
expect "záložní kód jde použít jen jednou" "$(sign_in_2fa "$WORK/jar9" abcde-12345)" "401"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET totp_tajemstvi = '', totp_zalozni = NULL WHERE user = 'autor'; DELETE FROM ka_kontrola_ip WHERE typ = 'login'"

echo "== vypnutá rozšíření Novinky a Formuláře a poptávky"
EXTENSIONS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna='extensions'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='statistika,presmerovani,claude' WHERE promenna='extensions'"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK"/web/storage/cache/*.txt 2>/dev/null || true
check "výpis novinek je pryč" 404 /novinky
check "novinka je pryč" 404 /novinky/vitejte-v-kalete
check "RSS je pryč" 404 /rss.xml
curl -s -o "$WORK/response" "$B/sitemap.xml"; ! grep -q "/novinky" "$WORK/response" && echo "  ok     mapa webu bez novinek" || { echo "  CHYBA  mapa webu s vypnutými novinkami"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q 'rss.xml' "$WORK/response" && echo "  ok     bez novinek ani odkaz na RSS" || { echo "  CHYBA  odkaz na RSS při vypnutých novinkách"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q 'href="[^"]*/novinky"' "$WORK/response" && echo "  ok     menu bez odkazu na novinky" || { echo "  CHYBA  menu odkazuje na vypnuté novinky"; ERRORS=$((ERRORS+1)); }
expect "odeslání formuláře nejde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/formular" -d x=1)" 404
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"; ! grep -q 'module=news"' "$WORK/response" && ! grep -q 'module=enquiries"' "$WORK/response" && echo "  ok     administrace bez novinek a poptávek" || { echo "  CHYBA  administrace ukazuje vypnutá rozšíření"; ERRORS=$((ERRORS+1)); }
mcp stavba_schema '{}' > "$WORK/response"; ! grep -q '\\"formular\\":' "$WORK/response" && ! grep -q 'seznam_novinek' <(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}') \
  && echo "  ok     builder a MCP nenabízejí prvky ani nástroje vypnutých rozšíření" || { echo "  CHYBA  schéma nebo MCP s vypnutými rozšířeními"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$EXTENSIONS' WHERE promenna='extensions'"
rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== old admin URLs of 1.3 (bookmarks, links in e-mails)"
expect "old module and action redirect to the current URL" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?modul=stranky&akce=novy")" "301 $B/admin.php?module=pages&action=new"
expect "old settings tab redirects to the current one" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?modul=config&zalozka=zalohy")" "301 $B/admin.php?module=settings&tab=backups"
check "the redirect target works" 200 "/admin.php?module=settings&tab=backups"

echo "== instalace aktualizace (testovací klíč a kanál)"
cat > "$WORK/vydani-test.php" <<'PHP'
<?php
[$site, $port] = [$argv[1], $argv[2]];
require $site . '/system/src/Core/Signature.php';
$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($pair);
file_put_contents($site . '/system/aktualizace.pub', base64_encode(sodium_crypto_sign_publickey($pair)) . " test\n");
// seznam souborů „nainstalované verze“: podle otisku .htaccess aktualizace pozná, že ho správce upravil
file_put_contents($site . '/system/soubory.json', json_encode(['verze' => '1.0.0-dev', 'soubory' => ['.htaccess' => hash_file('sha256', $site . '/.htaccess')]]));
@mkdir(dirname($site) . '/kanal');
$zip = new ZipArchive();
$zip->open(dirname($site) . '/kanal/k.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('image/test-aktualizace.txt', "nova verze\n");
$zip->addFromString('.htaccess', "# htaccess nove verze\n");
$zip->addFromString('system/bootstrap.php', (string) file_get_contents($site . '/system/bootstrap.php')); // balíček musí nést jádro
$zip->addFromString('index.php', (string) file_get_contents($site . '/index.php'));
$zip->close();
$sha = hash_file('sha256', dirname($site) . '/kanal/k.zip');
$m = ['verze' => '9.9.9', 'url' => "http://127.0.0.1:$port/k.zip", 'sha256' => $sha, 'min_php' => '8.4', 'zmeny' => ['test'],
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Signature::packageMessage('9.9.9', $sha, false), $sk))];
file_put_contents(dirname($site) . '/kanal/ok.json', json_encode($m));
file_put_contents(dirname($site) . '/kanal/zly.json', json_encode(['podpis' => base64_encode(random_bytes(64))] + $m));
PHP
# the channel on its own server: the built-in PHP server handles only one request at a time, it could not download from itself
CHANNEL_PORT=$((PORT + 1))
php "$WORK/vydani-test.php" "$WORK/web" "$CHANNEL_PORT"
(cd "$WORK/kanal" && exec php -S "127.0.0.1:$CHANNEL_PORT" > /dev/null 2>&1) & CHANNEL_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$CHANNEL_PORT/ok.json" && break; sleep 0.2; done
echo "# vlastni uprava spravce" >> "$WORK/web/.htaccess"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
update_from() { "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$1') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN"; }
update_from zly.json
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     balíček s cizím podpisem se nenainstaluje" || { echo "  CHYBA  nainstalován balíček s neplatným podpisem"; ERRORS=$((ERRORS+1)); }
update_from ok.json
[ -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     podepsaná aktualizace se nainstaluje" || { echo "  CHYBA  aktualizace se nenainstalovala"; ERRORS=$((ERRORS+1)); }
grep -q "vlastni uprava spravce" "$WORK/web/.htaccess" && [ -f "$WORK/web/.htaccess.kaleta-nova" ] && echo "  ok     vlastní .htaccess zůstal, nová verze leží vedle" || { echo "  CHYBA  aktualizace přepsala vlastní .htaccess"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_url', 'update_cache')"
kill "$CHANNEL_PID" 2>/dev/null || true

if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$WORK/web/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); fi
echo; [ "$ERRORS" -eq 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $ERRORS"; exit 1; }
