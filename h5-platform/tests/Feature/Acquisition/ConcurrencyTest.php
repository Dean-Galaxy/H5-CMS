<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Services\AcquisitionStore;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_postgresql_connections_contend_and_record_one_payment_even_with_different_event_ids(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $event = ['event_id' => AcquisitionStore::id(), 'schema_version' => 'PaymentSettled.v1', 'payment_id' => AcquisitionStore::id(),
            'order_id' => AcquisitionStore::id(), 'subscription_id' => AcquisitionStore::id(), 'transaction_kind' => 'first_subscription',
            'discount_applied' => true, 'amount_minor' => 300, 'currency' => 'USD', 'occurred_at' => now()->toIso8601String(),
            'is_test' => true, 'attribution_snapshot' => null];
        $processes = [];
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.payment:'.$event['payment_id']]);
        try {
            foreach ([1, 2] as $n) {
                $event['event_id'] = AcquisitionStore::id();
                $p = new Process([PHP_BINARY, __DIR__.'/Support/receipt-worker.php', base64_encode(json_encode($event, JSON_THROW_ON_ERROR))], base_path());
                $p->setTimeout(20);
                $p->start();
                $processes[] = $p;
            }
            $deadline = microtime(true) + 15;
            $locked = false;
            do {
                $pids = [];
                foreach ($processes as $process) {
                    $line = explode("\n", trim($process->getOutput()))[0];
                    $pid = json_decode($line, true)['pid'] ?? null;
                    if ($pid) {
                        $pids[] = $pid;
                    }
                    if (! $process->isRunning()) {
                        $this->fail('Worker exited early: '.$process->getErrorOutput());
                    }
                }
                if (count($pids) === 2) {
                    DB::select('select pg_stat_clear_snapshot()');
                    $waits = DB::select('select pid, wait_event_type from pg_stat_activity where pid in (?, ?)', $pids);
                    $locked = count(array_filter($waits, fn ($r) => $r->wait_event_type === 'Lock')) === 2;
                }
                if (! $locked) {
                    usleep(10000);
                }
            } while (! $locked && microtime(true) < $deadline);
            $this->assertTrue($locked, 'Both workers must actually contend on PostgreSQL.');
            $this->assertCount(2, array_unique($pids));
            DB::rollBack();
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $this->assertStringContainsString('received', $process->getOutput());
            }
            $this->assertDatabaseCount('acquisition_receipts', 1);
            $this->assertDatabaseCount('acquisition_event_ids', 2);
            $this->assertSame(300, (int) DB::table('acquisition_receipts')->sum('amount_minor'));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.2);
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
}
