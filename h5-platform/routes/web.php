<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('identity.home'));
Route::get('/health', function () {
    DB::select('select 1');

    return ['status' => 'ok', 'request_id' => request()->attributes->get('request_id'),
        'modules' => collect(config('h5.modules'))->map(fn ($m) => $m['ready'] ? 'ready' : 'not_ready')->all()];
});
foreach (array_keys(config('h5.modules')) as $module) {
    require __DIR__.'/modules/'.$module.'.php';
}
