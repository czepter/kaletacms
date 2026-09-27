<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Dílčí úpravy stavby podle id prvků – aby jazykový model (MCP) při opravě jednoho odkazu nemusel posílat celou stránku.
 * Operace se provedou postupně nad kopií; výsledek pak projde Stavba::vycisti() jako každá jiná stavba.
 *
 *   {"op":"uprav","id":"…","obsah":{…},"styl":{"mobil":{"mezera":"s","sloupce":null}},"tridy":[…],"kotva":"…","znacka":"…"}
 *       obsah a styl se slučují (null nebo "" hodnotu odebere), tridy se nahradí
 *   {"op":"nahrad","id":"…","prvek":{…}}
 *   {"op":"smaz","id":"…"}
 *   {"op":"vloz","prvky":[…] (nebo "prvek"),"do":"id rodiče | null = kořen","pozice":0 | "za":"id" | "pred":"id"}
 *   {"op":"presun","id":"…","do":…,"pozice":… | "za":… | "pred":…}
 */
final class Edits
{
    public const int MAX_OPERATIONS = 100;

    /**
     * @param array<string, mixed> $build
     * @param list<array<string, mixed>> $operations
     * @param array<string, string> $errors op[i] => text chyby (operace s chybou se přeskočí, ostatní proběhnou)
     * @return array<string, mixed>
     */
    public static function apply(array $build, array $operations, array &$errors = []): array
    {
        $root = ['id' => null, 'deti' => array_values($build['deti'] ?? [])];
        foreach (array_slice(array_values($operations), 0, self::MAX_OPERATIONS) as $i => $o) {
            $whereParts = 'op[' . $i . ']';
            if (!is_array($o)) {
                $errors[$whereParts] = 'Operace musí být objekt.';
                continue;
            }
            try {
                $root = self::applyOne($root, $o);
            } catch (\InvalidArgumentException $e) {
                $errors[$whereParts] = $e->getMessage();
            }
        }
        if (count($operations) > self::MAX_OPERATIONS) {
            $errors['op'] = 'Najednou jde provést nejvýš ' . self::MAX_OPERATIONS . ' operací – zbytek vynechán.';
        }

        return ['v' => $build['v'] ?? Build::VERSION, 'deti' => $root['deti']];
    }

    /** @return array<string, mixed> */
    private static function applyOne(array $root, array $o): array
    {
        $id = is_string($o['id'] ?? null) ? $o['id'] : '';
        switch ($o['op'] ?? '') {
            case 'uprav':
                return self::change($root, $id, function (array $p) use ($o): array {
                    if (is_array($o['obsah'] ?? null)) {
                        $p['obsah'] = self::merge(is_array($p['obsah'] ?? null) ? $p['obsah'] : [], $o['obsah']);
                    }
                    if (is_array($o['styl'] ?? null)) {
                        $style = is_array($p['styl'] ?? null) ? $p['styl'] : [];
                        foreach ($o['styl'] as $state => $properties) {
                            $style[$state] = $properties === null ? [] : self::merge(is_array($style[$state] ?? null) ? $style[$state] : [], (array) $properties);
                            if ($style[$state] === []) {
                                unset($style[$state]);
                            }
                        }
                        $p['styl'] = $style;
                    }
                    foreach (['tridy', 'kotva', 'znacka', 'podminky', 'atributy', 'popis'] as $key) {
                        if (array_key_exists($key, $o)) {
                            if ($o[$key] === null) {
                                unset($p[$key]);
                            } else {
                                $p[$key] = $o[$key];
                            }
                        }
                    }

                    return $p;
                });

            case 'nahrad':
                if (!is_array($o['prvek'] ?? null)) {
                    throw new \InvalidArgumentException('Chybí "prvek".');
                }

                return self::change($root, $id, fn (array $p): array => ['id' => $p['id']] + $o['prvek']);

            case 'smaz':
                [$root, $detached] = self::detach($root, $id);
                if ($detached === null) {
                    throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není.');
                }

                return $root;

            case 'vloz':
                $elements = is_array($o['prvky'] ?? null) ? array_values($o['prvky']) : (is_array($o['prvek'] ?? null) ? [$o['prvek']] : []);
                if ($elements === []) {
                    throw new \InvalidArgumentException('Chybí "prvky" (pole prvků) nebo "prvek".');
                }

                return self::insert($root, $elements, $o);

            case 'presun':
                [$without, $detached] = self::detach($root, $id);
                if ($detached === null) {
                    throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není.');
                }
                if (isset($o['do']) && self::find($detached, (string) $o['do'])) {
                    throw new \InvalidArgumentException('Prvek nejde přesunout do sebe sama.');
                }

                return self::insert($without, [$detached], $o);
        }
        throw new \InvalidArgumentException('Neznámá operace (op): uprav | nahrad | smaz | vloz | presun.');
    }

