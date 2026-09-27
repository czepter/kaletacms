<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Volná adresa v URL (seo_link stránky, novinky, kategorie, položky kolekce, adresa pop-up okna): když je základ obsazený,
 * dostane pořadové číslo (o-nas, o-nas-2, o-nas-3…). Základ se zkrátí tak, aby se s číslem vešel do sloupce.
 */
final class Slug
{
    /** @param callable(string): bool $isTaken */
    public static function makeUnique(string $base, callable $isTaken, int $max = 160): string
    {
        $url = mb_substr($base, 0, $max);
        for ($i = 2; $isTaken($url); $i++) {
            if ($i > 10000) {
                throw new \RuntimeException('Nenašla se volná adresa.');
            }
            $extension = '-' . $i;
            $url = rtrim(mb_substr($base, 0, $max - strlen($extension)), '-') . $extension;
        }

        return $url;
    }
}
