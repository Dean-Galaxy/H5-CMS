<?php

/** Disposable testing fixtures for real HTTP/admin browsers; never Stripe evidence. */
use App\Contracts\RegionResolver;
use App\Data\CheckoutInput;
use App\Data\ResolvedRegion;
use App\Data\ViewerIdentity;
use App\Enums\Role;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingAdministration;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use Carbon\CarbonImmutable;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\Support\FakeGateway;

umask(0077);
$root = dirname(__DIR__, 5);
$run = $root.'/.runtime';
$credentialPath = $run.'/03-admin-browser-credentials.json';
$mode = $argv[1] ?? 'seed';
$phase = 'environment_guard';
$credentialsWritten = false;

function billingBrowserRequire(bool $condition, string $code): void
{
    if (! $condition) {
        throw new LogicException($code);
    }
}

function billingBrowserWritePrivate(string $path, array $data, bool $newOnly = false): void
{
    billingBrowserRequire(! is_link($path) && (! $newOnly || ! file_exists($path)), 'FIXTURE_FILE_EXISTS_OR_UNSAFE');
    $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
    try {
        $handle = fopen($temporary, 'x');
        billingBrowserRequire($handle !== false, 'FIXTURE_PRIVATE_WRITE_FAILED');
        chmod($temporary, 0600);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = fwrite($handle, $json);
        fclose($handle);
        billingBrowserRequire($written === strlen($json) && rename($temporary, $path), 'FIXTURE_PRIVATE_WRITE_FAILED');
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/** Only the fixture process uses this synthetic session; browsers must log in normally. */
function billingBrowserActAs(Staff $staff): void
{
    Auth::guard('staff')->login($staff);
    session()->put('identity.version.staff', $staff->session_version);
    session()->put('identity.mfa_staff', $staff->id);
}

/** Simulated provider facts enter the same Billing services as verified official facts. */
function billingBrowserPaid(FakeGateway $gateway, string $label): array
{
    $viewer = Viewer::forceCreate([
        'id' => BillingStore::id(), 'name' => '03 browser TEST '.$label,
        'email' => '03-browser-'.bin2hex(random_bytes(12)).'@example.test',
        'password' => bin2hex(random_bytes(24)).'Aa9!', 'active' => true, 'email_verified_at' => now(),
    ]);
    $identity = new ViewerIdentity($viewer->id, $viewer->email, true);
    $engine = app(BillingEngine::class);
    $offer = $engine->offer($identity);
    $checkout = $engine->checkout($identity, new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), BillingStore::id(), null);
    $order = DB::table('billing_orders')->find($checkout->order_id);
    $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
    $sid = 'sub_browser_test_'.$sub->id;
    $iid = 'in_browser_test_'.$order->id;
    $charge = 'ch_browser_test_'.$order->id;
    $start = CarbonImmutable::now('UTC')->startOfSecond();
    $end = $start->addWeek();
    $gateway->subscriptions[$sid] = [
        'id' => $sid, 'customer_id' => $sub->customer_id, 'status' => 'active', 'cancel_at_period_end' => false,
        'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end),
        'regular_amount_minor' => 1200, 'currency' => 'USD', 'order_id' => $order->id,
        'latest_invoice_id' => $iid, 'is_test' => true,
    ];
    $gateway->checkouts[$order->checkout_id] = [
        'id' => $order->checkout_id, 'status' => 'complete', 'payment_status' => 'paid',
        'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id, 'is_test' => true,
    ];
    $gateway->invoices[$iid] = [
        'id' => $iid, 'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id,
        'amount_minor' => 300, 'currency' => 'USD', 'period_start' => BillingStore::iso($start),
        'period_end' => BillingStore::iso($end), 'paid_at' => BillingStore::iso($start),
        'status' => 'paid', 'paid' => true, 'verified_payment' => true, 'charge_id' => $charge, 'is_test' => true,
        'billing_reason' => 'subscription_create', 'regular_amount_minor' => 1200,
        'discount_amount_minor' => 900, 'payment_state' => 'succeeded',
    ];
    app(SettlementService::class)->syncCheckout($order->id);

    return ['viewer_id' => $viewer->id, 'order_id' => $order->id, 'subscription_id' => $sub->id,
        'provider_subscription_id' => $sid, 'charge_id' => $charge, 'is_test' => true];
}

/** Stable full-row evidence for the two seeded account ledgers, never printed. */
function billingBrowserLedgerDigest(array $scenario): array
{
    $orders = DB::table('billing_orders')->where('id', $scenario['order_id'])
        ->where('viewer_id', $scenario['viewer_id'])->orderBy('id')->get()->all();
    $subscriptions = DB::table('billing_subscriptions')->where('id', $scenario['subscription_id'])
        ->where('viewer_id', $scenario['viewer_id'])->orderBy('id')->get()->all();
    $payments = DB::table('billing_payments')->where('order_id', $scenario['order_id'])
        ->where('viewer_id', $scenario['viewer_id'])->where('subscription_id', $scenario['subscription_id'])
        ->orderBy('id')->get()->all();
    $paymentIds = array_map(static fn ($payment) => $payment->id, $payments);
    $entitlements = DB::table('billing_entitlements')->whereIn('payment_id', $paymentIds)
        ->where('viewer_id', $scenario['viewer_id'])->where('subscription_id', $scenario['subscription_id'])
        ->orderBy('id')->get()->all();
    $outbox = DB::table('billing_outbox')->whereIn('payment_id', $paymentIds)->orderBy('id')->get()->all();
    $facts = compact('orders', 'subscriptions', 'payments', 'entitlements', 'outbox');

    return ['sha256' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR)),
        'counts' => array_map('count', $facts)];
}

