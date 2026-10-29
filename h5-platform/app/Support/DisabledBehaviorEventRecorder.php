<?php

namespace App\Support;

use App\Contracts\BehaviorEventRecorder;

final class DisabledBehaviorEventRecorder implements BehaviorEventRecorder
{
    public function record(string $eventName, array $properties = [], ?string $deduplicationKey = null): bool
    {
        return false;
    }

    public function clearBrowserContext(): void {}
}
