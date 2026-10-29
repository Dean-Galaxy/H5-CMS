<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Contracts\BehaviorEventRecorder;
use App\Enums\BehaviorRecordingResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class BillingBehaviorOutbox
{
    public function dispatchPending(int $limit = 100): int
    {
        $completed = 0;
        $rows = DB::table('billing_behavior_outbox')->where('state', 'pending')
            ->whereNotExists(fn ($prior) => $prior->selectRaw('1')->from('billing_behavior_outbox as prior')
                ->whereColumn('prior.aggregate_key', 'billing_behavior_outbox.aggregate_key')
                ->whereColumn('prior.fact_version', '<', 'billing_behavior_outbox.fact_version')->where('prior.state', 'pending'))
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderByRaw('last_attempted_at ASC NULLS FIRST')->orderBy('occurred_at')->orderBy('fact_version')
            ->limit(max(1, min($limit, 1000)))->pluck('id');
        foreach ($rows as $id) {
            $completed += DB::transaction(function () use ($id) {
                $row = DB::table('billing_behavior_outbox')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->state !== 'pending' || ($row->next_attempt_at !== null && now()->lt($row->next_attempt_at))) {
                    return 0;
                }
                // Preserve each aggregate's order even if a prior version is waiting for retry.
                if (DB::table('billing_behavior_outbox')->where('aggregate_key', $row->aggregate_key)
                    ->where('fact_version', '<', $row->fact_version)->where('state', 'pending')->exists()) {
                    return 0;
                }
                try {
                    $result = DB::transaction(function () use ($row) {
                        $result = app(BehaviorEventRecorder::class)->recordFact($row->event_name, BillingStore::json($row->properties), 'billing:'.$row->id);
                        if ($result === BehaviorRecordingResult::Retry) {
                            throw new RuntimeException('BEHAVIOR_RETRY');
                        }

                        return $result;
                    });
                    DB::table('billing_behavior_outbox')->where('id', $row->id)->update([
                        'state' => $result === BehaviorRecordingResult::Accepted ? 'delivered' : 'skipped',
                        'attempts' => $row->attempts + 1, 'last_error' => $result === BehaviorRecordingResult::Skipped ? 'NOT_TRACKABLE' : null,
                        'last_attempted_at' => now(), 'next_attempt_at' => null, 'completed_at' => now(),
                    ]);

                    return 1;
                } catch (Throwable) {
                    // Retry forever at a bounded rate; retain the reliable fact, never expose exception/PII.
                    $seconds = min(86400, 60 * (2 ** min($row->attempts, 11)));
                    DB::table('billing_behavior_outbox')->where('id', $row->id)->update([
                        'attempts' => $row->attempts + 1, 'last_error' => 'RECORDER_RETRY_REQUIRED',
                        'last_attempted_at' => now(), 'next_attempt_at' => now()->addSeconds($seconds),
                    ]);

                    return 0;
                }
            });
        }

        return $completed;
    }
}
