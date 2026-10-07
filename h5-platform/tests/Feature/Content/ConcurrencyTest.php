<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Models\Publication;
use App\Modules\Content\Services\PublicationService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use RuntimeException;
use Tests\Feature\Content\Support\PublicationRace;

final class ConcurrencyTest extends ContentTestCase
{
    // Separate PostgreSQL connections must see committed fixtures. The shared TestCase
    // already refuses every database except the isolated *_test selected by dev.py.
    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
    }

    public function test_separate_processes_cannot_publish_an_occurrence_twice(): void
    {
        $d = $this->fixture(20);
        $p = $d->plan;
        $results = $this->race($d, ['scheduled', 'scheduled']);
        sort($results);
        $this->assertSame(['duplicate', 'published'], $results);
        $this->assertSame(10, $d->episodes()->whereNotNull('published_at')->count());
        $this->assertSame(1, Publication::where('plan_id', $p->id)->count());
    }

    public function test_manual_publish_edit_and_withdraw_serialize_with_scheduler(): void
    {
        foreach (['manual', 'edit', 'withdraw'] as $action) {
            $d = $this->fixture(20);
            $p = $d->plan;
            $results = $this->race($d, ['scheduled', $action]);
            $this->assertCount(2, $results);
            $numbers = Publication::where('drama_id', $d->id)->get()->flatMap->episode_numbers->all();
            $this->assertSame(count($numbers), count(array_unique($numbers)), 'No double publication in '.$action.' race');
            $this->assertContains($d->episodes()->whereNotNull('published_at')->count(), [0, 3, 10]);
            // If the scheduler won, staff sees STALE_VERSION and must explicitly refresh.
            if (in_array('STALE_VERSION', $results, true)) {
                $this->assertSame('published', $results[0]);
            }
            if ($action === 'withdraw' && ! in_array('STALE_VERSION', $results, true)) {
                $this->assertSame('withdrawn', $d->fresh()->state);
                $this->assertSame('disabled', $p->fresh()->state);
            }
            if ($action === 'edit' && $results[1] === 'ok') {
                $this->assertSame('draft', $p->fresh()->state);
            }
            if ($action === 'edit' && $results[1] === 'RANGE_CHANGED') {
                $this->assertSame('published', $results[0]);
            }
        }
    }

    public function test_slow_startup_has_its_own_budget_and_still_contends_on_real_locks(): void
    {
        $d = $this->fixture(20);
        $runner = new PublicationRace;
        $results = $runner->run($this->payload($d), ['scheduled', 'scheduled'], ['startup_delay_ms' => 12000]);
        sort($results);
        $this->assertSame(['duplicate', 'published'], $results);
        $this->assertGreaterThanOrEqual(12, $runner->report['startup_seconds']);
        $this->assertLessThan(20, $runner->report['execution_seconds']);
        $this->assertTrue($runner->report['lock_observed']);
        $this->assertTrue($runner->report['children_stopped']);
        $this->assertSame(10, $d->episodes()->whereNotNull('published_at')->count());
        $this->assertSame(1, Publication::where('plan_id', $d->plan->id)->count());
    }

    public function test_failed_startup_reports_the_phase_and_cleans_children_before_unlocking(): void
    {
        foreach (['timeout', 'exit'] as $failure) {
            $d = $this->fixture(20);
            $runner = new PublicationRace;
            $options = $failure === 'timeout'
                ? ['startup_delay_ms' => 2000, 'budgets' => ['startup' => 0.4]]
                : ['fail_worker' => 0];
            try {
                $runner->run($this->payload($d), ['scheduled', 'scheduled'], $options);
                $this->fail('The injected startup failure must fail the race');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Concurrency startup:', $e->getMessage());
                $this->assertStringContainsString($failure === 'timeout' ? 'Deadline exceeded' : 'Injected startup failure', $e->getMessage());
            }
            $this->assertSame('startup', $runner->report['failure_stage']);
            $this->assertTrue($runner->report['children_stopped']);
            $this->assertSame(0, $runner->report['parent_transaction_level']);
            $this->assertSame(0, $d->episodes()->whereNotNull('published_at')->count());
            $this->assertSame(0, Publication::where('drama_id', $d->id)->count());
        }
    }

    private function payload($drama): array
    {
        $p = $drama->plan;
        $preview = app(PublicationService::class)->preview($drama->id, 3);

        return ['drama' => $drama->id, 'owner' => $this->owner->id,
            'version' => $drama->version, 'plan' => $p->id, 'plan_version' => $p->version,
            'at' => $p->next_at->toIso8601String(), 'media_root' => $this->mediaRoot,
            'confirmation' => $preview['confirmation']];
    }

    private function race($drama, array $actions): array
    {
        $runner = new PublicationRace;
        $results = $runner->run($this->payload($drama), $actions);
        $this->assertTrue($runner->report['lock_observed']);
        $this->assertTrue($runner->report['children_stopped']);
        $this->assertSame(0, $runner->report['parent_transaction_level']);

        return $results;
    }
}
