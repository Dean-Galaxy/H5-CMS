<?php

namespace Tests\Feature\Audience;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Actual concurrent first writes on independent PHP and PostgreSQL connections. Run serially. */
final class ProgressConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_first_writes_keep_newest_session_and_highest_sequence_within_that_session(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        foreach ([false, true] as $sameSession) {
            $viewer = (string) Str::uuid7();
            $drama = (string) Str::uuid7();
            $episode = (string) Str::uuid7();
            $older = (string) Str::uuid7();
            $newer = $sameSession ? $older : (string) Str::uuid7();
            foreach (array_unique([$older, $newer]) as $session) {
                DB::table('audience_playback_sessions')->insert([
                    'viewer_id' => $viewer, 'drama_id' => $drama, 'episode_id' => $episode,
                    'play_session_id' => $session, 'expires_at' => now()->addMinutes(5), 'created_at' => now(),
                ]);
            }
            $common = ['viewer_id' => $viewer, 'drama_id' => $drama, 'episode_id' => $episode];
            $actions = [
                [...$common, 'session' => $older, 'sequence' => $sameSession ? 2 : 999, 'position' => 80],
                [...$common, 'session' => $newer, 'sequence' => $sameSession ? 3 : 1, 'position' => 7],
            ];
            $this->race($actions);
            $record = DB::table('audience_progress')->where('viewer_id', $viewer)->where('drama_id', $drama)->first();
            $this->assertSame($newer, $record->play_session_id);
            $this->assertSame(7, $record->position_seconds);
            $this->assertSame($sameSession ? 3 : 1, $record->sequence);
            $this->assertSame(1, DB::table('audience_progress')->where('viewer_id', $viewer)->count());
        }
    }

    private function race(array $actions): void
    {
        $processes = [];
        $report = ['database' => DB::connection()->getDatabaseName(), 'workers' => [], 'lock_observed' => false];
        $locked = false;
        $inspect = function () use (&$processes, &$report): void {
            foreach ($processes as $i => $process) {
                foreach (explode("\n", trim($process->getOutput())) as $line) {
                    $event = json_decode($line, true);
                    if (is_array($event)) {
                        $report['workers'][$i][$event['phase']] = $event;
                    }
                }
                $report['workers'][$i]['running'] = $process->isRunning();
                $report['workers'][$i]['stderr'] = $process->getErrorOutput();
                $report['workers'][$i]['exit_code'] = $process->getExitCode();
            }
        };
        $wait = function (callable $ready, float $seconds) use ($inspect, &$report): void {
            $deadline = hrtime(true) + (int) ($seconds * 1e9);
            do {
                $inspect();
                foreach ($report['workers'] as $worker) {
                    if (isset($worker['error']) || (! $worker['running'] && (! isset($worker['result']) || $worker['exit_code'] !== 0))) {
                        throw new RuntimeException('Progress race worker failed: '.json_encode($worker));
                    }
                }
                if ($ready()) {
                    return;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline);
            throw new RuntimeException('Progress race timed out: '.json_encode($report));
        };
        try {
            DB::beginTransaction();
            $locked = true;
            // There is deliberately no progress row yet. Both workers must reach the first INSERT.
            DB::statement('LOCK TABLE audience_progress IN ACCESS EXCLUSIVE MODE');
            $parentPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
            $report['parent_pid'] = $parentPid;
            foreach ($actions as $action) {
                $process = new Process([PHP_BINARY, __DIR__.'/ProgressSupport/save-worker.php', base64_encode(json_encode($action, JSON_THROW_ON_ERROR))], base_path());
                $process->setTimeout(null);
                $process->start();
                $processes[] = $process;
            }
            $wait(function () use (&$report): bool {
                return count(array_filter($report['workers'], fn ($w) => isset($w['ready']))) === 2;
            }, 30);
            $pids = array_map(fn ($worker) => $worker['ready']['pid'], $report['workers']);
            $this->assertCount(3, array_unique([$parentPid, ...$pids]));
            $wait(function () use ($pids, &$report): bool {
                DB::select('select pg_stat_clear_snapshot()');
                $locks = DB::select('select pid, wait_event_type, pg_blocking_pids(pid)::text as blockers from pg_stat_activity where pid in (?, ?)', $pids);
                $report['locks'] = $locks;
                $report['lock_observed'] = count($locks) === 2
                    && count(array_filter($locks, fn ($lock) => $lock->wait_event_type === 'Lock' && $lock->blockers !== '{}')) === 2;

                return $report['lock_observed'];
            }, 10);
            DB::rollBack();
            $locked = false;
            $wait(function () use (&$report): bool {
                return count(array_filter($report['workers'], fn ($w) => ! $w['running'] && isset($w['result']))) === 2;
            }, 20);
            $this->assertTrue($report['lock_observed']);
            $report['outcome'] = 'completed';
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.2);
                }
            }
            if ($locked) {
                DB::rollBack();
            }
            $inspect();
            $report['children_stopped'] = count(array_filter($report['workers'], fn ($w) => $w['running'])) === 0;
            $directory = base_path('../.runtime/audience-progress-races');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/'.Str::uuid7().'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            $this->assertTrue($report['children_stopped']);
            $this->assertSame(0, DB::transactionLevel());
        }
    }
}
