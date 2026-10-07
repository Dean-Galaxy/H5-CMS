<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\PromotableCatalog;
use App\Data\AssignableStaff;
use App\Data\AssignableStaffPage;
use App\Enums\Role;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use App\Modules\Acquisition\Services\AssetManager;
use App\Support\ApiProblem;
use App\Support\UnavailablePromotableCatalog;
use App\Support\UnavailableStaffDirectory;
use Livewire\Livewire;

final class AssignmentTest extends AcquisitionTestCase
{
    private function directory(array $items): \ArrayObject
    {
        $fixture = new \ArrayObject($items);
        // Explicit 1.0.3 test provider, never bound outside this test case.
        $this->app->instance(AcquisitionStaffDirectory::class, new class($fixture) implements AcquisitionStaffDirectory
        {
            public function __construct(private \ArrayObject $fixture) {}

            public function candidates(?string $cursor = null, int $limit = 50): AssignableStaffPage
            {
                $items = array_values($this->fixture->getArrayCopy());

                return new AssignableStaffPage(array_slice($items, $cursor ? 1 : 0, 1), $cursor || count($items) < 2 ? null : 'explicit-fixture-page-2');
            }

            public function candidate(string $staffId): AssignableStaff
            {
                return $this->fixture[$staffId] ?? throw new ApiProblem('NOT_FOUND', 404, '人员不可分配');
            }
        });

        return $fixture;
    }

    public function test_owner_shares_multiple_people_and_removal_revokes_access_without_changing_creator(): void
    {
        $creator = $this->signIn();
        [$pixel, $link] = $this->assets();
        $first = $this->staff();
        $second = $this->staff();
        $owner = $this->staff(Role::Owner);
        $this->directory([$first->id => new AssignableStaff($first->id, 'First', Role::Acquisition, true),
            $second->id => new AssignableStaff($second->id, 'Second', Role::Acquisition, true)]);
        $manager = app(AssetManager::class);
        $this->signIn($first);
        $this->problem('NOT_AUTHORIZED', fn () => $manager->staffCandidates());
        $this->problem('NOT_AUTHORIZED', fn () => $manager->assignment('pixel', $pixel['id']));
        $this->signIn($owner);
        $manager->share('pixel', $pixel['id'], 1, [$first->id, $second->id]);
        $manager->share('link', $link['id'], 1, [$second->id]);
        $this->assertCount(2, $manager->assignment('pixel', $pixel['id'])['selected']);
        $this->assertSame($creator->id, $manager->pixel($pixel['id'])['owner_id']);
        $this->assertDatabaseHas('acquisition_pixel_versions', ['pixel_id' => $pixel['id'], 'version' => 2]);
        $this->problem('STALE_VERSION', fn () => $manager->share('pixel', $pixel['id'], 1, []));
        $this->signIn($first);
        $this->assertCount(1, $manager->pixels());
        $this->assertSame([], $manager->links());
        $this->signIn($second);
        $this->assertSame([], $manager->summary($link['id'])['payments']);
        $this->signIn($owner);
        $manager->share('pixel', $pixel['id'], 2, [$second->id]);
        $this->signIn($first);
        $this->problem('NOT_FOUND', fn () => $manager->pixel($pixel['id']));
    }

    public function test_selected_person_outside_current_page_is_repopulated_and_revalidated_at_save(): void
    {
        $this->signIn($this->staff(Role::Owner));
        [$pixel] = $this->assets();
        $first = $this->staff();
        $second = $this->staff();
        $fixture = $this->directory([$first->id => new AssignableStaff($first->id, 'First page', Role::Acquisition, true),
            $second->id => new AssignableStaff($second->id, 'Second page', Role::Acquisition, true)]);
        $page = Livewire::test(AcquisitionDesk::class)->call('selectAssignment', 'pixel', $pixel['id'])
            ->call('loadStaff')->assertSee('First page')->assertDontSee('Second page')
            ->call('loadStaff')->assertSee('Second page')->set('staffIds', [$second->id])->call('share')->assertHasNoErrors();
        $this->assertDatabaseHas('acquisition_assignments', ['resource_id' => $pixel['id'], 'staff_id' => $second->id]);
        Livewire::test(AcquisitionDesk::class)->call('selectAssignment', 'pixel', $pixel['id'])
            ->assertSet('staffIds', [$second->id])->assertSee('Second page')->call('loadStaff')->assertSet('staffIds', [$second->id]);
        unset($fixture[$second->id]);
        $manager = app(AssetManager::class);
        $this->problem('NOT_FOUND', fn () => $manager->share('pixel', $pixel['id'], 2, [$second->id]));
        $this->assertDatabaseHas('acquisition_assignments', ['resource_id' => $pixel['id'], 'staff_id' => $second->id]);
        Livewire::test(AcquisitionDesk::class)->call('selectAssignment', 'pixel', $pixel['id'])
            ->assertSee('已失去分配资格')->assertSet('staffIds', [$second->id])->set('staffIds', [])->call('share');
        $this->assertDatabaseCount('acquisition_assignments', 0);
    }

    public function test_unavailable_read_ports_are_explicit_and_never_replaced_with_public_catalog_or_fake_staff(): void
    {
        $this->signIn($this->staff(Role::Owner));
        [$pixel, $link] = $this->assets();
        $manager = app(AssetManager::class);
        $this->app->instance(AcquisitionStaffDirectory::class, new UnavailableStaffDirectory);
        $this->app->instance(PromotableCatalog::class, new UnavailablePromotableCatalog);
        $this->problem('DEPENDENCY_NOT_READY', fn () => $manager->staffCandidates());
        $this->problem('DEPENDENCY_NOT_READY', fn () => $manager->share('pixel', $pixel['id'], 1, [$this->staff()->id]));
        $this->problem('DEPENDENCY_NOT_READY', fn () => $manager->catalog());
        $input = array_intersect_key($link, array_flip(['name', 'platform', 'drama_id', 'pixel_id', 'active', 'version']));
        $this->problem('DEPENDENCY_NOT_READY', fn () => $manager->saveLink($link['id'], $input));
        $manager->saveLink($link['id'], array_replace($input, ['active' => false]));
        $this->assertDatabaseHas('acquisition_links', ['id' => $link['id'], 'active' => false]);
        $this->assertDatabaseCount('acquisition_assignments', 0);
    }
}
