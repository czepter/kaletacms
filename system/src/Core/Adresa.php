<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Volná adresa v URL (seo_link stránky, novinky, kategorie, položky kolekce, adresa pop-up okna): když je základ obsazený,
 * dostane pořadové číslo (o-nas, o-nas-2, o-nas-3…). Základ se zkrátí tak, aby se s číslem vešel do sloupce.
 */
final class Adresa
{
    /** @param callable(string): bool $obsazena */
    public static function volna(string $zaklad, callable $obsazena, int $max = 160): string
    {
        $adresa = mb_substr($zaklad, 0, $max);
        for ($i = 2; $obsazena($adresa); $i++) {
            if ($i > 10000) {
                throw new \RuntimeException('Nenašla se volná adresa.');
            }
            $pripona = '-' . $i;
            $adresa = rtrim(mb_substr($zaklad, 0, $max - strlen($pripona)), '-') . $pripona;
        }

        return $adresa;
    }
}
