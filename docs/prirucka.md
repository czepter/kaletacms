# Příručka Kalety

Návod pro toho, kdo web spravuje: od instalace přes builder stránek až po napojení na AI.
[English version](guide.md)

## 1. Instalace a první kroky

1. Nahrajte soubory na hosting (PHP 8.4+, MySQL 8 / MariaDB 10.6+), založte prázdnou databázi a otevřete `/install.php`.
2. Ve formuláři vyberte **startovací web**: *Firemní web*, *Řemeslo a služby*, nebo *Poradenství a agentura*. Každý
   přinese vlastní styl a stránky Úvod, O nás, Služby a Kontakt z hotových sekcí s ukázkovými texty.
3. Vyberte, **co chcete mít zapnuté**: Novinky, Formuláře a poptávky, Newsletter, Statistiku, Přesměrování, jazykové
   verze, AI asistenta… Rozšíření jde kdykoli zapnout a vypnout v administraci (**Rozšíření**); vypnutím se nic nesmaže.
4. Instalace založí i skrytou kostru **Zásad ochrany osobních údajů** v jazyce webu s odkazem v patičce, v cookie liště a u souhlasu ve formuláři. Doplňte údaje v hranatých závorkách a stránku zveřejněte. Nová stránka ze šablony **Zásady ochrany osobních údajů** (Stránky → Nová stránka) se řídí tím, co má web zapnuté – poptávky, newsletter, statistika, měření a marketingové kódy, mapy – a doplní údaje o firmě. Vždy je to šablona ke kontrole, ne právní rada.
5. Po přihlášení vás na **Přehledu** provedou **První kroky**: vzhled webu, údaje o firmě, stránky, zásady a pošta.

Administrace je na adrese `/admin.php`. Role: **správce** smí všechno, **editor** spravuje obsah, **autor novinek** píše
jen své novinky a vydat je smí jen s právem vydávat. V **Uživatelé → Role** si založíte **vlastní role** – pojmenovanou
sadu sekcí (třeba „Obchodník“ jen s Poptávkami); změna role se přenese na všechny její členy.

## 2. Builder stránek

Builder otevřete v **Stránky → Builder** (nebo odkazem „Upravit zde“ na webu). Uprostřed je **plátno – skutečná stránka
webu**, vlevo panel **Přidat / Struktura**, vpravo vlastnosti vybraného prvku.

- **Vkládání:** klepněte na prvek nebo hotovou sekci v panelu Přidat, nebo je **přetáhněte na plátno** – modrá čára
  ukáže, kam dopadnou (rámeček = dovnitř kontejneru). Po najetí na hotovou sekci uvidíte její náhled.
- **Přesun:** vybraný prvek má na plátně úchyt ⠿ – přetáhněte ho jinam. Přetahovat jde i ve Struktuře. Na tabletu
  a telefonu klepněte na **Přesunout** (čtyři šipky) a pak na místo, kam prvek patří.
- **Text:** dvojklikem upravíte nadpis nebo text přímo na plátně; delší úpravy v panelu **Obsah**.
- **Styl:** panel **Styl** – rozložení, rozměry, mezery, typografie, pozadí. Nahoře přepínáte **Počítač / Tablet / Mobil**:
  hodnota pro menší obrazovku přepíše tu větší jen tam. Přepínače **Najetí** a **Stisk** nastaví vzhled při najetí myší
  (i fokusu z klávesnice) a při stisknutí – i zvlášť pro tablet a mobil. **Typografický styl** (Nadpis sekce, Perex…)
  nastaví písmo jedním výběrem; stín a rámeček poskládáte tužkou ✎ vedle pole. U mřížky je **editor mřížky**: počet
  sloupců a řádků a pojmenované oblasti. **Kopírovat styl / Vložit styl** přenese vzhled na jiný prvek.
- **Třídy:** v záložce **Pokročilé** dáte prvku třídu (např. `karta`). Styl třídy platí pro všechny prvky s touto třídou
  na celém webu – ideální pro opakovaný vzhled.
