<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Přílohy ke stažení v Médiích: PDF, dokumenty, tabulky, zvuk a video. Obrázky řeší Core\Obrazky.
 *
 * Povolené jsou jen vyjmenované přípony; soubor dostane nové bezpečné jméno a nikdy se nespouští
 * (media/.htaccess). HTML, SVG ani skripty nahrát nejdou - prohlížeč by je otevřel jako součást webu.
 */
final class Files
{
    public const array FILE_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'zip', 'epub', 'gpx', 'ics', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'webm', 'woff2', 'woff'];
    private const int MAX_BYTES = 200 * 1024 * 1024;
    private const string FORBIDDEN_TYPES = '#html|php|javascript|svg|x-sh|x-dosexec|x-executable|x-mach|x-msdownload#i';

    /** Kolik bajtů smí mít jeden nahrávaný soubor: menší z upload_max_filesize a post_max_size (0 = bez omezení). */
    public static function limit(): int
    {
        $bytes = static function (string $ini): int {
            $number = (int) $ini;

            return $number <= 0 ? 0 : $number * match (strtolower(substr(trim($ini), -1))) {
                'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
            };
        };
        $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);

        return $limits === [] ? 0 : min($limits);
    }

    /** Limit pro lidi: „2 MB“ místo zkratky z php.ini („2M“). */
    public static function limitText(): string
    {
        $mb = self::limit() / 1024 / 1024;

        return $mb <= 0 ? '' : (fmod($mb, 1.0) === 0.0 ? (string) (int) $mb : number_format($mb, 1, Language::code() === 'cs' ? ',' : '.', '')) . ' MB';
    }

    /** Hláška pro soubor nad limitem serveru – přeložená, s limitem v MB a s radou, co dělat. */
    public static function limitMessage(): string
    {
        return t('Soubor je větší, než server dovoluje nahrát (nejvýš %s). Zmenšete ho, nebo požádejte správce hostingu o vyšší limit.', self::limitText());
    }

    public static function isAttachment(string $displayName): bool
    {
        return in_array(strtolower(pathinfo($displayName, PATHINFO_EXTENSION)), self::FILE_EXTENSIONS, true);
    }

    /**
     * @param array<string, mixed> $file položka z $_FILES
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     * @throws \RuntimeException s českou hláškou pro uživatele
     */
    public static function save(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::limitMessage(),
                default => 'Soubor se nepodařilo nahrát.',
            });
        }

        return self::process((string) $file['tmp_name'], (string) ($file['name'] ?? ''), true);
    }

    /**
     * Příloha ze souboru, který už je na disku (MCP, import) – zdroj zůstane, uloží se kopie.
     *
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     */
    public static function saveFile(string $path, string $displayName): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Soubor se nepodařilo nahrát.');
        }

        return self::process($path, $displayName, false);
    }

    /** @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string} */
    private static function process(string $tmp, string $displayName, bool $uploaded): array
    {
        $extension = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::FILE_EXTENSIONS, true)) {
            throw new \RuntimeException('Tento typ souboru nahrát nejde. Povolené jsou obrázky a přílohy: ' . implode(', ', self::FILE_EXTENSIONS) . '.');
        }
        $type = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (preg_match(self::FORBIDDEN_TYPES, $type) || filesize($tmp) > self::MAX_BYTES) {
            throw new \RuntimeException(filesize($tmp) > self::MAX_BYTES ? 'Soubor je příliš velký (nejvýše 200 MB).' : 'Obsah souboru neodpovídá jeho příponě.');
        }
        $folder = 'media/' . date('Y/m');
        if (!is_dir(KALETA_ROOT . '/' . $folder) && !mkdir(KALETA_ROOT . '/' . $folder, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku ' . $folder . ' - zkontrolujte práva k zápisu.');
        }
        $name = pathinfo($displayName, PATHINFO_FILENAME);
        $target = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
        if (!($uploaded ? move_uploaded_file($tmp, KALETA_ROOT . '/' . $target) : copy($tmp, KALETA_ROOT . '/' . $target))) {
            throw new \RuntimeException('Soubor se nepodařilo uložit.');
        }

        // příloha se v tabulce médií pozná podle prázdného náhledu a nulových rozměrů
        return ['obr_poloha' => $target, 'obr_width' => 0, 'obr_height' => 0, 'obr_vel' => (int) filesize(KALETA_ROOT . '/' . $target),
            'nahl_poloha' => '', 'nahl_width' => 0, 'nahl_height' => 0, 'nazev' => mb_substr($name, 0, 150)];
    }

    public static function delete(string $path): void
    {
        if (preg_match('#^media/\d{4}/\d{2}/[a-z0-9-]+\.(' . implode('|', self::FILE_EXTENSIONS) . ')$#', $path) && is_file(KALETA_ROOT . '/' . $path)) {
            unlink(KALETA_ROOT . '/' . $path);
        }
    }

    public static function size(int $byteCount): string
    {
        return $byteCount >= 1048576 ? format_number($byteCount / 1048576) . ' MB' : max(1, (int) round($byteCount / 1024)) . ' kB';
    }
}
