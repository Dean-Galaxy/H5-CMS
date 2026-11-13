<?php

require dirname(__DIR__).'/staging_content_policy.php';

use H5Ops\StagingContentPolicy;

$closed = ['environment' => 'staging', 'workspace' => 'h5_page_test', 'controlled' => true,
    'acquisition_test' => true, 'cached' => true, 'mail' => 'array', 'driver' => 'unconfigured',
    'origin' => 'https://staging.sparklingshort.com', 'provider' => 'unconfigured',
    'sandbox' => ['enabled' => false], 'live' => ['enabled' => false],
    'ads' => ['transport' => 'disabled', 'external_enabled' => false, 'platform_reviewed' => false,
        'analytics_enabled' => false, 'measurement.enabled' => false, 'measurement.synthetic_policy' => false,
        'registration.observations_enabled' => false, 'registration.synthetic_policy' => false]];
$sandbox = $closed;
$sandbox['provider'] = 'paypal';
$sandbox['sandbox'] = ['enabled' => true, 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret',
    'webhook_id' => 'WH-SYNTHETIC', 'merchant_id' => 'SYNTHETICMERCHANT', 'merchant_scope' => 'stage-payment-test',
    'product_id' => 'PROD-ABCDEFGHIJKLMNOPQ', 'webhook_url' => 'https://staging.sparklingshort.com/webhooks/paypal'];
