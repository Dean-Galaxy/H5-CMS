<?php

namespace App\Contracts;

interface BehaviorEventRecorder
{
    /** Trusted server callers only. False means disabled, not consented, invalid, or temporarily unavailable.
     * Never throw into registration, playback, or billing. Keys are stable for retried facts.
     * Property allowlists and browser/server event separation: docs/contracts/analytics-v1.md.
     */
    public function record(string $eventName, array $properties = [], ?string $deduplicationKey = null): bool;

    /** Call after recording logout, before invalidating the session. Rotate browser subject; clear attribution. */
    public function clearBrowserContext(): void;
}
