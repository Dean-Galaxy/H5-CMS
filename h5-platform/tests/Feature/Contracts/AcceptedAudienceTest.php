<?php

namespace Tests\Feature\Contracts;

use App\Contracts\AttributionService;
use App\Contracts\ProgressService;
use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Audience\Http\CatalogController;
use App\Modules\Audience\Progress\ProgressRecorder;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AcceptedAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_website_and_progress_use_the_accepted_provider_without_local_opt_in(): void
    {
        $this->assertSame('', config('h5.local_modules'));
        $this->assertSame(['provider' => AudienceServiceProvider::class, 'ready' => true], config('h5.modules.audience'));
        $this->assertInstanceOf(ProgressRecorder::class, app(ProgressService::class));
        $this->assertFalse(app(AttributionService::class)->enabled());
        $this->assertSame(CatalogController::class.'@home', Route::getRoutes()->match(Request::create('/'))->getActionName());
        $this->getJson('/health')->assertOk()->assertJsonPath('modules.audience', 'ready')
            ->assertJsonPath('modules.acquisition', 'not_ready');
        $this->get('/')->assertForbidden()->assertSee('region');
    }

    public function test_fresh_local_content_and_unconfigured_prices_are_not_replaced_by_samples(): void
    {
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
        $this->get('/')->assertOk()->assertSee('New stories are on their way.')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/membership')->assertStatus(503)->assertSee('Payment is not available.');
        $this->assertDatabaseCount('billing_orders', 0);
        $this->assertDatabaseCount('billing_entitlements', 0);
    }

    public function test_fresh_progress_is_empty_and_a_guest_cannot_write_account_progress(): void
    {
        $dramaId = '00000000-0000-4000-8000-000000000001';
        $this->getJson('/api/v1/me/progress/'.$dramaId)->assertUnauthorized();
        $this->putJson('/api/v1/me/progress/'.$dramaId, [])->assertUnauthorized();
        $viewer = Viewer::forceCreate(['name' => 'Reception viewer', 'email' => 'audience-reception@example.test',
            'password' => 'TestOnlyPassword1234']);
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version]);
        $this->getJson('/api/v1/me/progress/'.$dramaId)->assertOk()->assertContent('null')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('audience_progress', 0);
        $this->assertDatabaseCount('audience_playback_sessions', 0);
    }
}
