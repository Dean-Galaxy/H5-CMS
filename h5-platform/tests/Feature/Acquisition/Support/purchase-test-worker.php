<?php

use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\SinglePurchaseTestSender;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $id, $mode, $directory] = $argv;
$root = realpath(base_path('..'));
$owned = json_decode(file_get_contents($root.'/.runtime/r1-a-owned.json'), true);
$db = DB::connection()->getDatabaseName();
if (PHP_SAPI !== 'cli' || ! app()->environment('testing') || $db !== getenv('H5_TEST_DATABASE')
    || ($owned['root'] ?? '') !== $root || ! in_array($db, $owned['databases'] ?? [], true)
    || preg_match('~^'.preg_quote(realpath(sys_get_temp_dir()), '~').'/h5-purchase-once-[a-f0-9]{16}/[a-f0-9]{16}$~D', realpath($directory) ?: '') !== 1
    || ! in_array($mode, ['hold', 'complete'], true)) {
    throw new RuntimeException('Marked synthetic Purchase testing only.');
}
$app->register(AcquisitionServiceProvider::class);
$input = json_decode(file_get_contents($directory.'/config.json'), true);
config($input['config']);
CarbonImmutable::setTestNow($input['now']);
$app->instance('env', 'staging');
config(['app.env' => 'staging']);
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'staging';
putenv('APP_ENV=staging');
Http::preventStrayRequests();
Http::fake(function ($request) use ($directory) {
    file_put_contents($directory.'/io', '1');

    return Http::response(str_contains($request->url(), 'graph.facebook.com') ? ['events_received' => 1] : ['code' => 0], 200);
});
if ($mode === 'hold') {
    $armed = true;
    DB::listen(function (QueryExecuted $q) use (&$armed, $directory) {
        if ($armed && str_starts_with($q->sql, 'update "acquisition_deliveries"') && in_array('PURCHASE_TEST_RESERVED_NO_RETRY', $q->bindings, true)) {
            $armed = false;
            DB::afterCommit(function () use ($directory) {
                file_put_contents($directory.'/reserved', '1');
                $until = microtime(true) + 12;
                while (! is_file($directory.'/release') && microtime(true) < $until) {
                    usleep(10000);
                }
                if (! is_file($directory.'/release')) {
                    exit(3);
                }
            });
        }
    });
}
$result = app(SinglePurchaseTestSender::class)->send($id);
echo json_encode($result);
exit($result['state'] === 'api_accepted' ? 0 : 1);
