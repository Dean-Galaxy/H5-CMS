<?php

namespace Tests\Feature\Contracts;

use App\Contracts\CatalogReader;
use App\Contracts\MembershipReader;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Modules\Content\ContentServiceProvider;
use App\Modules\Content\Models\CategoryRecord;
use App\Modules\Content\Services\ContentCatalog;
use App\Modules\Content\Services\PlaybackService;
use App\Support\UnavailableServices;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptedContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_loads_without_local_opt_in_and_registers_each_job_once(): void
    {
        $this->assertSame('', config('h5.local_modules'));
        $this->assertSame(['provider' => ContentServiceProvider::class, 'ready' => true], config('h5.modules.content'));
        $this->assertInstanceOf(ContentCatalog::class, app(CatalogReader::class));
        $this->assertInstanceOf(PlaybackService::class, app(PlaybackAuthorizer::class));
        $this->assertInstanceOf(UnavailableServices::class, app(MembershipReader::class));
        $this->getJson('/health')->assertOk()->assertJsonPath('modules.content', 'ready')->assertJsonPath('modules.billing', 'not_ready');
        $this->getJson('/api/v1/billing/offer')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
        $this->getJson('/api/v1/admin/content')->assertUnauthorized();
        $commands = array_map(fn ($event) => $event->command, app(Schedule::class)->events());
        foreach (['content:publish-due', 'content:process-media'] as $command) {
            $this->assertCount(1, array_filter($commands, fn ($value) => str_contains($value, $command)));
        }
    }

    public function test_public_content_still_requires_a_trusted_region_then_reads_its_own_database(): void
    {
        $this->getJson('/api/v1/catalog?region=US', ['CF-IPCountry' => 'US', 'X-Country-Code' => 'US'])
            ->assertForbidden()->assertJsonPath('error.code', 'REGION_BLOCKED');
        // Explicit testing-only region; no membership, media or successful payment fixture.
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
        $category = CategoryRecord::create(['name_en' => 'Reception category', 'name_zh' => '接收分类']);
        $this->getJson('/api/v1/categories?locale=zh_CN')->assertOk()
            ->assertJsonPath('items.0.id', $category->id)->assertJsonPath('items.0.name', '接收分类');
        $this->getJson('/api/v1/home?locale=en')->assertOk()
            ->assertJsonPath('categories.0.id', $category->id)->assertJsonPath('categories.0.name', 'Reception category');
        $category->update(['active' => false]);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/v1/catalog')->assertOk()->assertJsonCount(0, 'items');
    }
}
