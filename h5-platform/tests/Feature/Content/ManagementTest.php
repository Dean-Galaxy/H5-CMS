<?php

namespace Tests\Feature\Content;

use App\Enums\Role;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Identity\Models\Viewer;

final class ManagementTest extends ContentTestCase
{
    public function test_new_drama_snapshots_defaults_and_fixed_beijing_tomorrow(): void
    {
        $d = $this->createDrama();
        $this->assertSame(5, $d->free_count);
        $this->assertFalse($d->free_overridden);
        $plan = $d->plan;
        $this->assertSame('completed', $plan->mode);
        $this->assertSame(1, $plan->interval_days);
        $this->assertSame(10, $plan->batch_size);
        $this->assertSame(1, $plan->start_number);
        $this->assertSame('2026-10-07T16:00:00Z', $plan->launch_at->toIso8601ZuluString());
        $this->travel(2)->days();
        $this->asStaff($this->owner);
        $this->patchJson('/api/v1/admin/content/dramas/'.$d->id, ['version' => 1, ...$this->draftInput()])->assertOk();
        $this->assertSame('2026-10-07T16:00:00Z', $plan->fresh()->launch_at->toIso8601ZuluString());
        $this->assertDatabaseCount('content_publications', 0);
    }

    public function test_license_offsets_round_trip_without_beijing_drift(): void
    {
        $data = [...$this->draftInput(), 'license_from' => '2026-10-07T00:00:00+08:00', 'license_until' => '2026-10-14T00:00:00+08:00'];
        $id = $this->postJson('/api/v1/admin/content/dramas', $data)->assertOk()->json('id');
        $d = DramaRecord::find($id);
        $this->assertSame('2026-10-06T16:00:00Z', $d->license_from->toIso8601ZuluString());
        $this->assertSame('2026-10-13T16:00:00Z', $d->license_until->toIso8601ZuluString());
    }

    public function test_manual_same_value_is_override_and_sync_requires_current_impact(): void
    {
        $a = $this->createDrama();
        $b = $this->createDrama();
        $this->patchJson('/api/v1/admin/content/dramas/'.$b->id.'/free', ['version' => 1, 'free_count' => 5])->assertOk();
        $preview = $this->postJson('/api/v1/admin/content/default-preview', ['free_count' => 0, 'scope' => 'sync'])->assertOk()->json();
        $this->assertSame([$a->id], array_column($preview['dramas'], 'id'));
        $this->createDrama();
        $this->patchJson('/api/v1/admin/content/default', $preview)->assertStatus(409);
        $preview = $this->postJson('/api/v1/admin/content/default-preview', ['free_count' => 0, 'scope' => 'sync'])->json();
        $this->patchJson('/api/v1/admin/content/default', $preview)->assertOk()->assertJsonPath('affected_count', 2);
        $this->assertSame(0, $a->fresh()->free_count);
        $this->assertSame(5, $b->fresh()->free_count);
        $this->assertTrue($b->fresh()->free_overridden);
        $new = $this->createDrama();
        $this->assertSame(0, $new->free_count);
        $preview = $this->postJson('/api/v1/admin/content/default-preview', ['free_count' => 9, 'scope' => 'new_only'])->json();
        $this->patchJson('/api/v1/admin/content/default', $preview)->assertOk()->assertJsonPath('affected_count', 0);
        $this->assertSame(0, $a->fresh()->free_count);
        $this->assertSame(9, $this->createDrama()->free_count);
    }

    public function test_content_permissions_mfa_and_viewer_staff_separation(): void
    {
        $operator = $this->makeStaff(Role::ContentOperator);
        $this->asStaff($operator);
        $this->getJson('/api/v1/admin/content')->assertOk();
        $this->postJson('/api/v1/admin/content/default-preview', ['free_count' => 1, 'scope' => 'sync'])->assertForbidden();
        foreach ([Role::Support, Role::Acquisition] as $role) {
            $this->asStaff($this->makeStaff($role));
            $this->getJson('/api/v1/admin/content')->assertForbidden();
            $this->postJson('/api/v1/admin/content/dramas', $this->draftInput())->assertForbidden();
        }
        $this->asStaff($operator);
        $this->withSession(['identity.mfa_staff' => null]);
        app('session.store')->save();
        $this->getJson('/api/v1/admin/content')->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
        auth('staff')->logout();
        app('session.store')->save();
        $viewer = Viewer::create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'ViewerPass1234']);
        $this->asViewer($viewer);
        $this->postJson('/api/v1/admin/content/dramas', $this->draftInput())->assertUnauthorized();
    }

    public function test_versions_and_episode_object_scope_prevent_overwrite(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $ep = $a->episodes()->first();
        $payload = ['version' => 1, 'number' => 1, 'title_en' => 'Changed', 'title_zh' => '', 'video_asset_id' => $ep->video_asset_id];
        $this->patchJson('/api/v1/admin/content/dramas/'.$b->id.'/episodes/'.$ep->id, $payload)->assertNotFound();
        $this->patchJson('/api/v1/admin/content/dramas/'.$a->id.'/free', ['version' => 1, 'free_count' => 5])->assertOk();
        $this->patchJson('/api/v1/admin/content/dramas/'.$a->id, ['version' => 1, ...$this->draftInput()])->assertStatus(409);
    }
}
