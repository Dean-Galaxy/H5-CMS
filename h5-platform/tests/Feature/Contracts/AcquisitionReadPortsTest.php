<?php

namespace Tests\Feature\Contracts;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\AttributionService;
use App\Contracts\PromotableCatalog;
use App\Data\AssignableStaff;
use App\Data\AssignableStaffPage;
use App\Data\PromotableDrama;
use App\Data\PromotableDramaPage;
use App\Enums\Role;
use App\Support\DependencyNotReady;
use App\Support\UnavailablePromotableCatalog;
use App\Support\UnavailableStaffDirectory;
use Tests\TestCase;

class AcquisitionReadPortsTest extends TestCase
{
    private function sample(string $name): array
    {
        return json_decode(file_get_contents(base_path('../docs/contracts/examples/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_consumers_read_typed_minimal_pages_without_private_staff_or_media_fields(): void
    {
        $staff = AssignableStaffPage::fromArray($this->sample('assignable-staff-page'));
        $this->assertInstanceOf(AssignableStaff::class, $staff->items[0]);
        $this->assertSame(Role::Acquisition, $staff->items[0]->role);
        $this->assertTrue($staff->items[0]->active);
        $this->assertSame(['id', 'name', 'role', 'active'], array_keys($staff->items[0]->toArray()));
        $this->assertSame($this->sample('assignable-staff-page'), $staff->toArray());

        $catalog = PromotableDramaPage::fromArray($this->sample('promotable-drama-page'));
        $this->assertInstanceOf(PromotableDrama::class, $catalog->items[0]);
        $this->assertSame('US', $catalog->items[0]->country_code);
        $this->assertSame('zh_CN', $catalog->items[0]->locale);
        $this->assertSame(['id', 'title', 'locale', 'country_code', 'published_episode_count'], array_keys($catalog->items[0]->toArray()));
        $this->assertSame($this->sample('promotable-drama-page'), $catalog->toArray());
        $this->assertSame([], AssignableStaffPage::fromArray($this->sample('assignable-staff-empty'))->items);
        $this->assertSame([], PromotableDramaPage::fromArray($this->sample('promotable-drama-empty'))->items);
    }

    public function test_absent_read_providers_do_not_turn_into_successful_empty_lists_or_sample_items(): void
    {
        $this->assertInstanceOf(AcquisitionStaffDirectory::class, app(AcquisitionStaffDirectory::class));
        $this->assertInstanceOf(PromotableCatalog::class, app(PromotableCatalog::class));
        // Explicit missing-provider condition remains meaningful after 01/02 implement these ports.
        $this->app->bind(AcquisitionStaffDirectory::class, UnavailableStaffDirectory::class);
        $this->app->bind(PromotableCatalog::class, UnavailablePromotableCatalog::class);
        $id = $this->sample('assignable-staff-page')['items'][0]['id'];
        foreach ([
            fn () => app(AcquisitionStaffDirectory::class)->candidates(),
            fn () => app(AcquisitionStaffDirectory::class)->candidate($id),
            fn () => app(PromotableCatalog::class)->promotable(),
            fn () => app(PromotableCatalog::class)->promotableDrama($id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Missing providers must fail explicitly.');
            } catch (DependencyNotReady $error) {
                $this->assertSame(503, $error->status);
                $this->assertSame('DEPENDENCY_NOT_READY', $error->errorCode);
            }
        }
        $this->assertFalse(app(AttributionService::class)->enabled());
        $this->assertNull(app(AttributionService::class)->snapshot(null));
    }
}
