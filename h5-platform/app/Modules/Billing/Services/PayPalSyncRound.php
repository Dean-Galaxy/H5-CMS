<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PayPal\PayPalReadFailure;
use Throwable;

/** Memoizes full subscription observations only within one command invocation. */
final class PayPalSyncRound
{
    private array $outcomes = [];

    private array $freshFailures = [];

    private array $priorityReads = [];

    public function observe(string $id, callable $sync, bool $priorityEvidence = false): void
    {
        if ($priorityEvidence && ! ($this->priorityReads[$id] ?? false)) {
            // A newly matched funding or lifecycle hint must not reuse a weaker read.
            $this->invalidate($id);
        }
        if (! array_key_exists($id, $this->outcomes)) {
            $this->priorityReads[$id] = $priorityEvidence;
            try {
                $sync();
                $this->outcomes[$id] = null;
            } catch (Throwable $error) {
                $this->outcomes[$id] = $error;
                if (! $error instanceof PayPalReadFailure || ! $error->deferred) {
                    $this->freshFailures[$id] = true;
                }
            }
        }
        if ($this->outcomes[$id] !== null) {
            throw $this->outcomes[$id];
        }
    }

    public function freshFailures(): int
    {
        return count($this->freshFailures);
    }

    public function invalidate(string $id): void
    {
        unset($this->outcomes[$id], $this->priorityReads[$id]);
    }
}
