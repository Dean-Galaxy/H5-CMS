<?php

namespace Tests\Feature\Content\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Two real PHP/PostgreSQL connections; all fixtures must already be committed. */
final class PublicationRace
{
    public array $report = [];

    public function run(array $payload, array $actions, array $options = []): array
    {
        $budgets = array_replace(['startup' => 60.0, 'contention' => 10.0, 'execution' => 20.0], $options['budgets'] ?? []);
        $directory = base_path('../.runtime/content-races');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.bin2hex(random_bytes(12)).'.json';
        $processes = [];
        $locked = false;
        $started = hrtime(true);
        $stage = 'startup';
        $this->report = ['actions' => $actions, 'budgets_seconds' => $budgets, 'workers' => [], 'path' => $path,
            'app_base' => base_path(), 'database' => DB::connection()->getDatabaseName(), 'lock_observed' => false];
        $inspect = function () use (&$processes, $started): void {
            foreach ($processes as $i => $process) {
                $events = [];
                foreach (explode("\n", trim($process->getOutput())) as $line) {
                    $event = json_decode($line, true);
                    if (is_array($event)) {
                        $events[] = $event;
                    }
                }
                $previous = $this->report['workers'][$i] ?? [];
                $this->report['workers'][$i] = array_replace($previous, [
                    'events' => $events, 'stderr' => $process->getErrorOutput(),
                    'stdout' => $process->getOutput(), 'running' => $process->isRunning(), 'exit_code' => $process->getExitCode(),
                ]);
                foreach ($events as $event) {
                    if ($event['phase'] === 'ready') {
                        $this->report['workers'][$i]['pg_pid'] = $event['pg_pid'];
                        $this->report['workers'][$i]['ready_after_seconds'] ??= (hrtime(true) - $started) / 1e9;
                    }
                    if ($event['phase'] === 'result') {
                        $this->report['workers'][$i]['result'] = $event['result'];
                    }
                    if ($event['phase'] === 'error') {
                        $this->report['workers'][$i]['error'] = $event['message'];
                    }
                }
            }
        };
        $wait = function (callable $done, float $seconds) use ($inspect): void {
            $deadline = hrtime(true) + (int) ($seconds * 1e9);
            do {
                $inspect();
                foreach ($this->report['workers'] as $worker) {
                    if (isset($worker['error']) || (! $worker['running'] && (! array_key_exists('result', $worker) || $worker['exit_code'] !== 0))) {
                        throw new RuntimeException('Worker exited or failed before a valid result: '.($worker['error'] ?? 'exit '.var_export($worker['exit_code'], true)));
                    }
                }
                if ($done()) {
                    return;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline);
            throw new RuntimeException('Deadline exceeded after '.$seconds.' seconds');
        };
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('content_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $parentPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
            $this->report['parent_pg_pid'] = $parentPid;
            foreach ($actions as $i => $action) {
                $input = [...$payload, 'action' => $action, 'startup_delay_ms' => $options['startup_delay_ms'] ?? 0,
                    'fail_startup' => ($options['fail_worker'] ?? null) === $i];
                $process = new Process([PHP_BINARY, __DIR__.'/publication-worker.php', base64_encode(json_encode($input))], base_path());
                // A process's timeout normally includes bootstrap. Each stage instead has
                // its own monotonic deadline, so cold boot cannot consume execution time.
                $process->setTimeout(null);
                $process->start();
                $processes[] = $process;
            }
            $wait(fn () => count(array_filter($this->report['workers'], fn ($w) => isset($w['pg_pid']))) === count($actions), $budgets['startup']);
            $this->report['startup_seconds'] = (hrtime(true) - $started) / 1e9;
            $pids = array_column($this->report['workers'], 'pg_pid');
            if (count(array_unique([...$pids, $parentPid])) !== count($actions) + 1) {
                throw new RuntimeException('Workers must have separate PostgreSQL backends');
            }
            $stage = 'contention';
            $wait(function () use ($pids): bool {
                DB::select('select pg_stat_clear_snapshot()');
                $marks = implode(',', array_fill(0, count($pids), '?'));
                $locks = DB::select('select pid, wait_event_type, wait_event, pg_blocking_pids(pid)::text as blockers from pg_stat_activity where pid in ('.$marks.')', $pids);
                $this->report['locks'] = $locks;
                $waiting = count($locks) === count($pids) && count(array_filter($locks, fn ($l) => $l->wait_event_type === 'Lock' && $l->blockers !== '{}')) === count($pids);
                $this->report['lock_observed'] = $waiting;

                return $waiting;
            }, $budgets['contention']);
            DB::rollBack(); // Only the parent's observation lock; no parent data changes.
            $locked = false;
            $stage = 'execution';
            $executionStart = hrtime(true);
            $wait(fn () => count(array_filter($this->report['workers'], fn ($w) => ! $w['running'] && array_key_exists('result', $w))) === count($actions), $budgets['execution']);
            $this->report['execution_seconds'] = (hrtime(true) - $executionStart) / 1e9;
            $this->report['results'] = array_column($this->report['workers'], 'result');
            $this->report['outcome'] = 'completed';

            return $this->report['results'];
        } catch (Throwable $e) {
            $this->report['outcome'] = 'failed';
            $this->report['failure_stage'] = $stage;
            $this->report['failure'] = $e->getMessage();
            $inspect();
            throw new RuntimeException('Concurrency '.$stage.': '.$e->getMessage().'; report='.$path.'; workers='.json_encode($this->report['workers']), previous: $e);
        } finally {
            // Stop children BEFORE unlocking on failure. They must never resume and
            // write to a later test's fixtures after this test has already failed.
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.2);
                }
            }
            if ($locked) {
                DB::rollBack();
            }
            $inspect();
            $this->report['children_stopped'] = count(array_filter($this->report['workers'], fn ($w) => $w['running'])) === 0;
            $this->report['parent_transaction_level'] = DB::transactionLevel();
            $this->report['total_seconds'] = (hrtime(true) - $started) / 1e9;
            file_put_contents($path, json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
    }
}
