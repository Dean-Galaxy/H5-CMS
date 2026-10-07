<?php

namespace Tests\Feature\Billing;

use App\Contracts\RegionResolver;
use App\Data\CheckoutInput;
use App\Data\ResolvedRegion;
use App\Enums\SubscriptionAction;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\StripeGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\WebhookService;
use App\Modules\Billing\Support\CheckoutSafety;
use Illuminate\Support\Facades\DB;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/** PostgreSQL regressions; synthetic local fixtures do not prove official payment readiness. */
final class BillingCheckoutSafetyTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://billing-safety.example.test']);
    }

    public function test_existing_safe_checkout_returns_official_fragment_without_creating_again(): void
    {
        request()->headers->set('Host', 'untrusted.example.test');
        request()->headers->set('Forwarded', 'host=untrusted.example.test;proto=http');
        [$viewer, $input, $key, $order] = $this->existingCheckout();
        $intent = json_decode($order->checkout_request, true, flags: JSON_THROW_ON_ERROR);
        $expected = 'https://billing-safety.example.test/membership?from=drama&order_id='.$order->id.'#plans';
        $this->assertSame($expected, $intent['params']['success_url']);
        $this->assertSame($expected, $intent['params']['cancel_url']);
        $this->assertTrue(app(CheckoutSafety::class)->requestIsSafe($order, $intent));
        $this->assertTrue(app(CheckoutSafety::class)->requestIsSafe($order, $order->checkout_request));
        $url = 'https://checkout.stripe.com:443/c/pay/'.$order->checkout_id.'#fidkdWxOYHwnPyd1blpxYHZxWjA0';
        DB::table('billing_orders')->where('id', $order->id)->update(['checkout_url' => $url]);
        $before = DB::table('billing_orders')->find($order->id);
        $result = $this->engine->checkout($viewer, $input, $key, null);
        $this->assertSame($url, $result->checkout_url);
        $this->assertSame($order->id, $result->order_id);
        $this->assertSame((array) $before, (array) DB::table('billing_orders')->find($order->id));
        $this->assertSame(1, $this->gateway->createCalls);
    }

    public function test_existing_checkout_hides_unsafe_saved_request_without_rewriting_or_releasing_it(): void
    {
        [$viewer, $input, $key, $order] = $this->existingCheckout();
        $original = json_decode($order->checkout_request, true, flags: JSON_THROW_ON_ERROR);
        $return = $original['params']['success_url'];
        $changes = [
            'old HTTP success' => ['params.success_url', str_replace('https://', 'http://', $return)],
            'old HTTP cancel' => ['params.cancel_url', str_replace('https://', 'http://', $return)],
            'foreign origin' => ['params.success_url', str_replace('billing-safety.example.test', 'foreign.example.test', $return)],
            'wrong order return' => ['params.cancel_url', str_replace($order->id, BillingStore::id(), $return)],
            'userinfo return' => ['params.success_url', str_replace('https://', 'https://viewer@', $return)],
            'wrong client reference' => ['params.client_reference_id', BillingStore::id()],
            'wrong order metadata' => ['params.metadata.order_id', BillingStore::id()],
            'wrong viewer metadata' => ['params.metadata.viewer_id', BillingStore::id()],
            'wrong subscription metadata' => ['params.subscription_data.metadata.subscription_id', BillingStore::id()],
            'wrong price metadata' => ['params.subscription_data.metadata.price_version', '2'],
            'wrong customer' => ['params.customer', 'cus_other'],
            'wrong price snapshot' => ['params.line_items.0.price_data.unit_amount', 1300],
            'wrong product snapshot' => ['params.line_items.0.price_data.product_data.metadata.price_version', '2'],
            'wrong currency' => ['params.line_items.0.price_data.currency', 'eur'],
            'wrong immutable key' => ['idempotency_key', 'h5-checkout-'.BillingStore::id()],
        ];
        foreach ($changes as $label => [$path, $value]) {
            $intent = $original;
            data_set($intent, $path, $value);
            DB::table('billing_orders')->where('id', $order->id)->update(['checkout_request' => json_encode($intent, JSON_THROW_ON_ERROR)]);
            $before = DB::table('billing_orders')->find($order->id);
            $subscription = DB::table('billing_subscriptions')->find($order->subscription_id);
            $result = $this->engine->checkout($viewer, $input, $key, null);
            $this->assertNull($result->checkout_url, $label);
            $this->assertSame((array) $before, (array) DB::table('billing_orders')->find($order->id), $label);
            $this->assertSame((array) $subscription, (array) DB::table('billing_subscriptions')->find($order->subscription_id), $label);
            $this->assertSame(1, $this->gateway->createCalls, $label);
        }
        $this->assertPendingPlaceholder($viewer, $input, $order);
    }

    public function test_existing_checkout_hides_unsafe_provider_urls_without_changing_payment_state(): void
    {
        [$viewer, $input, $key, $order] = $this->existingCheckout();
        $urls = [
            'http://checkout.stripe.com/c/pay/session',
            'https://checkout.stripe.com.attacker.example/c/pay/session',
            'https://checkout.stripe.com./c/pay/session',
            'https://viewer@checkout.stripe.com/c/pay/session',
            'https://viewer:secret@checkout.stripe.com/c/pay/session',
            'https://checkout.stripe.com:444/c/pay/session',
            'https://checkout.stripe.com:/c/pay/session',
            'https://checkout.stripe.com\\@attacker.example/c/pay/session',
            "https://checkout.stripe.com/c/pay/session\n",
            'https://checkout.stripe.com/c/pay/session%0d%0aLocation:bad',
            'https://checkout.stripe.com/c/pay/session%255csecret',
            '//checkout.stripe.com/c/pay/session',
        ];
        foreach ($urls as $url) {
            DB::table('billing_orders')->where('id', $order->id)->update(['checkout_url' => $url]);
            $before = DB::table('billing_orders')->find($order->id);
            $result = $this->engine->checkout($viewer, $input, $key, null);
            $this->assertNull($result->checkout_url);
            $this->assertSame((array) $before, (array) DB::table('billing_orders')->find($order->id));
            $this->assertSame(1, $this->gateway->createCalls);
        }
        $this->assertPendingPlaceholder($viewer, $input, $order);
    }

    public function test_changed_origin_hides_existing_checkout_and_restoring_configuration_does_not_recreate_it(): void
    {
        [$viewer, $input, $key, $order] = $this->existingCheckout();
        config(['app.url' => 'https://replacement.example.test']);
        $this->assertNull($this->engine->checkout($viewer, $input, $key, null)->checkout_url);
        $this->assertSame((array) $order, (array) DB::table('billing_orders')->find($order->id));
        config(['app.url' => 'https://billing-safety.example.test']);
        $this->assertSame($order->checkout_url, $this->engine->checkout($viewer, $input, $key, null)->checkout_url);
        $this->assertSame(1, $this->gateway->createCalls);
    }

    public function test_untrusted_or_non_us_region_is_rejected_before_gateway_or_order_reservation(): void
    {
        $viewer = $this->viewer();
        $offer = $this->engine->offer($viewer);
        $input = new CheckoutInput($offer->offer_id, $offer->price_version, '/membership');
        // This targets Engine decision ordering only. Independent subprocess tests
        // exercise real staging bootstrap; this container change is not that evidence.
        $originalEnvironment = $this->app->environment();
        try {
            foreach ([['testing', 'CA', 'trusted'], ['staging', 'ZZ', 'unresolved'], ['staging', 'US', 'local_us_test'], ['production', 'US', 'unresolved']] as [$environment, $country, $source]) {
                $this->app->instance('env', $environment);
                $this->app->instance(RegionResolver::class, new class($country, $source) implements RegionResolver
                {
                    public function __construct(private string $country, private string $source) {}

                    public function resolve(): ResolvedRegion
                    {
                        return new ResolvedRegion($this->country, $this->source);
                    }
                });
                $this->assertProblem('REGION_BLOCKED', fn () => $this->engine->checkout($viewer, $input, BillingStore::id(), null));
                $this->assertDatabaseCount('billing_orders', 0);
                $this->assertDatabaseCount('billing_subscriptions', 0);
                $this->assertDatabaseCount('billing_accounts', 0);
                $this->assertSame(0, $this->gateway->createCalls);
            }
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }
    }

    public function test_missing_stripe_credentials_never_create_orders_placeholders_or_webhook_inbox(): void
    {
        $viewer = $this->viewer();
        $offer = $this->engine->offer($viewer);
        $input = new CheckoutInput($offer->offer_id, $offer->price_version, '/membership');
        $http = new class implements ClientInterface
        {
            public int $calls = 0;

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->calls++;
                throw new \RuntimeException('Unexpected network request in configuration test');
            }
        };
        ApiRequestor::setHttpClient($http);
        try {
            foreach ([['', 'whsec_local_fixture_not_a_credential'], ['sk_test_local_fixture_not_a_credential', ''], ['', '']] as [$secret, $webhookSecret]) {
                config(['billing.driver' => 'stripe_test', 'billing.api_version' => StripeGateway::API_VERSION,
                    'billing.stripe_secret' => $secret, 'billing.stripe_webhook_secret' => $webhookSecret, 'billing.test_clock_id' => null]);
                $this->app->instance(PaymentGateway::class, new StripeGateway);
                $engine = app(BillingEngine::class);
                $this->assertProblem('DEPENDENCY_NOT_READY', fn () => $engine->checkout($viewer, $input, BillingStore::id(), null));
                $this->assertProblem('DEPENDENCY_NOT_READY', fn () => app(WebhookService::class)->receive('{}', 'not-a-signature'));
                foreach (['billing_orders', 'billing_subscriptions', 'billing_accounts', 'billing_inbox', 'billing_payments', 'billing_entitlements', 'billing_outbox'] as $table) {
                    $this->assertDatabaseCount($table, 0);
                }
            }
            $this->assertSame(0, $http->calls);
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }
    }

    private function existingCheckout(): array
    {
        $viewer = $this->viewer();
        $offer = $this->engine->offer($viewer);
        $input = new CheckoutInput($offer->offer_id, $offer->price_version, '/membership?from=drama&order_id=untrusted#plans');
        $key = BillingStore::id();
        $result = $this->engine->checkout($viewer, $input, $key, null);
        $this->assertNotNull($result->checkout_url);

        return [$viewer, $input, $key, DB::table('billing_orders')->find($result->order_id)];
    }

    private function assertPendingPlaceholder(object $viewer, CheckoutInput $input, object $order): void
    {
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertDatabaseHas('billing_orders', ['id' => $order->id, 'state' => 'pending', 'checkout_id' => $order->checkout_id]);
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $order->subscription_id, 'state' => 'pending', 'resolution_required' => true]);
        $membership = $this->engine->membership($viewer->id, now()->toDateTimeImmutable());
        $this->assertTrue($membership->payment_resolution_required);
        $this->assertNotContains(SubscriptionAction::Checkout, $membership->allowed_actions);
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer, $input, BillingStore::id(), null));
        $this->assertSame(1, $this->gateway->createCalls);
    }
}
