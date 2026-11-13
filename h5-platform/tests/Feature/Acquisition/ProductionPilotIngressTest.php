<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\CatalogReader;
use App\Contracts\PromotionLandingEvidence;
use App\Data\CheckoutPageContext;
use App\Data\Drama;
use App\Data\PaymentSettled;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Production\PilotLandingEvidence;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Modules\Acquisition\Services\CheckoutContextStore;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;

final class ProductionPilotIngressTest extends AcquisitionTestCase
{
    private const ORIGIN = 'https://production-pilot.example.test';

    private object $publicGuard;

    private function usePilot(): void
    {
        config(['h5.acquisition_production_pilot' => true, 'app.url' => self::ORIGIN, 'acquisition.analytics_enabled' => false]);
        // Boundary double until 00's public contract can be tested on the combined candidate.
        $this->publicGuard = new class
        {
            public array $scope = [];

            public function productionAcquisitionPilotOrigin(): string
            {
                return 'https://production-pilot.example.test';
            }

            public function productionAcquisitionScope(): array
            {
                if ($this->scope === []) {
                    throw new \LogicException('Test scope closed');
                }

                return $this->scope;
            }
        };
        $this->app->instance(RuntimeEnvironmentGuard::class, $this->publicGuard);
        Event::forget(PaymentSettled::class); // Remove the ordinary module listener installed by the test base.
        $provider = new AcquisitionServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    private function request(string $method, string $path, array $headers = []): void
    {
        $request = Request::create(self::ORIGIN.$path, $method, server: $headers + [
            'HTTP_ORIGIN' => self::ORIGIN, 'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_USER_AGENT' => 'Synthetic visitor', 'REMOTE_ADDR' => '8.8.4.4',
        ]);
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
    }

    public function test_scope_is_closed_during_asset_setup_and_other_links_are_rejected(): void
    {
        $this->signIn();
        $this->usePilot();
        [$pixel, $link] = $this->assets('meta', 'production');
        $this->assertFalse(app(TrackingContext::class)->enabled());
        $this->assertFalse(Event::hasListeners(PaymentSettled::class));
        $this->assertInstanceOf(PilotLandingEvidence::class, app(PromotionLandingEvidence::class));
        $this->assertFalse(config('acquisition.external_enabled'));
        $this->assertSame('disabled', config('acquisition.transport'));

        [, $other] = $this->assets('meta', 'test');
        $this->publicGuard->scope = ['platform' => 'meta', 'link_id' => $link['id'], 'pixel_id' => $pixel['id'],
            'pixel_version' => $pixel['version'], 'from' => now()->subMinute()->toIso8601ZuluString(),
            'until' => now()->addHour()->toIso8601ZuluString()];
        $this->problem('NOT_FOUND', fn () => app(AttributionRecorder::class)->prepare(basename($other['url'])));
        $this->assertNull(session('tracking.pending'));
        Http::assertNothingSent();
    }

    public function test_allowed_visitor_requires_rendered_proof_and_freezes_one_source_then_withdraws(): void
    {
        $this->signIn();
        $this->freezeTime();
        $this->usePilot();
        [$pixel, $link] = $this->assets('meta', 'production');
        $this->publicGuard->scope = ['platform' => 'meta', 'link_id' => $link['id'], 'pixel_id' => $pixel['id'],
            'pixel_version' => $pixel['version'], 'from' => now()->subMinute()->toIso8601ZuluString(),
            'until' => now()->addHour()->toIso8601ZuluString()];
        $drama = $link['drama_id'];
        $path = '/dramas/'.$drama;
        $catalog = Mockery::mock(CatalogReader::class);
        $catalog->shouldReceive('drama')->with($drama, 'zh_CN')->andReturn(new Drama($drama, 'Synthetic', '', null, 0, 0, 5, 'published', []));
        $this->app->instance(CatalogReader::class, $catalog);
        $tracking = app(TrackingContext::class);
        $attribution = app(AttributionRecorder::class);
        $this->request('GET', '/r/'.basename($link['url']), [
            'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document',
        ]);
        $this->assertSame($path, $attribution->prepare(basename($link['url'])));
        $this->assertTrue($tracking->enabled());
        $this->request('POST', '/api/v1/tracking/consent');
        $tracking->consent(true, true);
        $this->assertFalse($tracking->publicContext()['analytics_available']);
        $this->assertSame('denied', $tracking->publicContext()['consent']['analytics']);
        $this->request('POST', '/api/v1/tracking/landing');
        $this->assertFalse($attribution->confirm($drama));
        $this->request('GET', $path, ['HTTP_PURPOSE' => 'prefetch']);
        app(PromotionLandingEvidence::class)->record($drama);
        $this->assertNull(session('tracking.production_get'));
        $this->request('GET', $path);
        app(PromotionLandingEvidence::class)->record($drama);
        $this->assertNotNull(session('tracking.production_get'));
        $this->request('POST', '/api/v1/tracking/landing');
        $this->assertTrue($attribution->confirm($drama));
        $first = $attribution->snapshot(null);
        $this->request('GET', '/r/'.basename($link['url']));
        $attribution->prepare(basename($link['url']));
        $this->request('GET', $path);
        app(PromotionLandingEvidence::class)->record($drama);
        $this->travel(10)->minutes();
        $this->request('POST', '/api/v1/tracking/landing');
        $this->assertFalse($attribution->confirm($drama));
        $this->assertSame($first->source_id, $attribution->snapshot(null)->source_id);
        $this->request('GET', $path);
        app(PromotionLandingEvidence::class)->record($drama);
        $this->request('POST', '/api/v1/tracking/landing');
        $this->assertTrue($attribution->confirm($drama));
        $latest = $attribution->snapshot(null);
        $this->assertNotSame($first->source_id, $latest->source_id);
        $this->request('POST', '/membership/checkout');
        $frozen = app(CheckoutContextStore::class)->capture($latest, new CheckoutPageContext('/membership', now()->toIso8601String()));
        $this->assertNotNull($frozen?->checkout_context_id);
        $this->assertSame($latest->source_id, $frozen->source_id);
        $this->assertSame($pixel['version'], $frozen->pixel_config_version);
        DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['version' => $pixel['version'] + 1]);
        $this->assertNull($attribution->snapshot(null));
        $this->request('POST', '/api/v1/tracking/consent');
        $tracking->consent(false, false);
        $this->assertNull($attribution->snapshot(null));
        $this->assertSame(2, DB::table('acquisition_sources')->whereNotNull('revoked_at')->count());
        $this->assertDatabaseCount('acquisition_checkout_contexts', 1);
        $this->assertDatabaseCount('acquisition_subjects', 0);
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->assertDatabaseCount('acquisition_receipts', 0);
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $this->assertSame('skipped', app(BehaviorRecorder::class)->recordFact('payment_confirmed', [], 'synthetic')->value);
        Http::assertNothingSent();
    }
}
