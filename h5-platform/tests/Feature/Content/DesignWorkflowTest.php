<?php

namespace Tests\Feature\Content;

use App\Enums\Role;
use App\Modules\Content\Services\PublicationService;
use Illuminate\Support\Facades\DB;

final class DesignWorkflowTest extends ContentTestCase
{
    public function test_active_proposed_preview_is_read_only_and_only_explicit_pause_allows_edit(): void
    {
        $d = $this->fixture(35);
        $base = '/api/v1/admin/content/dramas/'.$d->id;
        $input = ['version' => 1, 'mode' => 'updating', 'interval_days' => 2, 'batch_size' => 5, 'launch_at' => now()->addDay()->toIso8601String()];
        $before = $d->plan->getRawOriginal();
        $catalog = DB::table('content_settings')->where('id', 1)->first();
        $operations = DB::table('content_operations')->count();
        $preview = $this->postJson($base.'/schedule-preview', $input)->assertOk()->assertJsonPath('through', 5)->json();
        $this->assertSame($before, $d->plan->fresh()->getRawOriginal());
        $this->assertEquals($catalog, DB::table('content_settings')->where('id', 1)->first());
        $this->assertSame($operations, DB::table('content_operations')->count());
        // Closing/canceling a preview has no server action. Direct writes still cannot bypass pause.
        $this->postJson($base.'/schedule', [...$input, 'confirmation' => $preview['confirmation']])->assertStatus(409);
        $this->putJson($base.'/plan', [...$input, 'start_number' => 1])->assertStatus(409);
        $this->assertSame($before, $d->plan->fresh()->getRawOriginal());
        $this->postJson($base.'/pause', ['version' => 9])->assertStatus(409);
        $this->assertSame($before, $d->plan->fresh()->getRawOriginal());
        $this->postJson($base.'/pause', ['version' => 1])->assertOk();
        $this->assertSame('paused', $d->plan->fresh()->state);
        $this->assertNull($d->plan->fresh()->next_at);
        $this->postJson($base.'/schedule', [...$input, 'confirmation' => $preview['confirmation']])->assertStatus(409);
        $input['version'] = 2;
        $preview = $this->postJson($base.'/schedule-preview', $input)->assertOk()->json();
        $paused = $d->plan->fresh()->getRawOriginal();
        $this->postJson($base.'/schedule', [...$input, 'batch_size' => 10, 'confirmation' => $preview['confirmation']])->assertStatus(409);
        $this->assertSame($paused, $d->plan->fresh()->getRawOriginal());
        $this->postJson($base.'/schedule', [...$input, 'confirmation' => $preview['confirmation']])->assertOk()->assertJsonPath('state', 'active');
        $this->assertSame(5, $d->plan->fresh()->batch_size);
        $this->assertSame(0, $d->episodes()->whereNotNull('published_at')->count());
    }

    public function test_draft_timing_migration_preserves_existing_running_paused_and_scheduled_records(): void
    {
        $plans = [];
        foreach (['active', 'paused', 'draft'] as $state) {
            $drama = $this->fixture(2);
            $plan = $drama->plan;
            $plan->update(['state' => $state, 'version' => 7, 'launch_at' => now()->addDay(),
                'next_at' => $state === 'active' ? now()->addDays(2) : null]);
            $plans[] = $plan->fresh();
        }
        $fields = ['id', 'state', 'version', 'launch_at', 'next_at', 'start_number', 'mode', 'interval_days', 'batch_size'];
        $before = array_map(fn ($p) => $p->only($fields), $plans);
        $migration = require database_path('migrations/2026_10_21_020002_add_content_plan_draft_timing.php');
        $migration->down();
        $this->assertEquals($before, array_map(fn ($p) => $p->fresh()->only($fields), $plans));
        $migration->up();
        $this->assertEquals($before, array_map(fn ($p) => $p->fresh()->only($fields), $plans));
        foreach ($plans as $plan) {
            $this->assertSame('scheduled', $plan->fresh()->draft_timing);
        }
        $this->assertSame(0, DB::table('content_episodes')->whereNotNull('published_at')->count());
    }

