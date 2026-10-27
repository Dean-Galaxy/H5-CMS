<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\ProviderGatewayRouter;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\PayPalWebhookReceiver;
use App\Modules\Billing\Services\ProviderInboxStore;
use App\Modules\Billing\Services\WebhookService;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Billing\Support\FakeGateway;
use Tests\TestCase;

final class ProviderInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.paypal' => ['enabled' => true, 'client_id' => 'local-fixture', 'client_secret' => 'local-fixture',
            'webhook_id' => 'LOCAL-WEBHOOK', 'merchant_id' => 'LOCAL-MERCHANT', 'merchant_scope' => 'merchant-a']]);
    }

    public function test_verified_sandbox_event_is_durable_deduplicated_and_does_not_grant_membership(): void
    {
        $receiver = $this->receiver(['SUCCESS', 'SUCCESS']);
        $id = $receiver->receive($this->body(), $this->headers());
        self::assertSame($id, $receiver->receive($this->body(), $this->headers()));
        self::assertDatabaseCount('billing_inbox', 1);
        self::assertDatabaseHas('billing_inbox', ['provider' => 'paypal', 'provider_environment' => 'sandbox', 'merchant_scope' => 'merchant-a',
            'provider_event_id' => 'WH-LOCAL', 'object_id' => 'I-LOCAL', 'is_test' => true, 'state' => 'pending']);
        self::assertDatabaseCount('billing_payments', 0);
        self::assertDatabaseCount('billing_entitlements', 0);
        self::assertDatabaseCount('billing_outbox', 0);
        $row = (array) DB::table('billing_inbox')->first();
        self::assertArrayNotHasKey('raw_body', $row);
        self::assertArrayNotHasKey('signature', $row);
    }

    public function test_failed_or_unknown_verification_never_acknowledges_inbox(): void
    {
        try {
            $this->receiver(['FAILURE'])->receive($this->body(), $this->headers());
            self::fail('Expected invalid signature');
        } catch (ApiProblem $e) {
            self::assertSame('VALIDATION_FAILED', $e->errorCode);
        }
        try {
            $this->receiver(['PENDING'])->receive($this->body(), $this->headers());
            self::fail('Expected unknown verification');
        } catch (RuntimeException $e) {
            self::assertSame('PAYPAL_VERIFICATION_UNKNOWN', $e->getMessage());
        }
        self::assertDatabaseCount('billing_inbox', 0);
    }

    public function test_header_injection_fails_before_any_verification_request(): void
    {
        $receiver = new PayPalWebhookReceiver(new SandboxApi(new Client(['handler' => new MockHandler([])])), new ProviderInboxStore);
        $headers = $this->headers();
        $headers['paypal-cert-url'] = 'https://localhost/private';
        $this->expectException(ApiProblem::class);
        $receiver->receive($this->body(), $headers);
    }

    public function test_identical_event_id_in_another_merchant_or_provider_does_not_collide(): void
    {
        $this->receiver(['SUCCESS'])->receive($this->body(), $this->headers());
        config(['billing.paypal.merchant_scope' => 'merchant-b']);
        $this->receiver(['SUCCESS'])->receive($this->body(), $this->headers());
        app(ProviderInboxStore::class)->receive(ProviderScope::legacyStripe(), [
            'id' => 'WH-LOCAL', 'type' => 'invoice.paid', 'object_id' => 'invoice-local', 'is_test' => true, 'created' => '2026-10-26T00:00:00Z',
        ]);
        self::assertDatabaseCount('billing_inbox', 3);
    }

    public function test_verified_event_id_with_conflicting_resource_is_rejected_without_overwrite(): void
    {
        $receiver = $this->receiver(['SUCCESS', 'SUCCESS']);
        $receiver->receive($this->body(), $this->headers());
        try {
            $receiver->receive(str_replace('I-LOCAL', 'I-DIFFERENT', $this->body()), $this->headers());
            self::fail('Expected event identity conflict');
        } catch (RuntimeException $e) {
            self::assertSame('BILLING_PROVIDER_EVENT_CONFLICT', $e->getMessage());
            self::assertDatabaseCount('billing_inbox', 1);
            self::assertSame('I-LOCAL', DB::table('billing_inbox')->first()->object_id);
        }
    }

    public function test_paypal_inbox_stays_pending_until_its_fact_processor_is_ready_instead_of_using_stripe(): void
    {
        $this->receiver(['SUCCESS'])->receive($this->body(), $this->headers());
        $fake = new FakeGateway;
        app()->instance(PaymentGateway::class, $fake);
        self::assertSame(0, app(WebhookService::class)->processPending());
        self::assertDatabaseHas('billing_inbox', ['provider' => 'paypal', 'state' => 'pending', 'last_error' => 'RECONCILIATION_REQUIRED', 'attempts' => 1]);
        self::assertDatabaseCount('billing_payments', 0);
    }

    public function test_orders_capture_event_is_ignored_without_using_subscription_payment_writer(): void
    {
        $body = str_replace('BILLING.SUBSCRIPTION.ACTIVATED', 'PAYMENT.CAPTURE.COMPLETED', $this->body());
        $this->receiver(['SUCCESS'])->receive($body, $this->headers());
        app()->instance(PaymentGateway::class, new FakeGateway);
        self::assertSame(1, app(WebhookService::class)->processPending());
        self::assertDatabaseHas('billing_inbox', ['provider' => 'paypal', 'state' => 'ignored']);
        self::assertDatabaseCount('billing_payments', 0);
    }

    public function test_gateway_routing_uses_persisted_scope_and_never_new_purchase_configuration(): void
    {
        $fake = new FakeGateway;
        $router = new ProviderGatewayRouter($fake);
        config(['billing.new_purchase_provider' => 'paypal']);
        self::assertSame($fake, $router->forScope(ProviderScope::legacyStripe()));
        $this->expectException(DependencyNotReady::class);
        $router->forScope(new ProviderScope('paypal', 'sandbox', 'merchant-a'));
    }

    public function test_other_stripe_merchant_cannot_use_the_legacy_stripe_account(): void
    {
        $this->expectException(DependencyNotReady::class);
        (new ProviderGatewayRouter(new FakeGateway))->forScope(new ProviderScope('stripe', 'test', 'another-merchant'));
    }

    private function receiver(array $statuses): PayPalWebhookReceiver
    {
        $responses = [new Response(200, [], '{"access_token":"local-fixture","token_type":"Bearer","expires_in":3600}')];
        foreach ($statuses as $status) {
            $responses[] = new Response(200, [], json_encode(['verification_status' => $status]));
        }

        return new PayPalWebhookReceiver(new SandboxApi(new Client(['handler' => new MockHandler($responses)])), new ProviderInboxStore);
    }

    private function headers(): array
    {
        return ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-local',
            'paypal-transmission-id' => 'transmission-local', 'paypal-transmission-sig' => base64_encode('local-not-official'),
            'paypal-transmission-time' => '2026-10-26T00:00:00Z'];
    }

    private function body(): string
    {
        return '{"id":"WH-LOCAL","event_type":"BILLING.SUBSCRIPTION.ACTIVATED","create_time":"2026-10-26T00:00:00Z","resource":{"id":"I-LOCAL","is_test":false,"paid":true}}';
    }
}