    /** Sloučí změny do pole: null nebo "" klíč odebere, ostatní přepíše. */
    private static function merge(array $previous, array $changes): array
    {
        foreach ($changes as $k => $v) {
            if ($v === null || $v === '') {
                unset($previous[$k]);
            } else {
                $previous[$k] = $v;
            }
        }

        return $previous;
    }

    /** Změní prvek s daným id; když ve stavbě není, hlásí chybu. */
    private static function change(array $root, string $id, callable $change): array
    {
        $found = false;
        $root = self::changeInNode($root, $id, $change, $found);
        if (!$found) {
            throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není. Id najdeš ve stavba_nacti.');
        }

        return $root;
    }

    private static function changeInNode(array $node, string $id, callable $change, bool &$found): array
    {
        foreach ($node['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $id) {
                $node['deti'][$i] = $change($p);
                $found = true;

                return $node;
            }
            $node['deti'][$i] = self::changeInNode($p, $id, $change, $found);
            if ($found) {
                return $node;
            }
        }

        return $node;
    }

    /** @return array{0: array<string, mixed>, 1: ?array<string, mixed>} [strom bez prvku, vyjmutý prvek] */
    private static function detach(array $node, string $id): array
    {
        foreach ($node['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $id) {
                array_splice($node['deti'], $i, 1);

                return [$node, $p];
            }
            [$new, $detached] = self::detach($p, $id);
            if ($detached !== null) {
                $node['deti'][$i] = $new;

                return [$node, $detached];
            }
        }

        return [$node, null];
    }

    private static function find(array $node, string $id): bool
    {
        if (($node['id'] ?? null) === $id) {
            return true;
        }
        foreach ($node['deti'] ?? [] as $p) {
            if (is_array($p) && self::find($p, $id)) {
                return true;
            }
        }

        return false;
    }

    /** Vloží prvky do rodiče „do“ (null = kořen) na pozici, za prvek „za“ nebo před prvek „pred“; bez určení na konec. */
    private static function insert(array $root, array $elements, array $o): array
    {
        $sibling = is_string($o['za'] ?? null) ? $o['za'] : (is_string($o['pred'] ?? null) ? $o['pred'] : null);
        if ($sibling !== null) {
            $done = false;
            $root = self::insertBeside($root, $sibling, $elements, isset($o['za']), $done);
            if (!$done) {
                throw new \InvalidArgumentException('Prvek „' . $sibling . '“ (za/pred) ve stavbě není.');
            }

            return $root;
        }
        $parent = isset($o['do']) && $o['do'] !== null && $o['do'] !== '' ? (string) $o['do'] : null;
        $position = isset($o['pozice']) && is_numeric($o['pozice']) ? (int) $o['pozice'] : null;
        if ($parent === null) {
            array_splice($root['deti'], $position ?? count($root['deti']), 0, $elements);

            return $root;
        }

        return self::change($root, $parent, function (array $p) use ($elements, $position): array {
            $p['deti'] = array_values(is_array($p['deti'] ?? null) ? $p['deti'] : []);
            array_splice($p['deti'], $position ?? count($p['deti']), 0, $elements);

            return $p;
        });
    }

    private static function insertBeside(array $node, string $sibling, array $elements, bool $after, bool &$done): array
    {
        foreach ($node['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $sibling) {
                array_splice($node['deti'], $after ? $i + 1 : $i, 0, $elements);
                $done = true;

                return $node;
            }
            $node['deti'][$i] = self::insertBeside($p, $sibling, $elements, $after, $done);
            if ($done) {
                return $node;
            }
        }

        return $node;
    }
}