- **Zkratky:** Ctrl+Z zpět, Ctrl+Shift+Z znovu, Ctrl+D duplikovat, Ctrl+C / Ctrl+V kopírovat a vložit (i mezi stránkami),
  Delete smazat, Esc vybrat nadřazený prvek, **?** nápověda. V nápovědě spustíte i **prohlídku editoru**.
- **Náhled:** vedle přepínače zařízení zvolíte **široký monitor (1920 px)** nebo přiblížení 50–100 %.
- **Podmínky zobrazení** (Pokročilé): prvek jen od–do data (akční banner) nebo jen pro nepřihlášené či přihlášené.
- **Ukládání a publikování:** změny se průběžně ukládají jako **koncept** – návštěvníci vidí publikovanou verzi, dokud
  nestisknete **Publikovat**. **Zahodit změny** vrátí publikovanou podobu, **Verze** nabídne posledních 20 publikací.
  **Sdílet** vytvoří odkaz na koncept pro kolegu nebo klienta: otevře se bez přihlášení, platí 1–7 dní a vyhledávače ho neindexují.
  Když stránku mezitím upraví někdo jiný, editor nabídne načíst novější verzi, nebo ji přepsat. Před publikováním
  **Kontrola** upozorní na tlačítka bez odkazu, obrázky bez popisu, chybějící hlavní nadpis a slabý kontrast textu.
- **Prvky navíc:** ikona, galerie s prohlížečkou fotek, záložky, akordeon, karusel, mapa (načte se až po klepnutí),
  vyskakovací okno (otevře ho tlačítko s odkazem `#kotva-okna`, samo po čase, po odrolování nebo při odchodu),
  drobečková navigace, **počítadlo**, **ukazatele průběhu**, **hodnocení** hvězdičkami, **odpočet**, **sociální sítě**,
  **vyhledávání**, **tlačítko nahoru** a **odběr novinek**. Sekce umí **video na pozadí**, video **plakát**, navigace
  **mega menu** a obrázek pozadí **paralaxu**.

Stránku, která byla dosud jen textem, builder převede na stavbu sám. Zpět k textu ji vrátíte v nastavení stránky.

**Stránky** mají vlastní titulek a obrázek pro vyhledávače a sdílení i volbu noindex (Nastavení stránky → Vyhledávače
a sdílení). Smazaná stránka jde do **koše** a 30 dní ji jde obnovit; **Duplikovat** vytvoří skrytou kopii i se stavbou.

## 3. Vzhled webu

**Vzhled → Vzhled webu** mění celý web najednou. Tři pojmy, všude ve stejném smyslu: **startovací web** je ukázkový
obsah se stylem (vybíráte ho při instalaci) a **styl** je hotová sada barev, písem, velikostí a zaoblení. Vlastní PHP
šablony Kaleta od verze 1.6 nepoužívá – vzhled určuje jen Vzhled webu, sdílené třídy a builder.

Stránka má záložky:

- **Styl** – styl vyberete jedním klikem a v dalších záložkách ho dolaďte. Styl, ze kterého web vychází, je označený
  jako *aktuální*. Vzhled podle vaší značky nechte navrhnout Clauda – barvy a písma uloží do design systému.
- **Barvy** – hlavní, doplňková, text, pozadí, plocha. Odstíny se dopočítají samy. Blok **Čitelnost** hlídá kontrast
  podle WCAG; červeně označenou dvojici barev změňte.
- **Tmavý režim** – vypnutý (vždy světlý), podle zařízení návštěvníka, nebo vždy tmavý – s vlastní barvou textu, pozadí
  a plochy. **Přepínač pro návštěvníky** přidá do záhlaví vedle jazyků volbu světlý / tmavý / podle zařízení; volba se
  pamatuje v prohlížeči návštěvníka.
