<?php

namespace Tests\Feature\Audience;

use App\Contracts\PromotionLandingEvidence;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Promotion\LandingCapture;
use App\Modules\Acquisition\Promotion\PageViewSender;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Audience\AudienceServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Content\ContentTestCase;

/** Real module/HTML/session HTTP chain; synthetic content and credentials, intercepted outbound HTTP only. */
final class PromotionLandingChainTest extends ContentTestCase
{
    private const ORIGIN = 'https://staging.example.test';

    private array $originalEnvironment;

    private ?string $credentialsPath = null;

    // Sender must leave its own transaction before I/O; do not wrap this suite in RefreshDatabase's transaction.
    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        config(['h5.modules.audience.ready' => true, 'acquisition.transport' => 'disabled',
            'acquisition.external_enabled' => false, 'acquisition.platform_reviewed' => false]);
        $this->app->register(AudienceServiceProvider::class);
        $this->app->register(AcquisitionServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        $this->assertInstanceOf(LandingCapture::class, app(PromotionLandingEvidence::class));
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        if ($this->credentialsPath !== null) {
            unlink($this->credentialsPath);
            rmdir(dirname($this->credentialsPath));
        }
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

    private function assets(): array
    {
        $drama = $this->fixture(published: true);
        $manager = app(AssetManager::class);
        $pixel = $manager->savePixel(null, ['name' => 'Explicit chain test pixel', 'platform' => 'meta',
            'external_id' => '1234567890', 'environment' => 'test', 'active' => true, 'version' => 0]);
        $link = $manager->saveLink(null, ['name' => 'Explicit chain test link', 'platform' => 'meta',
            'drama_id' => $drama->id, 'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0]);

        return [$drama, $pixel, $link];
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

    private function openLink(array $link): string
    {
        $response = $this->get(self::ORIGIN.'/r/'.basename($link['url']))
            ->assertRedirect('/dramas/'.$link['drama_id']);
        $this->assertNotEmpty($response->headers->getCookies());
        $this->assertNull(auth('web')->user());
        $this->assertNull(session('tracking.promotion_get'));

        return '/dramas/'.$link['drama_id'];
    }

    private function token(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<meta name="csrf-token" content="([^"]+)">/', $response->getContent(), $match));

        return html_entity_decode($match[1], ENT_QUOTES);
    }

    private function consent(string $token): void
    {
        $this->postJson(self::ORIGIN.'/api/v1/tracking/consent', [
            'advertising' => true, 'analytics' => false, 'policy_version' => 'analytics-v1',
        ], ['Origin' => self::ORIGIN, 'X-CSRF-TOKEN' => $token])->assertOk()
            ->assertJsonPath('consent.advertising', 'granted')->assertJsonPath('consent.analytics', 'denied');
    }

    private function confirm(string $dramaId, string $pageId, string $token): TestResponse
    {
        return $this->postJson(self::ORIGIN.'/api/v1/tracking/landing', [
            'page_instance_id' => $pageId, 'drama_id' => $dramaId, 'path' => '/dramas/'.$dramaId,
        ], ['Origin' => self::ORIGIN, 'X-CSRF-TOKEN' => $token]);
    }

    private function noBusiness(): void
    {
        foreach (['acquisition_receipts', 'acquisition_deliveries', 'acquisition_subjects', 'acquisition_behaviors',
            'acquisition_analytics_orders', 'acquisition_c_subjects', 'acquisition_c_events',
            'acquisition_r1_proofs', 'acquisition_r1_choices', 'billing_orders', 'billing_payments', 'billing_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('acquisition_privacy')->whereNotNull('viewer_id')->count());
        $this->assertNull(auth('web')->user());
    }

    public function test_real_html_get_then_same_page_consent_and_confirmation_send_one_nonfinancial_pageview(): void
    {
        [$drama, $pixel, $link] = $this->assets();
        $this->staging();
        $path = $this->openLink($link);
        $page = $this->get(self::ORIGIN.$path.'?utm_campaign=EXPLICIT_FAKE&email=never-store@example.test')
            ->assertOk()->assertSee('</html>', false)->assertSee($drama->title_en);
        $token = $this->token($page);
        $proof = session('tracking.promotion_get');
        $this->assertEqualsCanonicalizing(['key', 'link_id', 'drama_id', 'at'], array_keys($proof));
        $this->assertSame(session('tracking.pending.key'), $proof['key']);
        $this->assertSame($link['id'], $proof['link_id']);
        $this->assertSame($drama->id, $proof['drama_id']);
        $this->assertDatabaseCount('acquisition_privacy', 0);
        $this->assertDatabaseCount('acquisition_sources', 0);
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 0);
        Http::assertNothingSent();

        $input = ['advertising' => true, 'analytics' => false, 'policy_version' => 'analytics-v1'];
        // Laravel accepts same-origin Fetch Metadata; force its token fallback for this rejection probe.
        $this->postJson(self::ORIGIN.'/api/v1/tracking/consent', $input, [
            'Origin' => self::ORIGIN, 'Sec-Fetch-Site' => 'cross-site',
        ])->assertStatus(419);
        $this->postJson(self::ORIGIN.'/api/v1/tracking/consent', $input, [
            'Origin' => 'https://other.example.test', 'X-CSRF-TOKEN' => $token,
        ])->assertForbidden();
        $this->assertDatabaseCount('acquisition_privacy', 0);
        $this->travel(2)->seconds();
        $this->consent($token);
        $this->assertSame($proof, session('tracking.promotion_get'));
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 0);
        // No intervening HTML GET: the original page's token and cookie jar continue the flow.
        $pageId = (string) Str::uuid();
        $this->confirm($drama->id, $pageId, $token)->assertOk()->assertJsonPath('confirmed', true);
        $row = DB::table('acquisition_promotion_pageviews')->sole();
        $this->assertSame('pending', $row->state);
        $this->assertTrue(CarbonImmutable::parse($row->event_at)->greaterThan(CarbonImmutable::parse($proof['at'])));
        $this->assertDatabaseCount('acquisition_sources', 1);
        $this->assertNull(session('tracking.promotion_get'));
        $context = json_decode(Crypt::decryptString($row->context_encrypted), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::ORIGIN.$path, $context['page_url']);
        $this->confirm($drama->id, $pageId, $token)->assertOk()->assertJsonPath('confirmed', true);
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 1);
        $this->assertSame($row->id, DB::table('acquisition_promotion_pageviews')->sole()->id);

        $directory = realpath(sys_get_temp_dir()).'/h5-promotion-chain-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->credentialsPath = $directory.'/credentials.json';
        file_put_contents($this->credentialsPath, json_encode([$pixel['id'].':'.$pixel['version'] => [
            'platform' => 'meta', 'external_id' => $pixel['external_id'], 'environment' => 'test',
            'token' => 'UNIT-TEST-NOT-A-REAL-SECRET', 'test_event_code' => 'UNIT_TEST',
        ]], JSON_THROW_ON_ERROR));
        chmod($this->credentialsPath, 0600);
        config(['acquisition.credentials_file' => $this->credentialsPath, 'acquisition.transport' => 'http',
            'acquisition.external_enabled' => true, 'acquisition.platform_reviewed' => true,
            'acquisition.meta_api_version' => 'v99.0']); // Explicit fake version, never sent to Meta.
        Http::assertNothingSent();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $payloadChecked = false;
        Http::fake(function ($request) use ($row, $path, &$payloadChecked) {
            $payloadChecked = true;
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('https://graph.facebook.com/v99.0/1234567890/events', $request->url());
            $event = $request->data()['data'][0];
            $this->assertSame('PageView', $event['event_name']);
            $this->assertSame('h5_promotion_'.$row->id, $event['event_id']);
            $this->assertSame(self::ORIGIN.$path, $event['event_source_url']);
            $this->assertArrayNotHasKey('custom_data', $event);
            foreach (['Purchase', 'value', 'revenue', 'never-store', 'EXPLICIT_FAKE', $row->source_id, $row->link_id] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $request->body());
            }

            return Http::response(['events_received' => 1], 200);
        });
        $result = app(PageViewSender::class)->send($row->id);
        $this->assertSame('api_accepted', $result['state'], json_encode($result));
        $this->assertTrue($payloadChecked);
        $this->assertSame('PROMOTION_ALREADY_CONSUMED_OR_MISSING', app(PageViewSender::class)->send($row->id)['reason']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('acquisition_promotion_pageviews', ['id' => $row->id, 'context_encrypted' => null]);
        $this->noBusiness();
    }

    public function test_prefetched_real_drama_page_cannot_support_later_confirmation(): void
    {
        [$drama, , $link] = $this->assets();
        $this->staging();
        $path = $this->openLink($link);
        $page = $this->get(self::ORIGIN.$path, ['Purpose' => 'prefetch'])->assertOk()->assertSee('</html>', false);
        $this->assertNull(session('tracking.promotion_get'));
        $token = $this->token($page);
        $this->consent($token);
        $this->confirm($drama->id, (string) Str::uuid(), $token)->assertOk()->assertJsonPath('confirmed', false);
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 0);
        $this->assertDatabaseCount('acquisition_sources', 0);
        Http::assertNothingSent();
        $this->noBusiness();
    }

    public function test_missing_real_drama_page_cannot_support_later_confirmation(): void
    {
        [$drama, , $link] = $this->assets();
        $this->staging();
        $path = $this->openLink($link);
        $token = session()->token(); // Token from the actual redirect session, not a replacement session.
        $drama->update(['state' => 'withdrawn']);
        $this->get(self::ORIGIN.$path)->assertNotFound();
        $this->assertNull(session('tracking.promotion_get'));
        $this->consent($token);
        $this->confirm($drama->id, (string) Str::uuid(), $token)->assertOk()->assertJsonPath('confirmed', false);
        $this->assertDatabaseCount('acquisition_promotion_pageviews', 0);
        $this->assertDatabaseCount('acquisition_sources', 0);
        Http::assertNothingSent();
        $this->noBusiness();
    }
}
