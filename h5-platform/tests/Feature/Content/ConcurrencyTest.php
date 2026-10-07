<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Models\Publication;
use App\Modules\Content\Services\PublicationService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

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

    private function race($drama, array $actions): array
    {
        $p = $drama->plan;
        $preview = app(PublicationService::class)->preview($drama->id, 3);
        $processes = [];
        // Hold the same first lock until both children have booted and reached their
        // independent connections. Releasing it lets PostgreSQL serialize the race.
        DB::beginTransaction();
        DB::table('content_settings')->where('id', 1)->lockForUpdate()->first();
        try {
            foreach ($actions as $i => $action) {
                $payload = ['action' => $action, 'drama' => $drama->id, 'owner' => $this->owner->id,
                    'version' => $drama->version, 'plan' => $p->id, 'plan_version' => $p->version,
                    'at' => $p->next_at->toIso8601String(), 'media_root' => $this->mediaRoot,
                    'confirmation' => $preview['confirmation'], 'ready' => $this->mediaRoot.'/ready-'.$i];
                $proc = new Process([PHP_BINARY, __DIR__.'/Support/publication-worker.php', base64_encode(json_encode($payload))], base_path());
                $proc->setTimeout(20);
                $proc->start();
                $processes[] = $proc;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($this->mediaRoot.'/ready-0') || ! is_file($this->mediaRoot.'/ready-1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($this->mediaRoot.'/ready-0', $processes[0]->getErrorOutput().$processes[0]->getOutput().' Exit '.$processes[0]->getExitCode());
            $this->assertFileExists($this->mediaRoot.'/ready-1', $processes[1]->getErrorOutput().$processes[1]->getOutput().' Exit '.$processes[1]->getExitCode());
        } finally {
            DB::commit();
        }
        $results = [];
        foreach ($processes as $proc) {
            $proc->wait();
            $this->assertTrue($proc->isSuccessful(), $proc->getErrorOutput().$proc->getOutput());
            $results[] = json_decode(trim($proc->getOutput()), true, flags: JSON_THROW_ON_ERROR)['result'];
        }
        unlink($this->mediaRoot.'/ready-0');
        unlink($this->mediaRoot.'/ready-1');

        return $results;
    }
}