- **Písmo a velikosti** – písmo titulků a textu (i vlastní soubory WOFF2), základní písmo na telefonu a monitoru, poměr
  nadpisů, šířka obsahu a **typografické styly** (Hlavní titulek, Nadpis sekce, Perex…), které v builderu vyberete u prvku.
- **Tvary** – zaoblení tlačítek, karet, obrázků a polí formulářů.
- **Logo a ikona**.
- **Import a export** – stažení a načtení vzhledu ve formátu W3C Design Tokens (DTCG) pro Figmu nebo Tokens Studio.

Vpravo je živý náhled úvodní stránky (počítač / telefon). Nic se neukládá, dokud nestisknete Uložit vzhled.

**Koncept vzhledu.** Změna design systému, sdílené třídy nebo menu se k návštěvníkům nedostane hned: jde do jednoho
**konceptu vzhledu** – ze Vzhledu webu, z builderu, z editoru menu i od Clauda. Dokud koncept existuje, každá obrazovka
administrace ukazuje lištu s tím, co mění, a třemi tlačítky:

- **Náhled celého webu** – podepsaný odkaz, který ukáže web s konceptem vzhledu a s koncepty všech stránek a částí webu,
  pro vás i pro kolegu (platí den). Náhled ukončíte lištou nahoře.
- **Publikovat vzhled** – všechno najednou. Předchozí vzhled zůstane ve **Vzhledu webu → Import a export → Dřívější
  vzhledy** (posledních 20); **Vrátit tento vzhled** ho znovu načte do konceptu.
- **Zahodit** – platí dál publikovaný vzhled.

Úplně nová sdílená třída platí hned – nic publikovaného nemění a nové stránky ji potřebují.

## 4. Části webu: záhlaví, patička, obálky, pop-up okna

**Vzhled → Části webu.** Dokud část nepublikujete z builderu, kreslí ji vestavěná výchozí podoba.

- **Začít ze šablony:** čtyři záhlaví (logo vlevo a menu vpravo, logo uprostřed, s kontaktní lištou, minimální), čtyři
  patičky (sloupce, kompaktní, s tiráží, s výzvou k akci) a jednoduché nebo rozšířené obálky. Šablona je čistá kostra –
  barvy, písma a mezery si bere z design systému. Uloží se do konceptu části, takže web se změní až po publikování;
  zkontrolujte ji na celém webu přes **Náhled celého webu**.

- **Záhlaví a patička** jsou na každé stránce. Prvky **Logo**, **Navigace** (na telefonu se schová za tlačítko) a
  **Údaje firmy** se plní samy.
- **Obálky** (detail novinky, výpis novinek, stránka 404) přidají sekce kolem obsahu, který skládá systém. Prvek
  **Obsah stránky** označuje místo, kam systém obsah vloží.
- **Varianty:** u záhlaví a patičky zvolte **Přidat variantu**, pojmenujte ji a zaškrtněte stránky. Varianta platí jen
  tam. Prázdná varianta část skryje – hodí se pro landing page.

**Vzhled → Menu** sestaví hlavní menu a menu v patičce: stránky, vlastní odkazy, novinky a skupiny, pod každou položkou
jedno podmenu. Pořadí měníte přetažením nebo šipkami. Dokud menu neuložíte, skládá se samo ze stránek „v navigaci“.

**Vzhled → Pop-up okna** jsou okna přes stránku – přihlášení k newsletteru, materiál ke stažení za e-mail, oznamovací
lišta, sleva nebo pozvánka na akci. Začněte od hotového vzoru, obsah poskládejte v builderu a publikujte; v **Nastavení**
pak určete, kdy a kde se okno ukáže, a zapněte ho.

- **Typ:** okno uprostřed, vysouvací panel v rohu, lišta nahoře nebo dole, nebo celá obrazovka.
- **Spouštěč:** po zadaném počtu sekund, po odrolování části stránky, když se návštěvník chystá odejít, po nečinnosti,
  po zadaném počtu stránek v návštěvě – nebo jen odkazem či tlačítkem na `#popup-<adresa>` (ten okno otevře kdykoli).
