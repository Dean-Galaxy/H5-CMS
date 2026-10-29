<?php

namespace App\Contracts;

use App\Enums\BehaviorRecordingResult;

interface BehaviorEventRecorder
{
    /** Trusted server callers only. False means disabled, not consented, invalid, or temporarily unavailable.
     * Never throw into registration, playback, or billing. Keys are stable for retried facts.
     * Property allowlists and browser/server event separation: docs/contracts/analytics-v1.md.
     */
    public function record(string $eventName, array $properties = [], ?string $deduplicationKey = null): bool;

    /** Reliable server facts: Accepted includes identical replay; Skipped is final (no consent/invalid);
     * Retry retains the producer outbox item (consumer unavailable/temporary fault).
     */
    public function recordFact(string $eventName, array $properties, string $deduplicationKey): BehaviorRecordingResult;

    /** Call after recording logout, before invalidating the session. Rotate browser subject; clear attribution. */
    public function clearBrowserContext(): void;
}
