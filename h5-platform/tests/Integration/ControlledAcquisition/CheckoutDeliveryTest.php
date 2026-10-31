<?php

namespace Tests\Integration\ControlledAcquisition;

use App\Contracts\AttributionService;
use App\Contracts\BillingService;
use App\Contracts\CheckoutAttribution;
use App\Contracts\MembershipReader;
use App\Data\AttributionSnapshot;
use App\Data\Checkout;
use App\Data\CheckoutInput;
use App\Data\Membership;
use App\Data\Offer;
use App\Data\PaymentSettled;
use App\Data\ViewerIdentity;
use App\Enums\OrderState;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionState;
use App\Enums\TransactionKind;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Modules\Acquisition\Services\CheckoutContextStore;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Identity\Models\Viewer;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Acquisition\AcquisitionTestCase;

/** Real 01 session, 04 HTTP, 05 capture/listener/gate/worker/adapters; explicit Billing/settlement fixtures. */
final class CheckoutDeliveryTest extends AcquisitionTestCase
{
    private const ORIGIN = 'https://staging.example.test';

    private array $savedEnvironment;

    private array $billingCalls = [];

    private array $fakeOrders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedEnvironment = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $this->freezeTime();
        $this->withoutVite();
        $this->signIn(); // Existing test-only staff fixture, used only to create synthetic assets.
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        Route::middleware('web')->group(base_path('routes/modules/acquisition.php'));
        $billing = Mockery::mock(BillingService::class);
        $billing->shouldReceive('offer')->andReturnUsing(fn () => new Offer((string) Str::uuid(), 1, 'USD',
            1200, 300, true, now()->addMinutes(30)->toIso8601String(), 1200));
        $billing->shouldReceive('checkout')->andReturnUsing(function (ViewerIdentity $viewer, CheckoutInput $input,
            string $key, ?AttributionSnapshot $source): Checkout {
            $this->billingCalls[] = compact('viewer', 'input', 'key', 'source');
            // No ledger/payment writes and no automatic PaymentSettled on browser checkout.
            return $this->fakeOrders[$key] ??= new Checkout((string) Str::uuid(), OrderState::Pending,
                null, now()->addMinutes(30)->toIso8601String());
        });
        $this->app->instance(BillingService::class, $billing);
        $membership = Mockery::mock(MembershipReader::class);
        $membership->shouldReceive('membership')->andReturn(new Membership(false, null, null, false,
            SubscriptionState::None, false, null, null, [SubscriptionAction::Checkout]));
        $this->app->instance(MembershipReader::class, $membership);
        $viewer = Viewer::forceCreate(['id' => (string) Str::uuid(), 'name' => '06 explicit synthetic viewer',
            'email' => Str::uuid().'@example.test', 'password' => 'SyntheticPassword123!', 'active' => true,
            'email_verified_at' => now()]);
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version,
            '_token' => str_repeat('a', 40)]);
        // AcquisitionTestCase's staff helper intentionally selects an admin session cookie.
        // Select the actual viewer cookie before the multi-request Audience journey.
        $cookie = config('h5.workspace').'_viewer';
        config(['session.cookie' => $cookie]);
        session()->setName($cookie);
        $this->withCookie($cookie, session()->getId());
        $this->withCredentials(); // Laravel JSON requests otherwise omit this browser session cookie.
        session()->save(); // Persist synthetic viewer version/token before StartSession reloads this explicit ID.
        $this->environment('staging');
        config(['app.debug' => false, 'app.url' => self::ORIGIN, 'h5.controlled_staging' => true,
            'h5.acquisition_staging_test' => true, 'h5.simulation' => false, 'h5.local_us_test_region' => false,
            'h5.local_modules' => '', 'h5.modules.audience.ready' => true,
            'h5.modules.acquisition' => ['provider' => AcquisitionServiceProvider::class, 'ready' => true],
            'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
            'acquisition.analytics_enabled' => false, 'acquisition.measurement.enabled' => false,
            'acquisition.measurement.synthetic_policy' => false, 'acquisition.registration.synthetic_policy' => false,
            'acquisition.registration.observations_enabled' => false, 'session.secure' => true,
            'session.http_only' => true, 'session.same_site' => 'lax', 'trustedproxy.proxies' => null,
            'mail.default' => 'smtp', 'mail.driver' => null, 'mail.from.address' => 'synthetic@example.test',
            'mail.mailers' => ['smtp' => ['transport' => 'smtp', 'scheme' => 'smtps', 'url' => null,
                'host' => 'smtp.example.test', 'port' => 465, 'timeout' => 10, 'username' => 'SYNTHETIC',
                'password' => 'NOT_A_REAL_SECRET', 'require_tls' => true, 'verify_peer' => true]],
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4', 'HTTPS' => 'on', 'SERVER_PORT' => 443,
            'HTTP_USER_AGENT' => '06 synthetic combined browser']);
        $this->withHeaders(['Origin' => self::ORIGIN, 'Sec-Fetch-Site' => 'same-origin',
            'X-CSRF-TOKEN' => str_repeat('a', 40)]);
        $this->assertSame(self::ORIGIN, app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin());
        $this->assertInstanceOf(CheckoutContextStore::class, app(CheckoutAttribution::class));
        $this->assertInstanceOf(AttributionRecorder::class, app(AttributionService::class));
    }

    private function environment(string $environment): void
    {
        $this->app->instance('env', $environment);
        config(['app.env' => $environment]);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $environment;
        putenv('APP_ENV='.$environment);
    }

    protected function tearDown(): void
    {
        [$server, $env, $process] = $this->savedEnvironment;
        if ($server === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $server;
        }
        if ($env === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $env;
        }
        putenv($process === false ? 'APP_ENV' : 'APP_ENV='.$process);
        $this->app->instance('env', 'testing');
        config(['app.env' => 'testing']);
        parent::tearDown();
    }

    public static function platforms(): array
    {
        return ['Meta' => ['meta'], 'TikTok' => ['tiktok']];
    }

    /** Reuses explicit test assets/source rows; never changes a real simulated source into a trusted one. */
    private function purchase(string $platform, bool $capture = true): array
    {
        [$pixel, $link] = $this->assets($platform);
        $source = $this->source($pixel, $link, false); // Synthetic eligible-source fixture, not official visitor evidence.
        $this->postJson(self::ORIGIN.'/api/v1/tracking/consent', ['analytics' => true, 'advertising' => true,
            'policy_version' => 'analytics-v1'])->assertOk()->assertJsonPath('advertising_test_only', true)
            ->assertJsonPath('analytics_available', false)->assertJsonPath('consent.analytics', 'denied');
        $privacy = session('tracking.privacy');
        $this->assertNotNull($privacy);
        DB::table('acquisition_sources')->where('id', $source->source_id)->update(['privacy_id' => $privacy,
            'context_encrypted' => Crypt::encryptString(json_encode(['fbc' => 'fb.1.1700000000000.FAKE_CLICK',
                'fbp' => 'fb.1.1700000000000.1234', 'ttclid' => 'E.C.P.FAKE_CLICK', 'ttp' => 'FAKE_TTP']))]);
        $this->withSession(['tracking.source' => $source->source_id]);
        session()->save();
        $response = $this->get(self::ORIGIN.'/membership?purchase=1&return_to=%2Fcatalog');
        $response->assertOk();
        $offer = $response->viewData('offer');
        $draft = session('audience.billing.offers.'.$offer->offer_id);
        $this->assertSame(['path' => '/membership', 'rendered_at' => now()->toIso8601String()], $draft['checkout_page']);
        if (! $capture) {
            // Actual capture port is still resolved: it rejects the empty current HTTP UA.
            $this->withServerVariables(['HTTP_USER_AGENT' => '']);
        }
        $input = ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1',
            '_token' => str_repeat('a', 40)];
        $this->post(self::ORIGIN.'/membership/checkout', $input)->assertRedirect();
        $this->assertCount(1, $this->billingCalls);
        $attribution = $this->billingCalls[0]['source'];
        $this->assertNotNull($attribution);
        $this->assertSame($source->source_id, $attribution->source_id);
        $this->assertSame($capture, $attribution->checkout_context_id !== null);
        $this->assertDatabaseCount('acquisition_receipts', 0); // Redirect is NOT settlement.
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        Http::assertNothingSent();

        return [$input, $attribution];
    }

    private function settled(AttributionSnapshot $source): PaymentSettled
    {
        $checkout = array_values($this->fakeOrders)[0];
        // Explicit trusted-event fixture, not emitted by Billing stub, browser or provider webhook.
        $event = new PaymentSettled((string) Str::uuid(), 'PaymentSettled.v1', (string) Str::uuid(),
            $checkout->order_id, (string) Str::uuid(), TransactionKind::FirstSubscription, true, 300, 'USD',
            now()->toIso8601String(), true, $source);
        event($event); // Real registered 05 listener.
        event($event);
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertDatabaseCount('acquisition_deliveries', 1);

        return $event;
    }

    private function transport(string $event): object
    {
        $row = DB::table('acquisition_deliveries')->where('receipt_id', $event)->sole();
        $this->httpCredentials($row); // Existing isolated temporary synthetic credential file.
        config(['app.url' => self::ORIGIN]); // Helper's local URL is not this controlled staging origin.
        Http::fake(['https://graph.facebook.com/*' => Http::sequence()->push([], 503)->push(['events_received' => 1]),
            'https://business-api.tiktok.com/open_api/v1.3/event/track/' => Http::sequence()->push([], 503)->push(['code' => 0])]);
        Http::preventStrayRequests();

        return $row;
    }

    #[DataProvider('platforms')]
    public function test_purchase_real_capture_minimal_http_retry_and_replay(string $platform): void
    {
        [$input, $source] = $this->purchase($platform);
        $context = DB::table('acquisition_checkout_contexts')->where('id', $source->checkout_context_id)->sole();
        $data = json_decode(Crypt::decryptString($context->context_encrypted), true);
        $this->assertSame(self::ORIGIN.'/membership', $data['page_url']);
        $this->assertSame('8.8.4.4', $data['ip']);
        $this->post(self::ORIGIN.'/membership/checkout', $input)->assertRedirect();
        $this->assertDatabaseCount('acquisition_checkout_contexts', 1);
        $this->assertSame($this->billingCalls[0]['key'], $this->billingCalls[1]['key']);
        $this->assertSame($source->toArray(), $this->billingCalls[1]['source']->toArray());
        $event = $this->settled($source);
        $delivery = $this->transport($event->event_id);
        app(DeliveryWorker::class)->deliver($delivery->id);
        $first = DB::table('acquisition_deliveries')->where('id', $delivery->id)->sole();
        $this->assertSame('retry', $first->state);
        $this->travel(61)->seconds();
        app(DeliveryWorker::class)->deliver($delivery->id);
        $last = DB::table('acquisition_deliveries')->where('id', $delivery->id)->sole();
        $this->assertSame('api_accepted', $last->state); // Fake adapter response only.
        $this->assertSame($first->payload_encrypted, $last->payload_encrypted);
        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $payload = $requests[0][0]->data();
        $purchase = $payload['data'][0];
        $this->assertSame($platform === 'meta' ? ['data', 'test_event_code']
            : ['event_source', 'event_source_id', 'data', 'test_event_code'], array_keys($payload));
        $this->assertSame($platform === 'meta'
            ? ['event_name', 'event_time', 'event_id', 'action_source', 'event_source_url', 'user_data', 'custom_data']
            : ['event', 'event_time', 'event_id', 'user', 'page', 'properties'], array_keys($purchase));
        $this->assertSame('UNIT_TEST', $payload['test_event_code']);
        $this->assertSame('Purchase', $purchase[$platform === 'meta' ? 'event_name' : 'event']);
        $this->assertSame(strtotime($event->occurred_at), $purchase['event_time']);
        $this->assertSame($delivery->event_key, $purchase['event_id']);
        $this->assertSame(['currency' => 'USD', 'value' => 3], $purchase[$platform === 'meta' ? 'custom_data' : 'properties']);
        $this->assertSame(self::ORIGIN.'/membership', $platform === 'meta' ? $purchase['event_source_url'] : $purchase['page']['url']);
        if ($platform === 'meta') {
            $this->assertSame('website', $purchase['action_source']);
        } else {
            $this->assertSame('web', $payload['event_source']);
        }
        $user = (array) $purchase[$platform === 'meta' ? 'user_data' : 'user'];
        $this->assertSame($platform === 'meta' ? ['client_ip_address', 'client_user_agent', 'fbc', 'fbp']
            : ['ip', 'user_agent', 'ttclid', 'ttp'], array_keys($user));
        foreach ([$event->order_id, $event->payment_id, $source->source_id, $source->checkout_context_id,
            'email', 'phone', 'transaction_kind', 'discount_applied', 'return_to', 'ltv'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($payload));
        }
        event($event);
        $this->assertFalse(app(DeliveryWorker::class)->deliver($delivery->id));
        Http::assertSentCount(2);
        $this->assertNoAnalysisOrLedger();
    }

    #[DataProvider('platforms')]
    public function test_withdrawal_stops_already_queued_retry(string $platform): void
    {
        [, $source] = $this->purchase($platform);
        $event = $this->settled($source);
        $delivery = $this->transport($event->event_id);
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertSame('retry', DB::table('acquisition_deliveries')->where('id', $delivery->id)->value('state'));
        $this->postJson(self::ORIGIN.'/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false,
            'policy_version' => 'analytics-v1'])->assertOk()->assertJsonPath('consent.advertising', 'denied');
        $this->travel(61)->seconds();
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'blocked',
            'last_error' => 'CONSENT_REQUIRED_OR_REVOKED']);
        Http::assertSentCount(1);
        $this->assertNoAnalysisOrLedger();
    }

    #[DataProvider('platforms')]
    public function test_missing_capture_preserves_purchase_but_blocks_ad_delivery(string $platform): void
    {
        [, $source] = $this->purchase($platform, false);
        $this->assertDatabaseCount('acquisition_checkout_contexts', 0);
        $event = $this->settled($source);
        $delivery = $this->transport($event->event_id);
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'blocked',
            'last_error' => 'CHECKOUT_CONTEXT_REQUIRED']);
        Http::assertNothingSent();
        $this->assertNoAnalysisOrLedger();
    }

    public function test_production_default_cannot_use_the_staging_test_lane(): void
    {
        $defaults = require config_path('h5.php');
        $this->assertFalse($defaults['acquisition_staging_test']);
        $this->assertSame(['provider' => null, 'ready' => false], $defaults['modules']['acquisition']);
        $this->environment('production');
        config(['h5.controlled_staging' => false, 'h5.acquisition_staging_test' => false,
            'h5.modules.acquisition' => $defaults['modules']['acquisition']]);
        app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        $this->getJson(self::ORIGIN.'/api/v1/tracking/context')->assertOk()->assertJsonPath('enabled', false);
        config(['h5.acquisition_staging_test' => true]);
        try {
            app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
            $this->fail('Production must reject controlled test activation');
        } catch (\LogicException $error) {
            $this->assertSame('ACQUISITION_TEST_STAGING_REQUIRED', $error->getMessage());
        }
        Http::assertNothingSent();
        $this->assertNoAnalysisOrLedger();
    }

    private function assertNoAnalysisOrLedger(): void
    {
        foreach (['acquisition_subjects', 'acquisition_behaviors', 'acquisition_analytics_orders', 'acquisition_play_states',
            'acquisition_c_preferences', 'acquisition_c_subjects', 'acquisition_c_sessions', 'acquisition_c_events',
            'acquisition_c_plays', 'acquisition_r1_proofs', 'acquisition_r1_choices', 'acquisition_r1_operations',
            'billing_orders', 'billing_payments', 'billing_entitlements', 'billing_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('acquisition_privacy')->whereNotNull('viewer_id')->count());
        $this->assertSame(0, DB::table('acquisition_sources')->whereNotNull('subject_id')->count());
    }
}
