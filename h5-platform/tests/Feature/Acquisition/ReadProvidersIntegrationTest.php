<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\AttributionService;
use App\Contracts\PromotableCatalog;
use App\Contracts\RegionResolver;
use App\Enums\Role;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Content\Services\ContentPromotableCatalog;
use App\Modules\Identity\Services\StaffDirectory;
use App\Support\ApiProblem;
use App\Support\ServerRegionResolver;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Content\ContentTestCase;

/** Real accepted read providers. Users, publication state and media are testing fixtures only. */
final class ReadProvidersIntegrationTest extends ContentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(AcquisitionServiceProvider::class);
        config(['h5.modules.acquisition.ready' => true, 'h5.local_us_test_region' => false,
            'acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false]);
        // Remove ContentTestCase's regional fixture; exercise the normal unresolved resolver.
        $this->app->forgetInstance(RegionResolver::class);
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $session = app('session.store');
        $this->app->rebinding('request', static function ($app, $request) use ($session): void {
            $request->setLaravelSession($session);
        });
        $this->assertInstanceOf(StaffDirectory::class, app(AcquisitionStaffDirectory::class));
        $this->assertInstanceOf(ContentPromotableCatalog::class, app(PromotableCatalog::class));
        $this->assertInstanceOf(ServerRegionResolver::class, app(RegionResolver::class));
    }

    public function test_real_directories_support_both_platforms_sharing_revocation_and_publication_revalidation(): void
    {
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
        $assets = [];
        foreach (['meta', 'tiktok'] as $platform) {
            $pixel = $manager->savePixel(null, $this->pixelInput($platform));
            $input = ['name' => 'Explicit testing link '.$platform, 'platform' => $platform, 'drama_id' => $drama->id,
                'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0];
            $link = $manager->saveLink(null, $input);
            $manager->share('pixel', $pixel['id'], 1, [$first->id, $second->id]);
            $manager->share('link', $link['id'], 1, [$first->id]);
            $this->assertCount(2, $manager->assignment('pixel', $pixel['id'])['selected']);
            $assets[] = [$pixel, $link, $input];
        }

        $this->asStaff($first);
        $this->rejects('NOT_AUTHORIZED', fn () => $manager->staffCandidates());
        $this->assertSame($drama->id, $manager->catalog()['items'][0]['id']);
        foreach ($assets as [$pixel, $link]) {
            $this->assertSame($pixel['id'], $manager->pixel($pixel['id'])['id']);
            $this->assertSame($link['id'], $manager->link($link['id'])['id']);
            $this->assertSame([], $manager->summary($link['id'])['payments']);
        }
        $this->asStaff($second);
        foreach ($assets as [$pixel, $link]) {
            $this->assertSame($pixel['id'], $manager->pixel($pixel['id'])['id']);
            $this->rejects('NOT_FOUND', fn () => $manager->link($link['id']));
            $this->rejects('NOT_FOUND', fn () => $manager->summary($link['id']));
        }

        $this->asStaff($this->owner);
        $first->forceFill(['active' => false])->save();
        foreach ($assets as [$pixel]) {
            $selected = $manager->assignment('pixel', $pixel['id'])['selected'];
            $this->assertFalse(collect($selected)->firstWhere('id', $first->id)['available']);
            $this->rejects('NOT_FOUND', fn () => $manager->share('pixel', $pixel['id'], 2, [$first->id]));
            $this->assertCount(2, $manager->assignment('pixel', $pixel['id'])['selected']);
            $manager->share('pixel', $pixel['id'], 2, []);
        }
        $this->asStaff($second);
        foreach ($assets as [$pixel]) {
            $this->rejects('NOT_FOUND', fn () => $manager->pixel($pixel['id']));
        }
        $this->asStaff($this->owner);
        $drama->update(['state' => 'draft']);
        $this->assertSame([], $manager->catalog()['items']);
        foreach ($assets as [$pixel, $link, $input]) {
            $input['version'] = 2;
            $this->rejects('NOT_FOUND', fn () => $manager->saveLink($link['id'], $input));
            $this->assertFalse((bool) $manager->saveLink($link['id'], [...$input, 'active' => false])['active']);
        }
        $this->assertSame('ZZ', app(RegionResolver::class)->resolve()->country_code);
        $this->assertFalse(app(AttributionService::class)->enabled());
        $this->assertNull(app(AttributionService::class)->snapshot(null));
        $this->assertSame('disabled', config('acquisition.transport'));
    }

    public function test_workbench_uses_real_cursor_and_repopulates_saved_choices_before_revalidating_them(): void
    {
        $drama = $this->fixture(1, 1, true);
        for ($i = 0; $i < 51; $i++) {
            $staff = $this->makeStaff(Role::Acquisition);
            $staff->update(['name' => 'Explicit testing colleague '.$i]);
        }
        $manager = app(AssetManager::class);
        $firstPage = $manager->staffCandidates();
        $this->assertCount(50, $firstPage['items']);
        $this->assertNotNull($firstPage['next_cursor']);
        $nextPage = $manager->staffCandidates($firstPage['next_cursor']);
        $this->assertSame([$staff->id], array_column($nextPage['items'], 'id'));
        $pixel = $manager->savePixel(null, $this->pixelInput('meta'));
        $link = $manager->saveLink(null, ['name' => 'Explicit testing saved selection', 'platform' => 'meta',
            'drama_id' => $drama->id, 'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0]);

        Livewire::test(AcquisitionDesk::class)->call('selectAssignment', 'pixel', $pixel['id'])
            ->call('loadStaff')->assertDontSee($staff->name)->call('loadStaff')->assertSee($staff->name)
            ->set('staffIds', [$staff->id])->call('share')->assertHasNoErrors();
        Livewire::test(AcquisitionDesk::class)->call('selectAssignment', 'pixel', $pixel['id'])
            ->assertSet('staffIds', [$staff->id])->assertSee($staff->name)
            ->call('loadStaff')->assertSet('staffIds', [$staff->id])->assertSee($staff->name);
        $this->assertSame([$staff->id], array_column($manager->assignment('pixel', $pixel['id'])['selected'], 'id'));

        $staff->forceFill(['role' => Role::Support])->save();
        $this->rejects('NOT_FOUND', fn () => $manager->share('pixel', $pixel['id'], 2, [$staff->id]));
        Livewire::test(AcquisitionDesk::class)->call('selectAssignment', 'pixel', $pixel['id'])
            ->assertSee('已失去分配资格')->assertSet('staffIds', [$staff->id])->set('staffIds', [])->call('share');
        $this->assertSame([], $manager->assignment('pixel', $pixel['id'])['selected']);

        $page = Livewire::test(AcquisitionDesk::class)->call('selectLink', $link['id'])
            ->assertSet('link.drama_id', $drama->id)->assertSee('已保存，提交时复核');
        $drama->update(['state' => 'draft']);
        $page->set('link.name', 'Must not save stale selection')->call('saveLink');
        $this->assertSame($link['name'], $manager->link($link['id'])['name']);
        $page->set('link.active', 0)->call('saveLink')->assertHasNoErrors()->assertSet('link.active', 0);
        $this->assertFalse((bool) $manager->link($link['id'])['active']);

        request()->session()->forget('identity.mfa_staff');
        $this->rejects('MFA_REQUIRED', fn () => $manager->staffCandidates());
        $this->rejects('MFA_REQUIRED', fn () => $manager->catalog());
        $this->assertSame('ZZ', app(RegionResolver::class)->resolve()->country_code);
    }

    private function pixelInput(string $platform): array
    {
        return ['name' => 'Explicit testing '.$platform, 'platform' => $platform,
            'external_id' => $platform === 'meta' ? '900000001205' : 'TEST_READERS_05',
            'environment' => 'test', 'active' => true, 'version' => 0];
    }

    private function rejects(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected '.$code);
        } catch (ApiProblem $error) {
            $this->assertSame($code, $error->errorCode);
        }
    }
}
