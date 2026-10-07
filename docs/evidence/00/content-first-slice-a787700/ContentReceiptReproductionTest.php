<?php
namespace Tests\Receipt;

use App\Modules\Content\Models\Asset;
use Tests\Feature\Content\ContentTestCase;

final class ContentReceiptReproductionTest extends ContentTestCase
{
    /** Replays the wrong target request emitted by the unchanged candidate admin.js in the browser fixture. */
    public function test_observes_wrong_episode_request_overwriting_another_draft(): void
    {
        $d = $this->fixture(2);
        [$first, $second] = $d->episodes()->get()->all();
        $other = $this->readyAsset();
        $second->update(['video_asset_id' => $other->id, 'title_en' => 'Second original']);
        $payload = ['number' => 2, 'title_en' => 'First edited', 'title_zh' => '',
            'video_asset_id' => $first->video_asset_id, 'subtitle_asset_id' => null,
            'subtitle_language' => null, 'version' => $second->version];
        $this->patchJson('/api/v1/admin/content/dramas/'.$d->id.'/episodes/'.$second->id, $payload)->assertOk();
        $this->assertSame($first->video_asset_id, $second->fresh()->video_asset_id);
        $this->assertSame('First edited', $second->fresh()->title_en);
        $this->assertNotSame($other->id, $second->fresh()->video_asset_id);
        $this->assertSame(1, $first->fresh()->number);
    }

    /** Regression of the originally missing binding, now returned by the updated candidate. */
    public function test_bound_old_cover_is_present_and_preserved_on_unrelated_edit(): void
    {
        $d = $this->fixture(1);
        $old = Asset::findOrFail($d->cover_asset_id);
        $old->update(['created_at' => now()->subDay()]);
        $rows = [];
        for ($n=0; $n<200; $n++) {
            $rows[] = ['id' => (string) \Illuminate\Support\Str::uuid7(), 'created_by' => $this->owner->id,
                'kind' => 'video', 'state' => 'pending', 'extension' => 'mp4',
                'expected_bytes' => 1, 'received_bytes' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        Asset::insert($rows);
        $listing = $this->getJson('/api/v1/admin/content')->assertOk()->json();
        $this->assertGreaterThanOrEqual(201, count($listing['assets']));
        $this->assertContains($old->id, array_column($listing['assets'], 'id'));
        $this->getJson('/api/v1/admin/content/dramas/'.$d->id)->assertOk()->assertJsonPath('drama.cover_asset_id', $old->id);
        $payload = [...$this->draftInput(), 'version' => $d->version, 'title_en' => 'Only intended title change', 'cover_asset_id' => $old->id];
        $this->patchJson('/api/v1/admin/content/dramas/'.$d->id, $payload)->assertOk();
        $this->assertSame($old->id, $d->fresh()->cover_asset_id);
        $this->assertDatabaseHas('content_assets', ['id' => $old->id, 'state' => 'ready']);
    }
}
