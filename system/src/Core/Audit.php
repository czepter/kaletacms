<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Build;
use Kaleta\Builder\Check;

/**
 * Site audit (1.9): what hurts a site in search engines and for visitors, collected across the whole site – for the
 * administrator (Administration → Site audit) and for Claude (site_audit), who can then fix it.
 *
 *  - links on the site to pages, news or items that do not exist (pages, site parts, templates, components, pop-ups,
 *    the menu, collection items and news), and broken external links found by the background link check;
 *  - pages and item pages without a description, duplicate titles;
 *  - menu items pointing at hidden or deleted pages;
 *  - the builder check of every published build: buttons without a link, images without alt, the heading outline;
 *  - the most frequent addresses that end in 404 and have no redirect.
 *
 * Runs on demand only: a company site has hundreds of rows, not millions.
 */
final class Audit
{
    /** Kinds of findings in the order they are shown. */
    public const array KINDS = [
        'link' => 'Broken links', 'menu' => 'Menu', 'description' => 'Missing descriptions', 'title' => 'Duplicate titles',
        'build' => 'Buttons, images and headings', 'not_found' => 'Frequent 404 errors',
    ];

    /** At most this many findings of one kind – beyond that the list would not help anyone. */
    private const int PER_KIND = 100;

    /** @var array<string, bool> resolved internal paths */
    private array $resolved = [];

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return list<array{kind: string, where: string, message: string, edit: string, url: string, target: array<string, int|string>, element?: string}>
     */
    public function run(): array
    {
        $this->findings = [];
        $this->pages();
        $this->parts();
        $this->collections();
        $this->menus();
        $this->news();
        $this->notFound();
        $order = array_flip(array_keys(self::KINDS));
        $counts = [];
        $out = [];
        foreach ($this->findings as $f) {
            $counts[$f['kind']] = ($counts[$f['kind']] ?? 0) + 1;
            if ($counts[$f['kind']] <= self::PER_KIND) {
                $out[] = $f;
            }
        }
        usort($out, fn (array $a, array $b): int => $order[$a['kind']] <=> $order[$b['kind']]);

        return $out;
    }

    /* ---------- sources ---------- */

    private function pages(): void
    {
        $db = $this->app->db();
        $home = (int) $this->app->settings()->get('home_page');
        $titles = [];
        foreach ($db->all('SELECT ids, titulek, seo_titulek, seo_link, popis, text, stavba, zobrazit, noindex, jazyk FROM {stranky} WHERE smazano IS NULL') as $p) {
            $where = t('Page “%s”', $p['titulek']);
            $target = ['page' => (int) $p['ids']];
            $url = (int) $p['ids'] === $home ? '' : (string) $p['seo_link'];
            $edit = 'admin.php?module=pages&action=edit&id=' . (int) $p['ids'];
            $build = $p['stavba'] !== null ? Build::fromJson((string) $p['stavba']) : null;
            $this->links($p['stavba'] ?? (string) $p['text'], $where, $build !== null ? 'admin.php?module=pages&action=builder&id=' . (int) $p['ids'] : $edit, $url, $target);
            if (!$p['zobrazit']) {
                continue; // a hidden page is not in search engines – only its links matter (it may be published later)
            }
            if ($build !== null) {
                foreach (Check::builds($build, true, 50) as $c) {
                    $this->add('build', $where, $c['zprava'], 'admin.php?module=pages&action=builder&id=' . (int) $p['ids'], $url, $target, $c['id']);
                }
            }
            if ($p['noindex']) {
                continue;
            }
            if (trim((string) $p['popis']) === '') {
                $this->add('description', $where, t('No description for search engines – they then make up their own from the page text.'), $edit, $url, $target);
            }
            $titles[$p['jazyk'] . '|' . mb_strtolower(trim($p['seo_titulek'] !== '' ? (string) $p['seo_titulek'] : (string) $p['titulek']))][] = [$where, $edit, $url, $target];
        }
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.seo_link, p.seo_titulek, p.popis, p.data, p.jazyk, k.seo_link AS kolekce, k.pole, k.nazev AS kolekce_nazev FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.noindex = 0 AND p.smazano IS NULL') as $p) {
            $where = t('Item “%s” (%s)', $p['nazev'], $p['kolekce_nazev']);
            $edit = 'admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&polozka=' . (int) $p['idp'];
            $url = ($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $p['kolekce'] . '/' . $p['seo_link'];
            $target = ['collection' => (string) $p['kolekce'], 'item' => (int) $p['idp']];
            if (trim((string) $p['popis']) === '' && !$this->hasLongerText((string) $p['data'], (string) $p['pole'])) {
                $this->add('description', $where, t('No description for search engines and no longer text to take one from.'), $edit, $url, $target);
            }
            $titles[$p['jazyk'] . '|' . mb_strtolower(trim($p['seo_titulek'] !== '' ? (string) $p['seo_titulek'] : (string) $p['nazev']))][] = [$where, $edit, $url, $target];
        }
        foreach ($titles as $key => $same) {
            if (count($same) < 2) {
                continue;
            }
            foreach ($same as [$where, $edit, $url, $target]) {
                $this->add('title', $where, t('The title “%s” is used %d times – search engines cannot tell the pages apart.', explode('|', $key, 2)[1], count($same)), $edit, $url, $target);
            }
        }
    }