try {
    billingBrowserRequire(PHP_SAPI === 'cli' && in_array($mode, ['seed', 'code', 'check', 'verify-ui'], true), 'FIXTURE_CLI_ONLY');
    billingBrowserRequire(realpath($run) === $run && is_file($run.'/workspace.json') && ! is_link($run.'/workspace.json'), 'FIXTURE_RUNTIME_UNSAFE');
    $workspace = json_decode(file_get_contents($run.'/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
    billingBrowserRequire(($workspace['root'] ?? null) === $root && realpath($root) === $root
        && preg_match('/\Ah5_[a-f0-9]{10}\z/D', $workspace['db'] ?? '') === 1, 'FIXTURE_WORKSPACE_MISMATCH');
    $database = $workspace['db'].'_test';
    billingBrowserRequire(getenv('APP_ENV') === 'testing' && getenv('DB_CONNECTION') === 'pgsql'
        && getenv('DB_HOST') === '127.0.0.1' && getenv('DB_PORT') === (string) $workspace['db_port']
        && getenv('DB_DATABASE') === $database && getenv('H5_TEST_DATABASE') === $database
        && getenv('H5_WORKSPACE') === $workspace['workspace'].'_test', 'FIXTURE_TEST_DATABASE_REQUIRED');
    if ($mode === 'seed') {
        billingBrowserRequire(! file_exists($credentialPath) && ! is_link($credentialPath), 'FIXTURE_ALREADY_PREPARED');
    }

    require $root.'/h5-platform/vendor/autoload.php';
    $app = require $root.'/h5-platform/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $url = parse_url((string) config('app.url'));
    billingBrowserRequire($app->environment('testing') && config('app.env') === 'testing'
        && config('database.default') === 'pgsql' && config('database.connections.pgsql.host') === '127.0.0.1'
        && in_array(config('database.connections.pgsql.url'), [null, ''], true)
        && (int) config('database.connections.pgsql.port') === (int) $workspace['db_port']
        && config('database.connections.pgsql.database') === $database
        && config('h5.workspace') === $workspace['workspace'].'_test'
        && config('h5.controlled_staging') === false && config('h5.local_modules') === ''
        && config('billing.driver') === 'unconfigured' && config('mail.default') === 'array'
        && in_array(config('billing.stripe_secret'), [null, ''], true)
        && in_array(config('billing.stripe_webhook_secret'), [null, ''], true)
        && is_array($url) && ($url['scheme'] ?? null) === 'http' && ($url['host'] ?? null) === '127.0.0.1'
        && isset($url['port']) && $url['port'] >= 1024 && $url['port'] <= 65535
        && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])
        && in_array($url['path'] ?? '', ['', '/'], true), 'FIXTURE_LOADED_CONFIGURATION_UNSAFE');
    // All checks above occur before opening a database connection.
    $actual = DB::selectOne('SELECT current_database() AS name, inet_server_port() AS port');
    billingBrowserRequire($actual->name === $database && (int) $actual->port === (int) $workspace['db_port'], 'FIXTURE_DATABASE_CONNECTION_MISMATCH');

    if ($mode === 'check') {
        // This mode has no dependency on migrations/tables and performs no writes.
        echo json_encode(['is_test' => true, 'database' => $database, 'database_guard' => 'passed',
            'fixture_written' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    if ($mode === 'verify-ui') {
        $phase = 'verify_ui';
        billingBrowserRequire(is_file($credentialPath) && ! is_link($credentialPath)
            && (fileperms($credentialPath) & 0777) === 0600, 'FIXTURE_CREDENTIAL_FILE_UNSAFE');
        $credentials = json_decode(file_get_contents($credentialPath), true, flags: JSON_THROW_ON_ERROR);
        billingBrowserRequire(($credentials['root'] ?? null) === $root && ($credentials['database'] ?? null) === $database
            && ($credentials['is_test'] ?? null) === true && isset($credentials['samples']['ledger_baseline']),
            'FIXTURE_BASELINE_REQUIRED');
        $verification = DB::transaction(function () use ($credentials) {
            // Prevent accidental writes and compare all observations in one consistent snapshot.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $samples = $credentials['samples'];
            $price = DB::table('billing_prices')->orderByDesc('version')->first();
            $checks = [
                'current_price_matches' => $price !== null && $price->regular_amount_minor === 1234
                    && $price->first_week_amount_minor === 321 && $price->currency === 'USD' && $price->is_test === true,
                'price_version_incremented_once' => $price !== null && $price->version === $samples['price_version'] + 1,
            ];
            $counts = ['orders' => 0, 'subscriptions' => 0, 'payments' => 0, 'entitlements' => 0, 'outbox' => 0];
            foreach (['active' => 0, 'partial_refund' => 125] as $name => $expectedRefund) {
                $scenario = $samples[$name];
                $order = DB::table('billing_orders')->where('id', $scenario['order_id'])
                    ->where('viewer_id', $scenario['viewer_id'])->first();
                $subscription = DB::table('billing_subscriptions')->where('id', $scenario['subscription_id'])
                    ->where('viewer_id', $scenario['viewer_id'])->first();
                $payment = DB::table('billing_payments')->where('order_id', $scenario['order_id'])
                    ->where('viewer_id', $scenario['viewer_id'])->first();
                $checks[$name.'_amounts_unchanged'] = $order !== null && $order->amount_minor === 300
                    && $order->regular_amount_minor === 1200 && $order->is_test === true
                    && $subscription !== null && $subscription->regular_amount_minor === 1200
                    && $subscription->is_test === true && $payment !== null && $payment->amount_minor === 300
                    && $payment->refunded_amount_minor === $expectedRefund && $payment->is_test === true;
                $digest = billingBrowserLedgerDigest($scenario);
                $baseline = $samples['ledger_baseline'][$name];
                $checks[$name.'_ledger_unchanged'] = $digest['counts'] === array_fill_keys(array_keys($counts), 1)
                    && $digest['counts'] === $baseline['counts']
                    && is_string($baseline['sha256'] ?? null) && hash_equals($baseline['sha256'], $digest['sha256']);
                foreach ($counts as $table => $count) {
                    $counts[$table] += $digest['counts'][$table];
                }
            }
            $supportId = $credentials['staff']['support']['id'];
            $caseId = $samples['cases']['support'];
            $body = '03 browser TEST: saved through the real browser form.';
            $noteCount = DB::table('billing_notes')->where('case_id', $caseId)->where('staff_id', $supportId)
                ->where('body', $body)->count();
            $checks['support_case_owner_matches'] = DB::table('billing_cases')->where('id', $caseId)
                ->where('staff_id', $supportId)->where('order_id', $samples['active']['order_id'])->exists();
            $checks['support_note_saved_once'] = $noteCount === 1
                && DB::table('billing_notes')->where('case_id', $caseId)->where('body', $body)->count() === 1;
            billingBrowserRequire(! in_array(false, $checks, true), 'FIXTURE_UI_VERIFICATION_FAILED');

            return ['is_test' => true, 'verified' => true, 'official_payment_verified' => false,
                'checks' => $checks, 'counts' => $counts, 'support_saved_note_count' => $noteCount,
                'price_version' => $price->version, 'regular_amount_minor' => $price->regular_amount_minor,
                'first_week_amount_minor' => $price->first_week_amount_minor];
        });
        echo json_encode($verification, JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    if ($mode === 'code') {
        $phase = 'one_time_code';
        billingBrowserRequire(is_file($credentialPath) && ! is_link($credentialPath)
            && (fileperms($credentialPath) & 0777) === 0600, 'FIXTURE_CREDENTIAL_FILE_UNSAFE');
        $credentials = json_decode(file_get_contents($credentialPath), true, flags: JSON_THROW_ON_ERROR);
        $role = $argv[2] ?? '';
        billingBrowserRequire(in_array($role, ['owner', 'support', 'supportOther', 'content', 'acquisition'], true), 'FIXTURE_ROLE_INVALID');
        billingBrowserRequire(($credentials['root'] ?? null) === $root && ($credentials['database'] ?? null) === $database
            && ($credentials['is_test'] ?? null) === true && isset($credentials['staff'][$role]), 'FIXTURE_STAFF_NOT_FOUND');
        $entry = $credentials['staff'][$role];
        $staff = Staff::query()->whereKey($entry['id'])->where('email', $entry['email'])->first();
        billingBrowserRequire($staff !== null && $staff->active && $staff->role->value === $entry['role'], 'FIXTURE_STAFF_NOT_FOUND');
        $path = $run.'/03-admin-browser-code-'.$role.'.json';
        billingBrowserRequire(($entry['totp_file'] ?? null) === $path, 'FIXTURE_CODE_PATH_INVALID');
        billingBrowserWritePrivate($path, ['is_test' => true, 'role' => $role,
            'code' => app(AppAuthentication::class)->getCurrentCode($staff),
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString()]);
        echo json_encode(['is_test' => true, 'code_file' => $path], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    $phase = 'seed';
    config(['session.driver' => 'array']);
    $session = app('session')->driver('array');
    $session->start();
    request()->setLaravelSession($session);
    app()->instance('session.store', $session);
    app()->instance(RegionResolver::class, new class implements RegionResolver
    {
        public function resolve(): ResolvedRegion
        {
            return new ResolvedRegion('US', 'local_us_test');
        }
    });
    $gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $gateway);
    $summary = DB::transaction(function () use ($root, $run, $database, $credentialPath, $gateway, &$credentialsWritten) {
        $credentials = ['root' => $root, 'database' => $database, 'is_test' => true,
            'official_payment_verified' => false, 'staff' => []];
        $staff = [];
        foreach (['owner' => Role::Owner, 'support' => Role::Support, 'supportOther' => Role::Support,
            'content' => Role::ContentOperator, 'acquisition' => Role::Acquisition] as $key => $role) {
            $password = bin2hex(random_bytes(24)).'Aa9!';
            $employee = Staff::create(['name' => '03 browser TEST '.$key,
                'email' => '03-browser-'.bin2hex(random_bytes(12)).'@example.test', 'password' => $password, 'role' => $role]);
            $secret = app(AppAuthentication::class)->generateSecret();
            $employee->saveAppAuthenticationSecret($secret);
            $staff[$key] = $employee->refresh();
            $credentials['staff'][$key] = ['id' => $employee->id, 'role' => $role->value,
                'email' => $employee->email, 'password' => $password, 'totp_secret' => $secret,
                'totp_file' => $run.'/03-admin-browser-code-'.$key.'.json'];
        }
        billingBrowserActAs($staff['owner']);
        $admin = app(BillingAdministration::class);
        $current = $admin->currentPrice();
        $price = $admin->savePrice(['version' => (int) ($current['version'] ?? 0),
            'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'is_test' => true]);
        $active = billingBrowserPaid($gateway, 'active');
        $partial = billingBrowserPaid($gateway, 'partial-refund');
        $gateway->refunds[$partial['charge_id']] = [['id' => 're_browser_test_'.BillingStore::id(),
            'amount_minor' => 125, 'currency' => 'USD', 'status' => 'succeeded']];
        app(SettlementService::class)->syncRefunds($partial['charge_id']);
        app(SettlementService::class)->syncSubscription($partial['provider_subscription_id']);
        $cases = [];
        foreach (['support' => $active, 'supportOther' => $partial] as $key => $scenario) {
            billingBrowserActAs($staff[$key]);
            $cases[$key] = $admin->openCase($scenario['order_id']);
            $admin->addNote($cases[$key], '03 browser TEST: '.$key.' owns this synthetic case; no real customer request.');
        }
        $engine = app(BillingEngine::class);
        $activeMembership = $engine->membership($active['viewer_id'], new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $partialMembership = $engine->membership($partial['viewer_id'], new DateTimeImmutable('now', new DateTimeZone('UTC')));
        billingBrowserRequire($activeMembership->access_active && $activeMembership->renewal_enabled
            && $activeMembership->next_renewal_amount_minor === 1200
            && $partialMembership->access_active && ! $partialMembership->renewal_enabled
            && DB::table('billing_payments')->where('order_id', $partial['order_id'])->value('refunded_amount_minor') === 125,
            'FIXTURE_FACT_VERIFICATION_FAILED');
        $credentials['samples'] = ['active' => $active, 'partial_refund' => $partial, 'cases' => $cases,
            'price_version' => $price['version'], 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300,
            'partial_refund_amount_minor' => 125,
            'ledger_baseline' => ['active' => billingBrowserLedgerDigest($active),
                'partial_refund' => billingBrowserLedgerDigest($partial)]];
        billingBrowserWritePrivate($credentialPath, $credentials, newOnly: true);
        $credentialsWritten = true;

        return ['is_test' => true, 'official_payment_verified' => false, 'database' => $database,
            'created' => ['staff' => 5, 'viewers' => 2, 'payments' => 2, 'refunds' => 1, 'cases' => 2, 'notes' => 2],
            'orders' => ['active' => $active['order_id'], 'partial_refund' => $partial['order_id']],
            'case_ids' => $cases, 'credentials_file' => $credentialPath];
    });
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if ($credentialsWritten && is_file($credentialPath) && ! is_link($credentialPath)) {
        unlink($credentialPath);
    }
    // Neither an SQL exception nor a framework message may expose generated credentials.
    fwrite(STDERR, json_encode(['error' => $mode === 'verify-ui' ? 'BILLING_ADMIN_BROWSER_UI_VERIFICATION_FAILED' : 'BILLING_ADMIN_BROWSER_FIXTURE_FAILED',
        'phase' => $phase], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
