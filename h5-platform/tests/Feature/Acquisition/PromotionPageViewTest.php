<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\CatalogReader;
use App\Data\Drama;
use App\Enums\Role;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Promotion\LandingCapture;
use App\Modules\Acquisition\Promotion\PageViewSender;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Acquisition\Services\AttributionRecorder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;

final class PromotionPageViewTest extends AcquisitionTestCase
{
    private array $originalEnvironment;

    public function refreshDatabase(): void
    {
        // Register before migrate:fresh builds the console when no module is preloaded.
        $this->app->register(AcquisitionServiceProvider::class);
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $this->freezeTime();
        $this->signIn($this->staff(Role::Owner));
        $id = array_key_first($this->dramas);
        $catalog = Mockery::mock(CatalogReader::class);
        $catalog->shouldReceive('drama')->with($id, 'zh_CN')->andReturn(new Drama($id, 'Explicit published fixture', '', null, 0, 0, 5, 'published', []));
        $this->app->instance(CatalogReader::class, $catalog);
    }

    protected function tearDown(): void
    {
        [$a, $b, $c] = $this->originalEnvironment;
        if ($a === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $a;
        }
        if ($b === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $b;
        }
        putenv($c === false ? 'APP_ENV' : 'APP_ENV='.$c);
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    private function staging(): void
    {
        $this->app->instance('env', 'staging');
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');
        config(['app.env' => 'staging', 'app.debug' => false, 'app.url' => 'https://staging.example.test',
            'h5.acquisition_staging_test' => true, 'h5.controlled_staging' => true, 'h5.simulation' => false,
            'h5.local_us_test_region' => false, 'h5.local_modules' => '',
            'h5.modules.acquisition' => ['provider' => AcquisitionServiceProvider::class, 'ready' => true],
            'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
            'acquisition.analytics_enabled' => false, 'acquisition.measurement.enabled' => false,
            'acquisition.measurement.synthetic_policy' => false, 'acquisition.registration.synthetic_policy' => false,
            'acquisition.registration.observations_enabled' => false, 'session.secure' => true,
            'session.http_only' => true, 'session.same_site' => 'lax', 'trustedproxy.proxies' => null,
            'mail.default' => 'smtp', 'mail.driver' => null, 'mail.from.address' => 'noreply@example.test',
            'mail.mailers' => ['smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'url' => null, 'host' => 'smtp.example.test',
                'port' => 587, 'timeout' => 10, 'username' => 'FAKE_USER', 'password' => 'FAKE_NOT_SENT', 'require_tls' => true, 'verify_peer' => true]]]);
    }

