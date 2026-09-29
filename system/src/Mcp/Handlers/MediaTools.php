<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * MCP tools: media (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait MediaTools
{
    /** list_media (seznam_medii) */
    private function toolListMedia(string $name, array $a): mixed
    {
        $db = $this->app->db();

        $search = is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '' ? '%' . addcslashes(trim($a['hledat']), '%_\\') . '%' : null;

        return array_map(fn (array $o): array => $this->medium($o),
            $db->all('SELECT * FROM {media}' . ($search !== null ? ' WHERE nazev LIKE ? OR obr_poloha LIKE ?' : '') . ' ORDER BY ido DESC LIMIT ?',
                [...($search !== null ? [$search, $search] : []), max(1, min(50, (int) ($a['limit'] ?? 20)))]));
    }

    /** upload_file (nahraj_soubor) */
    private function toolUploadFile(string $name, array $a): mixed
    {
        return $this->uploadFile($a);
    }

    /** update_media and delete_media */
    private function toolUpdateMedia(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $file = $db->one('SELECT * FROM {media} WHERE ido = ?', [$id]) ?? throw new \InvalidArgumentException('The file does not exist. Use list_media.');
        $need($auth->isAdmin() || (int) $file['vlastnik'] === $auth->id(), 'Only the owner of the file or an administrator can change it.');
        if ($name === 'update_media') {
            $changes = array_filter(['nazev' => isset($a['alt']) ? mb_substr(trim((string) $a['alt']), 0, 150) : null, 'popis' => isset($a['caption']) ? mb_substr(trim((string) $a['caption']), 0, 500) : null,
                'autor' => isset($a['author']) ? mb_substr(trim((string) $a['author']), 0, 120) : null], fn (?string $v): bool => $v !== null);
            if ($changes !== []) {
                $db->update('media', $changes, ['ido' => $id]);
                \Kaleta\Front\Cache::clear();
            }

            return ['id' => $id, 'path' => $file['obr_poloha'], 'changed' => array_keys($changes)];
        }
        $usedAt = array_keys(\Kaleta\Admin\Modules\Media::findUsagesElsewhere($db)[$id] ?? []);
        if ($usedAt !== [] || $db->value('SELECT 1 FROM {media_pouziti} WHERE ido = ? LIMIT 1', [$id]) !== null) {
            throw new \DomainException('The file is still used on the site' . ($usedAt !== [] ? ': ' . implode(', ', array_slice($usedAt, 0, 5)) : ' (in a news item)') . ' – remove it from there first.');
        }
        \Kaleta\Core\Images::delete($file['obr_poloha'], $file['nahl_poloha']);
        \Kaleta\Core\Files::delete($file['obr_poloha']);
        $db->delete('media', ['ido' => $id]);

        return ['deleted' => $id, 'path' => $file['obr_poloha']];
    }

    /** delete_media: the same as update_media */
    private function toolDeleteMedia(string $name, array $a): mixed
    {
        return $this->toolUpdateMedia($name, $a);
    }
}
