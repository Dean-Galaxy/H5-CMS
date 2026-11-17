<?php

namespace Tests\Feature\Acquisition;

use App\Data\PaymentSettled;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Http\TrackingController;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\Verification;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Content\ContentTestCase;

final class ProductionPilotHttpTest extends ContentTestCase
{
    public static function launchModes(): array
    {
        return [
            'manual' => [false],
            'automatic' => [true],
            'dual consent' => [true, true, true, true],
            'site analytics only' => [true, true, true, false],
            'unrelated viewer' => [true, true, false, true],
            'continuous anonymous visitor' => [true, true, false, true, true],
        ];
    }

    public function test_clean_production_route_registration_requires_the_site_analytics_flag(): void
    {
        config(['h5.acquisition_production_pilot' => true]);
        $router = app('router');
        $original = $router->getRoutes();
        try {
            foreach ([false, true] as $siteAnalytics) {
                config(['h5.acquisition_automatic_site_analytics' => $siteAnalytics]);
                $router->setRoutes(new RouteCollection);
                Route::middleware('web')->group(base_path('routes/modules/acquisition.php'));
                $handler = $router->getRoutes()->getByAction(TrackingController::class.'@events');
                if ($siteAnalytics) {
                    $this->assertNotNull($handler);
                    $this->assertSame($handler, $router->getRoutes()->match(Request::create('/api/v1/tracking/events', 'POST')));
                } else {
                    $this->assertNull($handler);
                }
            }
        } finally {
            $router->setRoutes($original);
        }
    }

