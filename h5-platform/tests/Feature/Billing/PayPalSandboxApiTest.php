<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\SandboxWebhookEnvelope;
use App\Modules\Billing\Gateway\PayPal\SandboxWeeklyPlan;
use App\Support\DependencyNotReady;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class PayPalSandboxApiTest extends TestCase
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.paypal' => ['enabled' => true, 'client_id' => 'local-fixture-client', 'client_secret' => 'local-fixture-secret',
            'merchant_id' => 'LOCAL-MERCHANT', 'merchant_scope' => 'local-fixture', 'webhook_id' => 'LOCAL-WEBHOOK']]);
    }

    public function test_calls_fixed_sandbox_with_tls_no_redirects_and_server_generated_weekly_plan(): void
    {
        $api = $this->api([$this->token(), new Response(201, [], '{"id":"P-LOCAL"}')]);
        self::assertSame('P-LOCAL', $api->createPlan(new SandboxWeeklyPlan(1, 1200, 300, true), 'PROD-ABCDEFGHIJKLMNOPQ', '00000000-0000-4000-8000-000000000001')['id']);
        self::assertCount(2, $this->history);
        foreach ($this->history as $call) {
            self::assertSame('api-m.sandbox.paypal.com', $call['request']->getUri()->getHost());
            self::assertSame('https', $call['request']->getUri()->getScheme());
            self::assertTrue($call['options']['verify']);
            self::assertFalse($call['options']['allow_redirects']);
            self::assertSame('', $call['options']['proxy']);
        }
        self::assertSame('Bearer local-fixture-token', $this->history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame('00000000-0000-4000-8000-000000000001', $this->history[1]['request']->getHeaderLine('PayPal-Request-Id'));
        self::assertSame('3.00', json_decode((string) $this->history[1]['request']->getBody(), true)['billing_cycles'][0]['pricing_scheme']['fixed_price']['value']);
    }

    public function test_suspend_is_only_an_operation_response_and_does_not_claim_membership_or_call_activate(): void
    {
        $api = $this->api([$this->token(), new Response(204), new Response(200, [], '{"id":"I-LOCAL","status":"SUSPENDED"}')]);
        $api->suspend('I-LOCAL');
        self::assertSame('SUSPENDED', $api->subscription('I-LOCAL')['status']);
        self::assertCount(3, $this->history);
        self::assertSame('/v1/billing/subscriptions/I-LOCAL/suspend', $this->history[1]['request']->getUri()->getPath());
        self::assertSame('GET', $this->history[2]['request']->getMethod());
    }

    public function test_verification_failure_is_false_and_success_still_requires_fact_lookup(): void
    {
        $api = $this->api([$this->token(), new Response(200, [], '{"verification_status":"FAILURE"}'), new Response(200, [], '{"verification_status":"SUCCESS"}')]);
        $envelope = $this->envelope();
        self::assertFalse($api->verify($envelope));
        self::assertTrue($api->verify($envelope));
        self::assertSame($envelope->verificationRequestBody(), (string) $this->history[1]['request']->getBody());
        self::assertSame('subscription_lookup', $envelope->unverifiedHint()['kind']);
    }

    public function test_other_webhook_scope_is_rejected_before_network(): void
    {
        $api = $this->api([]);
        config(['billing.paypal.webhook_id' => 'OTHER-WEBHOOK']);
        $this->expectExceptionMessage('PAYPAL_WEBHOOK_SCOPE_MISMATCH');
        $api->verify($this->envelope());
    }

    public function test_unknown_verification_status_never_becomes_success(): void
    {
        $api = $this->api([$this->token(), new Response(200, [], '{"verification_status":"PENDING"}')]);
        $this->expectExceptionMessage('PAYPAL_VERIFICATION_UNKNOWN');
        $api->verify($this->envelope());
    }

    public function test_redirect_is_not_followed_or_retried(): void
    {
        $api = $this->api([$this->token(), new Response(302, ['Location' => 'https://attacker.test'], 'secret-response')]);
        try {
            $api->subscription('I-LOCAL');
            self::fail('Expected safe failure');
        } catch (RuntimeException $e) {
            self::assertSame('PAYPAL_RECONCILIATION_REQUIRED', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertCount(2, $this->history);
        }
    }

    public function test_unknown_mutation_response_is_not_automatically_retried_or_logged(): void
    {
        $error = new ConnectException('secret response and authorization must not escape', new Request('POST', 'https://irrelevant.test'));
        $api = $this->api([$this->token(), $error]);
        try {
            $api->suspend('I-LOCAL');
            self::fail('Expected reconciliation');
        } catch (RuntimeException $e) {
            self::assertSame('PAYPAL_RECONCILIATION_REQUIRED', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertCount(2, $this->history);
        }
    }

    public function test_missing_configuration_blocks_even_a_cached_token_without_http(): void
    {
        $api = $this->api([$this->token(), new Response(200, [], '{"id":"I-LOCAL"}')]);
        $api->subscription('I-LOCAL');
        config(['billing.paypal.client_secret' => null]);
        try {
            $api->subscription('I-LOCAL');
            self::fail('Expected unavailable');
        } catch (DependencyNotReady) {
            self::assertCount(2, $this->history);
        }
    }

    public function test_configuration_change_discards_old_merchant_token(): void
    {
        $api = $this->api([$this->token(), new Response(200, [], '{}'), $this->token('second-fixture-token'), new Response(200, [], '{}')]);
        $api->subscription('I-LOCAL');
        config(['billing.paypal.client_id' => 'second-fixture-client', 'billing.paypal.merchant_scope' => 'second-fixture']);
        $api->subscription('I-LOCAL');
        self::assertCount(4, $this->history);
        self::assertSame('/v1/oauth2/token', $this->history[2]['request']->getUri()->getPath());
        self::assertSame('Bearer second-fixture-token', $this->history[3]['request']->getHeaderLine('Authorization'));
    }

    public function test_live_environment_and_fake_transport_outside_testing_are_rejected(): void
    {
        $api = $this->api([]);
        $original = app()->environment();
        try {
            foreach (['production', 'local', 'staging'] as $environment) {
                app()->instance('env', $environment);
                try {
                    $api->subscription('I-LOCAL');
                    self::fail('Expected blocked environment');
                } catch (DependencyNotReady) {
                    self::assertCount(0, $this->history);
                }
            }
        } finally {
            app()->instance('env', $original);
        }
    }

    public function test_path_injection_is_rejected_before_oauth(): void
    {
        $api = $this->api([]);
        try {
            $api->subscription('I-LOCAL/../../payments');
            self::fail('Expected ID rejection');
        } catch (InvalidArgumentException) {
            self::assertCount(0, $this->history);
        }
    }

    public function test_missing_enablement_is_not_inferred_from_credentials(): void
    {
        $api = $this->api([]);
        config(['billing.paypal.enabled' => false]);
        $this->expectException(DependencyNotReady::class);
        $api->subscription('I-LOCAL');
    }

    private function api(array $responses): SandboxApi
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($this->history));

        return new SandboxApi(new Client(['handler' => $handler]));
    }

    private function token(string $token = 'local-fixture-token'): Response
    {
        return new Response(200, [], json_encode(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600]));
    }

    private function envelope(): SandboxWebhookEnvelope
    {
        return SandboxWebhookEnvelope::fromRequest('{ "id":"WH-LOCAL", "create_time":"2026-10-26T00:00:00Z", "event_type":"BILLING.SUBSCRIPTION.ACTIVATED", "resource":{"id":"I-LOCAL"} }', [
            'paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-local',
            'paypal-transmission-id' => 'local-transmission', 'paypal-transmission-sig' => base64_encode('local-fixture'),
            'paypal-transmission-time' => '2026-10-26T00:00:00Z',
        ], 'LOCAL-WEBHOOK');
    }
}
