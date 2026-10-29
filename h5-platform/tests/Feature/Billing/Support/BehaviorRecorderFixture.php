<?php

namespace Tests\Feature\Billing\Support;

use App\Contracts\BehaviorEventRecorder;
use App\Enums\BehaviorRecordingResult;
use Illuminate\Support\Facades\DB;

/** Explicit local consumer fixture; no analytics consent or platform claim. */
final class BehaviorRecorderFixture implements BehaviorEventRecorder
{
    public string $mode = 'accepted';

    public array $calls = [];

    public function record(string $eventName, array $properties = [], ?string $deduplicationKey = null): bool
    {
        return false;
    }

    public function recordFact(string $eventName, array $properties, string $deduplicationKey): BehaviorRecordingResult
    {
        $this->calls[] = [$eventName, $properties, $deduplicationKey];
        if ($this->mode === 'skipped') {
            return BehaviorRecordingResult::Skipped;
        }
        if ($this->mode === 'sql_failure') {
            DB::statement('SELECT * FROM billing_fixture_missing_table');
        }
        DB::table('cache')->insertOrIgnore(['key' => $deduplicationKey,
            'value' => json_encode([$eventName, $properties], JSON_THROW_ON_ERROR), 'expiration' => now()->addDay()->timestamp]);

        return $this->mode === 'retry_after_write' ? BehaviorRecordingResult::Retry : BehaviorRecordingResult::Accepted;
    }

    public function clearBrowserContext(): void {}
}
