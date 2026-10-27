<?php
// Read-only, fixed-field snapshot of this checkout's unconfigured local Sandbox preview.
$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
$app = require $root.'/h5-platform/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if ($c['root'] !== $root || ! $app->environment('local') || config('database.connections.pgsql.database') !== $c['db']
    || (int) config('database.connections.pgsql.port') !== $c['db_port'] || config('billing.paypal.enabled') !== false
    || config('billing.new_purchase_provider') !== 'unconfigured') {
    throw new LogicException('Snapshot only supports this checkout unconfigured local preview.');
}
$counts = [];
foreach (['billing_orders', 'billing_subscriptions', 'billing_payments', 'billing_refunds', 'billing_entitlements',
    'billing_inbox', 'billing_outbox', 'billing_provider_intents', 'billing_paypal_observations'] as $table) {
    $counts[$table] = Illuminate\Support\Facades\DB::table($table)->count();
}
echo json_encode(['workspace' => $c['workspace'], 'database' => $c['db'], 'db_port' => $c['db_port'],
    'app_env' => $app->environment(), 'contract' => config('h5.contract'), 'paypal_enabled' => config('billing.paypal.enabled'),
    'purchase_provider' => config('billing.new_purchase_provider'), 'local_region_fixture' => config('h5.local_us_test_region'),
    'mail' => config('mail.default'), 'acquisition_ready' => config('h5.modules.acquisition.ready'),
    'migrations' => Illuminate\Support\Facades\DB::table('migrations')->count(), 'counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
