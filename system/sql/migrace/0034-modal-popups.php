<?php
/**
 * Kaleta 2.0: the per-page Modal element becomes a site pop-up (Builder\ModalConversion) – with the same content,
 * trigger and frequency, shown where the Modal was; links to its anchor lead to the pop-up. Safe to run again: it only
 * touches builds that still contain a Modal.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    Kaleta\Builder\ModalConversion::run($db);
};