- **Kde:** celý web, nebo vybrané stránky, stránky položek kolekcí a novinky; jazyková verze, období, jen telefon nebo
  počítač, návštěvníci z kampaně (`utm_*`) nebo z určitého webu.
- **Četnost:** jednou za návštěvu, jednou za N dní, dokud okno návštěvník nezavře nebo neodešle formulář v něm, nebo
  pokaždé. Pamatuje si to prohlížeč návštěvníka – bez cookies.
- **Výsledky:** zobrazení, zavření a konverze (odeslaný formulář nebo přihlášení k odběru v okně), počítané bez cookies.
  Formulář v okně jde do Poptávek jako každý jiný.

Každé okno zavře Esc i křížek, fokus se přesune do okna a po zavření se vrátí, animace respektují omezený pohyb a okno
počká, až návštěvník vyřídí lištu cookies.

## 5. Komponenty

Blok, který používáte na víc místech (karta služby, kontaktní pruh), uložte jako **komponentu**: vyberte ho v builderu
a v hlavičce panelu klepněte na ikonu „Uložit jako komponentu“. Úpravy ve **Vzhled → Komponenty** se projeví všude.

Co se má u každého použití lišit, nastavte jako **vlastnosti** (např. Nadpis, Odkaz) a v komponentě je vložte značkou
`{{nadpis}}`. U použití pak v panelu Obsah vyplníte hodnoty; prázdné pole = výchozí hodnota.

## 6. Kolekce

**Obsah → Kolekce** jsou seznamy podobných věcí s vlastními poli – reference, tým, produkty, pobočky, ceník.

1. Založte kolekci a pole (krátký text, delší text, formátovaný text, obrázek, odkaz, číslo, datum).
2. Přidejte položky.
3. V builderu vložte prvek **Výpis kolekce**. Jeho vnitřek je vzor jedné karty – do textů, obrázků a odkazů vložte
   značky `{{nazev}}`, `{{url}}` (stránka položky) nebo vlastní pole. Nápověda se značkami se ukáže v panelu Obsah.

Výpis umí **řazení** (i podle pole, např. ceny), **pevný filtr**, **tlačítka filtru** pro návštěvníky a **stránkování**.
Když u kolekce zapnete **stránky položek**, každá položka má adresu `/kolekce/polozka`. Vzhled té stránky navrhnete
v builderu přes **Šablona detailu**.

Na webu s **jazykovými verzemi** dejte každé položce jazyk. Překlad položky má stejnou adresu, např. `/compare/wordpress`
a `/de/compare/wordpress`; přepínač jazyků a `hreflang` je propojí. Každý další jazyk má vlastní **Šablonu detailu (DE)**.
Začíná jako kopie výchozí šablony a dokud ji nepublikujete, položky v tom jazyce používají výchozí. Drobečky vedou na
překlad stránky s adresou kolekce (u `/compare` třeba `/de/vergleich`).

**Stránky položek jsou plnohodnotné stránky (1.9).** Každá položka má jako stránka **Vyhledávače a sdílení**: vlastní
titulek pro vyhledávače, popis, obrázek pro sdílení a *Skrýt před vyhledávači (noindex)* – taková položka zůstane
dostupná, ale vynechá se z vyhledávačů, mapy webu, `llms.txt` i hledání na webu. Bez nich se použije název, začátek
prvního delšího textu a první obrázek. Skrytá položka se umí **sama zveřejnit** v nastavený čas a každé uložení nechá
předchozí verzi v **Historii položky** (posledních 20), jedním kliknutím ji vrátíte.

**Strukturovaná data pro vyhledávače:** v nastavení kolekce řekněte, co položky jsou – služba, osoba, produkt, událost
nebo otázka s odpovědí – a která pole plní vlastnosti (cena, pracovní pozice, začátek…). Stránky položek pak ponesou
data schema.org vedle údajů o firmě. Nabídka potřebuje pole s cenou a měnu.