    private function request(string $method, string $path, array $server = []): void
    {
        $r = Request::create('https://staging.example.test'.$path, $method, server: array_replace([
            'REMOTE_ADDR' => '8.8.4.4', 'HTTP_USER_AGENT' => 'Explicit visible browser fixture',
            'HTTP_ORIGIN' => 'https://staging.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], $server));
        $r->setLaravelSession(app('session.store'));
        $this->app->instance('request', $r);
    }

    private function journey(array $link, bool $grant = true, bool $get = true): bool
    {
        $this->request('GET', '/r/'.basename($link['url']));
        if ($grant) {
            app(TrackingContext::class)->consent(false, true);
        }
        app(AttributionRecorder::class)->prepare(basename($link['url']));
        if ($get) {
            $this->request('GET', '/dramas/'.$link['drama_id'].'?email=never-store@example.test');
            app(LandingCapture::class)->record($link['drama_id']); // explicit successful-render fixture pending public 00 hook
        }
        $this->request('POST', '/api/v1/tracking/landing', ['REMOTE_ADDR' => '8.8.8.8', 'HTTP_USER_AGENT' => 'POST must not replace GET']);

        return app(AttributionRecorder::class)->confirm($link['drama_id'], AcquisitionStore::id(), ['fbclid' => 'EXPLICIT_FAKE_CLICK']);
    }

    private function noBusiness(): void
    {
        foreach (['acquisition_receipts', 'acquisition_deliveries', 'acquisition_subjects', 'acquisition_behaviors',
            'acquisition_analytics_orders', 'acquisition_c_subjects', 'acquisition_c_events', 'acquisition_r1_proofs', 'acquisition_r1_choices'] as $t) {
            $this->assertDatabaseCount($t, 0);
        }
        $this->assertSame(0, DB::table('acquisition_privacy')->whereNotNull('viewer_id')->count());
    }

    public function test_successful_get_proof_then_consented_current_confirmation_sends_one_nonfinancial_meta_pageview(): void
    {
        [$pixel, $link] = $this->assets();
        $this->staging();
        $this->assertTrue($this->journey($link));
        $row = DB::table('acquisition_promotion_pageviews')->sole();
        $context = json_decode(Crypt::decryptString($row->context_encrypted), true);
        $this->assertSame('https://staging.example.test/dramas/'.$link['drama_id'], $context['page_url']);
        $this->assertSame('8.8.8.8', $context['ip']);
        $this->assertSame('POST must not replace GET', $context['user_agent']);
        $this->assertStringNotContainsString('8.8.4.4', $row->context_encrypted);
        $this->httpCredentials((object) ['pixel_id' => $pixel['id'], 'pixel_version' => $pixel['version'], 'platform' => 'meta', 'external_id' => $pixel['external_id'], 'environment' => 'test']);
        config(['app.url' => 'https://staging.example.test']);
        Http::fake(function ($r) use ($row, $link) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('attempted', DB::table('acquisition_promotion_pageviews')->sole()->state);
            $this->assertSame('PROMOTION_ALREADY_CONSUMED_OR_MISSING', app(PageViewSender::class)->send($row->id)['reason']);
            $d = $r->data()['data'][0];
            $this->assertSame('PageView', $d['event_name']);
            $this->assertSame('h5_promotion_'.$row->id, $d['event_id']);
            $this->assertSame('https://staging.example.test/dramas/'.$link['drama_id'], $d['event_source_url']);
            $this->assertSame('8.8.8.8', $d['user_data']['client_ip_address']);
            $this->assertStringEndsWith('.EXPLICIT_FAKE_CLICK', $d['user_data']['fbc']);
            $this->assertArrayNotHasKey('custom_data', $d);
            foreach ([$row->source_id, $row->link_id, 'Purchase', 'never-store'] as $private) {
                $this->assertStringNotContainsString($private, $r->body());
            }

            return Http::response(['events_received' => 1], 200);
        });
        $this->assertSame('api_accepted', app(PageViewSender::class)->send($row->id)['state']);
        $this->assertSame('PROMOTION_ALREADY_CONSUMED_OR_MISSING', app(PageViewSender::class)->send($row->id)['reason']);
        $this->assertDatabaseHas('acquisition_promotion_pageviews', ['id' => $row->id, 'context_encrypted' => null]);
        Http::assertSentCount(1);
        $this->noBusiness();
    }

    public function test_unknown_consent_missing_successful_get_prefetch_bot_wrong_drama_never_creates_source_or_event(): void
    {
        [, $link] = $this->assets();
        $this->staging();
        $this->assertFalse($this->journey($link, false));
        $this->assertArrayNotHasKey('ip', session('tracking.promotion_get'));
        session()->forget('tracking.promotion_get');
        $this->assertFalse($this->journey($link, true, false));
        foreach ([['HTTP_PURPOSE' => 'prefetch'], ['HTTP_USER_AGENT' => 'Some crawler bot'], ['HTTP_X_FORWARDED_FOR' => '8.8.8.8']] as $server) {
            $this->request('GET', '/dramas/'.$link['drama_id'], $server);
            app(LandingCapture::class)->record($link['drama_id']);
            $this->request('POST', '/api/v1/tracking/landing');
            $this->assertFalse(app(AttributionRecorder::class)->confirm($link['drama_id'], AcquisitionStore::id()));
        }
        $this->request('GET', '/dramas/'.AcquisitionStore::id());
        app(LandingCapture::class)->record($link['drama_id']);
        $this->assertNull(session('tracking.promotion_get'));
        $this->assertDatabaseCount('acquisition_sources', 0);
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 0);
        Http::assertNothingSent();
        $this->noBusiness();
    }

    public function test_first_consent_on_successful_page_confirms_without_refresh_and_never_backdates_event(): void
    {
        [, $link] = $this->assets();
        $this->staging();
        $this->assertFalse($this->journey($link, false));
        $this->assertSame(['key', 'link_id', 'drama_id', 'at'], array_keys(session('tracking.promotion_get')));
        $this->assertDatabaseCount('acquisition_sources', 0);
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 0);
        $getAt = session('tracking.promotion_get.at');
        $this->travel(2)->seconds();
        app(TrackingContext::class)->consent(false, true);
        $page = AcquisitionStore::id();
        $this->assertTrue(app(AttributionRecorder::class)->confirm($link['drama_id'], $page));
        $this->assertTrue(app(AttributionRecorder::class)->confirm($link['drama_id'], $page));
        $row = DB::table('acquisition_promotion_pageviews')->sole();
        $this->assertGreaterThan(strtotime($getAt), strtotime($row->event_at));
        $this->assertEquals(now()->timestamp, strtotime($row->event_at));
        $this->request('GET', '/dramas/'.$link['drama_id']);
        app(LandingCapture::class)->record($link['drama_id']);
        $this->assertDatabaseCount('acquisition_sources', 1);
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 1);
        $this->assertNull(session('tracking.promotion_get'));
        Http::assertNothingSent();
    }

    public function test_withdrawal_cancels_event_and_regrant_never_reactivates_it(): void
    {
        [, $link] = $this->assets();
        $this->staging();
        $this->assertTrue($this->journey($link));
        $id = DB::table('acquisition_promotion_pageviews')->sole()->id;
        app(TrackingContext::class)->consent(false, false);
        app(TrackingContext::class)->consent(false, true);
        $this->assertDatabaseHas('acquisition_promotion_pageviews', ['id' => $id, 'state' => 'blocked', 'reason' => 'CONSENT_REQUIRED_OR_REVOKED', 'context_encrypted' => null]);
        Http::assertNothingSent();
        $this->noBusiness();
    }

    public function test_disabled_production_missing_credentials_and_changed_test_purpose_refuse_sending(): void
    {
        [$pixel, $link] = $this->assets();
        $this->staging();
        $this->journey($link);
        $id = DB::table('acquisition_promotion_pageviews')->sole()->id;
        $this->assertSame('EXTERNAL_DISABLED', app(PageViewSender::class)->send($id)['reason']);
        $this->assertDatabaseHas('acquisition_promotion_pageviews', ['id' => $id, 'state' => 'pending']);
        config(['acquisition.transport' => 'http', 'acquisition.external_enabled' => true, 'acquisition.platform_reviewed' => true]);
        $this->assertSame('CREDENTIALS_REQUIRED', app(PageViewSender::class)->send($id)['reason']);
        $this->journey($link);
        $id = DB::table('acquisition_promotion_pageviews')->where('state', 'pending')->sole()->id;
        DB::table('acquisition_pixel_versions')->where('pixel_id', $pixel['id'])->update(['environment' => 'production']);
        $this->assertSame('PROMOTION_TEST_ASSET_OR_LINK_INVALID', app(PageViewSender::class)->send($id)['reason']);
        $this->app->instance('env', 'production');
        config(['app.env' => 'production']);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');
        $this->assertSame('STAGING_TEST_RUNTIME_INVALID', app(PageViewSender::class)->send($id)['reason']);
        Http::assertNothingSent();
    }

    public function test_api_uncertainty_is_terminal_and_dedicated_cli_never_scans_financial_delivery_queue(): void
    {
        [$pixel, $link] = $this->assets();
        $this->staging();
        $this->journey($link);
        $row = DB::table('acquisition_promotion_pageviews')->sole();
        $this->httpCredentials((object) ['pixel_id' => $pixel['id'], 'pixel_version' => $pixel['version'], 'platform' => 'meta', 'external_id' => $pixel['external_id'], 'environment' => 'test']);
        config(['app.url' => 'https://staging.example.test']);
        Http::fake(['*' => Http::failedConnection()]);
        $this->artisan('acquisition:promotion-pageviews', ['--event' => $row->id])->assertFailed();
        $this->assertDatabaseHas('acquisition_promotion_pageviews', ['id' => $row->id, 'state' => 'unconfirmed', 'reason' => 'PROMOTION_RESULT_UNKNOWN_NO_RETRY']);
        $this->assertSame('PROMOTION_ALREADY_CONSUMED_OR_MISSING', app(PageViewSender::class)->send($row->id)['reason']);
        $this->journey($link);
        $next = DB::table('acquisition_promotion_pageviews')->where('state', 'pending')->sole();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
        $this->artisan('acquisition:promotion-pageviews', ['--limit' => 10])->assertFailed();
        $this->assertDatabaseHas('acquisition_promotion_pageviews', ['id' => $next->id, 'state' => 'unconfirmed', 'reason' => 'PROMOTION_API_NOT_CONFIRMED_NO_RETRY']);
        $this->assertSame([], app(PageViewSender::class)->run(10));
        $this->noBusiness();
    }

    public function test_exact_version_credentials_expiry_and_scoped_assigned_report_and_upgrade_preserve_old_facts(): void
    {
        [$pixel, $link] = $this->assets();
        $operator = $this->staff();
        app(AssetManager::class)->share('link', $link['id'], $link['version'], [$operator->id]);
        $this->staging();
        $this->journey($link);
        $row = DB::table('acquisition_promotion_pageviews')->sole();
        $this->httpCredentials((object) ['pixel_id' => $pixel['id'], 'pixel_version' => $pixel['version'], 'platform' => 'meta', 'external_id' => $pixel['external_id'], 'environment' => 'test']);
        config(['app.url' => 'https://staging.example.test']);
        DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['version' => 2]);
        DB::table('acquisition_pixel_versions')->insert(['pixel_id' => $pixel['id'], 'version' => 2, 'platform' => 'meta', 'external_id' => $pixel['external_id'], 'environment' => 'test', 'created_at' => now()]);
        Http::fake(['*' => Http::response(['events_received' => 1], 200)]);
        $this->assertSame('api_accepted', app(PageViewSender::class)->send($row->id)['state']); // exact original v1, not fallback to v2
        $this->signIn($operator);
        $this->assertSame('api_accepted', app(AssetManager::class)->summary($link['id'])['page_views'][0]['state']);
        $this->signIn($this->staff(Role::Owner));
        $before = DB::table('acquisition_sources')->get()->toJson();
        $migration = require database_path('migrations/2026_11_13_050011_create_acquisition_promotion_pageviews.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('acquisition_sources')->get()->toJson());
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 0);
        $this->journey($link);
        $row = DB::table('acquisition_promotion_pageviews')->sole();
        $this->travel(1)->days();
        $this->assertSame('PROMOTION_EVENT_EXPIRED', app(PageViewSender::class)->send($row->id)['reason']);
        $this->assertDatabaseHas('acquisition_promotion_pageviews', ['id' => $row->id, 'context_encrypted' => null]);
    }

    public function test_optional_capture_failure_does_not_throw_and_scoped_summary_does_not_expose_private_data(): void
    {
        [, $link] = $this->assets();
        $this->staging();
        $this->request('GET', '/r/'.basename($link['url']));
        app(TrackingContext::class)->consent(false, true);
        app(AttributionRecorder::class)->prepare(basename($link['url']));
        DB::statement('ALTER TABLE acquisition_pixels RENAME TO acquisition_pixels_unavailable');
        $this->request('GET', '/dramas/'.$link['drama_id']);
        app(LandingCapture::class)->record($link['drama_id']);
        DB::statement('ALTER TABLE acquisition_pixels_unavailable RENAME TO acquisition_pixels');
        $this->assertNull(session('tracking.promotion_get'));
        $this->journey($link);
        $summary = app(AssetManager::class)->summary($link['id']);
        $this->assertSame([['state' => 'pending', 'reason' => null, 'count' => 1]], $summary['page_views']);
        $this->assertStringNotContainsString('8.8.4.4', json_encode($summary));
        $this->signIn();
        $this->problem('NOT_FOUND', fn () => app(AssetManager::class)->summary($link['id']));
        Http::assertNothingSent();
        $this->noBusiness();
    }
}
