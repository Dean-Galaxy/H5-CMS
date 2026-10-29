<?php

use App\Contracts\RegionResolver;
use App\Data\CheckoutInput;
use App\Data\ResolvedRegion;
use App\Data\ResumeInput;
use App\Data\ViewerIdentity;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Data\ExternalPayment;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\PaymentLedger;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Feature\Billing\Support\FakeGateway;

$started = hrtime(true);
$emit = static function (string $phase, array $data = []) use ($started): void {
    echo json_encode(['phase' => $phase, 'elapsed_seconds' => (hrtime(true) - $started) / 1e9, ...$data], JSON_THROW_ON_ERROR)."\n";
    flush();
};
$emit('entered', ['pid' => getmypid()]);
try {
    require __DIR__.'/../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = config('database.connections.pgsql.database');
    if (! app()->environment('testing') || ! is_string($db) || ! str_ends_with($db, '_test') || $db !== getenv('H5_TEST_DATABASE')) {
        throw new RuntimeException('Billing worker refused a non-isolated test database.');
    }
    $providerPath = (new ReflectionClass(BillingServiceProvider::class))->getFileName();
    if (realpath($providerPath) !== realpath(base_path('app/Modules/Billing/BillingServiceProvider.php'))) {
        throw new RuntimeException('Billing worker autoloaded a different worktree.');
    }
    $data = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    $fixturePath = realpath($data['fixture_path']);
    $allowedRoot = realpath(base_path('../.runtime/billing-races')).DIRECTORY_SEPARATOR;
    if (! $fixturePath || ! str_starts_with($fixturePath, $allowedRoot)) {
        throw new RuntimeException('Billing worker fixture must belong to this worktree.');
    }
    $fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
    $app->register(BillingServiceProvider::class);
    config(['billing.quote_policy_approved' => true, 'session.driver' => 'array', 'billing.driver' => 'unconfigured']);
    config(['billing.new_purchase_provider' => 'stripe']);
    $gateway = new FakeGateway;
    foreach (['subscriptions', 'invoices', 'checkouts', 'refunds'] as $field) {
        $gateway->{$field} = $fixture[$field] ?? [];
    }
    $app->instance(PaymentGateway::class, $gateway);
    $app->bind(RegionResolver::class, fn () => new class implements RegionResolver
    {
        public function resolve(): ResolvedRegion
        {
            return new ResolvedRegion('US', 'local_us_test');
        }
    });
    // An accidental Stripe call in a future code change must never leave this test.
    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            throw new RuntimeException('Real payment HTTP is forbidden in a billing concurrency test.');
        }
    });
    $user = Viewer::findOrFail($data['viewer_id']);
    $viewer = new ViewerIdentity($user->id, $user->email, $user->email_verified_at !== null);
    $engine = app(BillingEngine::class);
    $emit('ready', ['pg_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid, 'database' => $db, 'app_base' => base_path()]);
    $result = ['code' => 'OK'];
    try {
        switch ($data['action']) {
            case 'checkout':
                $checkout = $engine->checkout($viewer,
                    new CheckoutInput($data['offer_id'], $data['price_version'], '/membership'), $data['idempotency_key'], null);
                $result['order_id'] = $checkout->order_id;
                break;
            case 'settle':
                app(SettlementService::class)->settleInvoice($data['invoice_id']);
                break;
            case 'provider_settle':
                $f = $fixture['provider_payment'];
                $scope = new ProviderScope($f['provider'], $f['environment'], $f['merchant_scope']);
                $fact = new ExternalPayment($scope, $f['reference'], $f['charge_id'],
                    $f['subscription_id'], $f['customer_id'], $f['first_order_id'], true, 300, 1200, 'USD',
                    new DateTimeImmutable($f['period_start']), new DateTimeImmutable($f['period_end']), new DateTimeImmutable($f['paid_at']));
                $result['payment_id'] = app(PaymentLedger::class)->record($data['local_subscription_id'], $fact);
                break;
            case 'resume':
                $input = $data['confirmation'];
                $membership = $engine->resume($viewer, new ResumeInput(
                    $input['quote_id'], $input['confirmation_version'], $input['confirmed_next_renewal_at'],
                    $input['confirmed_amount_minor'], $input['confirmed_currency'],
                ));
                $result['renewal_enabled'] = $membership->renewal_enabled;
                break;
            default:
                throw new RuntimeException('Unknown billing race action.');
        }
    } catch (ApiProblem $error) {
        $result['code'] = $error->errorCode;
    }
    $emit('result', ['result' => [...$result, 'create_calls' => $gateway->createCalls,
        'renewal_calls' => $gateway->renewalCalls, 'subscriptions' => $gateway->subscriptions]]);
} catch (Throwable $error) {
    $emit('error', ['class' => $error::class, 'message' => $error->getMessage()]);
    exit(1);
}
