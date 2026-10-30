<?php
$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
require __DIR__.'/r1_window_boundary.php';
require __DIR__.'/r1_window_runtime.php';
$_SERVER = H5R1Boundary::normalize($_SERVER, $root.'/h5-platform/public');
$app = H5R1Runtime::boot(true);
header('X-06-R1-Gateway: UnconfiguredGateway');
header('X-06-R1-Role: '.getenv('H5_R1_DB_ROLE'));
$request = Illuminate\Http\Request::capture();
$path = $request->getPathInfo();
$read = in_array($request->method(), ['GET','HEAD'], true);
$static = $read ? H5R1Boundary::staticTarget($root.'/h5-platform/public', $_SERVER['REQUEST_URI'] ?? '/') : null;
if (! H5R1Boundary::allows($request->method(), $path) && ! $static) {
    http_response_code(403); header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['error'=>'R1_SYNTHETIC_WINDOW_ONLY']); return;
}
if (getenv('H5_R1_DB_ROLE') === 'restore' && ! ($read && (in_array($path, ['/health','/api/v1/auth/me','/api/v2/measurement/context','/api/v3/measurement/purposes/context'], true) || $static))) {
    http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error'=>'R1_RESTORE_READ_ONLY_PROBE']); return;
}
if ($request->method() === 'POST' && in_array($path, ['/api/v1/auth/register','/api/v1/auth/login','/api/v1/auth/forgot-password','/api/v1/auth/reset-password'], true)
    && (! is_string($request->input('email')) || ! preg_match('/^[A-Za-z0-9._+-]+@example\.test$/D', $request->input('email')))) {
    http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error'=>'SYNTHETIC_EXAMPLE_TEST_ACCOUNT_ONLY']); return;
}
if ($static) {
    header('Content-Type: '.match (strtolower(pathinfo($static, PATHINFO_EXTENSION))) {
        'js'=>'application/javascript','css'=>'text/css','svg'=>'image/svg+xml','png'=>'image/png','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','ico'=>'image/x-icon',default=>'image/jpeg',
    });
    readfile($static); return;
}
$app->handleRequest($request);
