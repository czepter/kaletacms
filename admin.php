<?php
/**
 * Kaleta - admin.
 * URLs look like admin.php?module=news&action=edit&id=5 (1.3 and older: ?module=news&action=edit, see Admin\LegacyUrls).
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

// old URLs (bookmarks, links in e-mails, forms open during an update): current names before anything reads them
[$_GET, $legacyUrl] = Kaleta\Admin\LegacyUrls::normalize($_GET);
[$_POST] = Kaleta\Admin\LegacyUrls::normalize($_POST); // e.g. the settings tab of a form opened before the update
if ($legacyUrl && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? 'admin.php'), '?') . ($_GET !== [] ? '?' . http_build_query($_GET) : ''), true, 301);
    exit;
}

$app = Kaleta\Core\App::boot();
$response = (new Kaleta\Admin\Kernel($app))->handle();
// forms may submit only to the site itself; the exception is consent to connecting an application (OAuth): after submitting,
// the browser goes to the application's return URL (claude.ai, localhost for Claude Code) and CSP form-action guards this redirect too
$headers = $response->headers;
$formTargets = "'self'" . (preg_match('#^https?://[a-z0-9.\[\]:-]+$#i', $headers['X-Kaleta-Form-Action'] ?? '') ? ' ' . $headers['X-Kaleta-Form-Action'] : '');
unset($headers['X-Kaleta-Form-Action']);
// admin: nothing from it belongs in the browser or proxy cache, and it may run only its own scripts (no inline, no third-party)
(new Kaleta\Core\Response($response->body, $response->status, $headers + [
    'Cache-Control' => 'no-store, private',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' https:; "
        . "frame-src 'self' https:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action {$formTargets}; frame-ancestors 'self'",
] + ($app->request->isHttps() ? ['Strict-Transport-Security' => 'max-age=15552000'] : [])))->send();
Kaleta\Core\Webhook::afterResponse($app); // e.g. a just-published news item
