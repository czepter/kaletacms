<?php
/**
 * Kaleta - public part of the site.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = Kaleta\Core\App::boot();
(new Kaleta\Front\Kernel($app))->handle()->send();

// after the page is sent: notifications about just-published (including scheduled) articles and a check for security updates (at most once per 12 hours)
Kaleta\Core\Notifications::runInBackground($app);
Kaleta\Core\Updater::runInBackground($app);
