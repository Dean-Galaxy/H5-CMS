<?php

namespace Tests\Feature\Audience;

use App\Contracts\CatalogReader;
use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Content\Models\Banner;
use App\Modules\Content\Models\CategoryRecord;
use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Content\ContentTestCase;

/** Actual Content provider and PostgreSQL; media bytes are explicitly testing-only fixtures. */
final class CatalogPagesTest extends ContentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.modules.audience.ready' => true]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
    }

    public function test_home_categories_banner_and_episode_pages_use_real_content_and_keep_order(): void
    {
        $first = $this->fixture(published: true);
        $first->update(['title_en' => 'First story', 'sort_order' => 1]);
        $second = $this->fixture(published: true);
        $second->update(['title_en' => 'Second story', 'sort_order' => 2]);
        $category = CategoryRecord::create(['name_en' => 'Romance', 'name_zh' => '爱情', 'sort_order' => 1, 'active' => true]);
        $first->categories()->attach($category);
        Banner::create(['title_en' => 'Featured first', 'title_zh' => '', 'drama_id' => $first->id,
            'image_asset_id' => $first->cover_asset_id, 'active' => true, 'sort_order' => 0]);
        $this->get('/')->assertOk()->assertSeeInOrder(['First story', 'Second story'])
            ->assertSee('href="/dramas/'.$first->id.'"', false)->assertSee('Featured first');
        $this->get('/catalog?category_id='.$category->id)->assertOk()->assertSee('First story')->assertDontSee('Second story');
        $this->get('/dramas/'.$first->id)->assertOk()->assertSee('6 episodes available')->assertSee('Member episode');
        $episode = $first->episodes()->where('number', 5)->first();
        $this->get('/dramas/'.$first->id.'/episodes/'.$episode->id)->assertOk()->assertSee('preload="none"', false)
            ->assertDontSee('master.m3u8')->assertDontSee('media.mp4')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/episodes/'.$episode->id.'/playback-grants', ['play_session_id' => null])->assertOk()->assertJsonPath('allowed', true);
        $paid = $first->episodes()->where('number', 6)->first();
        $this->postJson('/api/v1/episodes/'.$paid->id.'/playback-grants', ['play_session_id' => null])->assertOk()
            ->assertJsonPath('allowed', false)->assertJsonPath('reason', 'MEMBERSHIP_REQUIRED');
    }

    public function test_language_falls_back_to_provided_content_and_clears_cursor_on_switch(): void
    {
        $drama = $this->fixture(published: true);
        $drama->update(['title_en' => 'Only English title', 'title_zh' => '']);
        $this->withHeader('Accept-Language', 'zh-TW,en;q=0.8')->get('/')->assertOk()->assertSee('下一段故事')->assertSee('Only English title');
        $this->get('/catalog?lang=en&cursor=stale')->assertOk()->assertSee('Showing the first page again.')
            ->assertSee('/catalog?lang=zh_CN"', false)->assertDontSee('cursor=stale&amp;lang=zh_CN', false);
    }

    public function test_empty_down_and_unknown_region_are_distinct_without_runtime_samples(): void
    {
        $this->get('/')->assertOk()->assertSee('New stories are on their way.');
        $drama = $this->fixture(published: true);
        $this->get('/dramas/'.$drama->id)->assertOk();
        $drama->update(['state' => 'withdrawn']);
        $this->get('/dramas/'.$drama->id)->assertNotFound()->assertSee('removed');
    }

    public function test_unknown_region_is_not_an_empty_catalog(): void
    {
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('ZZ', 'unresolved');
            }
        });
        $this->get('/')->assertForbidden()->assertSee('region')->assertDontSee('New stories are on their way.');
    }

    public function test_missing_catalog_is_not_replaced_by_runtime_samples(): void
    {
        $this->app->bind(CatalogReader::class, fn () => throw new DependencyNotReady('Content'));
        $this->get('/')->assertStatus(503);
    }

    public function test_episode_cannot_be_attached_to_another_drama_and_public_text_is_escaped(): void
    {
        $a = $this->fixture(published: true);
        $b = $this->fixture(published: true);
        $a->update(['title_en' => '<script>alert(1)</script>']);
        $this->get('/dramas/'.$a->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/dramas/'.$a->id.'/episodes/'.$b->episodes()->first()->id)->assertNotFound();
    }
}
