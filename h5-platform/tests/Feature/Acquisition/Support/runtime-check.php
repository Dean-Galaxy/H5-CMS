<?php

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\AttributionService;
use App\Contracts\PromotableCatalog;
use Illuminate\Contracts\Console\Kernel;

// Read-only CLI evidence; no fixtures, credentials, or writes to application data.
require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('local', 'testing')) {
    throw new LogicException('Only isolated local/testing inspection is supported.');
}
$status = ['contract' => config('h5.contract'), 'environment' => app()->environment(),
    'workspace' => config('h5.workspace'), 'local_modules' => config('h5.local_modules'),
    'local_us_test_region' => config('h5.local_us_test_region'), 'acquisition_ready' => config('h5.modules.acquisition.ready'),
    'transport' => config('acquisition.transport', 'disabled'),
    'attribution_enabled' => app(AttributionService::class)->enabled(),
    'snapshot_null' => app(AttributionService::class)->snapshot(null),
    'staff_directory' => get_class(app(AcquisitionStaffDirectory::class)),
    'promotable_catalog' => get_class(app(PromotableCatalog::class)),
    'database' => config('database.connections.pgsql.database'), 'db_port' => config('database.connections.pgsql.port')];
echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
