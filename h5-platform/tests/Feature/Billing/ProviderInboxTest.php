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
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'webhook_id' => '1AB23456CD789012E', 'merchant_id' => 'LOCALMERCHANT', 'merchant_scope' => 'merchant-a',
            'webhook_url' => 'https://callback.example.test/webhooks/paypal']]);
        $this->fixtureBinding();
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

    #[DataProvider('fractionalEventTimes')]
    public function test_verified_fractional_event_time_is_canonicalized_before_sql_and_replay_is_deduplicated(string $fraction): void
    {
        $receiver = $this->receiver(['SUCCESS', 'SUCCESS']);
        $body = str_replace('2026-10-26T00:00:00Z', '2026-10-26T00:00:00.'.$fraction.'Z', $this->body());
        $id = $receiver->receive($body, $this->headers());
        self::assertSame($id, $receiver->receive($body, $this->headers()));
        self::assertDatabaseCount('billing_inbox', 1);
        self::assertSame('2026-10-26T00:00:00.000000Z',
            CarbonImmutable::parse(DB::table('billing_inbox')->first()->occurred_at)->utc()->format('Y-m-d\TH:i:s.u\Z'));
        self::assertDatabaseCount('billing_payments', 0);
        self::assertDatabaseCount('billing_entitlements', 0);
        self::assertDatabaseCount('billing_outbox', 0);
    }

    public function test_verified_same_second_fraction_variants_are_the_same_inbox_hint(): void
    {
        $receiver = $this->receiver(['SUCCESS', 'SUCCESS', 'SUCCESS', 'SUCCESS']);
        $id = null;
        foreach (['.936', '.936000', '.001', ''] as $fraction) {
            $body = str_replace('00:00:00Z', '00:00:00'.$fraction.'Z', $this->body());
            $received = $receiver->receive($body, $this->headers());
            $id ??= $received;
            self::assertSame($id, $received);
        }
        self::assertDatabaseCount('billing_inbox', 1);
        self::assertDatabaseCount('billing_payments', 0);
    }

    #[DataProvider('conflictingEventHints')]
    public function test_second_resolution_does_not_accept_conflicting_verified_event_identity(string $case): void
    {
        $receiver = $this->receiver(['SUCCESS', 'SUCCESS']);
        $body = str_replace('00:00:00Z', '00:00:00.936Z', $this->body());
        $receiver->receive($body, $this->headers());
        $before = (array) DB::table('billing_inbox')->first();
        $changed = match ($case) {
            'next-second' => str_replace('00:00:00.936Z', '00:00:01.001Z', $body),
            'previous-second' => str_replace('2026-10-26T00:00:00.936Z', '2026-10-25T23:59:59.999Z', $body),
            'resource' => str_replace('I-LOCAL', 'I-DIFFERENT', $body),
            'event-type' => str_replace('BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.CANCELLED', $body),
        };
        try {
            $receiver->receive($changed, $this->headers());
            self::fail('Expected identity conflict');
        } catch (RuntimeException $e) {
            self::assertSame('BILLING_PROVIDER_EVENT_CONFLICT', $e->getMessage());
        }
        self::assertDatabaseCount('billing_inbox', 1);
        self::assertSame($before, (array) DB::table('billing_inbox')->first());
        self::assertDatabaseCount('billing_payments', 0);
    }

    public static function conflictingEventHints(): array
    {
        return [['next-second'], ['previous-second'], ['resource'], ['event-type']];
    }

    public function test_canonicalization_preserves_related_object_conflict_and_legacy_second_event(): void
    {
        $scope = ProviderScope::legacyStripe();
        $event = ['id' => 'EVT-LOCAL', 'type' => 'invoice.paid', 'object_id' => 'INVOICE-LOCAL',
            'related_object_id' => 'SUB-LOCAL', 'is_test' => true, 'created' => '2026-10-26T00:00:00Z'];
        $store = app(ProviderInboxStore::class);
        $id = $store->receive($scope, $event);
        self::assertSame($id, $store->receive($scope, $event));
        $event['created'] = '2026-10-26T00:00:00.936Z';
        $event['related_object_id'] = 'SUB-DIFFERENT';
        try {
            $store->receive($scope, $event);
            self::fail('Expected related object conflict');
        } catch (RuntimeException $e) {
            self::assertSame('BILLING_PROVIDER_EVENT_CONFLICT', $e->getMessage());
        }
        self::assertSame('SUB-LOCAL', DB::table('billing_inbox')->first()->related_object_id);
    }

    public static function fractionalEventTimes(): array
    {
        return array_map(fn ($fraction) => [$fraction], ['936', '914', '915', '000001', '499999', '500000', '999999']);
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
        config(['billing.paypal.merchant_scope' => 'merchant-b', 'billing.paypal.client_id' => 'other-fixture', 'billing.paypal.merchant_id' => 'OTHERMERCHANT']);
        $this->fixtureBinding();
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

    public function test_unbound_or_wrong_callback_cannot_reach_signature_acceptance(): void
    {
        $receiver = $this->receiver(['SUCCESS']);
        DB::table('billing_paypal_merchant_bindings')->delete();
        try {
            $receiver->receive($this->body(), $this->headers());
            self::fail('No binding');
        } catch (RuntimeException $e) {
            self::assertInstanceOf(DependencyNotReady::class, $e);
        }
        $this->fixtureBinding();
        config(['billing.paypal.webhook_url' => 'https://different.example.test/webhooks/paypal']);
        try {
            $receiver->receive($this->body(), $this->headers());
            self::fail('Wrong callback registration');
        } catch (RuntimeException $e) {
            self::assertInstanceOf(DependencyNotReady::class, $e);
        }
        self::assertDatabaseCount('billing_inbox', 0);
    }

    private function fixtureBinding(): void
    {
        DB::table('billing_paypal_merchant_bindings')->insert([
            'merchant_scope' => config('billing.paypal.merchant_scope'),
            'client_id_sha256' => hash('sha256', config('billing.paypal.client_id')),
            'merchant_id' => config('billing.paypal.merchant_id'), 'environment' => 'sandbox',
            'binding_source' => 'operator_dashboard', 'evidence_ref' => 'TEST-receipt',
            'receipt_sha256' => hash('sha256', 'fixture'), 'verified_at' => now(), 'created_at' => now(),
        ]);
    }

    private function receiver(array $statuses): PayPalWebhookReceiver
    {
        $responses = [new Response(200, [], '{"access_token":"local-fixture","token_type":"Bearer","expires_in":3600}')];
        foreach ($statuses as $status) {
            $responses[] = new Response(200, [], json_encode(['id' => config('billing.paypal.webhook_id'), 'url' => config('billing.paypal.webhook_url'), 'event_types' => array_map(fn ($name) => ['name' => $name], SandboxApi::REQUIRED_WEBHOOK_EVENTS)]));
            $responses[] = new Response(200, [], json_encode(['verification_status' => $status]));
        }

        $api = new SandboxApi(new Client(['handler' => new MockHandler($responses)]));
        app()->instance(SandboxApi::class, $api);

        return new PayPalWebhookReceiver($api, new ProviderInboxStore);
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
