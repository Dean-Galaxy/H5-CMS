<?php
/** Local cli-server only. Real app/router/middleware; provider protocol fixture only, no invented success endpoint. */
$root = dirname(__DIR__, 4);
$public = $root.'/h5-platform/public';
$target = realpath($public.(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
if ($target && str_starts_with($target, realpath($public).'/') && is_file($target)
    && in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), ['js','css','png','jpg','jpeg','svg','ico','woff','woff2','ttf','map'], true)) {
    return false;
}
require $root.'/h5-platform/vendor/autoload.php';
use Tests\Browser\Support\AnalyticsRuntime as Runtime;
$app = Runtime::boot(true);
[$gateway, $lock] = Runtime::gateway();
try {
    $app->handleRequest(Illuminate\Http\Request::capture());
} finally {
    Runtime::saveGateway($gateway, $lock);
}
