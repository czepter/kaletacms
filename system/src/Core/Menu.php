<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Menu webu (Vzhled → Menu): hlavní navigace a menu v patičce, zvlášť pro každou jazykovou verzi.
 * Položky: stránka (s vlastním textem nebo názvem stránky), vlastní odkaz, novinky, skupina (jen text) –
 * a pod každou z nich jedna úroveň podmenu. Dokud hlavní menu nikdo neuloží, skládá se samo ze stránek „v menu“.
 */
final class Menu
{
    /** @var array<string, string> umístění => popisek */
    public const array LOCATIONS = ['hlavni' => 'Hlavní menu', 'paticka' => 'Menu v patičce'];

    public const array TYPES = ['stranka', 'odkaz', 'novinky', 'skupina'];

    public const int MAX_ITEMS = 80;

    /** @return list<array<string, mixed>>|null uložené položky, null = menu se skládá automaticky */
    public static function load(Db $db, string $location, string $language): ?array
    {
        $json = $db->value('SELECT polozky FROM {menu} WHERE umisteni = ? AND jazyk = ?', [$location, $language]);

        return $json === null ? null : self::sanitize(json_decode((string) $json, true));
    }

    /** Uloží položky; null vrátí menu do automatického režimu. */
    public static function save(Db $db, string $location, string $language, ?array $items): void
    {
        if ($items === null) {
            $db->run('DELETE FROM {menu} WHERE umisteni = ? AND jazyk = ?', [$location, $language]);

            return;
        }
        $db->run('INSERT INTO {menu} (umisteni, jazyk, polozky, zmeneno) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE polozky = VALUES(polozky), zmeneno = NOW()',
            [$location, $language, (string) json_encode(self::sanitize($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * Jediný validátor položek (administrace i MCP): známé typy, platné adresy, nejvýš jedna úroveň podmenu.
     *
     * @return list<array<string, mixed>>
     */
    public static function sanitize(mixed $input, int $depth = 0, int &$count = 0): array
    {
        $result = [];
        foreach (is_array($input) ? $input : [] as $p) {
            if (!is_array($p) || $count >= self::MAX_ITEMS || !in_array($p['typ'] ?? null, self::TYPES, true)) {
                continue;
            }
            $item = ['typ' => $p['typ'], 'text' => mb_substr(trim((string) ($p['text'] ?? '')), 0, 80)];
            if ($p['typ'] === 'stranka') {
                $item['ids'] = (int) ($p['ids'] ?? 0);
                if ($item['ids'] <= 0) {
                    continue;
                }
            } elseif ($p['typ'] === 'odkaz') {
                $item['url'] = trim((string) ($p['url'] ?? ''));
                $item['nove_okno'] = !empty($p['nove_okno']);
                if ($item['text'] === '' || !self::isValidUrl($item['url'])) {
                    continue;
                }
            } elseif ($p['typ'] === 'skupina' && $item['text'] === '') {
                continue;
            }
            $count++;
            if ($depth === 0) {
                $children = self::sanitize($p['deti'] ?? [], 1, $count);
                if ($children !== []) {
                    $item['deti'] = $children;
                }
            }
            $result[] = $item;
        }

        return $result;
    }

    /** Adresa vlastního odkazu: https, cesta na webu (/…), kotva, e-mail nebo telefon. */
    public static function isValidUrl(string $url): bool
    {
        return (bool) preg_match('#^(https?://[^\s<>"]{1,500}|/[^\s<>"]{0,500}|\#[A-Za-z0-9_-]{1,80}|mailto:[^\s<>"]{3,200}|tel:[+\d ()-]{3,40})$#', $url);
    }

    /**
     * Položky k vykreslení; skryté a smazané stránky (i s podmenu) vypadnou, skupina bez podmenu taky.
     *
     * @return list<array{text: string, url: string, nove_okno: bool, deti: list<array<string, mixed>>, novinky?: bool, auto?: bool}>  auto = odkaz na novinky přidaný automatickým menu
     */
    public static function items(App $app, string $location, string $language, int $home): array
    {
        $db = $app->db();
        $pages = [];
        foreach ($db->all('SELECT ids, titulek, seo_link, v_menu FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND jazyk = ? ORDER BY poradi, titulek', [$language]) as $s) {
            $pages[(int) $s['ids']] = $s;
        }
        $url = fn (array $s): string => $app->url((int) $s['ids'] === $home ? '' : $s['seo_link']);
        $saved = self::load($db, $location, $language);
        if ($saved === null) {
            if ($location !== 'hlavni') {
                return [];
            }
            // automaticky: stránky „v menu“ podle pořadí a na konci novinky (prvek Navigace je může vypnout)
            $auto = array_map(fn (array $s): array => ['text' => $s['titulek'], 'url' => $url($s), 'nove_okno' => false, 'deti' => []],
                array_values(array_filter($pages, fn (array $s): bool => (bool) $s['v_menu'])));
            if (Extensions::isEnabled($app->settings(), 'novinky')) {
                $auto[] = ['text' => t('Novinky'), 'url' => $app->url('novinky'), 'nove_okno' => false, 'deti' => [], 'novinky' => true, 'auto' => true];
            }

            return $auto;
        }
        $withNews = Extensions::isEnabled($app->settings(), 'novinky');
        $convert = function (array $p) use (&$convert, $pages, $url, $app, $withNews): ?array {
            $children = array_values(array_filter(array_map($convert, $p['deti'] ?? [])));

            return match ($p['typ']) {
                'stranka' => isset($pages[$p['ids']])
                    ? ['text' => $p['text'] !== '' ? $p['text'] : $pages[$p['ids']]['titulek'], 'url' => $url($pages[$p['ids']]), 'nove_okno' => false, 'deti' => $children]
                    : null,
                'novinky' => !$withNews ? null : ['text' => $p['text'] !== '' ? $p['text'] : t('Novinky'), 'url' => $app->url('novinky'), 'nove_okno' => false, 'deti' => $children, 'novinky' => true],
                'odkaz' => ['text' => $p['text'], 'url' => str_starts_with($p['url'], '/') ? $app->url($p['url']) : $p['url'], 'nove_okno' => $p['nove_okno'], 'deti' => $children],
                'skupina' => $children === [] ? null : ['text' => $p['text'], 'url' => '', 'nove_okno' => false, 'deti' => $children],
                default => null,
            };
        };

        return array_values(array_filter(array_map($convert, $saved)));
    }

    /**
     * Seznam <li> (bez obalového <ul>) pro šablonu i prvek Navigace. Položka s podmenu má třídu „podmenu“ a vnořený <ul>;
     * aktivní odkaz dostane aria-current, jeho nadřazená položka třídu „aktivni“.
     *
     * @param list<array<string, mixed>> $items z polozky()
     * @param string $path  cesta zobrazené stránky (např. /web/en/sluzby)
     * @param string $root  adresa úvodu ($app->url('')) – úvod je aktivní jen přesnou shodou
     */
    public static function html(array $items, string $path, string $root): string
    {
        $active = function (string $url) use ($path, $root): bool {
            if ($url === '' || preg_match('#^[a-z]+:|^\##i', $url)) {
                return false;
            }
            $target = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

            return $target === rtrim($path, '/') || ($target !== rtrim($root, '/') && $target !== '' && str_starts_with($path, $target . '/'));
        };
        $li = function (array $p) use (&$li, $active): string {
            $isEnabled = $active($p['url']);
            $link = $p['url'] === ''
                ? '<button type="button" class="menu-skupina">' . e($p['text']) . '</button>' // skupina bez odkazu: tlačítko jde zaměřit klávesnicí a otevřít podmenu
                : '<a href="' . e($p['url']) . '"' . ($isEnabled ? ' aria-current="page"' : '') . ($p['nove_okno'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($p['text']) . '</a>';
            if ($p['deti'] === []) {
                return '<li>' . $link . '</li>';
            }
            $inner = implode('', array_map($li, $p['deti']));
            $branch = str_contains($inner, 'aria-current');

            return '<li class="podmenu' . ($branch ? ' aktivni' : '') . '">' . $link . '<ul>' . $inner . '</ul></li>';
        };

        return implode('', array_map($li, $items));
    }

    /**
     * Zařazení stránky do menu z jejího formuláře (zaškrtávátko „v navigaci“): v automatickém režimu stačí sloupec v_menu,
     * v uloženém menu se stránka přidá na konec, nebo se odebere (její podmenu se posune o úroveň výš).
     */
    public static function setPage(Db $db, int $ids, string $language, bool $inMenu): void
    {
        $items = self::load($db, 'hlavni', $language);
        if ($items === null) {
            return;
        }
        $isEnabled = false;
        $without = [];
        foreach ($items as $p) {
            if ($p['typ'] === 'stranka' && $p['ids'] === $ids) {
                $isEnabled = true;
                if (!$inMenu) {
                    array_push($without, ...($p['deti'] ?? []));
                    continue;
                }
            }
            $children = [];
            foreach ($p['deti'] ?? [] as $d) {
                if ($d['typ'] === 'stranka' && $d['ids'] === $ids) {
                    $isEnabled = true;
                    if (!$inMenu) {
                        continue;
                    }
                }
                $children[] = $d;
            }
            if (isset($p['deti'])) {
                $p['deti'] = $children;
            }
            $without[] = $p;
        }
        if ($inMenu && !$isEnabled) {
            $without[] = ['typ' => 'stranka', 'ids' => $ids, 'text' => ''];
        }
        if ($inMenu !== $isEnabled || !$inMenu) {
            self::save($db, 'hlavni', $language, $without);
        }
    }

    /**
     * Je stránka v uloženém hlavním menu – jako stránka, nebo odkazem na její adresu (/sluzby, /de/leistungen)?
     * null = menu je automatické (platí sloupec v_menu).
     */
    public static function hasPage(Db $db, int $ids, string $language, ?string $seo = null): ?bool
    {
        $items = self::load($db, 'hlavni', $language);
        if ($items === null) {
            return null;
        }
        $seo ??= (string) $db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$ids]);
        $path = '/' . ($language !== '' ? $language . '/' : '') . $seo;
        foreach ($items as $p) {
            foreach ([$p, ...($p['deti'] ?? [])] as $x) {
                if (($x['typ'] === 'stranka' && $x['ids'] === $ids) || ($x['typ'] === 'odkaz' && $seo !== '' && rtrim((string) $x['url'], '/') === $path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
