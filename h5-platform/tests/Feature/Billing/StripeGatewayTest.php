<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateway\StripeGateway;
use App\Modules\Billing\Gateway\UnconfiguredGateway;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
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
            'app.url' => 'http://localhost:8203',
        ]);
        $this->gateway = new StripeGateway;
        $this->http = new class implements ClientInterface
        {
            public array $replies = [];

            public array $requests = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $path = parse_url($absUrl, PHP_URL_PATH);
                $key = strtoupper($method).' '.$path;
                $this->requests[] = compact('key', 'headers', 'params', 'absUrl');
                if (! isset($this->replies[$key]) || $this->replies[$key] === []) {
                    throw new \RuntimeException('Unexpected HTTP call: '.$key);
                }
                $reply = array_shift($this->replies[$key]);
                if ($reply instanceof \Throwable) {
                    throw $reply;
                }

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

    public function test_unconfigured_gateway_never_fabricates_checkout_success(): void
    {
        $this->expectException(DependencyNotReady::class);
        (new UnconfiguredGateway)->createCheckout((object) [], (object) []);
    }

    public function test_checkout_uses_server_weekly_snapshot_and_once_coupon_and_pinned_api(): void
    {
        $this->reply('customers', ['id' => 'cus_fixture', 'object' => 'customer', 'livemode' => false,
            'metadata' => ['viewer_id' => 'viewer_fixture']], 'POST');
        $this->reply('coupons', ['id' => 'coupon_fixture', 'object' => 'coupon', 'livemode' => false,
            'duration' => 'once', 'currency' => 'usd', 'amount_off' => 900], 'POST');
        $created = time() - 20;
        $expires = $created + 1860;
        $this->reply('checkout/sessions', ['id' => 'cs_fixture', 'object' => 'checkout.session', 'livemode' => false,
            'mode' => 'subscription', 'status' => 'open', 'customer' => 'cus_fixture', 'client_reference_id' => 'order_fixture',
            'amount_total' => 300, 'currency' => 'usd', 'url' => 'https://checkout.stripe.com/c/pay/fixture',
            'expires_at' => $expires, 'subscription' => null], 'POST');
        $order = (object) ['id' => 'order_fixture', 'viewer_id' => 'viewer_fixture', 'subscription_id' => 'local_sub',
            'is_test' => true, 'currency' => 'USD', 'regular_amount_minor' => 1200, 'amount_minor' => 300,
            'discount_applied' => true, 'price_version' => 3, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $created)];
        $sub = (object) ['id' => 'local_sub', 'viewer_id' => 'viewer_fixture', 'is_test' => true, 'currency' => 'USD',
            'regular_amount_minor' => 1200, 'customer_id' => null];
        $result = $this->gateway->createCheckout($order, $sub);
        $this->assertSame('cs_fixture', $result['session_id']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $expires), $result['expires_at']);
        $request = $this->http->requests[2];
        $this->assertSame(1200, $request['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('week', $request['params']['line_items'][0]['price_data']['recurring']['interval']);
        $this->assertSame('coupon_fixture', $request['params']['discounts'][0]['coupon']);
        $this->assertSame('false', $request['params']['allow_promotion_codes']);
        $this->assertSame(['card'], $request['params']['payment_method_types']);
        $this->assertSame($request['params']['metadata'], $request['params']['subscription_data']['metadata']);
        $this->assertSame($created + 1860, $request['params']['expires_at']);
        $this->assertStringContainsString('Stripe-Version: '.StripeGateway::API_VERSION, implode("\n", $request['headers']));
        $this->assertStringContainsString('Idempotency-Key: h5-checkout-order_fixture', implode("\n", $request['headers']));
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
