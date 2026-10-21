<?php

namespace Tests\Feature\Content;

use App\Enums\Role;
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\Banner;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Str;

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

    public function test_content_workspace_renders_module_owned_navigation_and_release_controls(): void
    {
        $response = $this->get('/admin/content-library')->assertOk();
        $response->assertSee('剧目库')->assertSee('发布排期')->assertSee('分类管理')->assertSee('首页 Banner')
            ->assertSee('分集与字幕')->assertSee('授权与试看')->assertSee('操作记录')->assertSee('content-dialog');
    }

    public function test_published_episode_media_replacement_requires_ready_contextual_candidate_and_keeps_episode_id(): void
    {
        $d = $this->fixture(1, 1, true);
        $episode = $d->episodes()->first();
        $oldVideo = $episode->video_asset_id;
        $oldPath = app(MediaStore::class)->path($episode->video->output_key.'/media.mp4');
        $pending = $this->readyAsset('video');
        $pending->update(['state' => 'processing', 'metadata' => $pending->metadata + ['context_drama_id' => $d->id, 'context_episode_id' => $episode->id, 'context_role' => 'episode_replacement_video']]);
        $candidate = $this->readyAsset('video');
        $candidate->update(['metadata' => $candidate->metadata + ['context_drama_id' => $d->id, 'context_episode_id' => $episode->id, 'context_role' => 'episode_replacement_video']]);
        $base = '/api/v1/admin/content/dramas/'.$d->id.'/episodes/'.$episode->id.'/media-replacement';
        $this->postJson($base, ['drama_version' => $d->version, 'episode_version' => $episode->version, 'role' => 'video', 'asset_id' => $pending->id, 'confirmation' => true])
            ->assertUnprocessable();
        $this->assertSame($oldVideo, $episode->fresh()->video_asset_id);
        $this->postJson($base, ['drama_version' => $d->version, 'episode_version' => $episode->version, 'role' => 'video', 'asset_id' => $candidate->id, 'confirmation' => true])
            ->assertOk()->assertJsonPath('id', $episode->id)->assertJsonPath('video_asset_id', $candidate->id);
        $this->assertSame($episode->id, $d->episodes()->first()->id);
        $this->assertNotSame($oldVideo, $episode->fresh()->video_asset_id);
        $this->assertFileExists($oldPath);
        $stale = $this->postJson($base, ['drama_version' => $d->version, 'episode_version' => 1, 'role' => 'video', 'asset_id' => $oldVideo, 'confirmation' => true]);
        $stale->assertStatus(409);
    }

    public function test_subtitle_candidate_upload_preserves_context_and_cancel_cannot_change_bound_media(): void
    {
        $d = $this->fixture(1, 1, true);
        $ep = $d->episodes()->first();
        $body = "1\n00:00:00,000 --> 00:00:01,000\nHello\n";
        $input = ['kind' => 'subtitle', 'filename' => '1.srt', 'bytes' => strlen($body), 'context_drama_id' => $d->id,
            'context_episode_id' => $ep->id, 'context_role' => 'episode_replacement_subtitle', 'context_language' => 'en'];
        $candidate = $this->postJson('/api/v1/admin/content/uploads', $input)->assertOk()->assertJsonPath('metadata.context_language', 'en')->json('id');
        $media = app(MediaStore::class);
        $media->append($candidate, 0, $body);
        $media->finish($candidate);
        $asset = $media->process($candidate);
        $this->assertSame('ready', $asset->state);
        $this->assertFalse($asset->metadata['language_verified']);
        $this->assertSame('en', $asset->metadata['context_language']);
        $payload = ['drama_version' => $d->fresh()->version, 'episode_version' => $ep->version, 'role' => 'subtitle',
            'asset_id' => $candidate, 'subtitle_language' => 'zh_CN', 'confirmation' => true];
        $url = '/api/v1/admin/content/dramas/'.$d->id.'/episodes/'.$ep->id.'/media-replacement';
        $this->postJson($url, $payload)->assertUnprocessable();
        $payload['subtitle_language'] = 'en';
        $this->postJson($url, $payload)->assertOk()->assertJsonMissingPath('subtitle.output_key');
        $this->postJson('/api/v1/admin/content/uploads/'.$candidate.'/cancel')->assertStatus(409);
        $this->assertSame($candidate, $ep->fresh()->subtitle_asset_id);
        $this->assertSame($ep->video_asset_id, $ep->fresh()->video_asset_id);
        $this->assertSame($d->free_count, $d->fresh()->free_count);
        $this->assertSame($d->plan->toArray(), $d->fresh()->plan->toArray());
        $history = $this->getJson('/api/v1/admin/content/dramas/'.$d->id)->assertOk()->json('operations');
        $this->assertContains('episode.media_replaced', array_column($history, 'action'));
        $other = $this->postJson('/api/v1/admin/content/uploads', $input)->assertOk()->json('id');
        Asset::findOrFail($other)->update(['state' => 'processing', 'processing_token' => (string) Str::uuid7()]);
        $this->postJson('/api/v1/admin/content/uploads/'.$other.'/cancel')->assertOk()->assertJsonPath('state', 'canceled');
        $this->assertNull(Asset::findOrFail($other)->processing_token);
        $this->assertSame('canceled', $media->process($other)->state);
        $this->assertSame($candidate, $ep->fresh()->subtitle_asset_id);
    }

    public function test_replacement_rejects_wrong_context_timing_and_missing_files_without_mutation(): void
    {
        $d = $this->fixture(2, 2, true);
        $ep = $d->episodes()->first();
        $a = $this->readyAsset('subtitle');
        $context = ['context_drama_id' => $d->id, 'context_episode_id' => $ep->id, 'context_role' => 'episode_replacement_subtitle', 'context_language' => 'en'];
        $a->update(['metadata' => array_merge($a->metadata, $context, ['last_end' => 100])]);
        $url = '/api/v1/admin/content/dramas/'.$d->id.'/episodes/'.$ep->id.'/media-replacement';
        $payload = ['drama_version' => $d->version, 'episode_version' => $ep->version, 'role' => 'subtitle', 'asset_id' => $a->id, 'subtitle_language' => 'en', 'confirmation' => true];
        $this->postJson($url, $payload)->assertUnprocessable();
        $a->update(['metadata' => array_merge($a->metadata, ['last_end' => 1, 'context_episode_id' => $d->episodes()->where('id', '!=', $ep->id)->first()->id])]);
        $this->postJson($url, $payload)->assertUnprocessable();
        $a->update(['metadata' => array_merge($a->metadata, $context)]);
        unlink(app(MediaStore::class)->path($a->output_key.'/subtitles.vtt'));
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertNull($ep->fresh()->subtitle_asset_id);
        $this->assertSame($d->version, $d->fresh()->version);
        $this->assertSame(2, $d->episodes()->whereNotNull('published_at')->count());
    }

    public function test_draft_media_binding_cannot_be_replaced_by_generic_edit_before_candidate_confirmation(): void
    {
        $d = $this->fixture(1);
        $ep = $d->episodes()->first();
        $candidate = $this->readyAsset();
        $this->patchJson('/api/v1/admin/content/dramas/'.$d->id.'/episodes/'.$ep->id, ['version' => $ep->version,
            'number' => 1, 'title_en' => '', 'title_zh' => '', 'video_asset_id' => $candidate->id])->assertUnprocessable();
        $this->assertSame($ep->video_asset_id, $ep->fresh()->video_asset_id);
        $this->assertNull($ep->fresh()->published_at);
        $this->assertSame($d->version, $d->fresh()->version);
    }
}
