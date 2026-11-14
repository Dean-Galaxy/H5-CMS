<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\IdentityContext;
use App\Data\PaymentSettled;
use App\Data\StaffIdentity;
use App\Enums\Role;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Production\SinglePurchaseSender;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Billing\Data\ExternalPayment;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\PaymentLedger;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductionPurchaseSenderTest extends TestCase
{
    use DatabaseTruncation;

    private const ORIGIN = 'https://pilot.example.test';

    private array $approved;

    private ProviderScope $scope;

    private string $credentialFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13T12:15:00Z'));
        Http::preventStrayRequests();
        $this->scope = new ProviderScope('paypal', 'live', 'pilot-fixture');
        $this->approved = ['platform' => 'meta', 'link_id' => BillingStore::id(), 'pixel_id' => BillingStore::id(),
            'pixel_version' => 1, 'from' => '2026-11-13T12:00:00Z', 'until' => '2026-11-13T13:00:00Z'];
        // Keep the original testing bootstrap and guarded synthetic database. Only console admission is doubled.
        $this->app->instance('env', 'production');
        $this->app->instance(RuntimeEnvironmentGuard::class, new class(self::ORIGIN)
        {
            public function __construct(private string $origin) {}

            public function assertSafeRuntime(): void {}

            public function productionAcquisitionPilotOrigin(): string
            {
                return $this->origin;
            }

            public function productionAcquisitionScope(): array
            {
                $scope = config('h5.acquisition_production_scope');
                if (now()->gte($scope['until'])) {
                    throw new \LogicException('FIXTURE_WINDOW_CLOSED');
                }

                return $scope;
            }
        });
        config(['app.url' => self::ORIGIN, 'h5.acquisition_production_pilot' => true,
            'h5.acquisition_production_scope' => $this->approved, 'billing.paypal_live' => [
                'enabled' => true, 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret',
                'merchant_id' => 'SELLERFIXTURE', 'merchant_scope' => 'pilot-fixture',
            ]]);
        $this->app->register(AcquisitionServiceProvider::class);
        config(['acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false,
            'acquisition.platform_reviewed' => false, 'acquisition.meta_api_version' => 'v26.0']);
        DB::table('billing_paypal_merchant_bindings')->insert([
            'merchant_scope' => 'pilot-fixture', 'environment' => 'live', 'merchant_id' => 'SELLERFIXTURE',
            'client_id_sha256' => hash('sha256', 'fixture-client'), 'binding_source' => 'operator_dashboard',
            'evidence_ref' => 'TEST-receipt', 'receipt_sha256' => hash('sha256', 'fixture'), 'verified_at' => now(), 'created_at' => now(),
        ]);
        $directory = realpath(sys_get_temp_dir()).'/h5-production-purchase-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->credentialFile = $directory.'/credentials.json';
        config(['acquisition.credentials_file' => $this->credentialFile]);
        $this->credential();
    }

    protected function tearDown(): void
    {
        if (isset($this->credentialFile)) {
            unlink($this->credentialFile);
            rmdir(dirname($this->credentialFile));
        }
        parent::tearDown();
    }

    private function credential(array $extra = []): void
    {
        file_put_contents($this->credentialFile, json_encode([$this->approved['pixel_id'].':1' => $extra + [
            'platform' => 'meta', 'external_id' => '12345678', 'environment' => 'production',
            'token' => 'OFFLINE-SYNTHETIC-TOKEN',
        ]], JSON_THROW_ON_ERROR));
        chmod($this->credentialFile, 0600);
    }

    public function test_exact_command_hands_off_once_and_calls_the_fixed_meta_endpoint_after_commit(): void
    {
        [$payment, $order] = $this->paid();
        [$otherPayment, $otherOrder] = $this->paid();
        $otherBefore = DB::table('billing_outbox')->where('payment_id', $otherPayment)->first();
        $moneyBefore = $this->billingRows();
        $this->assertFalse(Event::hasListeners(PaymentSettled::class));
        $this->assertArrayNotHasKey('acquisition:dispatch', Artisan::all());
        $this->assertArrayHasKey('acquisition:purchase-production', Artisan::all());
        $during = null;
        Http::fake(['graph.facebook.com/*' => function (Request $request) use (&$during, $payment, $order) {
            $slot = DB::table('acquisition_production_purchase')->where('slot', 1)->first();
            $row = DB::table('acquisition_deliveries')->find($slot->delivery_id);
            $replay = app(SinglePurchaseSender::class)->send($payment, $order);
            $during = [DB::transactionLevel(), $slot->payment_id, $row, app(DeliveryWorker::class)->deliver($row->id), $request, $replay];

            return Http::response(['events_received' => 1]);
        }]);
        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => $order])
            ->expectsOutput('{"state":"api_accepted","reason":null}')->assertSuccessful();
        $this->assertNotNull($during);
        [$transactionLevel, $reservedPayment, $row, $claimed, $request, $replay] = $during;
        $this->assertSame(0, $transactionLevel);
        $this->assertSame($payment, $reservedPayment);
        $this->assertSame(['failed', 1, 'PRODUCTION_PURCHASE_RESERVED_NO_RETRY'], [$row->state, $row->attempts, $row->last_error]);
        $this->assertNotNull($row->first_attempted_at);
        $this->assertFalse($claimed);
        $this->assertSame(['state' => 'blocked', 'reason' => 'PRODUCTION_PURCHASE_NOT_FRESH'], $replay);
        $this->assertSame('https://graph.facebook.com/v26.0/12345678/events', $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer OFFLINE-SYNTHETIC-TOKEN'));
        $this->assertSame(['data' => [[
            'event_name' => 'Purchase', 'event_time' => now()->subMinute()->timestamp,
            'event_id' => 'h5_'.hash('sha256', 'PaymentSettled.v1:'.$payment), 'action_source' => 'website',
            'event_source_url' => self::ORIGIN.'/membership',
            'user_data' => ['client_ip_address' => '8.8.8.8', 'client_user_agent' => 'Explicit local fixture',
                'fbc' => 'fb.1.1763031600000.SyntheticClick'],
            'custom_data' => ['currency' => 'USD', 'value' => 3.99],
        ]]], $request->data());

        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => $order])->assertFailed();
        $this->artisan('acquisition:purchase-production', ['payment' => $otherPayment, 'order' => $otherOrder])->assertFailed();
        $this->assertEquals($otherBefore, DB::table('billing_outbox')->where('payment_id', $otherPayment)->first());
        $this->assertDatabaseHas('billing_outbox', ['payment_id' => $payment, 'attempts' => 1, 'last_error' => null]);
        $this->assertDatabaseHas('acquisition_deliveries', ['state' => 'api_accepted', 'attempts' => 1, 'payload_encrypted' => null]);
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->assertEquals($moneyBefore, $this->billingRows());
        Http::assertSentCount(1);
    }

    public function test_wrong_order_revoked_consent_and_unapproved_runtime_cannot_create_the_slot(): void
    {
        [$payment, $order] = $this->paid();
        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => BillingStore::id()])->assertFailed();
        $this->app->instance('env', 'testing');
        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => $order])->assertFailed();
        $this->app->instance('env', 'production');
        config(['h5.acquisition_production_scope.platform' => 'tiktok']);
        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => $order])->assertFailed();
        config(['h5.acquisition_production_scope' => $this->approved]);
        DB::table('acquisition_privacy')->update(['advertising' => 'denied']);
        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => $order])->assertFailed();
        $this->assertDatabaseCount('acquisition_production_purchase', 0);
        $this->assertDatabaseCount('acquisition_receipts', 0);
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $this->assertDatabaseHas('billing_outbox', ['payment_id' => $payment, 'attempts' => 0, 'delivered_at' => null]);
        Http::assertNothingSent();
    }

    public function test_ready_payment_is_never_claimed_by_the_worker_and_no_receipt_is_rewritten(): void
    {
        [$payment, $order] = $this->paid();
        $this->credential(['test_event_code' => 'EXCLUDED']);
        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => $order])->assertFailed();
        $row = DB::table('acquisition_deliveries')->first();
        $this->assertSame(['pilot_ready', 0], [$row->state, $row->attempts]);
        $this->assertSame(0, app(DeliveryWorker::class)->run());
        $this->assertFalse(app(DeliveryWorker::class)->deliver($row->id));
        $actor = new StaffIdentity(BillingStore::id(), 'Synthetic Owner', Role::Owner);
        $identity = Mockery::mock(IdentityContext::class);
        $identity->shouldReceive('staff', 'requireStaff')->andReturn($actor);
        $this->app->instance(IdentityContext::class, $identity);
        $this->assertSame(0, app(AssetManager::class)->retry($row->link_id));
        $before = DB::table('acquisition_receipts')->first();
        DB::table('acquisition_pixels')->where('id', $row->pixel_id)->update(['version' => 2]);
        $this->credential();
        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => $order])->assertFailed();
        $this->assertEquals($before, DB::table('acquisition_receipts')->first());
        $this->assertEquals($row, DB::table('acquisition_deliveries')->first());
        Http::assertNothingSent();
    }

    public static function lateBlocks(): array
    {
        return [
            'verified refund' => ['billing_payments', 'refunded_amount_minor', 1],
            'consent withdrawal' => ['acquisition_privacy', 'advertising', 'denied'],
        ];
    }

    #[DataProvider('lateBlocks')]
    public function test_changes_after_durable_reservation_stop_before_http(string $table, string $column, int|string $value): void
    {
        [$payment, $order] = $this->paid();
        $id = $table === 'billing_payments' ? $payment : DB::table('acquisition_privacy')->sole()->id;
        DB::listen(function (QueryExecuted $event) use ($table, $id, $column, $value) {
            if (in_array('PRODUCTION_PURCHASE_RESERVED_NO_RETRY', $event->bindings, true)) {
                DB::table($table)->where('id', $id)->update([$column => $value]);
            }
        });
        $this->assertSame(['state' => 'blocked', 'reason' => 'PRODUCTION_PURCHASE_STOPPED_NO_RETRY'],
            app(SinglePurchaseSender::class)->send($payment, $order));
        $this->assertDatabaseHas('acquisition_deliveries', ['state' => 'failed', 'attempts' => 1,
            'last_error' => 'PRODUCTION_PURCHASE_STOPPED_NO_RETRY']);
        Http::assertNothingSent();
    }

    public function test_unknown_response_burns_the_only_attempt_and_never_reaches_generic_worker(): void
    {
        [$payment, $order] = $this->paid();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['is_transient' => true]], 503)]);
        $sender = app(SinglePurchaseSender::class);
        $this->assertSame(['state' => 'unconfirmed', 'reason' => 'PRODUCTION_PURCHASE_UNKNOWN_NO_RETRY'], $sender->send($payment, $order));
        $this->assertSame(['state' => 'blocked', 'reason' => 'PRODUCTION_PURCHASE_NOT_FRESH'], $sender->send($payment, $order));
        $row = DB::table('acquisition_deliveries')->first();
        $this->assertSame(['failed', 1, 'PRODUCTION_PURCHASE_UNKNOWN_NO_RETRY'], [$row->state, $row->attempts, $row->last_error]);
        $this->assertSame(0, app(DeliveryWorker::class)->run());
        $this->assertFalse(app(DeliveryWorker::class)->deliver($row->id));
        Http::assertSentCount(1);
    }

    private function paid(): array
    {
        $paidAt = now()->toImmutable()->subMinute();
        $scope = $this->scope;
        $link = $this->approved['link_id'];
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
                'ip' => '8.8.8.8', 'user_agent' => 'Explicit local fixture', 'fbc' => 'fb.1.1763031600000.SyntheticClick',
                'email' => 'excluded@example.test']))]);
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

        $payment = app(PaymentLedger::class)->record($subscription, new ExternalPayment(
            $scope, $sale, $sale, $providerId, 'PAYERFIXTURE', $order, true, 399, 1299, 'USD', $paidAt, $paidAt->addWeek(), $paidAt,
        ));

        return [$payment, $order];
    }

    private function billingRows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(),
            ['billing_orders', 'billing_subscriptions', 'billing_payments', 'billing_entitlements']);
    }
}