## 7. Formuláře a poptávky

Prvek **Formulář** (nebo hotová sekce *Poptávkový formulář*) vloží poptávkový formulář. V panelu Obsah nastavíte pole
(text, e-mail, telefon, výběr, přepínače, datum, číslo, souhlas), text tlačítka, poděkování nebo děkovnou stránku,
potvrzení odesílateli a e-mail pro upozornění. Proti spamu chrání skrytá pole a limit odeslání bez CAPTCHA a cookies.
Novou poptávku umí web poslat i do CRM nebo Make/Zapier (Nastavení → Webhooky → Webhook nové poptávky); měření
konverzí dostane událost `kaleta:odeslano` (a záznam do `dataLayer`).

**Nastavení → Webhooky** drží obě adresy webhooků (nová poptávka, vydaná novinka). Volání odchází až po odeslání stránky,
takže pomalý příjemce návštěvníka nikdy nezdrží, a každé je podepsané: hlavičky `X-Kaleta-Timestamp` a
`X-Kaleta-Signature` (`sha256=` HMAC-SHA256 z `časová značka.tělo` s tajným klíčem z této záložky) příjemci ověří, že
volání přišlo z vašeho webu. **Protokol doručení** ukazuje každé volání; nepovedené se zkusí znovu po 1, 5 a 30 minutách
a po 2 a 12 hodinách a vzdané jde jedním kliknutím poslat znovu. **Poslat zkušební volání** ověří spojení. Adresy webhooků
ani tajný klíč nejsou přes napojení na Clauda dostupné.

Odeslané zprávy jsou v **Obsah → Poptávky**: stav (nová, přečtená, vyřízená), odpověď e-mailem, export do CSV. Je-li formulář na
stránce, na kterou vede reklama nebo newsletter, poptávka ukáže i **kampaň** z adresy (`utm_source`, `utm_medium`, `utm_campaign`…) –
v administraci, v e-mailu s upozorněním, v CSV i ve webhooku, bez cookies.
Poptávky obsahují osobní údaje, proto se po nastaveném počtu měsíců (výchozí 24) samy mažou.

Rozšíření **Newsletter** přidá prvek **Odběr novinek**: návštěvník zadá e-mail a potvrdí ho odkazem (double opt-in).
Potvrzené adresy najdete v **Obsah → Odběratelé** a vyexportujete je do CSV i s odkazem na odhlášení pro váš
rozesílací nástroj.

Nebo v **Rozšíření → Newsletter** napojte mailingovou službu, kterou už používáte: Brevo, MailerLite, Mailchimp, Ecomail,
SmartEmailing nebo jakoukoli jinou přes webhook (Make, Zapier, n8n). Zadejte klíč API a seznam; každý potvrzený odběratel
se pak přidá do seznamu a odhlášený se z něj odebere. Přenos běží na pozadí a nepovedené pokusy se opakují; v
**Odběratelích** vidíte stav každé adresy a stávající odběratele pošlete do služby jedním tlačítkem.

**Obsah → Newslettery** pošlou potvrzeným odběratelům novinky přímo z webu. E-mailový builder tu není: jedna šablona
se řídí vaším design systémem – barvami, písmy, zaoblením a logem – a obsahuje předmět, text náhledu, úvod, nejnovější
(nebo vybrané) novinky, nepovinné tlačítko a údaje o firmě s odkazem na odhlášení. Uložte koncept, prohlédněte si náhled,
pošlete si zkoušku a pak newsletter odešlete hned nebo v nastavený čas (odeslání vyžaduje právo vydávat). Nejdřív je
potřeba nastavit dvě věci a obrazovka vám řekne, když chybějí:

- **SMTP server** v **Nastavení → Pošta** – Brevo, Amazon SES, Mailgun nebo vlastní schránku; hromadná pošta přes
  funkci `mail()` hostingu by skončila ve spamu. **Newslettery: e-mailů za hodinu** nastavte podle limitu své služby;
