<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Antispam;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Poptávkový / kontaktní formulář. Odesílá se na /formular (Front\Formulare): server vezme pole z publikované stavby
 * (ne z prohlížeče), ověří je, uloží poptávku (Administrace → Poptávky) a pošle upozornění e-mailem.
 * Ochrana bez cookies a CAPTCHA (Core\Antispam), takže stránka s formulářem zůstává v cache.
 */
final class Form extends Element
{
    public const string TYPE = 'formular';
    public const string EXTENSION = 'poptavky';
    public const string NAME = 'Formulář';
    public const string DESCRIPTION = 'Poptávka nebo dotaz – odeslané zprávy najdete v Poptávkách a přijdou i e-mailem.';
    public const string ICON = 'formular';
    public const string GROUP = 'Dynamické';
    public const array HTML_TAGS = ['form'];

    /** Typy polí formuláře. */
    public const array FIELD_TYPES = ['text' => 'text', 'email' => 'e-mail', 'tel' => 'telefon', 'textarea' => 'delší text', 'vyber' => 'výběr ze seznamu',
        'volba' => 'volba jedné možnosti (přepínače)', 'datum' => 'datum', 'cislo' => 'číslo', 'soubor' => 'příloha (soubor)', 'souhlas' => 'zaškrtnutí (souhlas)'];

    /** Přílohy formuláře: povolené typy a největší velikost jednoho souboru. */
    /** Telefon v atributu pattern (prohlížeč ho čte s příznakem v – závorky, lomítko a pomlčka ve třídě musí být escapované). */
    public const string PHONE_PATTERN = '[+\\(\\)\\d\\s\\/.\\-]{6,30}';

