<?php

// Run only against the caller-owned disposable PostgreSQL database, never a real site.
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\ProviderInboxStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root.'/h5-platform/vendor/autoload.php';
$app = require $root.'/h5-platform/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
$database = config('database.connections.pgsql.database');
check(app()->environment('testing') && is_string($database) && str_ends_with($database, '_test')
    && $database === getenv('H5_TEST_DATABASE'), 'Disposable test database required');
$cache = getenv('H5_RECONCILIATION_TEST_CACHE');
check(is_string($cache) && str_starts_with($cache, $root.'/.runtime/00-paid-closure-20261106/'), 'Private test cache required');
check(! file_exists($cache), 'Cache already exists');
$original = config()->all();
config([
    'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://billing.example.test',
    'h5.controlled_staging' => false, 'h5.simulation' => false, 'h5.local_us_test_region' => false,
    'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
    'h5.acquisition_staging_test' => false, 'h5.local_modules' => '',
    'h5.modules.acquisition' => ['provider' => null, 'ready' => false],
    'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
    'trustedproxy.proxies' => null, 'billing.driver' => 'unconfigured',
    'billing.new_purchase_provider' => 'unconfigured', 'billing.paypal.enabled' => false,
    'billing.paypal_live.enabled' => true,
    // Missing credentials make every official HTTP request unavailable before I/O.
    'billing.paypal_live.client_id' => null, 'billing.paypal_live.client_secret' => null,
    'acquisition.external_enabled' => false,
    'mail.default' => 'smtp', 'mail.driver' => null, 'mail.from.address' => 'noreply@example.test',
    'mail.mailers' => ['smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
        'host' => 'smtp.example.test', 'port' => '587', 'timeout' => 10,
        'username' => 'SYNTHETIC_NOT_SENT', 'password' => 'SYNTHETIC_NOT_SENT',
        'require_tls' => true, 'verify_peer' => true]],
]);
$production = config()->all();
$env = getenv();
$env['APP_ENV'] = 'production';
$env['APP_CONFIG_CACHE'] = $cache;
$passed = [];
try {
    foreach (['empty', 'module_absent', 'ignored_live', 'legacy_rejected', 'api_disabled', 'pending_error',
        'consumer_deferred', 'consumer_error'] as $case) {
        DB::table('billing_inbox')->delete();
        DB::table('billing_outbox')->delete();
        config()->set($production);
        if ($case === 'api_disabled') {
            config(['billing.paypal_live.enabled' => false]);
        }
        if ($case === 'module_absent') {
            config(['acquisition' => []]);
        }
        if (in_array($case, ['consumer_deferred', 'consumer_error'], true)) {
            // Reporting-only fixture, deliberately no payment/entitlement or dispatchable paid event.
            DB::table('billing_outbox')->insert(['id' => (string) Illuminate\Support\Str::uuid(),
                'payment_id' => (string) Illuminate\Support\Str::uuid(), 'payload' => '[]', 'created_at' => now(),
                'last_error' => $case === 'consumer_deferred' ? 'CONSUMER_NOT_READY' : 'CONSUMER_RETRY_REQUIRED']);
        }
        if (in_array($case, ['ignored_live', 'legacy_rejected', 'pending_error'], true)) {
            $scope = $case === 'legacy_rejected' ? ProviderScope::legacyStripe()
                : new ProviderScope('paypal', 'live', 'synthetic_live');
            app(ProviderInboxStore::class)->receive($scope, ['id' => 'SYNTHETIC-'.$case,
                'type' => $case === 'pending_error' ? 'PAYMENT.SALE.COMPLETED' : 'synthetic.ignored',
                'object_id' => 'SYNTHETIC-OBJECT', 'created' => now()->toDateTimeImmutable(),
                'is_test' => $scope->isTest()]);
        }
        file_put_contents($cache, '<?php return '.var_export(config()->all(), true).';');
        chmod($cache, 0600);
        $process = proc_open([PHP_BINARY, $root.'/scripts/ops/reconcile_paypal_live.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        check(is_resource($process), 'Cannot start runner');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        unlink($cache);
        $expected = in_array($case, ['empty', 'module_absent', 'ignored_live', 'consumer_deferred'], true) ? 0 : 1;
        check($exit === $expected, 'Unexpected exit for '.$case.': '.$stdout.$stderr);
        $event = DB::table('billing_inbox')->first();
        if ($case === 'ignored_live') {
            check($event->state === 'ignored' && $event->attempts === 1, 'Live event not consumed');
        } elseif ($case === 'legacy_rejected') {
            check($event->state === 'pending' && $event->attempts === 0, 'Legacy event was touched');
        } elseif ($case === 'pending_error') {
            check($event->state === 'pending' && $event->last_error === 'RECONCILIATION_REQUIRED', 'Error was lost');
            check((json_decode($stdout, true)['inbox_errors'] ?? 0) === 1, 'Pending error not reported');
        } elseif ($case === 'consumer_deferred') {
            $report = json_decode($stdout, true);
            check($report['outbox_deferred'] === 1 && $report['outbox_errors'] === 0, 'Expected defer marked as error');
            check(DB::table('billing_outbox')->whereNull('delivered_at')->count() === 1, 'Deferred event was lost');
        } elseif ($case === 'consumer_error') {
            check((json_decode($stdout, true)['outbox_errors'] ?? 0) === 1, 'Real consumer error was hidden');
        }
        check(DB::table('billing_orders')->count() === 0 && DB::table('billing_payments')->count() === 0
            && DB::table('billing_entitlements')->count() === 0, 'Unexpected financial facts');
        $passed[] = $case;
    }
    echo json_encode(['passed' => $passed, 'official_io' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if (file_exists($cache)) {
        unlink($cache);
    }
    DB::table('billing_inbox')->delete();
    DB::table('billing_outbox')->delete();
    config()->set($original);
}
