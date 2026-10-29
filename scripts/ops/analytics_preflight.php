<?php
/** 06 capability inventory in own testing environment. Never invokes financial ports or external transports. */
$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
$app = require $root.'/h5-platform/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$workspace = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || $workspace['root'] !== $root
    || config('database.connections.pgsql.database') !== $workspace['db'].'_test'
    || getenv('H5_TEST_DATABASE') !== $workspace['db'].'_test'
    || config('billing.paypal.enabled') !== false || config('billing.new_purchase_provider') !== 'unconfigured'
    || config('acquisition.external_enabled') !== false || config('acquisition.transport') !== 'disabled') {
    throw new LogicException('06 analytics preflight requires own test database and disabled external transports.');
}
$required = ['api/v1/tracking/context' => 'GET', 'api/v1/tracking/consent' => 'POST', 'api/v1/tracking/events' => 'POST', 'api/v1/tracking/landing' => 'POST'];
$routes = [];
foreach ($required as $uri => $method) {
    $routes[$uri] = false;
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
            $routes[$uri] = true;
        }
    }
}
$recorder = get_class(app(App\Contracts\BehaviorEventRecorder::class));
$reader = get_class(app(App\Contracts\BillingAnalyticsReader::class));
$missing = [];
foreach ($routes as $uri => $present) {
    if (! $present) { $missing[] = 'route:'.$uri; }
}
if ($recorder === App\Support\DisabledBehaviorEventRecorder::class) { $missing[] = '05:recorder'; }
if ($reader === App\Support\UnavailableBillingAnalyticsReader::class) { $missing[] = '03:financial_reader'; }
if (! view()->exists('audience.partials.tracking')) { $missing[] = '04:tracking_partial'; }
echo json_encode(['schema' => 1, 'contract' => 'analytics-v1', 'routes' => $routes,
    'bindings' => ['recorder' => $recorder, 'financial_reader' => $reader], 'missing' => $missing,
    'combined_ready' => $missing === [], 'scope' => 'capability preflight, not consumer acceptance',
    'external_enabled' => false, 'transport' => 'disabled'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
