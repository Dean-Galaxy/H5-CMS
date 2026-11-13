<?php

namespace Tests\Feature\Billing;

use App\Data\PaymentSettled;
use App\Modules\Acquisition\Services\CheckoutContextStore;
use App\Modules\Acquisition\Services\PaymentReceiver;
use App\Modules\Billing\Data\ExternalPayment;
use App\Modules\Billing\Gateway\PayPal\LiveApi;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\PaymentLedger;
use App\Modules\Billing\Services\ProviderInboxStore;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ProductionPilotOutboxTest extends TestCase
{
    use RefreshDatabase;

    private array $approved;

    private ProviderScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13T12:15:00Z'));
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $this->scope = new ProviderScope('paypal', 'live', 'pilot-fixture');
        $this->approved = ['platform' => 'meta', 'link_id' => BillingStore::id(), 'pixel_id' => BillingStore::id(), 'pixel_version' => 1,
            'from' => '2026-11-13T12:00:00Z', 'until' => '2026-11-13T13:00:00Z'];
        config(['app.url' => 'https://pilot.example.test', 'h5.acquisition_production_pilot' => true, 'billing.paypal_live' => [
            'enabled' => false, 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret',
            'merchant_id' => 'SELLERFIXTURE', 'merchant_scope' => 'pilot-fixture',
        ]]);
        // The base commit does not contain 00's pilot methods. Only the pilot
        // admission is doubled, while the existing boot checks still run.
        $this->app->instance(RuntimeEnvironmentGuard::class, new class($this->approved)
        {
            public function __construct(private array $approved) {}

            public function assertSafeRuntime(): void
            {
                // LiveApi still exercises the real generic boot checks in testing.
                $pilot = config('h5.acquisition_production_pilot');
                config(['h5.acquisition_production_pilot' => false]);
                try {
                    (new RuntimeEnvironmentGuard)->assertSafeRuntime();
                } finally {
                    config(['h5.acquisition_production_pilot' => $pilot]);
                }
            }

            public function productionAcquisitionPilotOrigin(): string
            {
                return 'https://pilot.example.test';
            }

            public function productionAcquisitionScope(): array
            {
                if (now()->gte($this->approved['until'])) {
                    throw new RuntimeException('PILOT_WINDOW_CLOSED');
                }

                return $this->approved;
            }
        });
        $this->app->instance(LiveApi::class, new LiveApi(new MockHandler));
        DB::table('billing_paypal_merchant_bindings')->insert([
            'merchant_scope' => 'pilot-fixture', 'environment' => 'live', 'merchant_id' => 'SELLERFIXTURE',
            'client_id_sha256' => hash('sha256', 'fixture-client'), 'binding_source' => 'operator_dashboard',
            'evidence_ref' => 'TEST-receipt', 'receipt_sha256' => hash('sha256', 'fixture'), 'verified_at' => now(), 'created_at' => now(),
        ]);
    }

    public function test_exact_payment_uses_original_ledger_event_and_never_consumes_the_other_payment(): void
    {
        $selected = $this->paid();
        $other = $this->paid();
        $outbox = app(OutboxService::class);
        $this->assertSame(0, $outbox->dispatchPending());
        $priorOther = $this->outbox($other);
        $this->assertSame('CONSUMER_NOT_READY', $priorOther->last_error);

        $billingRows = $this->billingRows();
        $receive = function (PaymentSettled $event, array $approved) use ($selected): bool {
            $this->assertTrue(config('h5.acquisition_production_pilot'));
            $this->assertSame($this->approved, $approved);
            $this->assertSame($selected, $event->payment_id);
            $this->assertSame([399, 'USD', false], [$event->amount_minor, $event->currency, $event->is_test]);
            app(PaymentReceiver::class)->handle($event);
            $receipt = DB::table('acquisition_receipts')->where('payment_id', $selected)->first();
            $this->assertSame('matched', $receipt->source_status);
            $delivery = DB::table('acquisition_deliveries')->where('receipt_id', $event->event_id)->first();
            $source = DB::table('acquisition_sources')->find($receipt->source_id);
            $context = app(CheckoutContextStore::class)->forDelivery($delivery, $receipt, $source);

            return $delivery->pixel_id === $approved['pixel_id'] && $delivery->pixel_version === $approved['pixel_version']
                && $delivery->environment === 'production' && $context['_checkout_status'] === 'valid';
        };
        $this->assertSame('delivered', $outbox->dispatchProductionPilotPayment($selected, $receive));
        $this->assertSame('already_delivered', $outbox->dispatchProductionPilotPayment($selected, fn () => $this->fail('Redispatched')));
        $this->assertSame(1, $this->outbox($selected)->attempts);
        $this->assertNotNull($this->outbox($selected)->delivered_at);
        $this->assertEquals($priorOther, $this->outbox($other));
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertDatabaseCount('acquisition_deliveries', 1);
        $this->assertEquals($billingRows, $this->billingRows());
    }

    public function test_receiver_failure_rolls_back_both_sides_and_allows_a_clean_retry(): void
    {
        $id = $this->paid();
        $prior = $this->outbox($id);
        $outbox = app(OutboxService::class);
        $this->assertRefused('BILLING_PILOT_RECEIVER_NOT_CONFIRMED', function () use ($id, $outbox) {
            $outbox->dispatchProductionPilotPayment($id, function (PaymentSettled $event): bool {
                app(PaymentReceiver::class)->handle($event);

                return false;
            });
        });
        $this->assertEquals($prior, $this->outbox($id));
        $this->assertDatabaseCount('acquisition_receipts', 0);
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $this->assertRefused('PILOT_WINDOW_CLOSED', function () use ($id, $outbox) {
            $outbox->dispatchProductionPilotPayment($id, function (PaymentSettled $event): bool {
                app(PaymentReceiver::class)->handle($event);
                $this->travelTo(CarbonImmutable::parse($this->approved['until']));

                return true;
            });
        });
        $this->assertEquals($prior, $this->outbox($id));
        $this->assertDatabaseCount('acquisition_receipts', 0);
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-13T12:15:00Z'));
        $this->assertSame('delivered', $outbox->dispatchProductionPilotPayment($id, function (PaymentSettled $event): bool {
            app(PaymentReceiver::class)->handle($event);

            return DB::table('acquisition_deliveries')->where('receipt_id', $event->event_id)->exists();
        }));
    }

    public function test_modified_payload_expired_window_refund_and_reversal_cannot_dispatch(): void
    {
        $id = $this->paid();
        $row = $this->outbox($id);
        $payload = BillingStore::json($row->payload);
        $payload['amount_minor']++;
        DB::table('billing_outbox')->where('id', $row->id)->update(['payload' => json_encode($payload)]);
        $attempt = fn () => app(OutboxService::class)->dispatchProductionPilotPayment($id, fn () => $this->fail('Ineligible payment reached receiver'));
        $this->assertRefused('BILLING_PILOT_PAYMENT_NOT_ELIGIBLE', $attempt);
        DB::table('billing_outbox')->where('id', $row->id)->update(['payload' => $row->payload]);
        DB::table('billing_payments')->where('id', $id)->update(['refunded_amount_minor' => 1]);
        $this->assertRefused('BILLING_PILOT_PAYMENT_NOT_ELIGIBLE', $attempt);
        DB::table('billing_payments')->where('id', $id)->update(['refunded_amount_minor' => 0]);
        $payment = DB::table('billing_payments')->find($id);
        DB::table('billing_inbox')->insert($this->scope->columns() + [
            'id' => BillingStore::id(), 'provider_event_id' => 'FIXTURE-REVERSAL', 'event_type' => 'PAYMENT.SALE.REVERSED',
            'object_id' => $payment->provider_charge_id, 'is_test' => false, 'occurred_at' => now(), 'created_at' => now(),
        ]);
        $this->assertRefused('BILLING_PILOT_PAYMENT_NOT_ELIGIBLE', $attempt);
        $this->travelTo(CarbonImmutable::parse($this->approved['until']));
        $this->assertRefused('PILOT_WINDOW_CLOSED', $attempt);
        $this->assertEquals($row, $this->outbox($id));
    }

    public function test_reversal_received_after_initial_validation_rolls_back_the_handoff(): void
    {
        $id = $this->paid();
        $prior = $this->outbox($id);
        $payment = DB::table('billing_payments')->find($id);
        $this->assertRefused('BILLING_PILOT_PAYMENT_NOT_ELIGIBLE', function () use ($id, $payment) {
            app(OutboxService::class)->dispatchProductionPilotPayment($id, function (PaymentSettled $event) use ($payment): bool {
                app(PaymentReceiver::class)->handle($event);
                // Place the inbox observation between validation and the delivered mark.
                app(ProviderInboxStore::class)->receive($this->scope, [
                    'id' => 'FIXTURE-INTERLEAVED-REVERSAL', 'type' => 'PAYMENT.SALE.REVERSED',
                    'object_id' => $payment->provider_charge_id, 'is_test' => false, 'created' => now(),
                ]);

                return true;
            });
        });
        $this->assertEquals($prior, $this->outbox($id));
        $this->assertDatabaseCount('acquisition_receipts', 0);
        $this->assertDatabaseCount('acquisition_deliveries', 0);
    }

    public function test_historical_payment_wrong_scope_wrong_link_and_disabled_pilot_are_rejected(): void
    {
        $outbox = app(OutboxService::class);
        $reject = fn () => $this->fail('Ineligible payment reached receiver');
        foreach ([
            $this->paid(paidAt: CarbonImmutable::parse('2026-11-13T11:59:59Z')),
            $this->paid(scope: new ProviderScope('paypal', 'live', 'other-seller')),
            $this->paid(link: BillingStore::id()),
        ] as $id) {
            $prior = $this->outbox($id);
            $this->assertRefused('BILLING_PILOT_PAYMENT_NOT_ELIGIBLE', fn () => $outbox->dispatchProductionPilotPayment($id, $reject));
            $this->assertEquals($prior, $this->outbox($id));
        }
        $current = $this->paid();
        config(['h5.acquisition_production_pilot' => false]);
        $this->assertRefused('BILLING_PILOT_PAYMENT_NOT_ELIGIBLE', fn () => $outbox->dispatchProductionPilotPayment($current, $reject));
    }

    private function paid(?CarbonImmutable $paidAt = null, ?ProviderScope $scope = null, ?string $link = null): string
    {
        $paidAt ??= now()->toImmutable()->subMinute();
        $scope ??= $this->scope;
        $link ??= $this->approved['link_id'];
        $pixel = $this->approved['pixel_id'];
        $order = BillingStore::id();
        $subscription = BillingStore::id();
        $viewer = BillingStore::id();
        $source = BillingStore::id();
        $context = BillingStore::id();
        $privacy = BillingStore::id();
        $created = $paidAt->subMinute();
        $providerId = 'I-'.bin2hex(random_bytes(6));
        $sale = bin2hex(random_bytes(8));
        if (! DB::table('acquisition_pixels')->where('id', $pixel)->exists()) {
            DB::table('acquisition_pixels')->insert(['id' => $pixel, 'owner_id' => $viewer, 'name' => 'Fixture', 'platform' => 'meta',
                'external_id' => '12345678', 'environment' => 'production', 'version' => 1, 'created_at' => $created, 'updated_at' => $created]);
            DB::table('acquisition_pixel_versions')->insert(['pixel_id' => $pixel, 'version' => 1, 'platform' => 'meta',
                'external_id' => '12345678', 'environment' => 'production', 'created_at' => $created]);
        }
        if (! DB::table('acquisition_links')->where('id', $link)->exists()) {
            DB::table('acquisition_links')->insert(['id' => $link, 'owner_id' => $viewer, 'name' => 'Fixture', 'code' => bin2hex(random_bytes(8)),
                'drama_id' => BillingStore::id(), 'drama_title' => 'Fixture', 'platform' => 'meta', 'pixel_id' => $pixel, 'created_at' => $created, 'updated_at' => $created]);
        }
        DB::table('acquisition_privacy')->insert(['id' => $privacy, 'analytics' => 'denied', 'advertising' => 'granted',
            'policy_version' => 'fixture', 'created_at' => $created, 'updated_at' => $created]);
        DB::table('acquisition_sources')->insert(['id' => $source, 'link_id' => $link, 'pixel_id' => $pixel, 'pixel_version' => 1, 'privacy_id' => $privacy,
            'channel' => 'meta', 'consent' => 'granted', 'policy_version' => 'fixture', 'captured_at' => $created,
            'expires_at' => $created->addDay(), 'is_simulation' => false]);
        DB::table('acquisition_checkout_contexts')->insert(['id' => $context, 'source_id' => $source, 'privacy_id' => $privacy,
            'pixel_id' => $pixel, 'pixel_version' => 1, 'platform' => 'meta', 'rendered_at' => $created,
            'captured_at' => $created, 'expires_at' => $created->addDay(),
            'context_encrypted' => Crypt::encryptString(json_encode(['page_url' => 'https://pilot.example.test/membership',
                'ip' => '8.8.8.8', 'user_agent' => 'Explicit local fixture']))]);
        DB::table('billing_subscriptions')->insert($scope->columns() + [
            'id' => $subscription, 'first_order_id' => $order, 'viewer_id' => $viewer, 'provider_id' => $providerId,
            'state' => 'active', 'provider_status' => 'ACTIVE', 'resolution_required' => false, 'price_version' => 1,
            'first_week_amount_minor' => 399, 'regular_amount_minor' => 1299, 'currency' => 'USD', 'is_test' => false,
            'created_at' => $created, 'updated_at' => $created,
        ]);
        DB::table('billing_orders')->insert($scope->columns() + [
            'id' => $order, 'subscription_id' => $subscription, 'viewer_id' => $viewer, 'transaction_kind' => 'first_subscription',
            'idempotency_key' => 'fixture-'.$order, 'fingerprint' => hash('sha256', $order), 'state' => 'pending',
            'return_to' => '/membership', 'price_version' => 1, 'amount_minor' => 399, 'regular_amount_minor' => 1299,
            'discount_applied' => true, 'currency' => 'USD', 'is_test' => false, 'created_at' => $created, 'updated_at' => $created,
            'attribution_snapshot' => json_encode(['source_id' => $source, 'link_id' => $link, 'channel' => 'meta',
                'pixel_config_version' => 1, 'captured_at' => BillingStore::iso($created), 'consent' => 'granted', 'checkout_context_id' => $context]),
        ]);

        return app(PaymentLedger::class)->record($subscription, new ExternalPayment(
            $scope, $sale, $sale, $providerId, 'PAYERFIXTURE', $order, true, 399, 1299, 'USD', $paidAt, $paidAt->addWeek(), $paidAt,
        ));
    }

    private function assertRefused(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection');
        } catch (RuntimeException $exception) {
            $this->assertSame($code, $exception->getMessage());
        }
    }

    private function billingRows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(),
            ['billing_orders', 'billing_subscriptions', 'billing_payments', 'billing_entitlements']);
    }

    private function outbox(string $paymentId): object
    {
        return DB::table('billing_outbox')->where('payment_id', $paymentId)->first();
    }
}
