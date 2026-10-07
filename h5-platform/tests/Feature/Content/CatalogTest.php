<?php

namespace Tests\Feature\Content;

use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Modules\Content\Models\CategoryRecord;

final class CatalogTest extends ContentTestCase
{
    public function test_real_home_category_banner_language_and_actual_published_counts(): void
    {
        $d = $this->fixture(6, 62);
        $this->publish($d, 6)->assertOk();
        $category = $this->postJson('/api/v1/admin/content/categories', ['name_en' => 'Romance', 'name_zh' => '', 'active' => true, 'sort_order' => 10])->assertOk()->json();
        $d->categories()->attach($category['id']);
        $image = $this->readyAsset('banner');
        $this->postJson('/api/v1/admin/content/banners', ['title_en' => 'Featured', 'title_zh' => '', 'drama_id' => $d->id, 'image_asset_id' => $image->id, 'sort_order' => 1, 'active' => true])->assertOk();
        $home = $this->getJson('/api/v1/home?locale=zh_CN')->assertOk()->assertJsonPath('locale', 'zh_CN')->assertJsonPath('categories.0.name', 'Romance')
            ->assertJsonPath('recommendations.0.title', 'A test drama')->assertJsonPath('recommendations.0.published_episode_count', 6)->assertJsonPath('recommendations.0.publication_state', 'updating')->json();
        $this->assertSame($home['categories'], $this->getJson('/api/v1/categories?locale=zh_CN')->json('items'));
        $this->assertSame($d->id, $home['banners'][0]['target_drama_id']);
        $this->getJson('/api/v1/catalog?category_id='.$category['id'])->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/v1/dramas/'.$d->id)->assertOk()->assertJsonPath('episodes.4.access_requirement', 'free')->assertJsonPath('episodes.5.access_requirement', 'membership');
        $this->assertStringNotContainsString('media.mp4', json_encode($home));
        $this->assertStringNotContainsString('source_key', json_encode($home));
        $this->get($home['banners'][0]['image_url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/withdraw', ['version' => $d->fresh()->version])->assertOk();
        $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(0, 'banners')->assertJsonCount(0, 'recommendations');
        $this->getJson('/api/v1/dramas/'.$d->id)->assertNotFound();
        $this->get($home['banners'][0]['image_url'])->assertNotFound();
    }

    public function test_visibility_expiry_drafts_unknown_region_and_empty_category(): void
    {
        $draft = $this->fixture();
        $d = $this->fixture(2, 2, true);
        $category = CategoryRecord::create(['name_en' => 'Empty', 'active' => true]);
        $this->getJson('/api/v1/dramas/'.$draft->id)->assertNotFound();
        $this->getJson('/api/v1/catalog?category_id='.$category->id)->assertOk()->assertJsonCount(0, 'items');
        $category->update(['active' => false]);
        $this->getJson('/api/v1/catalog?category_id='.$category->id)->assertNotFound();
        $d->update(['license_until' => now()]);
        $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(0, 'recommendations');
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('ZZ', 'unresolved');
            }
        });
        foreach (['home', 'categories', 'catalog', 'dramas/'.$d->id] as $path) {
            $this->getJson('/api/v1/'.$path.'?region=US', ['CF-IPCountry' => 'US'])->assertForbidden()->assertJsonPath('error.code', 'REGION_BLOCKED');
        }
    }

    public function test_cursor_is_stable_signed_and_bound_to_filter_locale_region_sort_version(): void
    {
        $a = $this->fixture(1, 1, true);
        $b = $this->fixture(1, 1, true);
        $c = $this->fixture(1, 1, true);
        $first = $this->getJson('/api/v1/catalog?limit=1')->assertOk()->json();
        $cursor = urlencode($first['next_cursor']);
        $second = $this->getJson('/api/v1/catalog?limit=1&cursor='.$cursor)->assertOk()->json();
        $this->assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
        $this->getJson('/api/v1/catalog?limit=1&locale=zh_CN&cursor='.$cursor)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['cursor']]]);
        $this->getJson('/api/v1/catalog?cursor=forged')->assertUnprocessable();
        $this->getJson('/api/v1/catalog?limit=0')->assertUnprocessable();
        $this->getJson('/api/v1/catalog?category_id=bad')->assertUnprocessable();
        $this->postJson('/api/v1/admin/content/categories', ['name_en' => 'New', 'name_zh' => '', 'sort_order' => 0, 'active' => true])->assertOk();
        $this->getJson('/api/v1/catalog?cursor='.$cursor)->assertUnprocessable();
    }
}
