<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\IdentityContext;
use App\Data\PaymentSettled;
use App\Data\StaffIdentity;
use App\Enums\Role;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\AnalyticsReport;
use App\Modules\Acquisition\Production\AutomaticPurchaseWorker;
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

            public function productionAutomaticPurchasePolicy(): ?array
            {
                $policy = config('test_automatic_policy');

                return $policy && ($policy['until'] === null || now()->lt($policy['until'])) ? $policy : null;
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
            'platform' => $this->approved['platform'], 'external_id' => '12345678', 'environment' => 'production',
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

    public function test_tiktok_uses_its_own_slot_and_keeps_meta_success_immutable(): void
    {
        [$metaPayment, $metaOrder] = $this->paid();
        $viewer = BillingStore::id();
        $tiktokUrl = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';
        $during = null;
        $competing = [];
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
            $tiktokUrl => function (Request $request) use (&$during, &$competing) {
                $slot = DB::table('acquisition_production_purchase')->where('slot', 2)->sole();
                $row = DB::table('acquisition_deliveries')->find($slot->delivery_id);
                $during = [$request, $row->state, $row->attempts, DB::transactionLevel(),
                    app(DeliveryWorker::class)->deliver($row->id), Artisan::call('acquisition:purchase-production', $competing)];

                return Http::response(['code' => 0, 'request_id' => 'OFFLINE-ACCEPTANCE']);
            },
        ]);
        $sender = app(SinglePurchaseSender::class);
        $this->assertSame('api_accepted', $sender->send($metaPayment, $metaOrder)['state']);
        $meta = DB::table('acquisition_production_purchase')->where('slot', 1)->sole();
        $metaDelivery = DB::table('acquisition_deliveries')->find($meta->delivery_id);
        $this->selectTikTok($viewer);
        $this->artisan('acquisition:purchase-production', ['payment' => $metaPayment, 'order' => $metaOrder])->assertFailed();
        [$otherPayment, $otherOrder] = $this->paid();
        $this->artisan('acquisition:purchase-production', ['payment' => $otherPayment, 'order' => $otherOrder])->assertFailed();
        $this->assertDatabaseMissing('acquisition_production_purchase', ['slot' => 2]);
        [$payment, $order] = $this->paid($viewer);
        $competing = ['payment' => $payment, 'order' => $order];
        $this->assertSame('api_accepted', $sender->send($payment, $order)['state']);
        $this->assertSame('blocked', $sender->send($payment, $order)['state']);
        [$request, $state, $attempts, $level, $claimed, $competingExit] = $during;
        $this->assertSame(['failed', 1, 0, false, 1], [$state, $attempts, $level, $claimed, $competingExit]);
        $this->assertSame($tiktokUrl, $request->url());
        $this->assertTrue($request->hasHeader('Access-Token', 'OFFLINE-SYNTHETIC-TOKEN'));
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertSame(['event_source' => 'web', 'event_source_id' => '12345678', 'data' => [[
            'event' => 'Purchase', 'event_time' => now()->subMinute()->timestamp,
            'event_id' => 'h5_'.hash('sha256', 'PaymentSettled.v1:'.$payment),
            'user' => ['ip' => '8.8.8.8', 'user_agent' => 'Explicit local fixture', 'ttclid' => 'TikTokFixture-42', 'ttp' => 'BrowserFixture_42'],
            'page' => ['url' => self::ORIGIN.'/membership'], 'properties' => ['currency' => 'USD', 'value' => 3.99],
        ]]], json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('acquisition_production_purchase', ['slot' => 2, 'payment_id' => $payment, 'order_id' => $order]);
        $this->assertEquals($meta, DB::table('acquisition_production_purchase')->where('slot', 1)->sole());
        $this->assertEquals($metaDelivery, DB::table('acquisition_deliveries')->find($meta->delivery_id));
        $this->assertDatabaseHas('billing_outbox', ['payment_id' => $otherPayment, 'delivered_at' => null, 'attempts' => 0]);
        Http::assertSentCount(2);
    }

    public function test_tiktok_defers_no_retries_and_rejects_test_credentials(): void
    {
        $viewer = BillingStore::id();
        $this->selectTikTok($viewer);
        [$payment, $order] = $this->paid($viewer);
        $this->credential(['test_event_code' => 'ONLY-FOR-TESTS']);
        $this->artisan('acquisition:purchase-production', ['payment' => $payment, 'order' => $order])->assertFailed();
        Http::assertNothingSent();

        $this->credential();
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0], 503)]);
        $sender = app(SinglePurchaseSender::class);
        $this->assertSame('unconfirmed', $sender->send($payment, $order)['state']);
        $this->assertSame('blocked', $sender->send($payment, $order)['state']);
        $this->assertDatabaseHas('acquisition_deliveries', ['platform' => 'tiktok', 'state' => 'failed', 'attempts' => 1,
            'last_error' => 'PRODUCTION_PURCHASE_UNKNOWN_NO_RETRY', 'payload_encrypted' => null]);
        Http::assertSentCount(1);
    }

    public function test_automatic_task_finds_the_new_settled_payment_and_sends_once_with_no_purchaser_callback(): void
    {
        [$oldPayment, $oldOrder] = $this->paid();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1]),
            'business-api.tiktok.com/*' => Http::response(['code' => 0])]);
        $this->artisan('acquisition:purchase-production', ['payment' => $oldPayment, 'order' => $oldOrder])->assertSuccessful();
        $manual = DB::table('acquisition_production_purchase')->sole();
        [$oldDeferred] = $this->paid();
        $this->travel(5)->minutes();
        $viewer = BillingStore::id();
        $this->selectTikTok($viewer);
        [$newPayment] = $this->paid($viewer);
        [$differentViewer] = $this->paid();
        $this->enableAutomatic('single', $viewer);
        $money = $this->billingRows();

        $this->artisan('acquisition:dispatch-production-purchases')->expectsOutputToContain('"api_accepted":1')->assertSuccessful();
        $this->artisan('acquisition:dispatch-production-purchases')->expectsOutputToContain('"selected":0')->assertSuccessful();
        $this->assertDatabaseHas('acquisition_automatic_purchases', ['payment_id' => $newPayment]);
        $this->assertDatabaseMissing('acquisition_automatic_purchases', ['payment_id' => $differentViewer]);
        $this->assertDatabaseMissing('acquisition_automatic_purchases', ['payment_id' => $oldDeferred]);
        $this->assertEquals($manual, DB::table('acquisition_production_purchase')->sole());
        $this->assertDatabaseHas('acquisition_deliveries', ['platform' => 'tiktok', 'state' => 'api_accepted', 'attempts' => 1]);
        $this->assertDatabaseHas('billing_outbox', ['payment_id' => $oldDeferred, 'delivered_at' => null]);
        $this->assertEquals($money, $this->billingRows());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://business-api.tiktok.com/open_api/v1.3/event/track/'
            && ($request->data()['data'][0]['event_id'] ?? '') === 'h5_'.hash('sha256', 'PaymentSettled.v1:'.$newPayment));
    }

    public static function independentChoices(): array
    {
        return [
            'both' => [true, true, 200],
            'site analysis only' => [true, false, 200],
            'advertising only' => [false, true, 200],
            'site analysis survives a platform timeout' => [true, true, 504],
            'continuous TikTok' => [true, true, 200, 'continuous'],
            'continuous Meta' => [true, true, 200, 'continuous', 'meta'],
            'continuous analytics only' => [true, false, 200, 'continuous'],
            'continuous advertising only' => [false, true, 200, 'continuous'],
            'continuous neither' => [false, false, 200, 'continuous'],
            'continuous preserves analytics through platform failure' => [true, true, 504, 'continuous'],
            'continuous honors late advertising withdrawal' => [true, true, 200, 'continuous', 'tiktok', true],
        ];
    }

    #[DataProvider('independentChoices')]
    public function test_scoped_timer_records_site_finance_and_advertising_as_independent_consented_results(
        bool $analytics,
        bool $advertising,
        int $httpStatus,
        string $mode = 'single',
        string $platform = 'tiktok',
        bool $withdrawAdvertising = false,
    ): void {
        $viewer = BillingStore::id();
        if ($platform === 'tiktok') {
            $this->selectTikTok($viewer);
        }
        [$payment, $order] = $this->paid($viewer, $advertising, replay: true);
        $this->enableAutomatic($mode, $viewer);
        config(['h5.acquisition_automatic_site_analytics' => true]);

        $subject = $analytics ? $this->analyticsCheckout($order, $viewer, true) : null;
        if ($withdrawAdvertising) {
            DB::table('acquisition_privacy')->update(['advertising' => 'denied']);
            DB::table('acquisition_sources')->update(['revoked_at' => now()]);
        }

        Http::fake($platform === 'tiktok'
            ? ['business-api.tiktok.com/*' => Http::response(['code' => 0], $httpStatus)]
            : ['graph.facebook.com/*' => Http::response(['events_received' => 1], $httpStatus)]);
        $this->assertSame($httpStatus === 200 ? 0 : 1, Artisan::call('acquisition:dispatch-production-purchases'));
        $expectedSiteState = $analytics ? 'accepted' : ($mode === 'continuous' ? 'waiting' : 'skipped');
        $this->assertSame($expectedSiteState, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['site_analytics']);
        $this->travel(2)->minutes();
        $this->artisan('acquisition:dispatch-production-purchases')->assertSuccessful();
        $this->assertDatabaseHas('billing_entitlements', ['payment_id' => $payment, 'revoked_at' => null]);
        $this->assertDatabaseCount('acquisition_behaviors', $analytics ? 1 : 0);
        if ($analytics) {
            $this->assertDatabaseHas('acquisition_behaviors', ['viewer_id' => $viewer, 'event_name' => 'payment_confirmed',
                'subject_id' => $subject, 'is_test' => false]);
        }
        $expectAd = $advertising && ! $withdrawAdvertising;
        Http::assertSentCount($expectAd ? 1 : 0);
        if ($expectAd) {
            $this->assertDatabaseHas('acquisition_deliveries', ['state' => $httpStatus === 200 ? 'api_accepted' : 'failed',
                'attempts' => 1, 'platform' => $platform]);
            Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $platform === 'tiktok' ? 'business-api.tiktok.com' : 'graph.facebook.com'));
        }
        if ($mode === 'continuous' && $analytics) {
            $this->assertNotNull(DB::table('acquisition_analytics_orders')->where('order_id', $order)->value('purchase_processed_at'));
        }

        $actor = new StaffIdentity(BillingStore::id(), 'Synthetic Owner', Role::Owner);
        $identity = Mockery::mock(IdentityContext::class);
        $identity->shouldReceive('staff', 'requireStaff')->andReturn($actor);
        $this->app->instance(IdentityContext::class, $identity);
        $report = app(AnalyticsReport::class);
        $this->assertTrue($report->available());
        $this->assertSame($analytics ? 399 : 0, $report->read(['test' => 'real'])['finance']['gross_minor']);
        config(['h5.acquisition_automatic_mode' => 'off', 'h5.acquisition_automatic_site_analytics' => false]);
        $this->assertSame($analytics, $report->available());
        if ($analytics) {
            $summary = $report->read(['test' => 'real']);
            $this->assertFalse($summary['diagnostics']['enabled']);
            $this->assertSame(399, $summary['finance']['gross_minor']);
        }
    }

    public function test_continuous_does_not_reprocess_paid_trials_or_pre_activation_orders_in_either_branch(): void
    {
        [$oldPayment, $oldOrder] = $this->paid($oldViewer = BillingStore::id());
        $this->analyticsCheckout($oldOrder, $oldViewer, true);
        $this->travel(5)->minutes();

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        [$exactPayment, $exactOrder] = $this->paid($exactViewer = BillingStore::id());
        $this->analyticsCheckout($exactOrder, $exactViewer, true);
        $this->artisan('acquisition:purchase-production', ['payment' => $exactPayment, 'order' => $exactOrder])->assertSuccessful();

        [$trialPayment, $trialOrder] = $this->paid($trialViewer = BillingStore::id());
        $this->analyticsCheckout($trialOrder, $trialViewer, true);
        $this->enableAutomatic('single', $trialViewer);
        $this->artisan('acquisition:dispatch-production-purchases')->expectsOutputToContain('"api_accepted":1')->assertSuccessful();

        $this->enableAutomatic('continuous');
        config(['h5.acquisition_automatic_site_analytics' => true]);
        $this->assertSame(0, Artisan::call('acquisition:dispatch-production-purchases'));
        $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('waiting', $summary['site_analytics']);
        $this->assertSame(0, $summary['selected']);
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->assertDatabaseMissing('acquisition_automatic_purchases', ['payment_id' => $oldPayment]);
        $this->assertDatabaseHas('acquisition_production_purchase', ['payment_id' => $exactPayment, 'slot' => 1]);
        $this->assertNotNull(DB::table('acquisition_automatic_purchases')->where('payment_id', $trialPayment)->value('run_id'));
        Http::assertSentCount(2);
    }

    public function test_continuous_task_rotates_preflight_failures_and_ignores_pre_activation_orders(): void
    {
        [$oldDeferred] = $this->paid();
        $this->travel(5)->minutes();
        [$refunded] = $this->paid();
        DB::table('billing_payments')->where('id', $refunded)->update(['refunded_amount_minor' => 399]);
        $this->travel(1)->second();
        [$fresh] = $this->paid();
        $this->enableAutomatic('continuous');
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

        $this->artisan('acquisition:dispatch-production-purchases', ['--limit' => 1])->expectsOutputToContain('"blocked":1')->assertSuccessful();
        $this->artisan('acquisition:dispatch-production-purchases', ['--limit' => 1])->expectsOutputToContain('"api_accepted":1')->assertSuccessful();
        $this->assertDatabaseHas('acquisition_automatic_purchases', ['payment_id' => $refunded, 'delivery_id' => null]);
        $this->assertDatabaseMissing('acquisition_automatic_purchases', ['payment_id' => $oldDeferred]);
        $this->assertDatabaseMissing('billing_outbox', ['payment_id' => $fresh, 'delivered_at' => null]);
        Http::assertSentCount(1);
    }

    public function test_unknown_automatic_result_cannot_be_sent_by_concurrent_or_later_worker(): void
    {
        [$payment] = $this->paid();
        $this->enableAutomatic('continuous');
        $worker = app(AutomaticPurchaseWorker::class);
        $nested = null;
        Http::fake(['graph.facebook.com/*' => function () use (&$nested, $worker) {
            $nested = $worker->run(5);

            return Http::response(['error' => ['is_transient' => true]], 504);
        }]);
        $this->artisan('acquisition:dispatch-production-purchases')->expectsOutputToContain('"unconfirmed":1')->assertFailed();
        $this->assertSame(0, $nested['selected']);
        $this->travel(2)->minutes();
        $this->artisan('acquisition:dispatch-production-purchases')->expectsOutputToContain('"selected":0')->assertSuccessful();
        $this->assertDatabaseHas('acquisition_deliveries', ['state' => 'failed', 'attempts' => 1,
            'last_error' => 'PRODUCTION_PURCHASE_UNKNOWN_NO_RETRY']);
        $this->assertDatabaseHas('billing_entitlements', ['payment_id' => $payment, 'revoked_at' => null]);
        Http::assertSentCount(1);
    }

    public function test_late_withdrawal_and_expired_acceptance_do_not_send(): void
    {
        $viewer = BillingStore::id();
        [$payment] = $this->paid($viewer);
        $this->enableAutomatic('single', $viewer);
        DB::listen(function (QueryExecuted $query) {
            if (in_array('PRODUCTION_PURCHASE_RESERVED_NO_RETRY', $query->bindings, true)) {
                DB::table('acquisition_privacy')->update(['advertising' => 'denied']);
            }
        });
        $this->artisan('acquisition:dispatch-production-purchases')->expectsOutputToContain('"blocked":1')->assertSuccessful();
        $this->assertDatabaseHas('acquisition_deliveries', ['state' => 'failed', 'attempts' => 1,
            'last_error' => 'PRODUCTION_PURCHASE_STOPPED_NO_RETRY']);
        $this->travel(45)->minutes();
        $this->artisan('acquisition:dispatch-production-purchases')->expectsOutputToContain('"state":"inactive"')->assertSuccessful();
        $this->assertDatabaseHas('billing_entitlements', ['payment_id' => $payment, 'revoked_at' => null]);
        Http::assertNothingSent();
    }

    public function test_scheduled_command_is_idle_by_default_and_never_creates_a_delivery(): void
    {
        $this->paid();
        $this->artisan('acquisition:dispatch-production-purchases')->expectsOutputToContain('"state":"inactive"')->assertSuccessful();
        $this->assertDatabaseCount('acquisition_automatic_purchases', 0);
        Http::assertNothingSent();
    }

    private function enableAutomatic(string $mode, ?string $viewer = null): void
    {
        $policy = ['mode' => $mode, 'from' => now()->subMinutes(4)->toIso8601ZuluString(),
            'until' => null, 'run_id' => null];
        if ($mode === 'single') {
            $policy = array_replace($policy, ['until' => now()->addMinutes(30)->toIso8601ZuluString(), 'run_id' => BillingStore::id(),
                'viewer_id' => $viewer, 'link_id' => $this->approved['link_id'], 'pixel_id' => $this->approved['pixel_id'],
                'pixel_version' => $this->approved['pixel_version']]);
        }
        config(['h5.acquisition_automatic_mode' => $mode, 'test_automatic_policy' => $policy]);
    }

    private function selectTikTok(string $viewer): void
    {
        $this->approved = array_replace($this->approved, ['platform' => 'tiktok', 'viewer_id' => $viewer,
            'link_id' => BillingStore::id(), 'pixel_id' => BillingStore::id()]);
        config(['h5.acquisition_production_scope' => $this->approved]);
        $this->credential();
    }

    private function paid(?string $viewer = null, bool $advertising = true, bool $replay = false): array
    {
        $paidAt = now()->toImmutable()->subMinute();
        $scope = $this->scope;
        $link = $this->approved['link_id'];
        $pixel = $this->approved['pixel_id'];
        $platform = $this->approved['platform'];
        $order = BillingStore::id();
        $subscription = BillingStore::id();
        $viewer ??= BillingStore::id();
        $source = BillingStore::id();
        $context = BillingStore::id();
        $privacy = BillingStore::id();
        $created = $paidAt->subMinute();
        $providerId = 'I-'.bin2hex(random_bytes(6));
        $sale = bin2hex(random_bytes(8));
        if (! DB::table('acquisition_pixels')->where('id', $pixel)->exists()) {
            DB::table('acquisition_pixels')->insert(['id' => $pixel, 'owner_id' => $viewer, 'name' => 'Fixture', 'platform' => $platform,
                'external_id' => '12345678', 'environment' => 'production', 'version' => 1, 'created_at' => $created, 'updated_at' => $created]);
            DB::table('acquisition_pixel_versions')->insert(['pixel_id' => $pixel, 'version' => 1, 'platform' => $platform,
                'external_id' => '12345678', 'environment' => 'production', 'created_at' => $created]);
        }
        if (! DB::table('acquisition_links')->where('id', $link)->exists()) {
            DB::table('acquisition_links')->insert(['id' => $link, 'owner_id' => $viewer, 'name' => 'Fixture', 'code' => bin2hex(random_bytes(8)),
                'drama_id' => BillingStore::id(), 'drama_title' => 'Fixture', 'platform' => $platform, 'pixel_id' => $pixel, 'created_at' => $created, 'updated_at' => $created]);
        }
        DB::table('acquisition_privacy')->insert(['id' => $privacy, 'analytics' => 'denied', 'advertising' => $advertising ? 'granted' : 'denied',
            'policy_version' => 'fixture', 'created_at' => $created, 'updated_at' => $created]);
        DB::table('acquisition_sources')->insert(['id' => $source, 'link_id' => $link, 'pixel_id' => $pixel, 'pixel_version' => 1, 'privacy_id' => $privacy,
            'channel' => $platform, 'consent' => 'granted', 'policy_version' => 'fixture', 'captured_at' => $created,
            'expires_at' => $created->addDay(), 'is_simulation' => false]);
        DB::table('acquisition_checkout_contexts')->insert(['id' => $context, 'source_id' => $source, 'privacy_id' => $privacy,
            'pixel_id' => $pixel, 'pixel_version' => 1, 'platform' => $platform, 'rendered_at' => $created,
            'captured_at' => $created, 'expires_at' => $created->addDay(),
            'context_encrypted' => Crypt::encryptString(json_encode(['page_url' => 'https://pilot.example.test/membership',
                'ip' => '8.8.8.8', 'user_agent' => 'Explicit local fixture', 'email' => 'excluded@example.test',
                ...($platform === 'meta' ? ['fbc' => 'fb.1.1763031600000.SyntheticClick']
                    : ['ttclid' => 'TikTokFixture-42', 'ttp' => 'BrowserFixture_42', 'fbc' => 'fb.1.1763031600000.ExcludedMeta'])]))]);
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
            'attribution_snapshot' => $advertising ? json_encode(['source_id' => $source, 'link_id' => $link, 'channel' => $platform,
                'pixel_config_version' => 1, 'captured_at' => BillingStore::iso($created), 'consent' => 'granted', 'checkout_context_id' => $context]) : null,
        ]);

        $fact = new ExternalPayment($scope, $sale, $sale, $providerId, 'PAYERFIXTURE', $order, true, 399, 1299,
            'USD', $paidAt, $paidAt->addWeek(), $paidAt);
        $ledger = app(PaymentLedger::class);
        $payment = $ledger->record($subscription, $fact);
        if ($replay) {
            $this->assertSame($payment, $ledger->record($subscription, $fact));
            $this->assertSame(1, DB::table('billing_outbox')->where('payment_id', $payment)->count());
        }

        return [$payment, $order];
    }

    private function analyticsCheckout(string $order, string $viewer, bool $analytics): string
    {
        $data = json_decode(DB::table('billing_orders')->where('id', $order)->value('attribution_snapshot') ?? 'null', true);
        $source = isset($data['source_id']) ? DB::table('acquisition_sources')->find($data['source_id']) : null;
        $privacy = $source?->privacy_id ?? DB::table('acquisition_privacy')->value('id');
        $subject = BillingStore::id();
        $grantedAt = now()->subMinutes(3);
        $checkoutAt = now()->subMinutes(2);
        DB::table('acquisition_privacy')->where('id', $privacy)->update(['viewer_id' => $viewer,
            'analytics' => $analytics ? 'granted' : 'denied', 'analytics_granted_at' => $analytics ? $grantedAt : null]);
        DB::table('acquisition_subjects')->insert(['id' => $subject, 'privacy_id' => $privacy, 'viewer_id' => $viewer,
            'session_key' => BillingStore::id(), 'created_at' => $checkoutAt, 'updated_at' => $checkoutAt]);
        DB::table('acquisition_analytics_orders')->insert(['viewer_id' => $viewer, 'order_id' => $order, 'subject_id' => $subject,
            'privacy_id' => $privacy, 'source_id' => $source?->id, 'checkout_at' => $checkoutAt, 'created_at' => $checkoutAt]);

        return $subject;
    }

    private function billingRows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(),
            ['billing_orders', 'billing_subscriptions', 'billing_payments', 'billing_entitlements']);
    }
}
