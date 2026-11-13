<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\PromotionLandingEvidence;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Promotion\LandingCapture;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Audience\AudienceServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Content\ContentTestCase;

/** Real HTML, anonymous session, original proof/consent/confirm; synthetic content, no platform sends. */
final class PromotionWatchChainTest extends ContentTestCase
{
    private const ORIGIN = 'https://staging.example.test';

    private array $originalEnvironment;

    public function refreshDatabase(): void
    {
        $this->app->register(AcquisitionServiceProvider::class);
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        config(['h5.modules.audience.ready' => true, 'acquisition.transport' => 'disabled',
            'acquisition.external_enabled' => false, 'acquisition.platform_reviewed' => false]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        Route::middleware('web')->group(base_path('routes/modules/acquisition.php'));
        $this->assertInstanceOf(LandingCapture::class, app(PromotionLandingEvidence::class));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        [$server, $env, $process] = $this->originalEnvironment;
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
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    private function staging(): void
    {
        $this->app->instance('env', 'staging');
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');
        config(['app.env' => 'staging', 'app.debug' => false, 'app.url' => self::ORIGIN,
            'h5.acquisition_staging_test' => true, 'h5.controlled_staging' => true, 'h5.simulation' => false,
            'h5.local_us_test_region' => false, 'h5.local_modules' => '',
            'h5.modules.acquisition' => ['provider' => AcquisitionServiceProvider::class, 'ready' => true],
            'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
            'acquisition.analytics_enabled' => false, 'acquisition.measurement.enabled' => false,
            'acquisition.measurement.synthetic_policy' => false, 'acquisition.registration.synthetic_policy' => false,
            'acquisition.registration.observations_enabled' => false,
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'trustedproxy.proxies' => null, 'mail.default' => 'smtp', 'mail.driver' => null,
            'mail.from.address' => 'noreply@example.test', 'mail.mailers' => ['smtp' => [
                'transport' => 'smtp', 'scheme' => 'smtp', 'url' => null, 'host' => 'smtp.example.test',
                'port' => 587, 'timeout' => 10, 'username' => 'FAKE_USER', 'password' => 'FAKE_NOT_SENT',
                'require_tls' => true, 'verify_peer' => true]]]);
        // Staff fixture setup uses a separate admin cookie. Start an empty, anonymous browser jar.
        $this->defaultCookies = $this->unencryptedCookies = [];
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4',
            'HTTP_USER_AGENT' => 'Explicit visible browser fixture', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    public function test_real_watch_confirmation_uses_only_prior_drama_get_proof_and_rejects_missing_expired_denied_or_withdrawn_evidence(): void
    {
        $drama = $this->fixture(count: 1, published: true);
        $manager = app(AssetManager::class);
        $pixel = $manager->savePixel(null, ['name' => 'Explicit watch-chain test pixel', 'platform' => 'meta',
            'external_id' => '1234567890', 'environment' => 'test', 'active' => true, 'version' => 0]);
        $links = [];
        foreach (['valid', 'missing', 'expired', 'denied', 'withdrawn'] as $scenario) {
            $links[$scenario] = $manager->saveLink(null, ['name' => 'Explicit watch '.$scenario, 'platform' => 'meta',
                'drama_id' => $drama->id, 'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0]);
        }
        $this->staging();
        $dramaPath = '/dramas/'.$drama->id;
        $episode = DB::table('content_episodes')->where('drama_id', $drama->id)->sole();
        $watchPath = $dramaPath.'/episodes/'.$episode->id;
        foreach ($links as $scenario => $link) {
            $this->defaultCookies = $this->unencryptedCookies = [];
            $this->get(self::ORIGIN.'/r/'.basename($link['url']))->assertRedirect($dramaPath);
            $this->assertNull(session('tracking.promotion_get'));
            if ($scenario !== 'missing') {
                $this->get(self::ORIGIN.$dramaPath)->assertOk()->assertSee($drama->title_en)->assertSee('</html>', false);
                $proof = session('tracking.promotion_get');
                $this->assertSame(session('tracking.pending.key'), $proof['key']);
                $this->assertEqualsCanonicalizing(['key', 'link_id', 'drama_id', 'at'], array_keys($proof));
            } else {
                $proof = null;
            }
            if ($scenario === 'expired') {
                $this->travel(10)->minutes();
            } else {
                $this->travel(2)->seconds();
            }
            $watch = $this->get(self::ORIGIN.$watchPath)->assertOk()->assertSee('data-player', false)->assertSee('</html>', false);
            $this->assertSame($proof, session('tracking.promotion_get'), 'Watch must never mint or refresh drama GET proof');
            $this->assertSame(1, preg_match('/<meta name="csrf-token" content="([^"]+)">/', $watch->getContent(), $match));
            $headers = ['Origin' => self::ORIGIN, 'X-CSRF-TOKEN' => html_entity_decode($match[1], ENT_QUOTES)];
            $consent = function (bool $grant) use ($headers): void {
                $this->postJson(self::ORIGIN.'/api/v1/tracking/consent', ['analytics' => false,
                    'advertising' => $grant, 'policy_version' => 'analytics-v1'], $headers)->assertOk()
                    ->assertJsonPath('consent.advertising', $grant ? 'granted' : 'denied')->assertJsonPath('consent.analytics', 'denied');
            };
            $consent($scenario !== 'denied');
            if ($scenario === 'withdrawn') {
                $consent(false);
                $this->assertNull(session('tracking.promotion_get'));
                $consent(true); // Regrant must not resurrect the withdrawn GET proof.
            }
            $payload = ['page_instance_id' => (string) Str::uuid(), 'drama_id' => $drama->id, 'path' => $watchPath];
            $confirmedAt = now();
            $this->postJson(self::ORIGIN.'/api/v1/tracking/landing', $payload, $headers)
                ->assertOk()->assertJsonPath('confirmed', $scenario === 'valid');
            $this->assertDatabaseCount('acquisition_promotion_pageviews', 1);
            $this->assertDatabaseCount('acquisition_sources', 1);
            if ($scenario === 'valid') {
                $row = DB::table('acquisition_promotion_pageviews')->sole();
                $this->assertSame($link['id'], $row->link_id);
                $this->assertTrue(CarbonImmutable::parse($row->event_at)->equalTo($confirmedAt));
                $this->assertTrue(CarbonImmutable::parse($row->event_at)->greaterThan(CarbonImmutable::parse($proof['at'])));
                $context = json_decode(Crypt::decryptString($row->context_encrypted), true);
                $this->assertSame(self::ORIGIN.$dramaPath, $context['page_url']);
                $this->postJson(self::ORIGIN.'/api/v1/tracking/landing', $payload, $headers)->assertOk()->assertJsonPath('confirmed', true);
                $this->assertSame($row->id, DB::table('acquisition_promotion_pageviews')->sole()->id);
                $consent(false);
                $this->postJson(self::ORIGIN.'/api/v1/tracking/landing', $payload, $headers)->assertOk()->assertJsonPath('confirmed', false);
                $this->assertDatabaseHas('acquisition_promotion_pageviews', ['id' => $row->id, 'state' => 'blocked', 'context_encrypted' => null]);
            }
            Http::assertNothingSent();
        }
        foreach (['acquisition_receipts', 'acquisition_deliveries', 'acquisition_subjects', 'acquisition_behaviors',
            'acquisition_analytics_orders', 'acquisition_c_subjects', 'acquisition_c_events', 'acquisition_r1_proofs',
            'acquisition_r1_choices', 'billing_orders', 'billing_payments', 'billing_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('acquisition_privacy')->whereNotNull('viewer_id')->count());
        $this->assertNull(auth('web')->user());
    }
}
