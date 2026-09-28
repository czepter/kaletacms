<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Extensions - optional parts of the system that the administrator turns on and off in Settings.
 *
 * The system is closed on purpose: all extensions are part of the package and are made in the Kaleta project.
 * Third-party plug-ins are not installed. A disabled extension disappears from the administration menu and from the site, the data stays.
 */
final class Extensions
{
    /** key => [name, description, enabled by default] */
    public const array CATALOG = [
        'novinky' => ['Novinky', 'Aktuality a blog: výpis /novinky s kategoriemi a štítky, RSS, prvek Novinky v builderu a odkaz v automatickém menu.', true],
        'poptavky' => ['Formuláře a poptávky', 'Prvek Formulář v builderu a schránka Poptávky: odeslané dotazy se uloží, přijdou e-mailem a jdou předat kolegovi nebo do CRM.', true],
        'newsletter' => ['Newsletter', 'Prvek Odběr novinek v builderu: návštěvník zadá e-mail a potvrdí ho odkazem (double opt-in). Potvrzené odběratele web pošle do vaší mailingové služby (Brevo, MailerLite, Mailchimp, Ecomail, SmartEmailing, webhook), nebo je vyexportujete do CSV.', false],
        'statistika' => ['Statistika', 'Vlastní měření návštěvnosti bez cookies.', true],
        'presmerovani' => ['Přesměrování', 'Správa přesměrování 301 ze starých adres – po přechodu z jiného webu nezbytné.', true],
        'jazyky' => ['Jazykové verze webu', 'Web ve více jazycích: každá další verze (/en/…) má své stránky, kategorie a novinky, přepínač jazyků a značky hreflang. Jazyky vyberete v Nastavení → Základní.', false],
        'api' => ['Veřejné API', 'Čtecí JSON API pro jiný web nebo aplikaci: /api/novinky, /api/novinky/<adresa>, /api/kategorie, /api/stranky.', false],
        'asistent' => ['AI asistent', 'V builderu nové sekce podle popisu a přepisy textů, v novinkách titulky, perex, SEO popis, štítky, korektura, popisy obrázků a překlad. Potřebuje vlastní klíč Claude, OpenAI, Google nebo Mistral (níže); text se posílá jen po kliknutí na tlačítko asistenta.', false],
        'claude' => ['Napojení na Claude', 'MCP server na adrese /mcp: Claude s právy vašeho účtu staví stránky v builderu, upravuje záhlaví, patičku, kolekce a vzhled a píše novinky. Stránky a novinky ukládá jako koncepty, které zveřejníte vy; menu, vzhled a nastavení platí hned. Přístupový token si každý vytvoří v nabídce Můj účet.', false],
    ];

    /** @return list<string> */
    public static function enabled(Settings $settings): array
    {
        $stored = $settings->get('extensions');
        if ($stored === '') {
            return array_keys(array_filter(self::CATALOG, fn (array $r): bool => $r[2]));
        }

        return array_values(array_intersect(explode(',', $stored), array_keys(self::CATALOG)));
    }

    public static function isEnabled(Settings $settings, string $key): bool
    {
        return $key === '' || in_array($key, self::enabled($settings), true);
    }

    /** @param list<string> $keys */
    public static function save(Settings $settings, array $keys): void
    {
        $keys = array_values(array_intersect($keys, array_keys(self::CATALOG)));
        // an empty string means "default state", so an empty selection is saved as "-"
        $settings->set('extensions', $keys === [] ? '-' : implode(',', $keys));
    }
}
