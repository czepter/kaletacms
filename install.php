<?php
/**
 * Kaleta - installation. Delete this file from the server after a successful installation.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

(new Kaleta\Install\Installer())->handle()->send();