- **cron**, který každých pár minut volá adresu úloh z **Nastavení → Stav systému**: e-maily odcházejí po dávkách při
  každém volání, takže se newsletter nezasekne ani na málo navštěvovaném webu.

Každý e-mail nese vlastní odkaz na odhlášení a odhlášení jedním klikem v poštovním programu. Nic nesleduje otevření;
odkazy na váš web nesou parametry `utm`, takže **Statistika** ukáže návštěvy, které newsletter přivedl. Seznam příjemců
se drží jen po dobu odesílání – potom zůstanou jen počty a data. Koncept newsletteru a zkoušku umí i Claude; odběratelům
ho pošle jen na váš pokyn.

## 8. Údaje o firmě

**Nastavení → Firma:** obchodní firma, druh podniku, IČO, DIČ, adresa, telefon, **otevírací doba** (po řádcích, např.
„Po–Pá 8:00–17:00“, „So 9–12“, „Ne zavřeno“), odkaz na mapu a souřadnice. Prvek **Údaje firmy** je vypíše kdekoli
na webu a vyhledávače je dostanou jako strukturovaná data – Google tak ukáže otevírací dobu i adresu.
Pro **tiráž** (právní informace o provozovateli, v Německu Impressum) vyplňte zápis v rejstříku a kdo firmu zastupuje
a založte stránku podle šablony **Tiráž** (Stránky → Nová stránka) – prvek Údaje firmy v režimu *Tiráž* vypíše všechny
vyplněné údaje o provozovateli.

## 9. Novinky, SEO a jazyky

**Novinky** jsou blog firmy: kategorie, štítky, plánované vydání, koš, historie verzí. SEO se stará samo (mapa webu,
kanonické adresy, strukturovaná data, `llms.txt` pro AI vyhledávače). Další jazykové verze (`/de/…`) zapnete v
**Rozšíření → Jazykové verze webu** a jazyky vyberete v Nastavení – na výběr je kolem čtyřiceti jazyků včetně všech
jazyků EU. Texty pro návštěvníky (Hledat, Číst dál, formuláře, cookie lišta) jsou přeložené do češtiny, angličtiny,
němčiny, francouzštiny, španělštiny, italštiny, polštiny a slovenštiny; ostatní jazyky je mají anglicky, datum ve svém
tvaru. Jazyk webu zvolíte už při instalaci, nezávisle na jazyku administrace.

Systémové adresy se řídí jazykem: česká verze má `/novinky` a `/hledani`, všechny ostatní `/news`, `/news/category/…`,
`/news/tag/…` a `/search`. Druhá podoba natrvalo přesměruje, takže staré odkazy i pozice ve vyhledávačích zůstanou.

**Překlad s Claudem:** požádejte Clauda o překlad stránky nebo celého webu. Přes napojení Clauda začíná překlad jako kopie
stavby původní stránky, takže rozložení zůstane stejné a mění se jen texty a odkazy. Záhlaví a patička nového jazyka
začínají jako kopie těch ve výchozím jazyce.
Jazyk, jehož úvodní stránka ještě nemá zveřejněný překlad, se návštěvníkům nenabízí: chybí v přepínači jazyků, `hreflang`
i v mapě webu, dokud jeho úvod nezveřejníte. Překlad tak můžete v klidu připravit.

Při první návštěvě web pošle návštěvníka, jehož prohlížeč dává přednost některému z jazyků webu, na tu verzi stránky (když
má překlad); jeho volbu v přepínači jazyků si pamatuje prohlížeč, bez cookies. Roboty vyhledávačů web nepřesměrovává.
Přepínač je výchozí vedle menu; do patičky ho přesunete prvkem **Přepínač jazyků** (rozbalovací nabídka, otevírá se nahoru)
a u prvku Navigace vypnete volbu *Přepínač jazyků*.

## 10. AI asistent a Claude

