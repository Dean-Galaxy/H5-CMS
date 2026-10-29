<?php

namespace App\Support;

use App\Contracts\BehaviorEventRecorder;
use App\Enums\BehaviorRecordingResult;

final class DisabledBehaviorEventRecorder implements BehaviorEventRecorder
{
    public function record(string $eventName, array $properties = [], ?string $deduplicationKey = null): bool
    {
        return false;
    }

    public function clearBrowserContext(): void {}

    public function recordFact(string $eventName, array $properties, string $deduplicationKey): BehaviorRecordingResult
    {
        return BehaviorRecordingResult::Retry;
    }
}
