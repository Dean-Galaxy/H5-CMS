<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Models\EpisodeRecord;
use App\Modules\Content\Models\Publication;
use App\Modules\Content\Services\PublicationService;

final class PublicationTest extends ContentTestCase
{
    public function test_35_episodes_publish_10_10_10_5_and_each_occurrence_is_idempotent(): void
    {
        $d = $this->fixture(35);
        $service = app(PublicationService::class);
        $sizes = [];
        for ($i = 0; $i < 4; $i++) {
            $p = $d->plan()->first();
            $at = $p->next_at;
            $this->travelTo($at);
            $this->assertSame('published', $service->execute($p->id, $p->version, $at));
            $this->assertSame('duplicate', $service->execute($p->id, $p->version, $at));
            $sizes[] = count(Publication::orderByDesc('executed_at')->first()->episode_numbers);
        }
        $this->assertSame([10, 10, 10, 5], $sizes);
        $this->assertSame(35, $d->episodes()->whereNotNull('published_at')->count());
        $this->assertSame('finished', $d->plan()->first()->state);
        $this->getJson('/api/v1/dramas/'.$d->id)->assertJsonPath('publication_state', 'completed')->assertJsonPath('published_episode_count', 35);
    }

    public function test_completed_requires_all_and_missing_or_processing_blocks_whole_batch(): void
    {
        $d = $this->fixture(34, 35);
        $p = $d->plan;
        $p->update(['mode' => 'completed']);
        $s = app(PublicationService::class);
        $this->assertSame('blocked', $s->execute($p->id, $p->version, $p->next_at));
        $this->assertSame(0, $d->episodes()->whereNotNull('published_at')->count());
        EpisodeRecord::create(['drama_id' => $d->id, 'number' => 35, 'video_asset_id' => $d->episodes()->first()->video_asset_id]);
        $this->assertSame(0, $d->episodes()->whereNotNull('published_at')->count()); // upload alone never catches up
        $p = $p->fresh();
        $this->travelTo($p->next_at);
        $this->assertSame('published', $s->execute($p->id, $p->version, $p->next_at));
        $this->assertCount(35, Publication::orderByDesc('executed_at')->first()->episode_numbers);
        $this->asStaff($this->owner);
        $another = $this->fixture(10, 20);
        $another->episodes()->where('number', 4)->delete();
        $this->assertSame('blocked', $s->execute($another->plan->id, 1, $another->plan->next_at));
        $this->assertSame('EPISODE_GAP', $another->plan()->first()->last_error);
        $processing = $this->fixture(10, 20);
        $processing->episodes()->first()->video->update(['state' => 'processing']);
        $this->assertSame('blocked', $s->execute($processing->plan->id, 1, $processing->plan->next_at));
        $this->assertSame(0, $processing->episodes()->whereNotNull('published_at')->count());
    }

    public function test_manual_pause_resume_edit_and_withdraw_make_old_executions_obsolete(): void
    {
        $d = $this->fixture(20);
        $old = $d->plan;
        $s = app(PublicationService::class);
        $this->publish($d, 3)->assertOk();
        $this->assertSame('obsolete', $s->execute($old->id, $old->version, $old->next_at));
        $p = $old->fresh();
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/pause', ['version' => $p->version])->assertOk();
        $this->assertSame(3, $d->episodes()->whereNotNull('published_at')->count());
        $this->travel(10)->days();
        $this->asStaff($this->owner);
        $p = $p->fresh();
        $next = now()->addDay();
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/resume', ['version' => $p->version, 'next_at' => $next->toIso8601String()])->assertOk()->assertJsonPath('start_number', 4);
        $this->assertSame(3, $d->episodes()->whereNotNull('published_at')->count());
        $p = $p->fresh();
        $this->travelTo($next);
        $this->assertSame('published', $s->execute($p->id, $p->version, $next));
        $this->assertSame(13, $d->episodes()->whereNotNull('published_at')->count());
        $old = $p->fresh();
        $this->asStaff($this->owner);
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/withdraw', ['version' => $d->fresh()->version])->assertOk();
        $this->travelTo($old->next_at);
        $this->assertSame('obsolete', $s->execute($old->id, $old->version, $old->next_at));
        $this->asStaff($this->owner);
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/relist', ['version' => $d->fresh()->version])->assertUnprocessable();
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/relist', ['version' => $d->fresh()->version, 'choice' => 'reset'])->assertOk();
        $this->assertSame('draft', $d->plan()->first()->state);
        $this->assertNull($d->plan()->first()->next_at);
    }

    public function test_saving_plan_invalidates_old_job_and_activation_requires_preview_and_future_time(): void
    {
        $d = $this->fixture(10);
        $old = $d->plan;
        $input = ['version' => 1, 'mode' => 'updating', 'interval_days' => 2, 'batch_size' => 5, 'start_number' => 1, 'launch_at' => now()->addDay()->toIso8601String()];
        $this->putJson('/api/v1/admin/content/dramas/'.$d->id.'/plan', $input)->assertOk();
        $this->assertSame('obsolete', app(PublicationService::class)->execute($old->id, 1, $old->next_at));
        $preview = $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/publication-preview', [])->assertOk()->json();
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/activate', ['version' => 2, 'confirmation' => str_repeat('a', 64)])->assertStatus(409);
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/activate', ['version' => 2, 'confirmation' => $preview['confirmation']])->assertOk();
        $this->putJson('/api/v1/admin/content/dramas/'.$d->id.'/plan', [...$input, 'version' => 3, 'launch_at' => now()->subDay()->toIso8601String()])->assertOk();
        $preview = $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/publication-preview', [])->json();
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/activate', ['version' => 4, 'confirmation' => $preview['confirmation']])->assertUnprocessable();
    }

    public function test_unapproved_missed_schedule_is_held_and_not_silently_caught_up(): void
    {
        $d = $this->fixture(20);
        $p = $d->plan;
        $this->travel(2)->hours();
        $this->assertSame('held', app(PublicationService::class)->execute($p->id, 1, $p->next_at));
        $this->assertSame('missed_pending_policy', $p->fresh()->state);
        $this->assertSame('D03_DECISION_REQUIRED', $p->fresh()->last_error);
        $this->assertSame(0, $d->episodes()->whereNotNull('published_at')->count());
    }
}
