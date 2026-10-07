<?php

namespace Tests\Feature\Content;

use App\Enums\Role;
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\Banner;
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

    public function test_older_bound_assets_remain_editable_after_more_than_200_uploads(): void
    {
        $d = $this->fixture(1);
        $episode = $d->episodes()->first();
        $video = $episode->video_asset_id;
        $subtitle = $this->readyAsset('subtitle');
        $episode->update(['subtitle_asset_id' => $subtitle->id, 'subtitle_language' => 'en']);
        $image = $this->readyAsset('banner');
        $bannerInput = ['drama_id' => $d->id, 'image_asset_id' => $image->id, 'title_en' => 'Old banner', 'title_zh' => '', 'active' => false, 'sort_order' => 0];
        $banner = $this->postJson('/api/v1/admin/content/banners', $bannerInput)->assertOk()->json('id');
        for ($i = 0; $i < 205; $i++) {
            Asset::create(['created_by' => $this->owner->id, 'kind' => 'video', 'extension' => 'mp4', 'expected_bytes' => 1]);
        }
        $index = $this->getJson('/api/v1/admin/content')->assertOk()->json();
        $this->assertNotContains($video, array_column($index['assets'], 'id'));
        $this->assertContains($d->cover_asset_id, array_column($index['assets'], 'id'));
        $this->assertContains($image->id, array_column($index['assets'], 'id'));
        $detail = $this->getJson('/api/v1/admin/content/dramas/'.$d->id)->assertOk()->json();
        $this->assertContains($video, array_column($detail['assets'], 'id'));
        $this->assertContains($subtitle->id, array_column($detail['assets'], 'id'));
        $this->patchJson('/api/v1/admin/content/dramas/'.$d->id, [...$this->draftInput(), 'version' => $d->version, 'title_en' => 'Title only', 'cover_asset_id' => $d->cover_asset_id])->assertOk();
        $this->patchJson('/api/v1/admin/content/dramas/'.$d->id.'/episodes/'.$episode->id, ['version' => $episode->version, 'number' => 1, 'title_en' => 'Episode title only', 'title_zh' => '', 'video_asset_id' => $video, 'subtitle_asset_id' => $subtitle->id, 'subtitle_language' => 'en'])->assertOk();
        $this->patchJson('/api/v1/admin/content/banners/'.$banner, [...$bannerInput, 'version' => 1, 'title_en' => 'Banner title only'])->assertOk();
        $this->assertSame($d->cover_asset_id, $d->fresh()->cover_asset_id);
        $this->assertSame($video, $episode->fresh()->video_asset_id);
        $this->assertSame($subtitle->id, $episode->fresh()->subtitle_asset_id);
        $this->assertSame($image->id, Banner::findOrFail($banner)->image_asset_id);
        $older = $this->getJson('/api/v1/admin/content/assets?before='.$index['asset_cursor'])->assertOk()->json();
        $this->assertContains($video, array_column($older['assets'], 'id'));
        $this->assertNull($older['next_cursor']);
        $this->assertArrayNotHasKey('source_key', $older['assets'][0]);
    }

    public function test_edit_keeps_original_identity_and_rejects_conflict_without_changing_either_episode(): void
    {
        $d = $this->fixture(2, 62);
        [$first, $second] = $d->episodes()->orderBy('number')->get()->all();
        $first->update(['title_en' => 'Original one']);
        $second->update(['title_en' => 'Original two', 'video_asset_id' => $this->readyAsset()->id]);
        $before = $d->episodes()->orderBy('id')->get()->toArray();
        $payload = ['version' => $first->version, 'number' => 2, 'title_en' => 'Moved one', 'title_zh' => '', 'video_asset_id' => $first->video_asset_id];
        $url = '/api/v1/admin/content/dramas/'.$d->id.'/episodes';
        $this->patchJson($url.'/'.$first->id, $payload)->assertStatus(422);
        $this->assertSame($before, $d->episodes()->orderBy('id')->get()->toArray());
        $this->assertSame($d->version, $d->fresh()->version);
        $this->patchJson($url.'/'.$first->id, [...$payload, 'number' => 3])->assertOk()->assertJsonPath('id', $first->id);
        $this->assertSame(2, $d->episodes()->count());
        $this->assertSame(3, $first->fresh()->number);
        $this->assertSame($first->video_asset_id, $first->fresh()->video_asset_id);
        $this->assertSame($second->toArray(), $second->fresh()->toArray());
        $afterMove = $d->episodes()->orderBy('id')->get()->toArray();
        $this->patchJson($url.'/'.$first->id, [...$payload, 'number' => 4])->assertStatus(409)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->postJson($url, $payload)->assertStatus(422);
        $this->assertSame($afterMove, $d->episodes()->orderBy('id')->get()->toArray());
        $created = $this->postJson($url, [...$payload, 'number' => 1])->assertOk()->json('id');
        $this->assertNotContains($created, [$first->id, $second->id]);
        $this->assertSame(3, $d->episodes()->count());
        $this->assertSame($afterMove, $d->episodes()->whereIn('id', [$first->id, $second->id])->orderBy('id')->get()->toArray());
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
