<?php

namespace Tests\Feature\Contracts;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\PromotableCatalog;
use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Enums\Role;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Content\Services\ContentPromotableCatalog;
use App\Modules\Identity\Services\StaffDirectory;
use App\Support\ApiProblem;
use Tests\Feature\Content\ContentTestCase;

/** Real Identity + Content + Acquisition services; media bytes and users are isolated testing fixtures. */
final class AcquisitionDirectoryIntegrationTest extends ContentTestCase
{
    public function test_real_directories_support_sharing_selection_revalidation_and_revocation_without_assuming_viewer_region(): void
    {
        $this->app->register(AcquisitionServiceProvider::class);
        config(['h5.modules.acquisition.ready' => true]);
        $this->assertInstanceOf(StaffDirectory::class, app(AcquisitionStaffDirectory::class));
        $this->assertInstanceOf(ContentPromotableCatalog::class, app(PromotableCatalog::class));
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('ZZ', 'unresolved');
            }
        });
        $drama = $this->fixture(6, 6, true);
        $first = $this->makeStaff(Role::Acquisition);
        $second = $this->makeStaff(Role::Acquisition);
        $this->makeStaff(Role::Support);
        $manager = app(AssetManager::class);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($manager->staffCandidates()['items'], 'id'));
        $catalog = $manager->catalog()['items'];
        $this->assertCount(1, $catalog);
        $this->assertSame($drama->id, $catalog[0]['id']);
        $this->assertSame(6, $catalog[0]['published_episode_count']);
        $this->assertSame('ZZ', app(RegionResolver::class)->resolve()->country_code);
        $pixel = $manager->savePixel(null, ['name' => '00 integration fixture', 'platform' => 'meta',
            'external_id' => '900000001234', 'environment' => 'test', 'active' => true, 'version' => 0]);
        $input = ['name' => '00 integration link', 'platform' => 'meta', 'drama_id' => $drama->id,
            'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0];
        $link = $manager->saveLink(null, $input);
        $manager->share('pixel', $pixel['id'], 1, [$first->id, $second->id]);
        $manager->share('link', $link['id'], 1, [$first->id]);
        $this->assertCount(2, $manager->assignment('pixel', $pixel['id'])['selected']);

        $this->asStaff($first);
        $this->assertSame($pixel['id'], $manager->pixel($pixel['id'])['id']);
        $this->assertSame($link['id'], $manager->link($link['id'])['id']);
        $this->asStaff($second);
        $this->assertSame($pixel['id'], $manager->pixel($pixel['id'])['id']);
        $this->rejects('NOT_FOUND', fn () => $manager->link($link['id']));

        $this->asStaff($this->owner);
        // This is fixture state, not an Identity administration endpoint; active is guarded.
        $first->forceFill(['active' => false])->save();
        $this->assertDatabaseHas('identity_staff', ['id' => $first->id, 'active' => false]);
        $selected = $manager->assignment('pixel', $pixel['id'])['selected'];
        $unavailable = collect($selected)->firstWhere('id', $first->id);
        $this->assertFalse($unavailable['available']);
        $this->rejects('NOT_FOUND', fn () => $manager->share('pixel', $pixel['id'], 2, [$first->id]));
        $manager->share('pixel', $pixel['id'], 2, []);
        $this->asStaff($second);
        $this->rejects('NOT_FOUND', fn () => $manager->pixel($pixel['id']));

        $this->asStaff($this->owner);
        $drama->update(['state' => 'draft']);
        $this->assertSame([], $manager->catalog()['items']);
        $input['version'] = 2;
        $this->rejects('NOT_FOUND', fn () => $manager->saveLink($link['id'], $input));
        $stopped = $manager->saveLink($link['id'], [...$input, 'active' => false]);
        $this->assertFalse((bool) $stopped['active']);
        $this->assertSame('ZZ', app(RegionResolver::class)->resolve()->country_code);
    }

    private function rejects(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (ApiProblem $error) {
            $this->assertSame($code, $error->errorCode);
        }
    }
}
