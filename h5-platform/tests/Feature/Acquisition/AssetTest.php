<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\AttributionService;
use App\Enums\Role;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\AssetManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class AssetTest extends AcquisitionTestCase
{
    public function test_owner_and_assigned_scope_is_enforced_for_reads_writes_probe_summary_and_retry(): void
    {
        $creator = $this->signIn();
        [$pixel, $link] = $this->assets();
        $manager = app(AssetManager::class);
        $other = $this->signIn();
        $this->assertSame([], $manager->pixels());
        $this->assertSame([], $manager->links());
        foreach ([fn () => $manager->pixel($pixel['id']), fn () => $manager->link($link['id']), fn () => $manager->summary($link['id']),
            fn () => $manager->simulatePixel($pixel['id']), fn () => $manager->retry($link['id'])] as $action) {
            $this->problem('NOT_FOUND', $action);
        }
        $this->problem('NOT_AUTHORIZED', fn () => $manager->share('pixel', $pixel['id'], 1, [$other->id]));
        // Explicit persisted fixture for the already-frozen Identity ResourceScope behavior.
        DB::table('acquisition_assignments')->insert(['resource_type' => 'pixel', 'resource_id' => $pixel['id'], 'staff_id' => $other->id, 'created_at' => now()]);
        $this->assertSame($pixel['id'], $manager->pixels()[0]['id']);
        $this->assertSame([], $manager->links());
        $this->signIn($creator);
        $this->assertSame($pixel['id'], $manager->pixel($pixel['id'])['id']);
        $this->signIn($this->staff(Role::Owner));
        $this->assertCount(1, $manager->pixels());
        $this->assertCount(1, $manager->links());
    }

    public function test_mfa_disabled_staff_and_wrong_roles_cannot_read_assets(): void
    {
        $manager = app(AssetManager::class);
        foreach ([Role::ContentOperator, Role::Support] as $role) {
            $this->signIn($this->staff($role));
            $this->problem('NOT_AUTHORIZED', fn () => $manager->pixels());
        }
        $staff = $this->signIn();
        request()->session()->forget('identity.mfa_staff');
        $this->problem('MFA_REQUIRED', fn () => $manager->pixels());
        $staff->active = false;
        $this->problem('AUTHENTICATION_REQUIRED', fn () => $manager->pixels());
    }

    public function test_registration_does_not_enable_external_delivery_or_accept_script_or_owner_fields(): void
    {
        $this->signIn();
        $manager = app(AssetManager::class);
        foreach (['script' => '<script>alert(1)</script>', 'owner_id' => AcquisitionStore::id(), 'token' => 'private'] as $key => $value) {
            $this->problem('VALIDATION_FAILED', fn () => $manager->savePixel(null, $this->pixelInput() + [$key => $value]));
        }
        $pixel = $manager->savePixel(null, $this->pixelInput());
        $manager->simulatePixel($pixel['id']);
        $this->assertSame('simulated', $manager->pixel($pixel['id'])['local_status']);
        $this->assertDatabaseCount('acquisition_receipts', 0);
        $this->assertDatabaseCount('billing_orders', 0);
        Http::assertNothingSent();
        $this->assertSame('disabled', config('acquisition.transport'));
    }

    public function test_pixel_versions_and_link_versions_reject_stale_updates_and_unfrozen_rebinding(): void
    {
        $this->signIn();
        [$pixel, $link] = $this->assets();
        $manager = app(AssetManager::class);
        $input = array_intersect_key($pixel, $this->pixelInput());
        $changed = $manager->savePixel($pixel['id'], array_replace($input, ['active' => false]));
        $this->assertSame(2, $changed['version']);
        $this->problem('STALE_VERSION', fn () => $manager->savePixel($pixel['id'], $input));
        $this->assertDatabaseHas('acquisition_pixel_versions', ['pixel_id' => $pixel['id'], 'version' => 1, 'external_id' => $pixel['external_id']]);
        $this->problem('DEPENDENCY_NOT_READY', fn () => $manager->savePixel($pixel['id'], array_replace($input, ['version' => 2, 'external_id' => '98765432100'])));
        // Stopping a link works even if its pixel and content are no longer available.
        $this->dramas = [];
        $linkInput = array_intersect_key($link, array_flip(['name', 'platform', 'drama_id', 'pixel_id', 'active', 'version']));
        $changed = $manager->saveLink($link['id'], array_replace($linkInput, ['active' => false]));
        $this->assertFalse((bool) $changed['active']);
        $this->problem('STALE_VERSION', fn () => $manager->saveLink($link['id'], $linkInput));
    }

    public function test_link_requires_accessible_matching_pixel_and_current_content(): void
    {
        $this->signIn();
        [$pixel, $link] = $this->assets();
        $input = array_intersect_key($link, array_flip(['name', 'platform', 'drama_id', 'pixel_id', 'active', 'version']));
        $input['version'] = 0;
        $manager = app(AssetManager::class);
        $this->problem('VALIDATION_FAILED', fn () => $manager->saveLink(null, array_replace($input, ['platform' => 'tiktok'])));
        $this->problem('NOT_FOUND', fn () => $manager->saveLink(null, array_replace($input, ['drama_id' => AcquisitionStore::id()])));
        $this->signIn();
        $this->problem('NOT_FOUND', fn () => $manager->saveLink(null, $input));
        $this->assertDatabaseCount('acquisition_links', 1);
    }

    public function test_unfrozen_attribution_is_explicitly_unavailable_and_does_not_trust_markers(): void
    {
        $service = app(AttributionService::class);
        $this->assertFalse($service->enabled());
        $this->assertNull($service->snapshot('forged-source'));
        $this->assertNull($service->snapshot(null));
        $this->getJson('/r/arbitrary-code')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
        $this->postJson('/api/v1/acquisition/payment-settled', [])->assertNotFound();
    }
}
