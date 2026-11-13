<?php

namespace Tests\Feature\Audience;

use App\Contracts\CatalogReader;
use App\Contracts\PromotionLandingEvidence;
use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Modules\Audience\AudienceServiceProvider;
use App\Support\DisabledPromotionLandingEvidence;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Content\ContentTestCase;

/** Real Catalog/Blade/HTTP with a synthetic optional evidence hook; no advertisement or network I/O. */
final class PromotionLandingEvidenceTest extends ContentTestCase
{
    private object $hook;

    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.modules.audience.ready' => true]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        $this->hook = new class implements PromotionLandingEvidence
        {
            public array $calls = [];

            public bool $fail = false;

            public function record(string $dramaId): void
            {
                if ($this->fail) {
                    throw new RuntimeException('PRIVATE_SYNTHETIC_HOOK_FAILURE');
                }
                $this->calls[] = $dramaId;
            }
        };
        $this->app->instance(PromotionLandingEvidence::class, $this->hook);
    }

    public function test_successful_html_drama_get_records_only_the_selected_published_drama(): void
    {
        $drama = $this->fixture(published: true);
        $this->get('/dramas/'.$drama->id)->assertOk()->assertSee('</html>', false)->assertSee($drama->title_en);
        $this->assertSame([$drama->id], $this->hook->calls);
        $this->get('/')->assertOk();
        $this->get('/catalog')->assertOk();
        $episode = $drama->episodes()->first();
        $this->get('/dramas/'.$drama->id.'/episodes/'.$episode->id)->assertOk();
        $this->assertSame([$drama->id], $this->hook->calls);
    }

    public function test_default_disabled_contract_leaves_drama_page_unchanged(): void
    {
        $this->app->forgetInstance(PromotionLandingEvidence::class);
        $this->assertInstanceOf(DisabledPromotionLandingEvidence::class, app(PromotionLandingEvidence::class));
        $this->get('/dramas/'.$this->fixture(published: true)->id)->assertOk()->assertSee('</html>', false);
        $this->assertSame([], $this->hook->calls);
    }

    public function test_head_json_prefetch_and_prerender_do_not_record_success(): void
    {
        $path = '/dramas/'.$this->fixture(published: true)->id;
        $this->head($path)->assertOk();
        $this->get($path, ['Accept' => 'application/json'])->assertOk();
        foreach ([['Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch;prerender'], ['Purpose' => 'prerender']] as $headers) {
            $this->get($path, $headers)->assertOk();
        }
        $this->assertSame([], $this->hook->calls);
    }

    public function test_unpublished_missing_and_wrong_route_never_record(): void
    {
        $draft = $this->fixture();
        $this->get('/dramas/'.$draft->id)->assertNotFound();
        $this->get('/dramas/'.Str::uuid())->assertNotFound();
        $this->get('/dramas/not-a-uuid')->assertNotFound();
        $published = $this->fixture(published: true);
        $published->update(['state' => 'withdrawn']);
        $this->get('/dramas/'.$published->id)->assertNotFound();
        $this->assertSame([], $this->hook->calls);
    }

    public function test_forbidden_catalog_region_never_records_success(): void
    {
        $published = $this->fixture(published: true);
        config(['h5.content_worldwide_self_produced' => false]);
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('ZZ', 'unresolved');
            }
        });
        $this->get('/dramas/'.$published->id)->assertForbidden();
        $this->assertSame([], $this->hook->calls);
    }

    public function test_rendering_exception_cannot_leave_success_evidence(): void
    {
        $drama = $this->fixture(published: true);
        View::composer('audience.drama', fn () => throw new RuntimeException('SYNTHETIC_RENDER_FAILURE'));
        $this->withoutExceptionHandling();
        try {
            $this->get('/dramas/'.$drama->id);
            $this->fail('Expected rendering to fail');
        } catch (\Throwable $error) {
            $this->assertStringContainsString('SYNTHETIC_RENDER_FAILURE', $error->getMessage());
        }
        $this->assertSame([], $this->hook->calls);
    }

    public function test_catalog_returning_another_drama_cannot_record_the_requested_drama(): void
    {
        $drama = $this->fixture(published: true);
        $data = app(CatalogReader::class)->drama($drama->id, 'en');
        $reader = \Mockery::mock(CatalogReader::class);
        $reader->shouldReceive('drama')->once()->andReturn($data);
        $this->app->instance(CatalogReader::class, $reader);
        $this->get('/dramas/'.Str::uuid())->assertOk();
        $this->assertSame([], $this->hook->calls);
    }

    public function test_hook_failure_does_not_leak_or_break_html_and_free_playback(): void
    {
        $drama = $this->fixture(published: true);
        Log::spy();
        $this->hook->fail = true;
        $this->get('/dramas/'.$drama->id)->assertOk()->assertSee('</html>', false)->assertDontSee('PRIVATE_SYNTHETIC_HOOK_FAILURE');
        $this->assertSame([], $this->hook->calls);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $episode = $drama->episodes()->where('number', 1)->first();
        $this->get('/dramas/'.$drama->id.'/episodes/'.$episode->id)->assertOk();
        $this->postJson('/audience/dramas/'.$drama->id.'/episodes/'.$episode->id.'/playback', ['play_session_id' => null])
            ->assertOk()->assertJsonPath('allowed', true);
    }
}