    public function test_operation_actor_comes_from_frozen_identity_context_and_old_rows_stay_unknown(): void
    {
        $d = $this->fixture(2);
        $row = DB::table('content_operations')->where('drama_id', $d->id)->first();
        $this->assertSame($this->owner->name, $row->actor_name);
        $this->assertSame('staff', $row->actor_kind);
        DB::table('content_operations')->where('id', $row->id)->update(['actor_name' => null, 'actor_kind' => null]);
        $this->getJson('/api/v1/admin/content/dramas/'.$d->id)->assertOk()->assertJsonPath('operations.0.actor_name', null);
        auth('staff')->logout();
        app('session.store')->save();
        app(PublicationService::class)->execute($d->plan->id, $d->plan->version, $d->plan->next_at);
        $this->assertSame('system', DB::table('content_operations')->where('drama_id', $d->id)->orderByDesc('id')->value('actor_kind'));
    }

    public function test_now_draft_saves_intent_without_publishing_or_activating(): void
    {
        $d = $this->createDrama();
        $at = $d->plan->launch_at->toIso8601String();
        $this->putJson('/api/v1/admin/content/dramas/'.$d->id.'/plan', ['version' => 1, 'mode' => 'completed',
            'interval_days' => 1, 'batch_size' => 10, 'start_number' => 1, 'launch_at' => $at, 'draft_timing' => 'now'])
            ->assertOk()->assertJsonPath('draft_timing', 'now')->assertJsonPath('state', 'draft')->assertJsonPath('next_at', null);
        $this->assertSame($at, $d->plan->fresh()->launch_at->toIso8601String());
        $this->assertSame(0, $d->episodes()->whereNotNull('published_at')->count());
    }

    public function test_failed_final_schedule_rolls_back_draft_and_its_audit_projection(): void
    {
        $d = $this->fixture(2);
        $d->update(['state' => 'withdrawn']);
        $d->plan->update(['state' => 'disabled', 'next_at' => null]);
        $base = '/api/v1/admin/content/dramas/'.$d->id;
        $input = ['version' => 1, 'mode' => 'completed', 'interval_days' => 1, 'batch_size' => 10, 'launch_at' => now()->addDay()->toIso8601String()];
        $preview = $this->postJson($base.'/schedule-preview', $input)->assertOk()->json();
        $before = $d->plan->fresh()->getRawOriginal();
        $operations = DB::table('content_operations')->count();
        $this->postJson($base.'/schedule', [...$input, 'confirmation' => $preview['confirmation']])->assertStatus(409);
        $this->assertSame($before, $d->plan->fresh()->getRawOriginal());
        $this->assertSame($operations, DB::table('content_operations')->count());
    }

    public function test_processed_asset_preview_requires_staff_permission_even_for_range_and_never_serves_source(): void
    {
        $a = $this->readyAsset();
        $url = '/api/v1/admin/content/assets/'.$a->id.'/preview';
        $this->get($url, ['Range' => 'bytes=10-19'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 10-19/1024')->assertHeader('Cache-Control', 'no-store, private');
        $this->get($url.'/source.mp4')->assertNotFound();
        $a->update(['state' => 'canceled']);
        $this->get($url)->assertNotFound();
        $a->update(['state' => 'ready']);
        $this->asStaff($this->makeStaff(Role::Support));
        $this->get($url, ['Range' => 'bytes=10-19'])->assertForbidden();
    }

    public function test_global_settings_page_is_owner_only_and_list_does_not_leak_media_paths(): void
    {
        $d = $this->fixture(2);
        $this->get('/admin/content-settings')->assertOk()->assertSee('查看影响并确认');
        $this->getJson('/api/v1/admin/content')->assertOk()->assertJsonPath('dramas.0.ready_count', 2)
            ->assertDontSee('output_key')->assertDontSee('source_key');
        $this->asStaff($this->makeStaff(Role::ContentOperator));
        $this->get('/admin/content-settings')->assertForbidden();
        $this->postJson('/api/v1/admin/content/default-preview', ['free_count' => 9, 'scope' => 'sync'])->assertForbidden();
        $this->get('/admin/content-library')->assertOk();
    }
}
