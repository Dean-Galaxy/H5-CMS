<?php

namespace Tests\Feature\Acquisition;

use App\Data\PaymentSettled;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Audience\AudienceServiceProvider;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Content\ContentTestCase;

final class ProductionPilotHttpTest extends ContentTestCase
{
    public function test_real_promotion_redirect_and_successful_drama_html_enable_the_scoped_advertising_choice(): void
    {
        $drama = $this->fixture(count: 1, published: true);
        $origin = 'https://pilot.example.test';
        config(['app.url' => $origin, 'h5.modules.audience.ready' => true, 'h5.modules.acquisition.ready' => true,
            'h5.acquisition_production_pilot' => true]);
        // 00 owns the public environment guard. Exercise actual 04 and 05 HTTP/session logic behind its boundary.
        $guard = new class
        {
            public array $scope = [];

            public function productionAcquisitionPilotOrigin(): string
            {
                return 'https://pilot.example.test';
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
        $this->defaultCookies = $this->unencryptedCookies = [];
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4', 'HTTP_USER_AGENT' => 'Synthetic production visitor']);
        $url = $origin.'/r/'.basename($link['url']);
        $this->get($url)->assertNotFound();
        $guard->scope = ['platform' => 'meta', 'link_id' => $link['id'], 'pixel_id' => $pixel['id'],
            'pixel_version' => $pixel['version'], 'from' => now()->subMinute()->toIso8601ZuluString(),
            'until' => now()->addHour()->toIso8601ZuluString()];
        $path = '/dramas/'.$drama->id;
        $this->get($url, ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'])
            ->assertRedirect($path);
        $this->assertNull(session('tracking.production_get'));
        $page = $this->get($origin.$path)->assertOk()->assertSee('</html>', false)->assertSee($drama->title_en);
        $this->assertNotNull(session('tracking.production_get'));
        $this->assertSame(1, preg_match('/<meta name="csrf-token" content="([^"]+)">/', $page->getContent(), $match));
        $headers = ['Origin' => $origin, 'Sec-Fetch-Site' => 'same-origin', 'X-CSRF-TOKEN' => html_entity_decode($match[1], ENT_QUOTES)];
        $this->postJson($origin.'/api/v1/tracking/consent', ['analytics' => true, 'advertising' => true,
            'policy_version' => 'analytics-v1'], $headers)->assertOk()->assertJsonPath('analytics_available', false)
            ->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'granted');
        $payload = ['page_instance_id' => (string) Str::uuid(), 'drama_id' => $drama->id, 'path' => $path];
        $this->postJson($origin.'/api/v1/tracking/landing', $payload, $headers)->assertOk()->assertJsonPath('confirmed', true);
        $this->assertDatabaseHas('acquisition_sources', ['link_id' => $link['id'], 'pixel_id' => $pixel['id'],
            'pixel_version' => $pixel['version'], 'subject_id' => null, 'is_simulation' => false]);
        $this->assertDatabaseCount('acquisition_sources', 1);
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $this->travelTo(now()->addHour());
        $this->get($origin.$path)->assertOk();
        $this->postJson($origin.'/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false,
            'policy_version' => 'analytics-v1'], $headers)->assertOk()
            ->assertJsonPath('enabled', false)->assertJsonPath('analytics_available', false)
            ->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'denied');
        $this->assertDatabaseHas('acquisition_privacy', ['advertising' => 'denied', 'viewer_id' => null]);
        Http::assertNothingSent();
    }
}
