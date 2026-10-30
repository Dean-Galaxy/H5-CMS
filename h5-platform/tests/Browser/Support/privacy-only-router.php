<?php
/** AN-M01 loopback test entry point. Never installs a gateway or provider protocol fixture. */
$root = dirname(__DIR__, 4);
$public = $root.'/h5-platform/public';
$target = realpath($public.(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
if ($target && str_starts_with($target, realpath($public).'/') && is_file($target)
    && in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), ['js','css','png','jpg','jpeg','svg','ico','woff','woff2','ttf'], true)) {
    return false;
}
require $root.'/h5-platform/vendor/autoload.php';
$app = Tests\Browser\Support\AnalyticsRuntime::boot(true);
if (getenv('H5_ANALYTICS_PRIVACY_ONLY') !== '1') { throw new LogicException('PRIVACY ONLY MODE REQUIRED'); }
Illuminate\Support\Facades\Http::preventStrayRequests();
$request = Illuminate\Http\Request::capture();
$path = $request->getPathInfo();
// This test-window restriction is additional to the unchanged application guards.
$blocked = str_starts_with($path, '/webhooks/')
    || (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
        && (str_starts_with($path, '/api/v1/auth/') || str_starts_with($path, '/membership/') || str_starts_with($path, '/billing/')));
if ($blocked) {
    http_response_code(403);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'AN_M01_PRIVACY_WINDOW_ONLY']);
    return;
}
$app->handleRequest($request);
