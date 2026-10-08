<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateway\StripeGateway;
use App\Modules\Billing\Gateway\UnconfiguredGateway;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Tests\TestCase;

final class StripeGatewayTest extends TestCase
{
    private StripeGateway $gateway;

    private object $http;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'billing.driver' => 'stripe_test', 'billing.api_version' => StripeGateway::API_VERSION,
            'billing.stripe_secret' => 'sk_test_local_fixture_not_a_credential',
            'billing.stripe_webhook_secret' => 'whsec_local_fixture_not_a_credential',
            'billing.test_clock_id' => null,
            'app.url' => 'http://localhost:8203',
        ]);
        $this->gateway = new StripeGateway;
        $this->http = new class implements ClientInterface
        {
            public array $replies = [];

            public array $requests = [];

            public ?\Closure $afterRequest = null;

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $path = parse_url($absUrl, PHP_URL_PATH);
                $key = strtoupper($method).' '.$path;
                $this->requests[] = compact('key', 'headers', 'params', 'absUrl');
                // Contract fixture for Stripe's mutually exclusive parameter
                // presence rule, not evidence of an official sandbox response.
                if ($key === 'POST /v1/checkout/sessions'
                    && array_key_exists('discounts', $params)
                    && array_key_exists('allow_promotion_codes', $params)) {
                    return [json_encode(['error' => [
                        'type' => 'invalid_request_error',
                        'message' => 'You may only specify one of these parameters: allow_promotion_codes, discounts.',
                    ]], JSON_THROW_ON_ERROR), 400, ['Request-Id' => 'req_local_parameter_conflict']];
                }
                if (! isset($this->replies[$key]) || $this->replies[$key] === []) {
                    throw new \RuntimeException('Unexpected HTTP call: '.$key);
                }
                $reply = array_shift($this->replies[$key]);
                if ($reply instanceof \Throwable) {
                    throw $reply;
                }

                ($this->afterRequest ?? static function (): void {})();

                return [json_encode($reply, JSON_THROW_ON_ERROR), 200, ['Request-Id' => 'req_local_fixture']];
            }
        };
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    private function reply(string $path, array $response, string $method = 'GET'): void
    {
        $this->http->replies[$method.' /v1/'.$path][] = $response;
    }

    private function price(): array
    {
        return ['id' => 'price_fixture', 'object' => 'price', 'livemode' => false, 'currency' => 'usd', 'unit_amount' => 1200,
            'recurring' => ['interval' => 'week', 'interval_count' => 1]];
    }

    private function subscriptionFixture(bool $canceled = false): array
    {
        return ['id' => 'sub_fixture', 'object' => 'subscription', 'livemode' => false, 'customer' => 'cus_fixture',
            'status' => 'active', 'cancel_at_period_end' => $canceled, 'metadata' => ['order_id' => 'order_fixture'],
            'latest_invoice' => 'in_fixture', 'items' => ['object' => 'list', 'has_more' => false, 'data' => [[
                'id' => 'si_fixture', 'object' => 'subscription_item', 'quantity' => 1, 'price' => $this->price(),
                'current_period_start' => 1800000000, 'current_period_end' => 1800604800,
            ]]]];
    }

    private function invoiceFixture(): array
    {
        return ['id' => 'in_fixture', 'object' => 'invoice', 'livemode' => false, 'customer' => 'cus_fixture',
            'currency' => 'usd', 'status' => 'paid', 'amount_paid' => 300, 'amount_due' => 300, 'amount_remaining' => 0,
            'amount_paid_off_stripe' => 0, 'total' => 300, 'starting_balance' => 0, 'ending_balance' => 0,
            'billing_reason' => 'subscription_create', 'status_transitions' => ['paid_at' => 1800000010],
            // These top-level invoice timestamps deliberately differ from service time.
            'period_start' => 1700000000, 'period_end' => 1700000001,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => [
                'subscription' => 'sub_fixture', 'metadata' => ['order_id' => 'order_fixture'],
            ]]];
    }

    private function invoiceReplies(bool $verified = true): void
    {
        $this->reply('invoices/in_fixture', $this->invoiceFixture());
        $this->reply('subscriptions/sub_fixture', $this->subscriptionFixture());
        $this->reply('invoices/in_fixture/lines', ['object' => 'list', 'has_more' => false, 'data' => [[
            'id' => 'il_fixture', 'object' => 'line_item', 'livemode' => false, 'currency' => 'usd', 'quantity' => 1,
            'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => [
                'subscription' => 'sub_fixture', 'subscription_item' => 'si_fixture', 'proration' => false,
            ]],
            'pricing' => ['type' => 'price_details', 'price_details' => ['price' => 'price_fixture']],
            'period' => ['start' => 1800000000, 'end' => 1800604800],
            'discount_amounts' => [['amount' => 900]],
        ]]]);
        $this->reply('prices/price_fixture', $this->price());
        $this->reply('invoice_payments', ['object' => 'list', 'has_more' => false, 'data' => $verified ? [[
            'id' => 'inpay_fixture', 'object' => 'invoice_payment', 'livemode' => false, 'invoice' => 'in_fixture',
            'status' => 'paid', 'amount_paid' => 300, 'currency' => 'usd',
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_fixture'],
        ]] : []]);
        if ($verified) {
            $this->reply('payment_intents/pi_fixture', ['id' => 'pi_fixture', 'object' => 'payment_intent', 'livemode' => false,
                'status' => 'succeeded', 'customer' => 'cus_fixture', 'amount_received' => 300, 'currency' => 'usd', 'latest_charge' => 'ch_fixture']);
            $this->reply('charges/ch_fixture', ['id' => 'ch_fixture', 'object' => 'charge', 'livemode' => false,
                'customer' => 'cus_fixture', 'paid' => true, 'captured' => true, 'amount_captured' => 300, 'currency' => 'usd',
                'payment_intent' => 'pi_fixture']);
        }
    }

    public function test_configuration_and_live_credentials_fail_closed_without_http(): void
    {
        foreach ([['billing.stripe_secret' => 'sk_live_REJECTED'], ['billing.api_version' => '2025-01-01.invalid'], ['billing.driver' => 'unconfigured']] as $change) {
            $original = config()->get(array_key_first($change));
            config()->set($change);
            try {
                $this->gateway->assertAvailable();
                $this->fail('Unsafe configuration was accepted.');
            } catch (DependencyNotReady) {
            }
            config()->set(array_key_first($change), $original);
        }
        $this->app->instance('env', 'production');
        try {
            $this->gateway->assertAvailable();
            $this->fail('Production was accepted.');
        } catch (DependencyNotReady) {
        }
        $this->assertSame([], $this->http->requests);
    }

    public function test_actual_client_options_and_global_tls_are_checked_before_http(): void
    {
        foreach ([
            ['api_key' => 'sk_test_wrong_fixture'], ['api_base' => 'https://other.example.test'],
            ['stripe_version' => '2025-01-01.invalid'], ['stripe_account' => 'acct_other'],
            ['stripe_context' => 'acct_other'],
        ] as $change) {
            $this->app->bind(StripeClient::class, fn ($app, $parameters) => new StripeClient([
                ...$parameters['config'], ...$change,
            ]));
            try {
                $this->gateway->assertAvailable();
                $this->fail('Different actual SDK configuration was accepted.');
            } catch (DependencyNotReady $error) {
                $this->assertSame('DEPENDENCY_NOT_READY', $error->errorCode);
            } finally {
                $this->app->offsetUnset(StripeClient::class);
            }
        }
        foreach (['verifySslCerts' => false, 'accountId' => 'acct_other', 'caBundlePath' => '/synthetic/other-ca.pem'] as $property => $bad) {
            $before = Stripe::${$property};
            Stripe::${$property} = $bad;
            try {
                $this->gateway->assertAvailable();
                $this->fail('Unsafe global Stripe settings were accepted.');
            } catch (DependencyNotReady $error) {
                $this->assertSame('DEPENDENCY_NOT_READY', $error->errorCode);
            } finally {
                Stripe::${$property} = $before;
            }
        }
        foreach ([[CURLOPT_SSL_VERIFYPEER => false], [CURLOPT_SSL_VERIFYHOST => 0], [CURLOPT_FOLLOWLOCATION => true]] as $options) {
            ApiRequestor::setHttpClient(new CurlClient($options));
            try {
                $this->gateway->assertAvailable();
                $this->fail('Unsafe curl options were accepted.');
            } catch (DependencyNotReady $error) {
                $this->assertSame('DEPENDENCY_NOT_READY', $error->errorCode);
            } finally {
                ApiRequestor::setHttpClient($this->http);
            }
        }
        ApiRequestor::setHttpClient(new \stdClass);
        try {
            $this->gateway->assertAvailable();
            $this->fail('An invalid test transport was accepted.');
        } catch (DependencyNotReady $error) {
            $this->assertSame('DEPENDENCY_NOT_READY', $error->errorCode);
        } finally {
            ApiRequestor::setHttpClient($this->http);
        }
        $this->assertSame([], $this->http->requests);
    }

    public function test_each_network_step_rechecks_mutated_configuration(): void
    {
        $this->reply('customers', ['id' => 'cus_fixture', 'object' => 'customer', 'livemode' => false,
            'metadata' => ['viewer_id' => 'viewer_fixture'], 'test_clock' => null], 'POST');
        $this->http->afterRequest = function (): void {
            config(['billing.api_version' => '2025-01-01.invalid']);
        };
        $order = (object) ['id' => 'order_fixture', 'viewer_id' => 'viewer_fixture', 'subscription_id' => 'local_sub',
            'is_test' => true, 'currency' => 'USD', 'regular_amount_minor' => 1200, 'amount_minor' => 300,
            'discount_applied' => true, 'price_version' => 3, 'return_to' => '/membership'];
        $sub = (object) ['id' => 'local_sub', 'viewer_id' => 'viewer_fixture', 'is_test' => true,
            'currency' => 'USD', 'regular_amount_minor' => 1200, 'customer_id' => null];
        try {
            $this->gateway->prepareCheckout($order, $sub);
            $this->fail('A second API call proceeded with changed configuration.');
        } catch (DependencyNotReady $error) {
            $this->assertSame('DEPENDENCY_NOT_READY', $error->errorCode);
        }
        $this->assertSame(['POST /v1/customers'], array_column($this->http->requests, 'key'));
    }

    public function test_unconfigured_gateway_never_fabricates_checkout_success(): void
    {
        $this->expectException(DependencyNotReady::class);
        (new UnconfiguredGateway)->createCheckout((object) [], (object) []);
    }

    private function preparedCheckout(int $ageSeconds = 0, bool $attempted = false, ?string $customerClock = null, bool $discounted = true): array
    {
        $this->reply('customers', ['id' => 'cus_fixture', 'object' => 'customer', 'livemode' => false,
            'metadata' => ['viewer_id' => 'viewer_fixture'], 'test_clock' => $customerClock], 'POST');
        if ($discounted) {
            $this->reply('coupons', ['id' => 'coupon_fixture', 'object' => 'coupon', 'livemode' => false,
                'duration' => 'once', 'currency' => 'usd', 'amount_off' => 900], 'POST');
        }
        $order = (object) ['id' => 'order_fixture', 'viewer_id' => 'viewer_fixture', 'subscription_id' => 'local_sub',
            'is_test' => true, 'currency' => 'USD', 'regular_amount_minor' => 1200, 'amount_minor' => $discounted ? 300 : 1200,
            'discount_applied' => $discounted, 'price_version' => 3, 'created_at' => now()->subMinutes(20)->toIso8601String(),
            'return_to' => '/membership?from=drama&order_id=untrusted#plans'];
        $sub = (object) ['id' => 'local_sub', 'viewer_id' => 'viewer_fixture', 'is_test' => true, 'currency' => 'USD',
            'regular_amount_minor' => 1200, 'customer_id' => null];
        $request = $this->gateway->prepareCheckout($order, $sub);
        if ($ageSeconds > 0) {
            $prepared = strtotime($request['prepared_at']) - $ageSeconds;
            $request['prepared_at'] = gmdate('Y-m-d\TH:i:s\Z', $prepared);
            $request['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $prepared + 1860);
            $request['params']['expires_at'] = $prepared + 1860;
        }
        $order->checkout_request = json_encode($request, JSON_THROW_ON_ERROR);
        $order->checkout_prepared_at = $request['prepared_at'];
        $order->checkout_attempted_at = $attempted ? $request['prepared_at'] : null;

        return [$order, $sub, $request];
    }

    private function sessionFixture(array $request, string $status = 'open', int $amount = 300): array
    {
        return ['id' => 'cs_fixture', 'object' => 'checkout.session', 'livemode' => false,
            'mode' => 'subscription', 'status' => $status, 'customer' => 'cus_fixture',
            'client_reference_id' => 'order_fixture', 'metadata' => ['order_id' => 'order_fixture'],
            'success_url' => $request['params']['success_url'], 'cancel_url' => $request['params']['cancel_url'],
            'amount_total' => $amount, 'currency' => 'usd', 'url' => $status === 'open' ? 'https://checkout.stripe.com/c/pay/fixture' : null,
            'expires_at' => $request['params']['expires_at'], 'subscription' => $status === 'complete' ? 'sub_fixture' : null,
            'payment_status' => $status === 'complete' ? 'paid' : 'unpaid'];
    }

    public function test_checkout_persists_new_preparation_deadline_weekly_snapshot_and_controlled_return(): void
    {
        $started = time();
        [$order, $sub, $request] = $this->preparedCheckout();
        $this->assertCount(2, $this->http->requests, 'Preparation never creates a payable Session.');
        $this->assertGreaterThanOrEqual($started, strtotime($request['prepared_at']));
        $this->assertSame(1860, strtotime($request['expires_at']) - strtotime($request['prepared_at']));
        $this->assertGreaterThan(strtotime($order->created_at) + 1860, $request['params']['expires_at']);
        $this->assertSame($request, $this->gateway->prepareCheckout($order, $sub), 'Persisted preparation must not reset the deadline.');
        $this->assertCount(2, $this->http->requests);
        $this->reply('checkout/sessions', $this->sessionFixture($request), 'POST');
        $result = $this->gateway->createCheckout($order, $sub);
        $this->assertSame('cs_fixture', $result['session_id']);
        $this->assertSame($request['expires_at'], $result['expires_at']);
        $posted = $this->http->requests[2];
        $this->assertSame(1200, $posted['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('week', $posted['params']['line_items'][0]['price_data']['recurring']['interval']);
        $this->assertSame('coupon_fixture', $posted['params']['discounts'][0]['coupon']);
        $this->assertArrayNotHasKey('allow_promotion_codes', $request['params']);
        $this->assertArrayNotHasKey('allow_promotion_codes', $posted['params']);
        $this->assertSame(['card'], $posted['params']['payment_method_types']);
        $this->assertSame($request['params']['expires_at'], $posted['params']['expires_at']);
        $this->assertSame('http://localhost:8203/membership?from=drama&order_id=order_fixture#plans', $posted['params']['success_url']);
        $this->assertSame($posted['params']['success_url'], $posted['params']['cancel_url']);
        $this->assertSame($posted['params']['metadata'], $posted['params']['subscription_data']['metadata']);
        $this->assertStringContainsString('Stripe-Version: '.StripeGateway::API_VERSION, implode("\n", $posted['headers']));
        $this->assertStringContainsString('Idempotency-Key: '.$request['idempotency_key'], implode("\n", $posted['headers']));
    }

    public function test_old_return_origins_and_order_ids_are_read_only_and_never_exposed(): void
    {
        foreach (['http://old.example.test/membership?order_id=order_fixture',
            'https://other.example.test/membership?order_id=order_fixture',
            'http://localhost:8203/membership?order_id=another_order'] as $bad) {
            [$order, $sub, $request] = $this->preparedCheckout();
            $request['params']['success_url'] = $bad;
            $order->checkout_request = json_encode($request, JSON_THROW_ON_ERROR);
            $original = clone $order;
            $this->http->requests = [];
            $session = $this->sessionFixture($request);
            $this->reply('checkout/sessions', ['object' => 'list', 'has_more' => false, 'data' => [$session]]);
            $this->reply('checkout/sessions/cs_fixture', $session);
            $result = $this->gateway->createCheckout($order, $sub);
            $this->assertNull($result['url']);
            $this->assertSame('cs_fixture', $result['session_id']);
            $this->assertSame(['GET /v1/checkout/sessions', 'GET /v1/checkout/sessions/cs_fixture'], array_column($this->http->requests, 'key'));
            $this->assertEquals($original, $order);
        }
    }

    public function test_untrusted_checkout_urls_and_remote_return_mismatch_are_not_returned(): void
    {
        foreach (['https://user@checkout.stripe.com/c/pay/fixture',
            'https://checkout.stripe.com:444/c/pay/fixture', 'https://checkout.stripe.com.evil.test/c/pay/fixture',
            'http://checkout.stripe.com/c/pay/fixture'] as $bad) {
            [$order, $sub, $request] = $this->preparedCheckout();
            $session = $this->sessionFixture($request);
            $session['url'] = $bad;
            $this->reply('checkout/sessions', $session, 'POST');
            $this->assertNull($this->gateway->createCheckout($order, $sub)['url']);
        }
        [$order, $sub, $request] = $this->preparedCheckout();
        $session = $this->sessionFixture($request);
        $session['cancel_url'] = 'https://another.example.test/membership';
        $this->reply('checkout/sessions', $session, 'POST');
        $this->assertNull($this->gateway->createCheckout($order, $sub)['url']);
        [$order, $sub, $request] = $this->preparedCheckout();
        $session = $this->sessionFixture($request);
        $session['url'] .= '#fidkdWxOYHwnPyd1blpxYHZxWjA0JykneA==';
        $this->reply('checkout/sessions', $session, 'POST');
        $this->assertSame($session['url'], $this->gateway->createCheckout($order, $sub)['url']);
    }

    public function test_regular_price_checkout_omits_discounts_and_promotion_code_input(): void
    {
        [$order, $sub, $request] = $this->preparedCheckout(discounted: false);
        $this->assertSame(['POST /v1/customers'], array_column($this->http->requests, 'key'));
        $this->assertArrayNotHasKey('allow_promotion_codes', $request['params']);
        $this->assertArrayNotHasKey('discounts', $request['params']);
        $this->reply('checkout/sessions', $this->sessionFixture($request, amount: 1200), 'POST');
        $result = $this->gateway->createCheckout($order, $sub);
        $posted = $this->http->requests[1];
        $this->assertSame('cs_fixture', $result['session_id']);
        $this->assertArrayNotHasKey('allow_promotion_codes', $posted['params']);
        $this->assertArrayNotHasKey('discounts', $posted['params']);
        $this->assertSame(1200, $posted['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('week', $posted['params']['line_items'][0]['price_data']['recurring']['interval']);
        $this->assertSame($request['params']['expires_at'], $posted['params']['expires_at']);
        $this->assertStringContainsString('Idempotency-Key: '.$request['idempotency_key'], implode("\n", $posted['headers']));
    }

    public function test_http_fixture_rejects_coupon_and_serialized_false_promotion_flag(): void
    {
        $client = new StripeClient(['api_key' => config('billing.stripe_secret'), 'stripe_version' => StripeGateway::API_VERSION]);
        try {
            $client->checkout->sessions->create([
                'mode' => 'subscription', 'discounts' => [['coupon' => 'coupon_fixture']],
                'allow_promotion_codes' => false,
            ]);
            $this->fail('Fixture accepted mutually exclusive Checkout parameters.');
        } catch (InvalidRequestException $error) {
            $this->assertSame(400, $error->getHttpStatus());
            $this->assertSame('invalid_request_error', $error->getError()->type);
        }
        $this->assertCount(1, $this->http->requests);
        $this->assertSame('false', $this->http->requests[0]['params']['allow_promotion_codes']);
        $this->assertSame('coupon_fixture', $this->http->requests[0]['params']['discounts'][0]['coupon']);
    }

    private function legacyPreparedCheckout(bool $attempted): array
    {
        [$order, $sub, $request] = $this->preparedCheckout(attempted: $attempted);
        // A pre-F05 persisted request must stay byte-for-byte unchanged.
        $request['params']['allow_promotion_codes'] = false;
        $order->checkout_request = json_encode($request, JSON_THROW_ON_ERROR);
        $this->http->requests = [];

        return [$order, $sub, $request];
    }

    public function test_legacy_conflict_reconciles_all_statuses_without_post_or_mutating_intent(): void
    {
        foreach ([false, true] as $attempted) {
            foreach (['open', 'complete', 'expired'] as $status) {
                [$order, $sub, $request] = $this->legacyPreparedCheckout($attempted);
                $original = clone $order;
                $this->assertSame($request, $this->gateway->prepareCheckout($order, $sub));
                $this->assertSame([], $this->http->requests);
                $session = $this->sessionFixture($request, $status);
                $this->reply('checkout/sessions', ['object' => 'list', 'has_more' => false, 'data' => [$session]]);
                $this->reply('checkout/sessions/cs_fixture', $session);
                $result = $this->gateway->createCheckout($order, $sub);
                $this->assertSame('cs_fixture', $result['session_id']);
                $this->assertSame($status, $result['status']);
                $this->assertSame($request['expires_at'], $result['expires_at']);
                $this->assertSame($status === 'open' ? $session['url'] : null, $result['url']);
                $this->assertSame(['GET /v1/checkout/sessions', 'GET /v1/checkout/sessions/cs_fixture'], array_column($this->http->requests, 'key'));
                $this->assertSame('cus_fixture', $this->http->requests[0]['params']['customer']);
                $this->assertEquals($original, $order);
                $this->assertSame($original->checkout_request, $order->checkout_request);
                $this->assertSame($request, json_decode($order->checkout_request, true, flags: JSON_THROW_ON_ERROR));
            }
        }
    }

    public function test_legacy_conflict_with_zero_or_multiple_sessions_stays_pending_and_unchanged(): void
    {
        foreach ([false, true] as $attempted) {
            foreach ([0, 2] as $matchCount) {
                [$order, $sub, $request] = $this->legacyPreparedCheckout($attempted);
                $original = clone $order;
                $one = $this->sessionFixture($request);
                $sessions = $matchCount === 0 ? [] : [$one, [...$one, 'id' => 'cs_duplicate']];
                $this->reply('checkout/sessions', ['object' => 'list', 'has_more' => false, 'data' => $sessions]);
                try {
                    $this->gateway->createCheckout($order, $sub);
                    $this->fail('Unresolved legacy intent was reopened.');
                } catch (ApiProblem $error) {
                    $this->assertSame('BILLING_PENDING', $error->errorCode);
                }
                $this->assertSame(['GET /v1/checkout/sessions'], array_column($this->http->requests, 'key'));
                $this->assertEquals($original, $order);
                $this->assertSame($original->checkout_request, $order->checkout_request);
                $this->assertSame($request, json_decode($order->checkout_request, true, flags: JSON_THROW_ON_ERROR));
            }
        }
    }

    public function test_persisted_promotion_flag_rejects_values_other_than_strict_false_before_http(): void
    {
        foreach ([true, null, 'false', 0] as $value) {
            [$order, $sub, $request] = $this->preparedCheckout();
            $request['params']['allow_promotion_codes'] = $value;
            $order->checkout_request = json_encode($request, JSON_THROW_ON_ERROR);
            $original = $order->checkout_request;
            $this->http->requests = [];
            try {
                $this->gateway->createCheckout($order, $sub);
                $this->fail('Unsafe persisted promotion-code input was accepted.');
            } catch (ApiProblem $error) {
                $this->assertSame('BILLING_PENDING', $error->errorCode);
            }
            $this->assertSame([], $this->http->requests);
            $this->assertSame($original, $order->checkout_request);
        }
    }

    public function test_persisted_coupon_shape_must_match_discounted_order_before_http(): void
    {
        foreach ([[true, null], [true, []], [true, [['promotion_code' => 'promo_fixture']]],
            [true, [['coupon' => '']]], [false, [['coupon' => 'coupon_fixture']]]] as [$discounted, $discounts]) {
            [$order, $sub, $request] = $this->preparedCheckout(discounted: $discounted);
            if ($discounts === null) {
                unset($request['params']['discounts']);
            } else {
                $request['params']['discounts'] = $discounts;
            }
            $order->checkout_request = json_encode($request, JSON_THROW_ON_ERROR);
            $this->http->requests = [];
            try {
                $this->gateway->createCheckout($order, $sub);
                $this->fail('Coupon parameters contradicting the order were accepted.');
            } catch (ApiProblem $error) {
                $this->assertSame('BILLING_PENDING', $error->errorCode);
            }
            $this->assertSame([], $this->http->requests);
        }
    }

    public function test_preparation_attaches_controlled_test_clock_and_accepts_same_clock_customer(): void
    {
        config()->set('billing.test_clock_id', 'clock_fixture');
        [$order, $sub, $request] = $this->preparedCheckout(customerClock: 'clock_fixture');
        $this->assertSame('clock_fixture', $this->http->requests[0]['params']['test_clock']);
        $this->assertSame('cus_fixture', $request['params']['customer']);

        unset($order->checkout_request);
        $sub->customer_id = 'cus_fixture';
        $this->http->requests = [];
        $this->reply('customers/cus_fixture', ['id' => 'cus_fixture', 'object' => 'customer', 'livemode' => false,
            'metadata' => ['viewer_id' => 'viewer_fixture'], 'test_clock' => ['id' => 'clock_fixture']]);
        $this->reply('coupons', ['id' => 'coupon_fixture', 'object' => 'coupon', 'livemode' => false,
            'duration' => 'once', 'currency' => 'usd', 'amount_off' => 900], 'POST');
        $this->gateway->prepareCheckout($order, $sub);
        $this->assertSame(['GET /v1/customers/cus_fixture', 'POST /v1/coupons'], array_column($this->http->requests, 'key'));
    }

    public function test_preparation_refuses_existing_customer_on_another_clock_without_rebinding(): void
    {
        [$order, $sub] = $this->preparedCheckout();
        unset($order->checkout_request);
        $sub->customer_id = 'cus_fixture';
        config()->set('billing.test_clock_id', 'clock_fixture');
        $this->http->requests = [];
        $this->reply('customers/cus_fixture', ['id' => 'cus_fixture', 'object' => 'customer', 'livemode' => false,
            'metadata' => ['viewer_id' => 'viewer_fixture'], 'test_clock' => 'clock_other']);
        try {
            $this->gateway->prepareCheckout($order, $sub);
            $this->fail('A Customer from another test clock was accepted.');
        } catch (ApiProblem $error) {
            $this->assertSame('BILLING_PENDING', $error->errorCode);
        }
        $this->assertSame(['GET /v1/customers/cus_fixture'], array_column($this->http->requests, 'key'));
    }

    public function test_preparation_refuses_customer_response_omitting_requested_clock(): void
    {
        config()->set('billing.test_clock_id', 'clock_fixture');
        try {
            $this->preparedCheckout();
            $this->fail('Missing requested test clock was accepted.');
        } catch (ApiProblem $error) {
            $this->assertSame('BILLING_PENDING', $error->errorCode);
        }
        $this->assertSame(['POST /v1/customers'], array_column($this->http->requests, 'key'));
        $this->assertSame('clock_fixture', $this->http->requests[0]['params']['test_clock']);
    }

    public function test_unknown_create_finds_original_session_without_recreating_customer_coupon_or_session(): void
    {
        foreach (['open', 'complete', 'expired'] as $status) {
            [$order, $sub, $request] = $this->preparedCheckout(120, true);
            $this->http->requests = [];
            $session = $this->sessionFixture($request, $status);
            $this->reply('checkout/sessions', ['object' => 'list', 'has_more' => false, 'data' => [$session]]);
            $this->reply('checkout/sessions/cs_fixture', $session);
            $result = $this->gateway->createCheckout($order, $sub);
            $this->assertSame('cs_fixture', $result['session_id']);
            $this->assertSame($status, $result['status']);
            $this->assertSame($request['expires_at'], $result['expires_at']);
            $this->assertSame($status === 'open' ? $session['url'] : null, $result['url']);
            $this->assertSame(['GET /v1/checkout/sessions', 'GET /v1/checkout/sessions/cs_fixture'], array_column($this->http->requests, 'key'));
            $this->assertSame('cus_fixture', $this->http->requests[0]['params']['customer']);
        }
    }

    public function test_unknown_or_too_late_create_with_no_match_remains_pending_without_new_post(): void
    {
        foreach ([[0, true], [61, false]] as [$age, $attempted]) {
            [$order, $sub] = $this->preparedCheckout($age, $attempted);
            $this->http->requests = [];
            $this->reply('checkout/sessions', ['object' => 'list', 'has_more' => false, 'data' => []]);
            try {
                $this->gateway->createCheckout($order, $sub);
                $this->fail('Unknown creation was reopened.');
            } catch (ApiProblem $error) {
                $this->assertSame('BILLING_PENDING', $error->errorCode);
            }
            $this->assertSame(['GET /v1/checkout/sessions'], array_column($this->http->requests, 'key'));
        }
    }

    public function test_reconciliation_paginates_and_refuses_multiple_matching_sessions(): void
    {
        [$order, $sub, $request] = $this->preparedCheckout(0, true);
        $this->http->requests = [];
        $one = $this->sessionFixture($request);
        $two = [...$one, 'id' => 'cs_duplicate'];
        $this->reply('checkout/sessions', ['object' => 'list', 'has_more' => true, 'data' => [$one]]);
        $this->reply('checkout/sessions', ['object' => 'list', 'has_more' => false, 'data' => [$two]]);
        try {
            $this->gateway->createCheckout($order, $sub);
            $this->fail('Ambiguous Sessions were accepted.');
        } catch (ApiProblem $error) {
            $this->assertSame('BILLING_PENDING', $error->errorCode);
        }
        $this->assertSame('cs_fixture', $this->http->requests[1]['params']['starting_after']);
        $this->assertSame(['GET /v1/checkout/sessions', 'GET /v1/checkout/sessions'], array_column($this->http->requests, 'key'));
    }

    public function test_provider_deadline_must_exactly_match_the_persisted_request(): void
    {
        [$order, $sub, $request] = $this->preparedCheckout();
        $session = $this->sessionFixture($request);
        $session['expires_at'] += 86400;
        $this->reply('checkout/sessions', $session, 'POST');
        $this->expectException(ApiProblem::class);
        $this->gateway->createCheckout($order, $sub);
    }

    public function test_paid_invoice_requires_actual_payment_and_uses_line_service_interval(): void
    {
        $this->invoiceReplies();
        $result = $this->gateway->invoice('in_fixture');
        $this->assertTrue($result['verified_payment']);
        $this->assertSame(300, $result['amount_minor']);
        $this->assertSame(1200, $result['regular_amount_minor']);
        $this->assertSame(900, $result['discount_amount_minor']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1800000000), $result['period_start']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1800604800), $result['period_end']);
        $this->assertSame('ch_fixture', $result['charge_id']);
        $this->assertSame('in_fixture', $this->http->requests[4]['params']['invoice']);
    }

    public function test_unpaid_invoice_reads_current_required_action_without_granting_payment(): void
    {
        $this->invoiceReplies(false);
        $invoice = &$this->http->replies['GET /v1/invoices/in_fixture'][0];
        $invoice['status'] = 'open';
        $invoice['amount_paid'] = 0;
        $invoice['amount_remaining'] = 300;
        $invoice['status_transitions']['paid_at'] = null;
        $this->http->replies['GET /v1/invoice_payments'][0]['data'] = [[
            'id' => 'inpay_fixture', 'object' => 'invoice_payment', 'livemode' => false,
            'invoice' => 'in_fixture', 'is_default' => true, 'status' => 'open',
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_fixture'],
        ]];
        $this->reply('payment_intents/pi_fixture', ['id' => 'pi_fixture', 'object' => 'payment_intent',
            'livemode' => false, 'customer' => 'cus_fixture', 'currency' => 'usd', 'status' => 'requires_action']);
        $result = $this->gateway->invoice('in_fixture');
        $this->assertFalse($result['paid']);
        $this->assertFalse($result['verified_payment']);
        $this->assertSame('requires_action', $result['payment_state']);
    }

    public function test_manually_marked_paid_invoice_is_not_verified_payment(): void
    {
        $this->invoiceReplies(false);
        $result = $this->gateway->invoice('in_fixture');
        $this->assertTrue($result['paid']);
        $this->assertFalse($result['verified_payment']);
        $this->assertNull($result['charge_id']);
    }

    public function test_payment_for_another_customer_is_not_verified(): void
    {
        $this->invoiceReplies();
        $this->http->replies['GET /v1/payment_intents/pi_fixture'][0]['customer'] = 'cus_other';
        $this->assertFalse($this->gateway->invoice('in_fixture')['verified_payment']);
    }

    public function test_restore_updates_only_cancellation_flag_and_rechecks_same_period(): void
    {
        $this->reply('subscriptions/sub_fixture', $this->subscriptionFixture(true));
        $this->reply('subscriptions/sub_fixture', $this->subscriptionFixture(false), 'POST');
        $this->reply('subscriptions/sub_fixture', $this->subscriptionFixture(false));
        $result = $this->gateway->setRenewal((object) [
            'provider_id' => 'sub_fixture', 'customer_id' => 'cus_fixture', 'regular_amount_minor' => 1200,
            'currency' => 'USD', 'is_test' => true,
        ], true, 'resume-fixture');
        $this->assertFalse($result['cancel_at_period_end']);
        $this->assertSame(['cancel_at_period_end' => 'false'], $this->http->requests[1]['params']);
        $this->assertCount(3, $this->http->requests);
    }

    public function test_refunds_include_all_pages_and_preserve_partial_pending_and_failed_facts(): void
    {
        $this->reply('charges/ch_fixture', ['id' => 'ch_fixture', 'object' => 'charge', 'livemode' => false]);
        $this->reply('refunds', ['object' => 'list', 'has_more' => true, 'data' => [[
            'id' => 're_one', 'object' => 'refund', 'charge' => 'ch_fixture', 'amount' => 100, 'status' => 'succeeded', 'currency' => 'usd',
        ]]]);
        $this->reply('refunds', ['object' => 'list', 'has_more' => false, 'data' => [[
            'id' => 're_two', 'object' => 'refund', 'charge' => 'ch_fixture', 'amount' => 200, 'status' => 'pending', 'currency' => 'usd',
        ], [
            'id' => 're_three', 'object' => 'refund', 'charge' => 'ch_fixture', 'amount' => 200, 'status' => 'failed', 'currency' => 'usd',
        ]]]);
        $result = $this->gateway->refunds('ch_fixture');
        $this->assertSame(['succeeded', 'pending', 'failed'], array_column($result, 'status'));
        $this->assertSame('re_one', $this->http->requests[2]['params']['starting_after']);
    }

    private function signedEvent(array $changes = []): array
    {
        $event = array_replace_recursive(['id' => 'evt_fixture', 'object' => 'event', 'livemode' => false,
            'api_version' => StripeGateway::API_VERSION, 'type' => 'invoice.paid', 'created' => time(),
            'data' => ['object' => ['id' => 'in_fixture', 'secret_field' => 'MUST_NOT_ESCAPE']]], $changes);
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, config('billing.stripe_webhook_secret'));

        return [$payload, $signature];
    }

    public function test_signed_webhook_retains_only_minimum_reconciliation_identity(): void
    {
        $event = $this->gateway->verifyEvent(...$this->signedEvent());
        $this->assertSame('evt_fixture', $event['id']);
        $this->assertSame('in_fixture', $event['object_id']);
        $this->assertTrue($event['is_test']);
        $this->assertStringNotContainsString('MUST_NOT_ESCAPE', json_encode($event));
        $this->assertSame([], $this->http->requests);
    }

    public function test_refund_event_exposes_charge_id_only_as_reconciliation_hint(): void
    {
        $event = $this->gateway->verifyEvent(...$this->signedEvent([
            'type' => 'refund.updated', 'data' => ['object' => ['id' => 're_fixture', 'charge' => 'ch_fixture']],
        ]));
        $this->assertSame('re_fixture', $event['object_id']);
        $this->assertSame('ch_fixture', $event['related_object_id']);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        [$payload, $signature] = $this->signedEvent();
        $this->expectException(ApiProblem::class);
        $this->gateway->verifyEvent($payload.' ', $signature);
    }

    public function test_live_webhook_and_version_mismatch_fail_closed(): void
    {
        foreach ([['livemode' => true], ['api_version' => '2020-08-27']] as $changes) {
            try {
                $this->gateway->verifyEvent(...$this->signedEvent($changes));
                $this->fail('Unsafe event accepted.');
            } catch (ApiProblem $e) {
                $this->assertSame('BILLING_PENDING', $e->errorCode);
            }
        }
    }

    public function test_platform_timeout_has_safe_fixed_error_and_no_false_success(): void
    {
        $this->http->replies['GET /v1/subscriptions/sub_fixture'][] = new ApiConnectionException('SECRET_FROM_PROVIDER');
        try {
            $this->gateway->subscription('sub_fixture');
            $this->fail('Timeout was treated as success.');
        } catch (ApiProblem $e) {
            $this->assertSame('BILLING_PENDING', $e->errorCode);
            $this->assertStringNotContainsString('SECRET_FROM_PROVIDER', $e->getMessage());
        }
    }
}