    private function parts(): void
    {
        $db = $this->app->db();
        foreach ($db->all('SELECT typ, jazyk, varianta, nazev, stavba FROM {casti} WHERE stavba IS NOT NULL') as $c) {
            $where = t('Site part “%s”', trim($c['typ'] . ' ' . $c['varianta'] . ' ' . $c['jazyk']));
            $edit = 'admin.php?module=parts&action=builder&typ=' . rawurlencode((string) $c['typ']) . ($c['varianta'] !== '' ? '&varianta=' . rawurlencode((string) $c['varianta']) : '') . '&jazyk=' . rawurlencode((string) $c['jazyk']);
            $this->links((string) $c['stavba'], $where, $edit, null, ['part' => (string) $c['typ']]);
            foreach (Check::builds((array) Build::fromJson((string) $c['stavba']), false, 50) as $f) {
                $this->add('build', $where, $f['zprava'], $edit, null, ['part' => (string) $c['typ']], $f['id']);
            }
        }
        foreach ($db->all('SELECT idm, nazev, stavba FROM {komponenty} WHERE stavba IS NOT NULL') as $c) {
            $this->links((string) $c['stavba'], t('Component “%s”', $c['nazev']), 'admin.php?module=components&action=builder&id=' . (int) $c['idm'], null, ['component' => (int) $c['idm']]);
        }
        foreach ($db->all('SELECT idpp, nazev, stavba FROM {popupy} WHERE stavba IS NOT NULL AND aktivni = 1') as $c) {
            $this->links((string) $c['stavba'], t('Pop-up “%s”', $c['nazev']), 'admin.php?module=popups&action=builder&id=' . (int) $c['idpp'], null, ['popup' => (int) $c['idpp']]);
        }
    }