$count = 0;
$check = function (array $configuration, ?string $expected, ?callable $binding = null) use (&$count): void {
    try {
        $actual = StagingContentPolicy::check($configuration, $binding ?? static fn () => null);
    } catch (Throwable) {
        $actual = null;
    }
    if ($actual !== $expected) {
        throw new RuntimeException('STAGING_POLICY_EXPECTATION_FAILED');
    }
    $count++;
};
$check($closed, 'disabled', static fn () => throw new RuntimeException('Must not need a Sandbox binding'));
$calls = 0;
$check($sandbox, 'paypal_sandbox', static function () use (&$calls): void { $calls++; });
if ($calls !== 1) { throw new RuntimeException('BINDING_NOT_CHECKED'); }
$check($sandbox, null, static fn () => throw new RuntimeException('Synthetic binding mismatch'));
foreach (['production', 'local', 'testing'] as $env) {
    $bad = $sandbox; $bad['environment'] = $env; $check($bad, null);
}
foreach (['controlled', 'acquisition_test', 'cached'] as $key) {
    foreach ([false, 'true', 1] as $value) { $bad = $sandbox; $bad[$key] = $value; $check($bad, null); }
}
foreach (['workspace' => 'h5_staging', 'mail' => 'smtp', 'driver' => 'stripe_test', 'provider' => 'paypal_live',
    'origin' => 'https://sparklingshort.com', 'stripe_secret' => 'synthetic-mixed', 'stripe_webhook_secret' => 'synthetic-mixed'] as $key => $value) {
    $bad = $sandbox; $bad[$key] = $value; $check($bad, null);
}
foreach ([true, 'false', 0] as $value) { $bad = $sandbox; $bad['live']['enabled'] = $value; $check($bad, null); }
foreach ([false, 'true', 1] as $value) { $bad = $sandbox; $bad['sandbox']['enabled'] = $value; $check($bad, null); }
$bad = $closed; $bad['sandbox']['enabled'] = true; $check($bad, null);
foreach (['client_id', 'client_secret', 'webhook_id', 'merchant_id', 'merchant_scope', 'product_id', 'webhook_url'] as $key) {
    $bad = $sandbox; unset($bad['sandbox'][$key]); $check($bad, null);
    $bad = $sandbox; $bad['live'][$key] = $sandbox['sandbox'][$key]; $check($bad, null);
}
foreach (['webhook_id' => 'WEBHOOK_ID', 'merchant_scope' => 'INVALID SCOPE', 'merchant_id' => 'bad merchant',
    'product_id' => 'INVALID', 'webhook_url' => 'https://sparklingshort.com/webhooks/paypal',
    'client_secret' => "invalid\nsecret"] as $key => $value) {
    $bad = $sandbox; $bad['sandbox'][$key] = $value; $check($bad, null);
}
foreach (array_keys($sandbox['ads']) as $key) {
    foreach ([$closed, $sandbox] as $base) {
        $bad = $base; $bad['ads'][$key] = $key === 'transport' ? 'http' : true; $check($bad, null);
        $bad = $base; $bad['ads'][$key] = 'false'; $check($bad, null);
    }
}
// Exercise the exact callback used by the wrapper with real Laravel services and
// a disposable in-memory SQL binding table. This is not the target PostgreSQL/runtime.
$root = dirname(__DIR__, 3);
require $root.'/h5-platform/vendor/autoload.php';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'staging';
$app = new Illuminate\Foundation\Application($root.'/h5-platform');
$app->instance('env', 'staging');
$config = new Illuminate\Config\Repository;
$app->instance('config', $config);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$acquisition = [];
foreach ($sandbox['ads'] as $key => $value) { Illuminate\Support\Arr::set($acquisition, $key, $value); }
$config->set([
    'app.env' => 'staging', 'app.debug' => false, 'app.url' => $sandbox['origin'],
    'h5.controlled_staging' => true, 'h5.acquisition_staging_test' => true, 'h5.simulation' => false,
    'h5.local_us_test_region' => false, 'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
    'h5.local_modules' => '', 'h5.modules.acquisition.provider' => App\Modules\Acquisition\AcquisitionServiceProvider::class,
    'h5.modules.acquisition.ready' => true, 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
    'trustedproxy.proxies' => [], 'mail.driver' => null, 'mail.default' => 'array',
    'mail.mailers.array' => ['transport' => 'array'], 'mail.from.address' => 'staging@example.invalid',
    'billing.new_purchase_provider' => 'paypal', 'billing.paypal' => $sandbox['sandbox'], 'billing.paypal_live' => ['enabled' => false],
    'acquisition' => $acquisition, 'database.default' => 'ops_fixture',
    'database.connections.ops_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
]);
$app->register(Illuminate\Events\EventServiceProvider::class);
$app->register(Illuminate\Database\DatabaseServiceProvider::class);
$app->instance(App\Modules\Billing\Gateway\PayPal\SandboxApi::class, new App\Modules\Billing\Gateway\PayPal\SandboxApi);
$migration = require $root.'/h5-platform/database/migrations/2026_10_28_200700_add_paypal_merchant_bindings.php';
$migration->up();
$binding = [StagingContentPolicy::class, 'assertLocalSandboxBinding'];
$check($closed, 'disabled', $binding); // No merchant row: the disabled lane must not query it.
$check($sandbox, null, $binding);
$row = ['merchant_scope' => $sandbox['sandbox']['merchant_scope'],
    'client_id_sha256' => hash('sha256', $sandbox['sandbox']['client_id']),
    'merchant_id' => $sandbox['sandbox']['merchant_id'], 'environment' => 'sandbox', 'binding_source' => 'operator_dashboard',
    'evidence_ref' => 'synthetic-only', 'receipt_sha256' => str_repeat('a', 64),
    'verified_at' => '2026-11-13 00:00:00', 'created_at' => '2026-11-13 00:00:00'];
Illuminate\Support\Facades\DB::table('billing_paypal_merchant_bindings')->insert($row);
$check($sandbox, 'paypal_sandbox', $binding);
foreach (['merchant_scope' => 'wrong-scope', 'client_id_sha256' => str_repeat('b', 64),
    'merchant_id' => 'WRONGMERCHANT', 'environment' => 'live', 'binding_source' => 'oauth'] as $key => $value) {
    Illuminate\Support\Facades\DB::table('billing_paypal_merchant_bindings')->delete();
    Illuminate\Support\Facades\DB::table('billing_paypal_merchant_bindings')->insert(array_replace($row, [$key => $value]));
    $check($sandbox, null, $binding);
}
Illuminate\Support\Facades\DB::disconnect('ops_fixture');
echo json_encode(['scope' => 'content configuration policy and real local Sandbox binding services', 'cases' => $count,
    'accepted' => ['disabled', 'paypal_sandbox'], 'rejected_matrix_passed' => true,
    'real_binding_service_sqlite_cases' => 8, 'native_wrapper_paths_pg_or_official_provider_tested' => false], JSON_THROW_ON_ERROR).PHP_EOL;