**Asistent** zapnete v **Rozšíření**. Vyberte poskytovatele (Anthropic Claude, OpenAI, Google Gemini, Mistral), vložte
klíč API a model. Asistent jen navrhuje. Text odchází k poskytovateli, jen když klepnete na jeho tlačítko.

- V builderu: **✨ Vytvořit sekci s AI** (popište, co má sekce obsahovat) a **Přepsat s AI** u textu prvku (kratší,
  delší, formálněji, přátelštěji, opravit chyby). Výsledek zkontrolujte, fakta doplňte sami. Ctrl+Z změnu vrátí.
- V novinkách: titulky, perex, SEO popis, štítky, korektura, popisy obrázků a překlad do jiného jazyka webu.

**Napojení na Claude (MCP)** zapnete v Rozšířeních. Pak máte dvě možnosti:

- **Konektor v aplikaci Claude (doporučeno):** v Nastavení → Konektory přidejte vlastní konektor s adresou
  `https://vas-web.cz/mcp`. Claude vás pošle na web přihlásit a potvrdit přístup (OAuth), nic nekopírujete. Připojené
  aplikace uvidíte a odpojíte v **Můj účet**. Funguje, když web běží přes HTTPS v kořeni domény.
- **Přístupový token** (Claude Code, jiné nástroje): v **Můj účet** si vytvořte token a spusťte

```bash
claude mcp add --transport http kaleta https://vas-web.cz/mcp --header "Authorization: Bearer <token>"
```

Claude Code umí i přihlášení přes OAuth: stačí `claude mcp add --transport http kaleta https://vas-web.cz/mcp` bez
hlavičky a při prvním použití potvrdit přístup v prohlížeči.

Claude pak s právy vašeho účtu postaví celý web: nastaví vzhled (barvy, písma, sdílené třídy), nahraje obrázky a písma
do Médií, poskládá stránky, záhlaví, patičku a stránku položky kolekce, opraví jednotlivé prvky podle id, nastaví název webu, údaje firmy
a přesměrování starých adres a píše novinky. Umí také založit **variantu** záhlaví a patičky pro vybrané stránky, nastavit stránce
nadřazenou stránku, jazykovou verzi a plánované zveřejnění, vrátit do konceptu starší publikovanou stavbu a číst poptávky
(jen s právem k Poptávkám). Po každé úpravě stavby dostane **podepsaný odkaz na náhled** konceptu
(platí 60 minut, otevře ho i bez přihlášení) – zkontroluje si výsledek a odkaz může poslat i vám. S každým uložením dostane i **kontrolu před publikováním** (tlačítka bez odkazu, obrázky bez popisu,
osnova nadpisů), aby je mohl rovnou opravit. Stavby stránek
a částí webu i novinky vznikají jako **koncept** a publikuje je jen na váš pokyn (a jen s právem vydávat). Rovnou
na webu se projeví: menu, vzhled (design systém), sdílené třídy, nastavení webu, přesměrování, položky
kolekce a text stránky – předchozí text stránky přitom jde do historie verzí. Stránku umí Claude přesunout do koše
(obnovíte ji 30 dní v administraci); e-mail webu, webhooky, poštu, zálohy ani zabezpečení přes napojení nezmění.

## 11. Přechod z WordPressu nebo z jiného webu na Kaletě

**Správa → Import a export → WordPress:** nahrajte export z WordPressu (Nástroje → Export, soubor XML). Import převede
příspěvky na novinky, stránky volitelně **rovnou do builderu**, stáhne obrázky do Médií a založí přesměrování ze starých
adres. Import jde spustit znovu – co už převedl, přeskočí.