    private function collections(): void
    {
        $db = $this->app->db();
        foreach ($db->all('SELECT idk, nazev, seo_link, detail, stavba FROM {kolekce}') as $k) {
            if ($k['detail'] && $k['stavba'] !== null) {
                $this->links((string) $k['stavba'], t('Item template of “%s”', $k['nazev']), 'admin.php?module=collections&action=builder&id=' . (int) $k['idk'], null, ['collection' => (string) $k['seo_link']]);
            }
        }
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.data, k.seo_link AS kolekce, k.nazev AS kolekce_nazev FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.zobrazit = 1 AND p.smazano IS NULL') as $p) {
            $this->links((string) $p['data'], t('Item “%s” (%s)', $p['nazev'], $p['kolekce_nazev']), 'admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&polozka=' . (int) $p['idp'], null,
                ['collection' => (string) $p['kolekce'], 'item' => (int) $p['idp']]);
        }
    }

    private function menus(): void
    {
        $db = $this->app->db();
        $pages = [];
        foreach ($db->all('SELECT ids, titulek, zobrazit, smazano FROM {stranky}') as $p) {
            $pages[(int) $p['ids']] = $p;
        }
        foreach ($db->all('SELECT umisteni, jazyk, polozky FROM {menu}') as $m) {
            $where = t('Menu “%s”', t(Menu::LOCATIONS[$m['umisteni']] ?? $m['umisteni'])) . ($m['jazyk'] !== '' ? ' (' . $m['jazyk'] . ')' : '');
            $walk = function (array $items) use (&$walk, $pages, $where): void {
                foreach ($items as $i) {
                    if (($i['typ'] ?? '') === 'stranka') {
                        $p = $pages[(int) ($i['ids'] ?? 0)] ?? null;
                        $message = match (true) {
                            $p === null => t('An item points at a page that no longer exists.'),
                            $p['smazano'] !== null => t('The item “%s” points at a page in the trash.', $p['titulek']),
                            !$p['zobrazit'] => t('The item “%s” points at a hidden page – visitors do not see it in the menu.', $p['titulek']),
                            default => null,
                        };
                        if ($message !== null) {
                            $this->add('menu', $where, $message, 'admin.php?module=menu', null, ['menu' => 'menu']);
                        }
                    } elseif (($i['typ'] ?? '') === 'odkaz') {
                        $this->checkUrl((string) ($i['url'] ?? ''), $where, 'admin.php?module=menu', null, ['menu' => 'menu']);
                    }
                    if (is_array($i['deti'] ?? null)) {
                        $walk($i['deti']);
                    }
                }
            };
            $walk(json_decode((string) $m['polozky'], true) ?: []);
        }
    }

    private function news(): void
    {
        if (!Extensions::isEnabled($this->app->settings(), 'novinky')) {
            return;
        }
        $db = $this->app->db();
        foreach ($db->all('SELECT idc, titulek, seo_link, jazyk, uvod, text FROM {novinky} WHERE visible = 1 AND smazano IS NULL ORDER BY datum DESC LIMIT 500') as $c) {
            $this->links($c['uvod'] . ' ' . $c['text'], t('News item “%s”', $c['titulek']), 'admin.php?module=news&action=edit&id=' . (int) $c['idc'],
                $this->relative($this->app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk'])), ['news' => (int) $c['idc']]);
        }
        // external links the background check found broken (Core\Links)
        foreach ($db->all('SELECT v.idc, v.url, v.stav, c.titulek, c.seo_link, c.jazyk FROM {odkazy_vadne} v JOIN {novinky} c ON c.idc = v.idc WHERE c.smazano IS NULL LIMIT 200') as $v) {
            $this->add('link', t('News item “%s”', $v['titulek']), t('The link %s does not work (%s).', $v['url'], (int) $v['stav'] === 0 ? t('no response') : 'HTTP ' . (int) $v['stav']), 'admin.php?module=news&action=edit&id=' . (int) $v['idc'],
                $this->relative($this->app->newsItemUrl((string) $v['seo_link'], (string) $v['jazyk'])), ['news' => (int) $v['idc']]);
        }
    }

    private function notFound(): void
    {
        foreach ($this->app->db()->all('SELECT n.cesta, n.pocet FROM {nenalezeno} n WHERE n.naposledy > NOW() - INTERVAL 30 DAY AND n.pocet >= 3 ORDER BY n.pocet DESC LIMIT 25') as $n) {
            $path = trim((string) $n['cesta'], '/');
            if ($this->app->db()->value('SELECT 1 FROM {presmerovani} WHERE z_adresy = ?', [$path]) !== null) {
                continue;
            }
            $this->add('not_found', '/' . $path, t('%d visits in the last 30 days ended with “page not found” – add a redirect to the right page.', (int) $n['pocet']),
                'admin.php?module=redirects&z=' . rawurlencode('/' . $path), null, ['redirect_from' => '/' . $path]);
        }
    }

    /* ---------- links ---------- */

    /** Every link in a build (JSON) or HTML; internal ones must lead somewhere. */
    private function links(string $content, string $where, string $edit, ?string $url, array $target): void
    {
        preg_match_all('#"(?:odkaz|url|href)":"((?:[^"\\\\]|\\\\.)*)"|href=\\\\?"([^"\\\\]*)\\\\?"#', $content, $m, PREG_SET_ORDER);
        $seen = [];
        foreach ($m as $match) {
            $link = stripslashes($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
            if ($link === '' || isset($seen[$link])) {
                continue;
            }
            $seen[$link] = true;
            $this->checkUrl($link, $where, $edit, $url, $target);
        }
    }

    private function checkUrl(string $link, string $where, string $edit, ?string $url, array $target): void
    {
        if ($link === '' || str_contains($link, '{{') || preg_match('#^(\#|mailto:|tel:|javascript:)#i', $link)) {
            return;
        }
        $origin = $this->app->request->origin() . $this->app->request->basePath();
        if (preg_match('#^https?://#i', $link)) {
            if (!str_starts_with(strtolower($link), strtolower($origin) . '/') && strtolower(rtrim($link, '/')) !== strtolower($origin)) {
                return; // an external link – the background link check tests those
            }
            $link = substr($link, strlen($origin));
        }
        if (!str_starts_with($link, '/')) {
            return;
        }
        $path = (string) parse_url($link, PHP_URL_PATH);
        if (!$this->resolves($path)) {
            $this->add('link', $where, t('The link %s leads to a page that does not exist.', $path), $edit, $url, $target);
        }
    }

    /** Does an internal path lead to something on the site (a page, news, an item, a file or a system address)? */
    public function resolves(string $path): bool
    {
        $path = '/' . trim(rawurldecode($path), '/');
        if (isset($this->resolved[$path])) {
            return $this->resolved[$path];
        }
        $db = $this->app->db();
        $segments = $path === '/' ? [] : explode('/', ltrim($path, '/'));
        $language = Language::defaults($this->app->settings());
        if ($segments !== [] && in_array($segments[0], Language::additional($this->app->settings()), true)) {
            $language = array_shift($segments);
        }
        $rest = '/' . implode('/', $segments);
        [$internal] = Routes::internalPath($rest, $language, $db);
        $s = $internal === '/' ? [] : explode('/', ltrim($internal, '/'));
        $ok = match (true) {
            $s === [] => true,
            is_file(KALETA_ROOT . '/' . ltrim($path, '/')) && preg_match('#^/(media|image)/#', $path) === 1 => true,
            in_array($s[0], ['hledani', 'rss.xml', 'feed.json', 'sitemap.xml', 'robots.txt', 'llms.txt', 'admin.php', 'mcp'], true) => true,
            $s[0] === 'novinky' => $this->newsPathExists(array_slice($s, 1)),
            $db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND zobrazit = 1 AND smazano IS NULL', [implode('/', $s)]) !== null => true,
            count($s) === 2 && $db->value('SELECT 1 FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.seo_link = ? AND k.detail = 1 AND p.seo_link = ? AND p.zobrazit = 1 AND p.smazano IS NULL', [$s[0], $s[1]]) !== null => true,
            $db->value('SELECT 1 FROM {presmerovani} WHERE z_adresy = ?', [trim($path, '/')]) !== null => true,
            default => false,
        };

        return $this->resolved[$path] = $ok;
    }

    /** @param list<string> $s the path after /novinky */
    private function newsPathExists(array $s): bool
    {
        $db = $this->app->db();

        return match (true) {
            $s === [] => true,
            count($s) === 1 => $db->value('SELECT 1 FROM {novinky} WHERE seo_link = ? AND visible = 1 AND smazano IS NULL', [$s[0]]) !== null,
            count($s) === 2 && $s[0] === 'kategorie' => $db->value('SELECT 1 FROM {kategorie} WHERE seo_link = ?', [$s[1]]) !== null,
            count($s) === 2 && $s[0] === 'stitek' => $db->value('SELECT 1 FROM {stitky} WHERE seo_link = ?', [$s[1]]) !== null,
            default => false,
        };
    }

    /* ---------- helpers ---------- */

    private function hasLongerText(string $data, string $fields): bool
    {
        $values = json_decode($data, true) ?: [];
        foreach (json_decode($fields, true) ?: [] as $f) {
            if (in_array($f['typ'] ?? '', ['radky', 'html'], true) && trim(strip_tags((string) ($values[$f['klic']] ?? ''))) !== '') {
                return true;
            }
        }

        return false;
    }

    /** A public URL of the app (with the base path) as a path inside the site. */
    private function relative(string $url): string
    {
        return ltrim(substr($url, strlen($this->app->request->basePath())), '/');
    }

    /**
     * @param ?string $url path of the page on the site ('' = the home page, null = no page of its own)
     * @param array<string, int|string> $target what to fix, for Claude
     */
    private function add(string $kind, string $where, string $message, string $edit, ?string $url, array $target, ?string $element = null): void
    {
        $this->findings[] = ['kind' => $kind, 'where' => $where, 'message' => $message, 'edit' => $this->app->url($edit),
            'url' => $url === null ? '' : $this->app->request->origin() . $this->app->url($url), 'target' => $target] + ($element !== null ? ['element' => $element] : []);
    }
}
