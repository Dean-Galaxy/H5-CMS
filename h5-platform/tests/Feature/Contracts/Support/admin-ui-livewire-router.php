<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Contracts\Support\AdminUiLivewireFixture;

// Run only via an explicit, loopback PHP test server; never part of app routes.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$base = dirname(__DIR__, 4);
$path = realpath($base.'/public'.rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
if ($path !== false && str_starts_with($path, $base.'/public/') && is_file($path)) {
    return false;
}
require $base.'/vendor/autoload.php';
require __DIR__.'/AdminUiLivewireFixture.php';
$app = require $base.'/bootstrap/app.php';
$request = Request::capture();
$app->instance('request', $request);
$app->make(Kernel::class)->bootstrap();
abort_unless($app->environment('local'), 404);
Livewire\Livewire::component('admin-ui-livewire-fixture', AdminUiLivewireFixture::class);
Route::middleware('web')->get('/admin-ui-livewire', function () {
    return Blade::render(<<<'BLADE'
        <!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>00 Livewire 检查</title>@livewireStyles<x-admin.assets /></head>
        <body><livewire:admin-ui-livewire-fixture />
        <label>其他输入<input id="outside-input"></label>
        <button id="open-dialog" type="button" onclick="H5Admin.openDialog('outside-dialog')">打开独立弹窗</button>
        <x-admin.dialog id="outside-dialog" title="独立弹窗"><input id="dialog-input" aria-label="弹窗输入"></x-admin.dialog>
        @livewireScripts</body></html>
        BLADE);
});
$app->handleRequest($request);