**Přesun webu na Kaletě** k jinému hostingu nebo nový web ze startovací sady agentury: na starém webu vytvořte **Import
a export → Export celého webu**, na novém hostingu nainstalujte Kaletu a v instalátoru zvolte **Začít z exportu**.
Přihlaste se, otevřete **Import a export → Import z Kalety** a nahrajte `.zip` (větší přes FTP do `storage/import/`).
Náhled ukáže, co export obsahuje; po potvrzení se vytvoří záloha databáze a import proběhne sám po dávkách – stránky,
novinky, kolekce, komponenty, sdílené třídy, části webu, menu, pop-upy, přesměrování, knihovna médií i její soubory, název
webu, údaje o firmě, jazyky a vzhled. Každý záznam si nechá své číslo, takže odkazy mezi stránkami, menu a komponentami
platí dál, a každá stavba projde stejnou kontrolou jako uložení v builderu. Uživatelské účty, hesla, klíče, tokeny ani
nastavení pošty a záloh v exportu nikdy nejsou, nový web si nechá své; naimportované novinky budou vaše. Import jde jen do
prázdného webu – čerstvé instalace, případně se startovacím webem.

Přenést jde i **jedna stránka**: **Stránky → Exportovat** stáhne JSON se stavbou stránky, sdílenými třídami, které používá,
a jejími komponentami (i komponentami v komponentách). **Stránky → Importovat** na jiném webu založí stránku skrytou,
doplní chybějící třídy (třída, kterou web už má, si nechá svůj vzhled) a komponenty a dřív naimportovanou komponentu použije znovu.

## 12. Audit webu a Stav systému

**Správa → Audit webu** projde celý web a vypíše, co opravit, u každé věci s odkazem, kde ji opravíte: odkazy na stránky,
které neexistují (ve stránkách, částech webu, šablonách, komponentách, pop-up oknech, menu, položkách a novinkách), a
nefunkční externí odkazy nalezené na pozadí, stránky a stránky položek bez popisu, stejné titulky, položky menu vedoucí
na skryté nebo smazané stránky, kontroly builderu (tlačítka bez odkazu, obrázky bez popisu, osnova nadpisů) a adresy,
které často končí „stránka nenalezena“ a nemají přesměrování. Stejný audit spustí Claude nástrojem `site_audit` a nalezené
opraví.

**Nastavení → Stav systému → Před Kaletou 2.0** vypíše, co verze 2.0 odstraní a tento web ještě používá: veřejné API,
nastavení uložená pod starými názvy, starý prvek Vyskakovací okno ve stránce a staré názvy tříd nebo pomocných funkcí ve
vlastním PHP kódu. Kaleta 1.9 vám na změnu dává celé jedno vydání.

## 13. Zálohy, aktualizace, export

**Nastavení → Zálohy a aktualizace:** podepsané aktualizace a automatické zálohy databáze – každý den, kdy se na webu něco
změnilo, jinak jednou týdně; uchovává se posledních 10. **Kopie mimo server** nahrají každou zálohu na server FTPS nebo do
úložiště S3 (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2) a na stejné místo kopírují i složku `media/` – jen nové a
změněné soubory, na pozadí, takže se tam postupně dostane i velká knihovna médií. Záložka ukazuje, jestli je kopie kompletní.

**Obnova webu po ztrátě hostingu:** nainstalujte Kaletu na nový hosting se stejnou předponou tabulek, nahrajte poslední
zálohu databáze přes FTP do `storage/zalohy/`, zkopírujte zpět složku `media/` z kopie na FTPS nebo S3 a pak v Nastavení →
Zálohy a aktualizace u té zálohy klikněte na **Obnovit** a přihlaste se účty ze zálohy.

**Import a export → Export celého webu** vytvoří otevřený balík s obsahem a médii – pro přesun (viz výše) nebo abyste
měli obsah i mimo Kaletu. Poptávky, odběratelé a účty se neexportují.

**Veřejné čtecí API** (`/api/novinky`, rozšíření *Veřejné API*) je od 1.8 zastaralé a v Kaletě 2.0 skončí; jeho odpovědi
nesou hlavičku `Deprecation`. Pro novinky použijte JSON Feed (`/feed.json`) nebo RSS, pro práci s webem napojení na Clauda
(MCP), pro události webhooky a pro odnesení obsahu export webu.
