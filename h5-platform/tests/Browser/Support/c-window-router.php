<?php
/** 06 C synthetic window: guest reads, local free media and privacy operations only. */
$root = dirname(__DIR__, 4);
require $root.'/h5-platform/vendor/autoload.php';
$app = Tests\Browser\Support\CWindowRuntime::boot(true);
header('X-06-C-Window: synthetic-only');
header('X-06-C-Gateway: UnconfiguredGateway');
$request = Illuminate\Http\Request::capture();
$path = $request->getPathInfo();
$read = in_array($request->method(), ['GET', 'HEAD'], true);
if (! Tests\Browser\Support\CWindowBoundary::allows($request->method(), $path)) {
    http_response_code(403); header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['error' => 'C_SYNTHETIC_PRIVACY_WINDOW_ONLY']); return;
}
Tests\Browser\Support\CWindowStorageFault::install($request);
$public = realpath($root.'/h5-platform/public');
$target = realpath($public.$path);
if ($read && $target && str_starts_with($target, $public.'/') && is_file($target)
    && in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), ['js','css','png','jpg','jpeg','svg','ico','woff','woff2','ttf'], true)) {
    // Stream after guards; PHP's static-file bypass would also bypass the per-request manifest/gateway checks.
    header('Content-Type: '.match (strtolower(pathinfo($target, PATHINFO_EXTENSION))) {
        'js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'ico' => 'image/x-icon',
        'png' => 'image/png', default => 'image/jpeg',
    });
    readfile($target); return;
}
$app->handleRequest($request);