    public const array ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'zip', 'dwg', 'dxf'];
    public const int MAX_ATTACHMENT = 10 * 1024 * 1024;

    public static function properties(): array
    {
        return [
            'nazev' => ['typ' => 'text', 'popisek' => 'Název formuláře (v Poptávkách a v e-mailu)', 'vychozi' => t('Poptávka'), 'max' => 120],
            'pole' => ['typ' => 'polozky', 'popisek' => 'Pole formuláře', 'max' => 20, 'pole' => [
                'popisek' => ['typ' => 'text', 'popisek' => 'Popisek', 'vychozi' => '', 'max' => 200],
                'typ' => ['typ' => 'vyber', 'popisek' => 'Typ', 'vychozi' => 'text', 'moznosti' => self::FIELD_TYPES],
                'povinne' => ['typ' => 'prepinac', 'popisek' => 'Povinné', 'vychozi' => false],
                'moznosti' => ['typ' => 'radky', 'popisek' => 'Možnosti výběru (každá na řádek)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'vyber']],
                'moznosti_volby' => ['typ' => 'radky', 'popisek' => 'Možnosti (každá na řádek)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'volba']],
            ], 'vychozi' => [
                ['popisek' => t('Jméno'), 'typ' => 'text', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('E-mail'), 'typ' => 'email', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Telefon'), 'typ' => 'tel', 'povinne' => false, 'moznosti' => ''],
                ['popisek' => t('Co pro vás můžeme udělat?'), 'typ' => 'textarea', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Souhlasím se zpracováním osobních údajů za účelem vyřízení poptávky.'), 'typ' => 'souhlas', 'povinne' => true, 'moznosti' => ''],
            ]],
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Text tlačítka', 'vychozi' => t('Odeslat poptávku'), 'max' => 80],
            'dekujeme' => ['typ' => 'text', 'popisek' => 'Poděkování po odeslání', 'vychozi' => t('Děkujeme, zprávu jsme dostali. Ozveme se vám co nejdřív.'), 'max' => 400],
            'prijemce' => ['typ' => 'text', 'popisek' => 'E-mail pro upozornění (prázdné = e-mail webu z Nastavení)', 'vychozi' => '', 'max' => 190],
            'dekovna' => ['typ' => 'odkaz', 'popisek' => 'Po odeslání přejít na stránku (prázdné = poděkování na místě formuláře)', 'vychozi' => ''],
            'potvrzeni' => ['typ' => 'prepinac', 'popisek' => 'Poslat odesílateli potvrzení e-mailem (jen poděkování, bez obsahu zprávy)', 'vychozi' => false],
        ];
    }

    public static function baseCss(): string
    {
        // kotva po odeslání míří na formulář: odstup, aby nad ním byl vidět i nadpis a nezakrylo ho přilepené záhlaví
        return '.ka-formular { display: grid; gap: var(--ka-mezera-s); }
.ka-formular, .ka-formular-hotovo { scroll-margin-top: 6rem; }
.ka-pole { display: grid; gap: var(--ka-mezera-2xs); margin: 0; }
.ka-pole > label { font-weight: 600; }
.ka-pole input:not([type="checkbox"]), .ka-pole select, .ka-pole textarea { box-sizing: border-box; width: 100%; padding: 0.7em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font: inherit; }
.ka-pole textarea { min-height: 8em; resize: vertical; }
.ka-pole :focus-visible { outline: 2px solid var(--ka-barva-primarni); outline-offset: 1px; }
.ka-pole-souhlas label { display: flex; gap: var(--ka-mezera-xs); align-items: flex-start; }
.ka-pole-souhlas input { margin-block-start: 0.3em; accent-color: var(--ka-barva-primarni); }
.ka-pole-zasady { display: inline-block; margin-inline-start: 1.6em; font-size: var(--ka-krok--1); }
.ka-povinne { color: var(--ka-barva-primarni); }
.ka-pole fieldset { display: grid; gap: var(--ka-mezera-2xs); margin: 0; padding: 0; border: 0; }
.ka-pole legend { margin-block-end: var(--ka-mezera-2xs); padding: 0; font-weight: 600; }
.ka-pole fieldset label { display: flex; gap: var(--ka-mezera-xs); align-items: center; font-weight: 400; }
.ka-pole fieldset input { accent-color: var(--ka-barva-primarni); }
.ka-pole [aria-invalid="true"] { border-color: #c4281c !important; }
.ka-pole-napoveda { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-pole-chyba { color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); font-size: var(--ka-krok--1); }
.ka-formular-hotovo, .ka-formular-chyba { margin: 0; padding: var(--ka-mezera-m); border-radius: var(--ka-zaobleni); }
.ka-formular-hotovo { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-text); }
.ka-formular-chyba { background: color-mix(in oklch, #c4281c 12%, var(--ka-barva-pozadi)); color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); }';
    }

    /** Kotva formuláře (kam se po odeslání vrátí stránka): stejná jako id, které formulář dostane při vykreslení. */
    public static function anchor(array $p): string
    {
        return $p['kotva'] ?? (!empty($p['styl']) ? 's-' . $p['id'] : 'formular-' . $p['id']);
    }

    /** Hlášení po odeslání podle kódu v adrese (?formular=<id>&vysledek=<kód>) – text nikdy nejde z adresy. */
    public static function messages(string $code): string
    {
        return match ($code) {
            'pole' => t('Zkontrolujte prosím označené pole.'),
            'limit' => t('Z vaší adresy přišlo v krátké době příliš mnoho zpráv. Zkuste to prosím později.'),
            'rychle' => t('Formulář odešel dřív, než jsme stihli ověřit, že ho posílá člověk. Počkejte prosím chvilku a odešlete ho znovu.'),
            'overeni' => t('Formulář se nepodařilo ověřit. Obnovte stránku a zkuste to znovu.'),
            default => t('Zprávu se nepodařilo odeslat. Zkuste to prosím znovu.'),
        };
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $r = $k->app->request;
        $result = $r->get('formular') === $p['id'] ? $r->get('vysledek') : '';
        $id = str_contains($a, ' id="') ? '' : ' id="' . e(self::anchor($p)) . '"';
        if ($result === 'ok') {
            // data-odeslano: image/web.js ohlásí konverzi (událost kaleta:odeslano a dataLayer, když na webu je)
            return '<div' . Text::withClass($a, 'ka-formular-hotovo') . $id . ' role="status" data-odeslano="' . e($o['nazev']) . '"><p>' . e($o['dekujeme']) . '</p></div>';
        }
        $k->types['tlacitko'] = true; // tlačítko formuláře vypadá jako prvek Tlačítko
        $html = $result !== '' ? '<p class="ka-formular-chyba" role="alert">' . e(self::messages($result)) . '</p>' : '';
        $invalid = $result === 'pole' ? $r->getInt('pole', -1) : -1;
        foreach ($o['pole'] as $i => $field) {
            $html .= self::fields($field, $i, $p['id'], $i === $invalid, $k->app->settings()->get('cookies_zasady_url'));
        }
        $antispam = new Antispam($k->app->db(), $k->app->settings());

        // data-formular: po chybě image/web.js vrátí do polí, co návštěvník vyplnil (drží to jen jeho prohlížeč)
        $files = in_array('soubor', array_column($o['pole'], 'typ'), true) ? ' enctype="multipart/form-data"' : '';

        return '<form' . Text::withClass($a, 'ka-formular') . $id . ' method="post" action="' . e($k->url('formular')) . '"' . $files . ' data-formular="' . e($p['id']) . '"' . ($result !== '' ? ' data-obnovit' : '') . '>'
            . '<input type="hidden" name="zdroj" value="' . e($k->source) . '"><input type="hidden" name="prvek" value="' . e($p['id']) . '">'
            . '<input type="hidden" name="zpet" value="' . e($k->app->url($r->path())) . '">'
            . $antispam->fields('formular|' . $k->source . '|' . $p['id'])
            . $html
            . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e($o['tlacitko']) . '</button></p></form>';
    }

    private static function fields(array $field, int $i, string $element, bool $error = false, string $privacyPolicy = ''): string
    {
        $id = 'f-' . $element . '-' . $i;
        $displayName = 'p' . $i;
        $required = $field['povinne'] ? ' required' : '';
        $star = $field['povinne'] ? ' <span class="ka-povinne" aria-hidden="true">*</span>' : '';
        $labelText = e($field['popisek']);
        // pole, které server odmítl: označené a s hláškou, na kterou odkazuje aria-describedby
        $marking = $error ? ' aria-invalid="true" aria-describedby="' . $id . '-chyba" autofocus' : '';
        $message = $error ? '<span class="ka-pole-chyba" id="' . $id . '-chyba">' . e($field['typ'] === 'email' ? t('Zadejte platnou e-mailovou adresu.') : t('Toto pole je potřeba vyplnit správně.')) . '</span>' : '';
        if ($field['typ'] === 'souhlas') {
            $link = $privacyPolicy !== '' ? ' <a class="ka-pole-zasady" href="' . e($privacyPolicy) . '" target="_blank">' . e(t('Zásady ochrany osobních údajů')) . '</a>' : '';

            return '<p class="ka-pole ka-pole-souhlas"><label><input type="checkbox" name="' . $displayName . '" value="1"' . $required . $marking . '> <span>' . $labelText . $star . '</span></label>' . $link . $message . '</p>';
        }
        if ($field['typ'] === 'volba') {
            $options = '';
            foreach (self::options($field) as $j => $m) {
                $options .= '<label><input type="radio" name="' . $displayName . '" value="' . e($m) . '"' . ($j === 0 ? $required . $marking : '') . '> ' . e($m) . '</label>';
            }

            return '<div class="ka-pole"><fieldset><legend>' . $labelText . $star . '</legend>' . $options . '</fieldset>' . $message . '</div>';
        }
        $label = '<label for="' . $id . '">' . $labelText . $star . '</label>';
        $input = match ($field['typ']) {
            'textarea' => '<textarea id="' . $id . '" name="' . $displayName . '" maxlength="5000"' . $required . $marking . '></textarea>',
            'vyber' => '<select id="' . $id . '" name="' . $displayName . '"' . $required . $marking . '><option value="">' . e(t('— vyberte —')) . '</option>'
                . implode('', array_map(fn (string $m): string => '<option>' . e($m) . '</option>', self::options($field))) . '</select>',
            'datum' => '<input id="' . $id . '" name="' . $displayName . '" type="date"' . $required . $marking . '>',
            'cislo' => '<input id="' . $id . '" name="' . $displayName . '" type="number" step="any" inputmode="decimal"' . $required . $marking . '>',
            'soubor' => '<input id="' . $id . '" name="' . $displayName . '" type="file" accept=".' . implode(',.', self::ATTACHMENT_EXTENSIONS) . '"' . $required . $marking . '>'
                . '<small class="ka-pole-napoveda">' . e(t('Nejvýš %d MB: PDF, obrázek, dokument nebo ZIP.', (int) (self::MAX_ATTACHMENT / 1048576))) . '</small>',
            // telefon: stejné pravidlo jako na serveru (Front\Formulare), prohlížeč ho zkontroluje hned; vzor platí i s příznakem v
            'tel' => '<input id="' . $id . '" name="' . $displayName . '" type="tel" autocomplete="tel" maxlength="30" pattern="' . self::PHONE_PATTERN . '" title="' . e(t('Telefonní číslo, například +420 123 456 789.')) . '"' . $required . $marking . '>',
            default => '<input id="' . $id . '" name="' . $displayName . '" type="' . ($field['typ'] === 'email' ? 'email" autocomplete="email' : 'text' . self::autocomplete($field['popisek'])) . '" maxlength="300"' . $required . $marking . '>',
        };

        return '<p class="ka-pole">' . $label . $input . $message . '</p>';
    }

    /**
     * Automatické vyplnění textového pole podle popisku (WCAG 1.3.5): jméno a firma. Typ pole zůstává „text“,
     * aby fungovaly i dříve postavené formuláře.
     */
    private static function autocomplete(string $labelText): string
    {
        return match (true) {
            (bool) preg_match('/^(vaše |celé |your |full )?(jméno|name)\b/iu', trim($labelText)) => '" autocomplete="name',
            (bool) preg_match('/^(firma|společnost|název firmy|company|organi[sz]ation)\b/iu', trim($labelText)) => '" autocomplete="organization',
            default => '',
        };
    }

    /** @return list<string> možnosti výběru nebo přepínačů */
    public static function options(array $field): array
    {
        $text = ($field['typ'] ?? '') === 'volba' ? (string) ($field['moznosti_volby'] ?? '') : (string) $field['moznosti'];

        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $m): bool => $m !== ''));
    }
}