    #[DataProvider('launchModes')]
    public function test_real_promotion_redirect_and_successful_drama_html_enable_the_scoped_advertising_choice(
        bool $automatic,
        bool $siteTrial = false,
        bool $matchingViewer = false,
        bool $advertising = true,
        bool $continuous = false,
    ): void {
        $drama = $this->fixture(count: 1, published: true);
        $viewer = $siteTrial && ! $continuous ? Viewer::create(['name' => 'Dual consent fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'LocalPassword1234', 'email_verified_at' => now()]) : null;
        $origin = 'https://pilot.example.test';
        config(['app.url' => $origin, 'h5.modules.audience.ready' => true, 'h5.modules.acquisition.ready' => true,
            'h5.acquisition_production_pilot' => true]);
        // 00 owns the public environment guard. Exercise actual 04 and 05 HTTP/session logic behind its boundary.
        $guard = new class
        {
            public array $scope = [];

            public array $automatic = [];

            public function productionAcquisitionPilotOrigin(): string
            {
                return 'https://pilot.example.test';
            }

            public function productionAutomaticPurchasePolicy(): ?array
            {
                return $this->automatic && ($this->automatic['until'] === null || now()->lt($this->automatic['until'])) ? $this->automatic : null;
            }

            public function productionAcquisitionScope(): array
            {
                if (! $this->scope || now()->greaterThanOrEqualTo($this->scope['until'])) {
                    throw new \LogicException('Test scope closed');
                }

                return $this->scope;
            }
        };
        $this->app->instance(RuntimeEnvironmentGuard::class, $guard);
        $this->app->register(AcquisitionServiceProvider::class);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        Route::middleware('web')->group(base_path('routes/modules/acquisition.php'));
        Http::preventStrayRequests();
        $this->assertFalse(Event::hasListeners(PaymentSettled::class));
        $manager = app(AssetManager::class);
        $pixel = $manager->savePixel(null, ['name' => 'Synthetic production asset', 'platform' => 'meta', 'external_id' => '1234567890',
            'environment' => 'production', 'active' => true, 'version' => 0]);
        $link = $manager->saveLink(null, ['name' => 'Synthetic promotion', 'platform' => 'meta', 'pixel_id' => $pixel['id'],
            'drama_id' => $drama->id, 'active' => true, 'version' => 0]);
        $second = $continuous ? $manager->saveLink(null, ['name' => 'Second synthetic promotion', 'platform' => 'meta', 'pixel_id' => $pixel['id'],
            'drama_id' => $drama->id, 'active' => true, 'version' => 0]) : null;
        $this->defaultCookies = $this->unencryptedCookies = [];
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4', 'HTTP_USER_AGENT' => 'Synthetic production visitor']);
        $url = $origin.'/r/'.basename($link['url']);
        $this->get($url)->assertNotFound();
        $guard->scope = ['platform' => 'meta', 'link_id' => $link['id'], 'pixel_id' => $pixel['id'],
            'pixel_version' => $pixel['version'], 'from' => now()->subMinute()->toIso8601ZuluString(),
            'until' => now()->addHour()->toIso8601ZuluString()];
        if ($automatic) {
            $guard->automatic = array_diff_key($guard->scope, ['platform' => true]) + [
                'mode' => 'single', 'run_id' => (string) Str::uuid(),
                'viewer_id' => $matchingViewer ? $viewer->id : (string) Str::uuid(),
            ];
            $guard->scope = [];
            config(['h5.acquisition_automatic_mode' => 'single', 'h5.acquisition_automatic_site_analytics' => $siteTrial]);
        }
        if ($continuous) {
            $guard->automatic = ['mode' => 'continuous', 'from' => now()->subMinute()->toIso8601ZuluString(),
                'until' => null, 'run_id' => null];
            config(['h5.acquisition_automatic_mode' => 'continuous']);
        }
        if ($viewer) {
            $this->asViewer($viewer);
        }
        $analytics = $matchingViewer || $continuous;
        $path = '/dramas/'.$drama->id;
        $this->get($url, ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'])
            ->assertRedirect($path);
        $this->assertNull(session('tracking.production_get'));
        $page = $this->get($origin.$path)->assertOk()->assertSee('</html>', false)->assertSee($drama->title_en);
        $this->assertNotNull(session('tracking.production_get'));
        $this->assertSame(1, preg_match('/<meta name="csrf-token" content="([^"]+)">/', $page->getContent(), $match));
        $headers = ['Origin' => $origin, 'Sec-Fetch-Site' => 'same-origin', 'X-CSRF-TOKEN' => html_entity_decode($match[1], ENT_QUOTES)];
        $choice = $this->postJson($origin.'/api/v1/tracking/consent', ['analytics' => true, 'advertising' => $advertising,
            'policy_version' => 'analytics-v1'], $headers)->assertOk()
            ->assertJsonPath('consent.analytics', $analytics ? 'granted' : 'denied')
            ->assertJsonPath('consent.advertising', $advertising ? 'granted' : 'denied');
        if (! $analytics) {
            $choice->assertJsonPath('analytics_available', false);
        }
        $payload = ['page_instance_id' => (string) Str::uuid(), 'drama_id' => $drama->id, 'path' => $path];
        $this->postJson($origin.'/api/v1/tracking/landing', $payload, $headers)->assertOk()->assertJsonPath('confirmed', $advertising);
        if ($advertising) {
            $this->assertDatabaseHas('acquisition_sources', ['link_id' => $link['id'], 'pixel_id' => $pixel['id'],
                'pixel_version' => $pixel['version'], 'subject_id' => session('tracking.subject'), 'is_simulation' => false]);
            $this->assertSame($analytics, session()->has('tracking.subject'));
        }
        $events = ['events' => [['event_id' => (string) Str::uuid(), 'event_name' => 'page_view', 'properties' => $payload]]];
        $this->postJson($origin.'/api/v1/tracking/events', $events, $headers)->assertOk()
            ->assertJsonPath('accepted', $analytics ? 1 : 0);
        $this->postJson($origin.'/api/v1/tracking/events', $events, $headers)->assertOk()
            ->assertJsonPath('accepted', $analytics ? 1 : 0);
        $this->assertDatabaseCount('acquisition_sources', $advertising ? 1 : 0);
        $this->assertDatabaseCount('acquisition_behaviors', $analytics ? 1 : 0);
        if ($analytics) {
            $this->assertDatabaseHas('acquisition_behaviors', ['viewer_id' => $viewer?->id,
                'event_name' => 'page_view', 'link_id' => $advertising ? $link['id'] : null]);
            if ($continuous) {
                $initialSource = session('tracking.source');
                $this->get($origin.$path)->assertOk();
                $this->assertSame($initialSource, session('tracking.source'));
                $this->get($origin.'/r/'.basename($second['url']))->assertRedirect($path);
                $this->get($origin.$path)->assertOk();
                $this->postJson($origin.'/api/v1/tracking/landing', array_replace($payload, ['page_instance_id' => (string) Str::uuid()]), $headers)
                    ->assertOk()->assertJsonPath('confirmed', true);
                $latestSource = session('tracking.source');
                $this->assertNotSame($initialSource, $latestSource);
                $this->assertDatabaseHas('acquisition_sources', ['id' => $latestSource, 'link_id' => $second['id']]);
                Notification::fake();
                $this->postJson($origin.'/api/v1/auth/register', ['name' => 'Synthetic new viewer', 'email' => 'continuous@example.test',
                    'password' => 'LocalPassword1234', 'password_confirmation' => 'LocalPassword1234'], $headers)->assertOk();
                $viewer = Viewer::sole();
                $this->get(app(Verification::class)->url($viewer))->assertRedirect();
                $this->assertTrue($viewer->fresh()->hasVerifiedEmail());
                $this->assertSame($latestSource, session('tracking.source'));
                $this->assertDatabaseHas('acquisition_behaviors', ['viewer_id' => $viewer->id, 'event_name' => 'registration_succeeded', 'link_id' => $second['id']]);
                $this->assertDatabaseHas('acquisition_behaviors', ['viewer_id' => $viewer->id, 'event_name' => 'email_verified', 'link_id' => $second['id']]);
                $this->assertSame(1, DB::table('acquisition_subjects')->count());
                $this->postJson($origin.'/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false,
                    'policy_version' => 'analytics-v1'], array_replace($headers, ['X-CSRF-TOKEN' => session()->token()]))->assertOk()
                    ->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'denied');
                Http::assertNothingSent();

                return;
            }
            $this->postJson($origin.'/api/v1/tracking/consent', ['analytics' => false, 'advertising' => $advertising,
                'policy_version' => 'analytics-v1'], $headers)->assertOk()->assertJsonPath('consent.analytics', 'denied')
                ->assertJsonPath('consent.advertising', $advertising ? 'granted' : 'denied');
            $this->postJson($origin.'/api/v1/tracking/events', $events, $headers)->assertOk()->assertJsonPath('accepted', 0);
        }
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $this->travelTo(now()->addHour());
        $this->get($origin.$path)->assertOk();
        $this->postJson($origin.'/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false,
            'policy_version' => 'analytics-v1'], $headers)->assertOk()
            ->assertJsonPath('enabled', false)->assertJsonPath('analytics_available', false)
            ->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'denied');
        $this->assertDatabaseHas('acquisition_privacy', ['advertising' => 'denied', 'viewer_id' => $matchingViewer ? $viewer->id : null]);
        Http::assertNothingSent();
    }
}
