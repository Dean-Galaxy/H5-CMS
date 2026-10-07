<?php

namespace Tests\Feature\Billing\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Real processes sharing committed fixtures in this worktree's isolated test DB. */
final class BillingRace
{
    public array $report = [];

    public function run(string $viewerId, array $fixture, array $actions): array
    {
        if (! app()->environment('testing') || DB::transactionLevel() !== 0
            || DB::connection()->getDatabaseName() !== getenv('H5_TEST_DATABASE')
            || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            throw new RuntimeException('Billing races require committed fixtures in the isolated test database.');
        }
        $directory = base_path('../.runtime/billing-races');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $id = bin2hex(random_bytes(12));
        $fixturePath = $directory.'/'.$id.'-fixture.json';
        $reportPath = $directory.'/'.$id.'-report.json';
        file_put_contents($fixturePath, json_encode($fixture, JSON_THROW_ON_ERROR));
        chmod($fixturePath, 0600);
        $this->report = [
            'report_path' => $reportPath, 'fixture_path' => $fixturePath,
            'database' => DB::connection()->getDatabaseName(), 'app_base' => base_path(),
            'actions' => $actions, 'workers' => [], 'lock_observed' => false,
        ];
        $processes = [];
        $locked = false;
        $stage = 'startup';
        $started = hrtime(true);
        $inspect = function () use (&$processes): void {
            foreach ($processes as $index => $process) {
                $worker = $this->report['workers'][$index] ?? [];
                $events = [];
                foreach (explode("\n", trim($process->getOutput())) as $line) {
                    $event = json_decode($line, true);
                    if (! is_array($event)) {
                        continue;
                    }
                    $events[] = $event;
                    if (($event['phase'] ?? '') === 'ready') {
                        $worker['pg_pid'] = $event['pg_pid'];
                    }
                    if (($event['phase'] ?? '') === 'result') {
                        $worker['result'] = $event['result'];
                    }
                    if (($event['phase'] ?? '') === 'error') {
                        $worker['error'] = $event['message'];
                    }
                }
                $this->report['workers'][$index] = [...$worker,
                    'events' => $events, 'stderr' => $process->getErrorOutput(),
                    'running' => $process->isRunning(), 'exit_code' => $process->getExitCode(),
                ];
            }
        };
        $wait = function (callable $done, float $seconds) use ($inspect): void {
            $deadline = hrtime(true) + (int) ($seconds * 1e9);
            do {
                $inspect();
                foreach ($this->report['workers'] as $worker) {
                    if (isset($worker['error']) || (! $worker['running']
                        && (! array_key_exists('result', $worker) || $worker['exit_code'] !== 0))) {
                        throw new RuntimeException('Worker failed: '.($worker['error'] ?? 'unexpected exit'));
                    }
                }
                if ($done()) {
                    return;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline);
            throw new RuntimeException('Race stage exceeded '.$seconds.' seconds');
        };
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('billing_accounts')->where('viewer_id', $viewerId)->lockForUpdate()->firstOrFail();
            $parentPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
            $this->report['parent_pg_pid'] = $parentPid;
            foreach ($actions as $action) {
                $input = ['viewer_id' => $viewerId, 'fixture_path' => $fixturePath, ...$action];
                $process = new Process([PHP_BINARY, __DIR__.'/billing-worker.php', base64_encode(json_encode($input, JSON_THROW_ON_ERROR))], base_path());
                $process->setTimeout(null);
                $process->start();
                $processes[] = $process;
            }
            $wait(fn () => count(array_filter($this->report['workers'], fn ($worker) => isset($worker['pg_pid']))) === count($actions), 60);
            $pids = array_column($this->report['workers'], 'pg_pid');
            if (count(array_unique([...$pids, $parentPid])) !== count($actions) + 1) {
                throw new RuntimeException('Each worker must have a separate PostgreSQL backend.');
            }
            $stage = 'contention';
            $wait(function () use ($pids): bool {
                DB::select('select pg_stat_clear_snapshot()');
                $marks = implode(',', array_fill(0, count($pids), '?'));
                $locks = DB::select('select pid, wait_event_type, wait_event, pg_blocking_pids(pid)::text as blockers from pg_stat_activity where pid in ('.$marks.')', $pids);
                $this->report['blocked_waiters'] = $locks;
                $blocked = count($locks) === count($pids)
                    && count(array_filter($locks, fn ($lock) => $lock->wait_event_type === 'Lock' && $lock->blockers !== '{}')) === count($pids);
                $this->report['lock_observed'] = $blocked;

                return $blocked;
            }, 10);
            // Both independent workers have actually attempted the account lock.
            // Only now remove the parent's observation lock and permit execution.
            $this->report['released_after_seconds'] = (hrtime(true) - $started) / 1e9;
            DB::rollBack();
            $locked = false;
            $stage = 'execution';
            $wait(fn () => count(array_filter($this->report['workers'], fn ($worker) => ! $worker['running'] && isset($worker['result']))) === count($actions), 30);
            $this->report['outcome'] = 'completed';
            $this->report['results'] = array_column($this->report['workers'], 'result');

            return $this->report['results'];
        } catch (Throwable $error) {
            $this->report['outcome'] = 'failed';
            $this->report['failure_stage'] = $stage;
            $this->report['failure'] = $error->getMessage();
            throw new RuntimeException('Billing race '.$stage.': '.$error->getMessage().'; report='.$reportPath, previous: $error);
        } finally {
            // Never let abandoned children mutate the next test's database.
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.2);
                }
            }
            if ($locked) {
                DB::rollBack();
            }
            $inspect();
            $this->report['children_stopped'] = count(array_filter($this->report['workers'], fn ($worker) => $worker['running'])) === 0;
            $this->report['parent_transaction_level'] = DB::transactionLevel();
            $this->report['elapsed_seconds'] = (hrtime(true) - $started) / 1e9;
            file_put_contents($reportPath, json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            chmod($reportPath, 0600);
        }
    }
}
