<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Příjem nahraných obrázků: ověření, zmenšení na rozumnou velikost, náhled, uložení do media/RRRR/MM/.
 * Obrázek se vždy znovu zakóduje přes GD - tím zmizí EXIF (poloha z mobilu) i případný podstrčený kód.
 *
 * Ke každému obrázku vznikají varianty: <jmeno>-1200.<ext> (střední) a <jmeno>-nahled.<ext> (640 px) pro srcset
 * a ke každé z nich sourozenec <soubor>.webp, který server podá prohlížečům s podporou WebP (.htaccess).
 */
final class Images
{
    public const int MAX_SIDE = 2000;
    public const int THUMBNAIL_SIDE = 640;
    public const int MEDIUM_SIDE = 1200;
    private const int MAX_BYTES = 20 * 1024 * 1024;
    private const int MAX_PIXELS = 50_000_000;

    private const array TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

    /**
     * @param array<string, mixed> $file položka z $_FILES
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     * @throws \RuntimeException s českou hláškou pro uživatele
     */
    public static function save(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => Files::limitMessage(),
                default => 'Soubor se nepodařilo nahrát.',
            });
        }

        return self::process((string) $file['tmp_name'], (string) ($file['name'] ?? 'obrazek'), true);
    }

    /**
     * Obrázek, který už na serveru leží (stažený při importu z WordPressu): projde stejnou cestou jako nahraný,
     * takže je od něj k nerozeznání - překódování přes GD, zmenšení, náhled, WebP. Zdrojový soubor zůstává na místě.
     *
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     * @throws \RuntimeException s českou hláškou pro uživatele
     */
    public static function saveFile(string $path, string $name): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Soubor se nepodařilo nahrát.');
        }

        return self::process($path, $name, false);
    }

    /**
     * @param bool $uploaded soubor přišel formulářem (přesouvá se přes move_uploaded_file); jinak se jen kopíruje
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     */
    private static function process(string $tmp, string $fileName, bool $uploaded): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Na serveru chybí rozšíření GD pro práci s obrázky.');
        }
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw new \RuntimeException('Povolené jsou jen obrázky JPG, PNG, WebP a GIF.');
        }
        if (filesize($tmp) > self::MAX_BYTES || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException('Obrázek je příliš velký (nejvýše 20 MB a 50 megapixelů).');
        }

        $extension = self::TYPES[$info[2]];
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $folder = 'media/' . date('Y/m');
        if (!is_dir(KALETA_ROOT . '/' . $folder) && !mkdir(KALETA_ROOT . '/' . $folder, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku ' . $folder . ' - zkontrolujte práva k zápisu.');
        }
        $base = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3));

        if ($extension === 'gif') {
            // GIF může být animovaný - ukládá se beze změny, náhled je první snímek
            $target = $base . '.gif';
            if (!($uploaded ? move_uploaded_file($tmp, KALETA_ROOT . '/' . $target) : copy($tmp, KALETA_ROOT . '/' . $target))) {
                throw new \RuntimeException('Soubor se nepodařilo uložit.');
            }
            $image = imagecreatefromgif(KALETA_ROOT . '/' . $target);
            [$w, $h] = [$info[0], $info[1]];
        } else {
            $image = @imagecreatefromstring((string) file_get_contents($tmp));
            if ($image === false) {
                throw new \RuntimeException('Obrázek je poškozený a nelze ho zpracovat.');
            }
            $image = self::rotateByExif($image, $tmp, $extension);
            $image = self::shrink($image, self::MAX_SIDE);
            [$w, $h] = [imagesx($image), imagesy($image)];
            $target = $base . '.' . $extension;
            self::write($image, KALETA_ROOT . '/' . $target, $extension);
            self::webp($image, KALETA_ROOT . '/' . $target, $extension);
            if (self::ratio($w, $h, self::MEDIUM_SIDE) < 1.0) {
                $medium = self::shrink($image, self::MEDIUM_SIDE);
                $mediumPath = KALETA_ROOT . '/' . $base . '-1200.' . $extension;
                self::write($medium, $mediumPath, $extension);
                self::webp($medium, $mediumPath, $extension);
            }
        }

        $preview = self::shrink($image, self::THUMBNAIL_SIDE);
        $thumbnailExtension = $extension === 'gif' ? 'png' : $extension;
        $thumbnailPath = $base . '-nahled.' . $thumbnailExtension;
        self::write($preview, KALETA_ROOT . '/' . $thumbnailPath, $thumbnailExtension);
        self::webp($preview, KALETA_ROOT . '/' . $thumbnailPath, $thumbnailExtension);

        return [
            'obr_poloha' => $target, 'obr_width' => $w, 'obr_height' => $h, 'obr_vel' => (int) filesize(KALETA_ROOT . '/' . $target),
            'nahl_poloha' => $thumbnailPath, 'nahl_width' => imagesx($preview), 'nahl_height' => imagesy($preview),
            'nazev' => mb_substr(trim(str_replace(['_', '-'], ' ', $name)), 0, 150),
        ];
    }

    /**
     * Náhrada obrázku se zachováním adresy: nový soubor projde stejným zpracováním a zapíše se na místo starého
     * (i s variantami a WebP), ve formátu starého souboru – adresa se nemění, odkazy na webu platí dál.
     *
     * @param array<string, mixed> $file položka z $_FILES
     * @return array{obr_width:int, obr_height:int, obr_vel:int, nahl_width:int, nahl_height:int}
     */
    public static function replace(string $old, array $file): array
    {
        if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+)\.(jpg|png|webp)$#', $old, $m)) {
            throw new \RuntimeException('Nahradit jde jen obrázek JPG, PNG nebo WebP.');
        }
        $new = self::save($file); // ověří, zmenší a znovu zakóduje nahraný soubor
        $image = @imagecreatefromstring((string) file_get_contents(KALETA_ROOT . '/' . $new['obr_poloha']));
        self::delete($new['obr_poloha'], $new['nahl_poloha']);
        if ($image === false) {
            throw new \RuntimeException('Obrázek je poškozený a nelze ho zpracovat.');
        }
        self::delete($old, $m[1] . '-nahled.' . $m[2]);
        [$base, $extension] = [$m[1], $m[2]];
        self::write($image, KALETA_ROOT . '/' . $old, $extension);
        self::webp($image, KALETA_ROOT . '/' . $old, $extension);
        if (self::ratio(imagesx($image), imagesy($image), self::MEDIUM_SIDE) < 1.0) {
            $medium = self::shrink($image, self::MEDIUM_SIDE);
            self::write($medium, KALETA_ROOT . '/' . $base . '-1200.' . $extension, $extension);
            self::webp($medium, KALETA_ROOT . '/' . $base . '-1200.' . $extension, $extension);
        }
        $preview = self::shrink($image, self::THUMBNAIL_SIDE);
        self::write($preview, KALETA_ROOT . '/' . $base . '-nahled.' . $extension, $extension);
        self::webp($preview, KALETA_ROOT . '/' . $base . '-nahled.' . $extension, $extension);

        return ['obr_width' => imagesx($image), 'obr_height' => imagesy($image), 'obr_vel' => (int) filesize(KALETA_ROOT . '/' . $old),
            'nahl_width' => imagesx($preview), 'nahl_height' => imagesy($preview)];
    }

    /** Smaže soubory obrázku; cesty mimo media/ ignoruje. */
    public static function delete(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+)\.(jpg|png|webp|gif|svg)$#', $path, $m)) {
                continue;
            }
            // s obrázkem mizí i jeho varianty pro srcset a WebP
            foreach ([$path, $path . '.webp', $path . '.avif', $m[1] . '-1200.' . $m[2], $m[1] . '-1200.' . $m[2] . '.webp', $m[1] . '-1200.' . $m[2] . '.avif'] as $file) {
                if (is_file(KALETA_ROOT . '/' . $file)) {
                    unlink(KALETA_ROOT . '/' . $file);
                }
            }
        }
    }

    /**
     * Poměr zmenšení na danou stranu. Běžný obrázek se vejde delší stranou; vysoký (výška přes dvojnásobek šířky – celostránkové
     * snímky, infografiky) se měří šířkou a výška smí být až trojnásobek, jinak by z něj zbyl úzký rozmazaný proužek.
     */
    public static function ratio(int $w, int $h, int $pageNumber): float
    {
        if ($w < 1 || $h < 1) {
            return 1.0;
        }

        return $h > 2 * $w ? min(1.0, $pageNumber / $w, 3 * $pageNumber / $h) : min(1.0, $pageNumber / max($w, $h));
    }

    private static function shrink(\GdImage $image, int $maxSide): \GdImage
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $ratio = self::ratio($w, $h, $maxSide);
        if ($ratio >= 1.0) {
            return $image;
        }
        $new = imagecreatetruecolor(max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)));
        imagealphablending($new, false);
        imagesavealpha($new, true);
        imagecopyresampled($new, $image, 0, 0, 0, 0, imagesx($new), imagesy($new), $w, $h);

        return $new;
    }

    private static function write(\GdImage $image, string $file, string $extension): void
    {
        $ok = match ($extension) {
            'jpg' => imagejpeg($image, $file, 85),
            'webp' => imagewebp($image, $file, 85),
            default => (function () use ($image, $file): bool {
                imagesavealpha($image, true);

                return imagepng($image, $file, 7);
            })(),
        };
        if (!$ok) {
            throw new \RuntimeException('Obrázek se nepodařilo uložit - zkontrolujte práva ke složce media/.');
        }
    }

    /**
     * Menší sourozenci pro moderní prohlížeče: foto.jpg.webp (o 25–35 % menší) a foto.jpg.avif (o dalších ~20 %),
     * když je PHP umí. Server podá ten, který prohlížeč přijme (.htaccess, nginx).
     */
    private static function webp(\GdImage $image, string $file, string $extension): void
    {
        imagepalettetotruecolor($image);
        if ($extension !== 'webp' && function_exists('imagewebp')) {
            @imagewebp($image, $file . '.webp', 82);
        }
        if (function_exists('imageavif')) {
            @imageavif($image, $file . '.avif', 55, 8); // rychlost 8: kódování nezdrží nahrávání
        }
    }

    /**
     * Atribut srcset pro obrázek z media/ podle existujících variant; prázdný řetězec, když žádné nejsou.
     *
     * @param string $path cesta od kořene webu bez úvodního lomítka (media/2026/09/foto.jpg)
     */
    /** Převládající barva obrázku jako #rrggbb (průměr přes celou plochu); null, když soubor nejde načíst. */
    public static function color(string $file): ?string
    {
        $type = is_file($file) ? @getimagesize($file) : false;
        $image = match ($type[2] ?? 0) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default => false,
        };
        if ($image === false) {
            return null;
        }
        $pixel = imagecreatetruecolor(1, 1);
        imagefill($pixel, 0, 0, imagecolorallocate($pixel, 255, 255, 255)); // průhledné PNG na bílém podkladu
        imagecopyresampled($pixel, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
        $rgb = imagecolorat($pixel, 0, 0);

        return sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }

    /** Velikosti ikony webu: karta prohlížeče, plocha iPhonu, Android a instalace webu. */
    public const array ICON_SIZES = [32, 180, 192, 512];

    /**
     * Čtvercové PNG ikony webu (media/ikona-<n>.png) z obrázku z Médií: ořízne střed na čtverec a zmenší.
     * Vrací false, když zdroj není rastrový obrázek (SVG ikona se pak použije jen jako rel=icon).
     */
    public static function icons(string $source): bool
    {
        $type = is_file($source) ? @getimagesize($source) : false;
        $image = match ($type[2] ?? 0) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if ($image === false) {
            return false;
        }
        $pageNumber = min(imagesx($image), imagesy($image));
        [$x, $y] = [intdiv(imagesx($image) - $pageNumber, 2), intdiv(imagesy($image) - $pageNumber, 2)];
        foreach (self::ICON_SIZES as $n) {
            $icon = imagecreatetruecolor($n, $n);
            imagealphablending($icon, false);
            imagesavealpha($icon, true);
            imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
            imagecopyresampled($icon, $image, 0, 0, $x, $y, $n, $n, $pageNumber, $pageNumber);
            imagepng($icon, KALETA_ROOT . '/media/ikona-' . $n . '.png', 9);
        }

        return true;
    }

    public static function srcset(string $path, string $base): string
    {
        // tentýž obrázek bývá na stránce víckrát (otvírák, výpis, blok): dotazy na disk stačí jednou za požadavek
        static $cache = [];
        if (isset($cache[$path . '|' . $base])) {
            return $cache[$path . '|' . $base];
        }
        if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+?)(-1200|-nahled)?\.(jpg|png|webp)$#', $path, $m)) {
            return '';
        }
        $variants = [];
        $widths = [];
        foreach (['-nahled', '-1200', ''] as $extension) {
            $file = $m[1] . $extension . '.' . $m[3];
            // skutečná šířka každé varianty: dřív se zmenšovalo podle delší strany, takže u vysokých obrázků „1200“ neznamenalo šířku
            $info = is_file(KALETA_ROOT . '/' . $file) ? @getimagesize(KALETA_ROOT . '/' . $file) : false;
            if ($info !== false && !isset($widths[$info[0]])) {
                $widths[$info[0]] = true;
                $variants[] = $base . '/' . $file . ' ' . $info[0] . 'w';
            }
        }

        return $cache[$path . '|' . $base] = count($variants) > 1 ? implode(', ', $variants) : '';
    }

    /** Fotky z mobilu bývají uložené naležato s příznakem otočení v EXIF. */
    private static function rotateByExif(\GdImage $image, string $file, string $extension): \GdImage
    {
        if ($extension !== 'jpg' || !function_exists('exif_read_data')) {
            return $image;
        }
        $angle = match ((int) (@exif_read_data($file)['Orientation'] ?? 1)) {
            3 => 180, 6 => 270, 8 => 90, default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
