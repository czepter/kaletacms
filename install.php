<?php
/**
 * Kaleta - installation. In the browser, or from the command line: php install.php --help
 * After a successful installation the file deletes itself (in a development copy delete it yourself).
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

if (PHP_SAPI === 'cli') {
    exit((new Kaleta\Install\Installer())->cli($_SERVER['argv'] ?? []));
}

(new Kaleta\Install\Installer())->handle()->send();
