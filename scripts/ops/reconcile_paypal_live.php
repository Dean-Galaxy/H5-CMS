<?php

// The production timer is deliberately narrower than the general billing scheduler.
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
$locked = false;
try {
    $app = require $root.'/h5-platform/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
    if (! app()->environment('production') || ! app()->configurationIsCached()
        || config('billing.paypal_live.enabled') !== true
        || config('billing.paypal.enabled') !== false
        || config('billing.driver') !== 'unconfigured'
        || config('acquisition.external_enabled') !== false) {
        throw new RuntimeException('LIVE_RECONCILIATION_SCOPE_UNAVAILABLE');
    }
    // Fail before the generic command can touch another provider or environment.
    foreach (['billing_orders', 'billing_subscriptions', 'billing_payments', 'billing_inbox'] as $table) {
        if (DB::table($table)->where(fn ($q) => $q->where('provider', '<>', 'paypal')
            ->orWhere('provider_environment', '<>', 'live'))->exists()) {
            throw new RuntimeException('LIVE_RECONCILIATION_SCOPE_CONFLICT');
        }
    }
    $locked = (bool) DB::selectOne('SELECT pg_try_advisory_lock(30420601) AS locked')->locked;
    if (! $locked) {
        throw new RuntimeException('LIVE_RECONCILIATION_ALREADY_RUNNING');
    }
    // Existing routines own signatures, idempotency, cancellation and trusted facts.
    // --limit is per category; the existing pending-operation retries are capped at 100.
    $exit = $kernel->call('billing:reconcile', ['--limit' => 10]);
    $inbox = DB::table('billing_inbox')->where('state', 'pending')->whereNotNull('last_error')->count();
    $outbox = DB::table('billing_outbox')->whereNull('delivered_at')->whereNotNull('last_error')->count();
    $ok = $exit === 0 && $inbox === 0 && $outbox === 0;
    echo json_encode(['ok' => $ok, 'scope' => 'paypal_live', 'command_exit' => $exit,
        'inbox_errors' => $inbox, 'outbox_errors' => $outbox], JSON_THROW_ON_ERROR).PHP_EOL;
    $exit = $ok ? 0 : 1;
} catch (Throwable) {
    // Never print provider responses, account data, secrets or exception messages.
    fwrite(STDERR, "PAYPAL_LIVE_RECONCILIATION_FAILED\n");
    $exit = 1;
} finally {
    if ($locked) {
        DB::selectOne('SELECT pg_advisory_unlock(30420601)');
    }
}
exit($exit);
