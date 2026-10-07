<?php

namespace Tests\Feature\Billing;

use App\Data\CheckoutInput;
use App\Enums\SubscriptionAction;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\StripeGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/** Real PostgreSQL + BillingEngine + Stripe SDK; every HTTP response below is an isolated fixture. */
final class BillingCheckoutCompatibilityTest extends BillingTestCase
{
    private object $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07T12:00:00Z'));
        config()->set([
            'billing.driver' => 'stripe_test',
            'billing.api_version' => StripeGateway::API_VERSION,
            'billing.stripe_secret' => 'sk_test_local_fixture_not_a_credential',
            'billing.stripe_webhook_secret' => 'whsec_local_fixture_not_a_credential',
            'billing.test_clock_id' => null,
            'app.url' => 'http://localhost:8203',
        ]);
        $this->app->instance(PaymentGateway::class, new StripeGateway);
        $this->engine = app(BillingEngine::class);
        $this->http = new class implements ClientInterface
        {
            public array $replies = [];

            public array $requests = [];

            public array $unexpected = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $key = strtoupper($method).' '.parse_url($absUrl, PHP_URL_PATH);
                $this->requests[] = compact('key', 'params', 'headers');
                // Never delegate to CurlClient, including for an unexpected SDK request.
                if (strtoupper($method) !== 'GET'
                    || parse_url($absUrl, PHP_URL_SCHEME) !== 'https'
                    || parse_url($absUrl, PHP_URL_HOST) !== 'api.stripe.com'
                    || empty($this->replies[$key])) {
                    $this->unexpected[] = $key;
                    throw new \RuntimeException('Unexpected isolated Stripe request: '.$key);
                }

                return [json_encode(array_shift($this->replies[$key]), JSON_THROW_ON_ERROR), 200, ['Request-Id' => 'req_compatibility_fixture']];
            }
        };
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        try {
            // BillingEngine safely catches provider exceptions; this assertion makes an
            // unexpected POST fail the test even when that exception became BILLING_PENDING.
            $this->assertSame([], $this->http->unexpected, 'No unplanned network request may be hidden by the pending-state handler.');
            foreach ($this->http->replies as $key => $remaining) {
                $this->assertSame([], $remaining, 'Expected HTTP fixture was not consumed: '.$key);
            }
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
            parent::tearDown();
        }
    }

    public function test_unknown_legacy_intent_with_no_session_keeps_original_snapshot_and_blocks_second_checkout(): void
    {
        [$viewer, $input, $key, $before] = $this->legacyIntent(attempted: true);
        $this->reply('checkout/sessions', $this->sessionList([]));
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer, $input, $key, null));
        $this->assertUnchangedIntent($before);
        $this->assertDatabaseHas('billing_orders', ['id' => $before->id, 'state' => 'processing', 'checkout_id' => null, 'last_error' => 'CHECKOUT_RESULT_UNKNOWN']);
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $before->subscription_id, 'state' => 'pending', 'resolution_required' => true]);
        $this->assertNull(DB::table('billing_accounts')->where('viewer_id', $viewer->id)->value('first_offer_used_at'));
        $membership = $this->engine->membership($viewer->id, now()->toDateTimeImmutable());
        $this->assertTrue($membership->payment_resolution_required);
        $this->assertNotContains(SubscriptionAction::Checkout, $membership->allowed_actions);

        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer, $input, 'legacy-second-purchase', null));
        $this->travel(2)->minutes();
        $this->reply('checkout/sessions', $this->sessionList([]));
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer, $input, $key, null));
        $this->assertUnchangedIntent($before);
        $this->assertSame(['GET /v1/checkout/sessions', 'GET /v1/checkout/sessions'], array_column($this->http->requests, 'key'));
        foreach ($this->http->requests as $request) {
            $this->assertSame('cus_legacy_compatibility', $request['params']['customer']);
        }
    }

    public function test_later_unique_session_recovers_original_legacy_checkout_without_creating_again(): void
    {
        [$viewer, $input, $key, $before] = $this->legacyIntent(attempted: true);
        $this->reply('checkout/sessions', $this->sessionList([]));
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer, $input, $key, null));
        $this->travel(10)->seconds();
        $session = $this->checkoutSessionFixture($before);
        $this->reply('checkout/sessions', $this->sessionList([$session]));
        $this->reply('checkout/sessions/'.$session['id'], $session);

        $recovered = $this->engine->checkout($viewer, $input, $key, null);
        $this->assertSame($before->id, $recovered->order_id);
        $this->assertSame($session['url'], $recovered->checkout_url);
        $this->assertSame(BillingStore::iso($before->expires_at), $recovered->expires_at);
        $this->assertUnchangedIntent($before);
        $this->assertDatabaseHas('billing_orders', ['id' => $before->id, 'state' => 'pending', 'checkout_id' => $session['id'], 'last_error' => null]);
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $before->subscription_id, 'customer_id' => 'cus_legacy_compatibility', 'resolution_required' => true]);
        $this->assertSame([
            'GET /v1/checkout/sessions', 'GET /v1/checkout/sessions', 'GET /v1/checkout/sessions/'.$session['id'],
        ], array_column($this->http->requests, 'key'));
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer, $input, 'legacy-after-recovery', null));
    }

    public function test_unattempted_legacy_coupon_intent_is_read_only_even_inside_initial_creation_window(): void
    {
        [$viewer, $input, $key, $before] = $this->legacyIntent(attempted: false);
        $this->assertNull($before->checkout_attempted_at);
        $this->assertSame(1860, CarbonImmutable::parse($before->expires_at)->timestamp - now()->timestamp);
        $session = $this->checkoutSessionFixture($before);
        $this->reply('checkout/sessions', $this->sessionList([$session]));
        $this->reply('checkout/sessions/'.$session['id'], $session);

        $recovered = $this->engine->checkout($viewer, $input, $key, null);
        $this->assertSame($before->id, $recovered->order_id);
        $this->assertSame($session['url'], $recovered->checkout_url);
        $this->assertUnchangedIntent($before);
        $this->assertNotNull(DB::table('billing_orders')->where('id', $before->id)->value('checkout_attempted_at'));
        $this->assertSame(['GET /v1/checkout/sessions', 'GET /v1/checkout/sessions/'.$session['id']], array_column($this->http->requests, 'key'));
    }

    /** Model a pre-F05 row exactly as it would be read after an application upgrade. */
    private function legacyIntent(bool $attempted): array
    {
        $viewer = $this->viewer();
        $offer = $this->engine->offer($viewer);
        $input = new CheckoutInput($offer->offer_id, $offer->price_version, '/membership');
        $key = 'legacy-compatible-checkout';
        $orderId = BillingStore::id();
        $subscriptionId = BillingStore::id();
        $prepared = now()->startOfSecond();
        $expires = $prepared->copy()->addSeconds(1860);
        $metadata = ['order_id' => $orderId, 'viewer_id' => $viewer->id, 'subscription_id' => $subscriptionId, 'price_version' => '1'];
        $returnUrl = 'http://localhost:8203/membership?order_id='.$orderId;
        $intent = [
            'params' => [
                'mode' => 'subscription', 'customer' => 'cus_legacy_compatibility', 'client_reference_id' => $orderId,
                'metadata' => $metadata, 'subscription_data' => ['metadata' => $metadata],
                'line_items' => [[
                    'price_data' => ['currency' => 'usd', 'unit_amount' => 1200,
                        'recurring' => ['interval' => 'week', 'interval_count' => 1],
                        'product_data' => ['name' => 'TEST — H5 weekly membership', 'metadata' => ['price_version' => '1']]],
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['card'], 'allow_promotion_codes' => false,
                'adaptive_pricing' => ['enabled' => false], 'automatic_tax' => ['enabled' => false],
                'discounts' => [['coupon' => 'coupon_legacy_compatibility']],
                'success_url' => $returnUrl, 'cancel_url' => $returnUrl, 'expires_at' => $expires->timestamp,
            ],
            'idempotency_key' => 'h5-checkout-'.$orderId, 'customer_id' => 'cus_legacy_compatibility',
            'prepared_at' => BillingStore::iso($prepared), 'expires_at' => BillingStore::iso($expires),
        ];
        DB::table('billing_accounts')->insert(['viewer_id' => $viewer->id]);
        DB::table('billing_subscriptions')->insert([
            'id' => $subscriptionId, 'viewer_id' => $viewer->id, 'first_order_id' => $orderId,
            'customer_id' => $intent['customer_id'], 'state' => 'pending', 'price_version' => 1,
            'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => true,
            'renewal_enabled' => false, 'resolution_required' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_orders')->insert([
            'id' => $orderId, 'viewer_id' => $viewer->id, 'subscription_id' => $subscriptionId, 'offer_id' => $offer->offer_id,
            'idempotency_key' => 'client:'.$key, 'fingerprint' => hash('sha256', json_encode([$offer->offer_id, $offer->price_version, '/membership'], JSON_THROW_ON_ERROR)),
            'transaction_kind' => 'first_subscription', 'price_version' => 1, 'amount_minor' => 300, 'regular_amount_minor' => 1200,
            'discount_applied' => true, 'currency' => 'USD', 'is_test' => true, 'state' => 'processing', 'payment_state' => 'pending',
            'return_to' => '/membership', 'expires_at' => $expires, 'checkout_prepared_at' => $prepared,
            'checkout_attempted_at' => $attempted ? $prepared : null, 'checkout_request' => json_encode($intent, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$viewer, $input, $key, DB::table('billing_orders')->find($orderId)];
    }

    private function assertUnchangedIntent(object $before): void
    {
        $after = DB::table('billing_orders')->find($before->id);
        $this->assertSame($before->viewer_id, $after->viewer_id);
        $this->assertSame($before->subscription_id, $after->subscription_id);
        $this->assertSame($before->idempotency_key, $after->idempotency_key);
        $this->assertSame($before->fingerprint, $after->fingerprint);
        $this->assertSame($before->checkout_request, $after->checkout_request, 'Never rewrite the persisted provider request, including its legacy false field.');
        $this->assertSame($before->checkout_prepared_at, $after->checkout_prepared_at);
        $this->assertSame($before->expires_at, $after->expires_at);
        $intent = BillingStore::json($after->checkout_request);
        $this->assertFalse($intent['params']['allow_promotion_codes']);
        $this->assertSame('coupon_legacy_compatibility', $intent['params']['discounts'][0]['coupon']);
        $this->assertSame('h5-checkout-'.$before->id, $intent['idempotency_key']);
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertDatabaseCount('billing_entitlements', 0);
        $this->assertDatabaseCount('billing_outbox', 0);
    }

    private function checkoutSessionFixture(object $order): array
    {
        return [
            'id' => 'cs_legacy_compatibility', 'object' => 'checkout.session', 'livemode' => false,
            'mode' => 'subscription', 'status' => 'open', 'customer' => 'cus_legacy_compatibility',
            'client_reference_id' => $order->id, 'metadata' => ['order_id' => $order->id],
            'amount_total' => 300, 'currency' => 'usd', 'payment_status' => 'unpaid', 'subscription' => null,
            'url' => 'https://checkout.stripe.com/c/pay/legacy_compatibility',
            'expires_at' => CarbonImmutable::parse($order->expires_at)->timestamp,
        ];
    }

    private function sessionList(array $sessions): array
    {
        return ['object' => 'list', 'has_more' => false, 'data' => $sessions];
    }

    private function reply(string $path, array $response): void
    {
        $this->http->replies['GET /v1/'.$path][] = $response;
    }
}
