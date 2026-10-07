<?php

namespace App\Modules\Acquisition\Services;

use App\Modules\Acquisition\Sending\Credentials;
use App\Modules\Acquisition\Sending\DeliveryGate;
use App\Modules\Acquisition\Sending\DeliveryResult;
use App\Modules\Acquisition\Sending\LocalSimulator;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Sending\PlatformAdapter;
use App\Modules\Acquisition\Sending\TikTokAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliveryWorker
{
    public function run(int $limit = 50): int
    {
        $count = 0;
        $ids = DB::table('acquisition_deliveries')->where(function ($q) {
            $q->where(function ($q) {
                $q->whereIn('state', ['pending', 'retry'])->where('next_attempt_at', '<=', now());
            })
                ->orWhere(function ($q) {
                    $q->where('state', 'sending')->where('lease_until', '<=', now());
                });
        })->orderBy('created_at')->limit(max(1, min($limit, 200)))->pluck('id');
        foreach ($ids as $id) {
            if ($this->deliver($id)) {
                $count++;
            }
        }

        return $count;
    }

    public function deliver(string $id): bool
    {
        $row = $this->claim($id);
        if (! $row) {
            return false;
        }
        $reserved = false;
        try {
            [$receipt, $source, $pixel, $context] = $this->context($row);
            $reason = app(DeliveryGate::class)->reason($row, $receipt, $source, $pixel, $context);
            if ($reason) {
                return $this->finish($row, new DeliveryResult('blocked', $reason));
            }
            $mode = config('acquisition.transport');
            $credential = $mode === 'http' ? app(Credentials::class)->forDelivery($row) : null;
            if ($mode === 'http' && ! $credential) {
                return $this->finish($row, new DeliveryResult('blocked', 'CREDENTIALS_REQUIRED'));
            }
            $adapter = $this->adapter($row->platform);
            // Preparation is outside any transaction. A paused preparer may lose its lease.
            $draft = $row->payload_encrypted ? null : $adapter->payload($row, $receipt, $context,
                $mode === 'local_simulation' ? 'LOCAL_SIMULATION' : ($row->environment === 'test' ? $credential['test_event_code'] : null));
            $reservation = $this->reserveAttempt($row, $draft, $mode);
            if ($reservation === null) {
                return false;
            }
            if ($reservation instanceof DeliveryResult) {
                return $this->finish($row, $reservation);
            }
            [$row, $payload] = $reservation;
            $reserved = true;
            // No refund: dying after reservation, including before I/O, consumes the budget.
            if (! $this->ownsLease(DB::table('acquisition_deliveries')->where('id', $id)->first(), $row->lease_token)) {
                return false;
            }
            $result = $mode === 'local_simulation'
                ? app(LocalSimulator::class)->send($row, $payload)
                : $adapter->send($row, $payload, $credential['token']);
        } catch (Throwable) {
            // Persist only a safe reason, never a payload, URL, token or exception message.
            $result = new DeliveryResult($reserved ? 'retry' : 'blocked', $reserved ? 'TRANSPORT_UNCERTAIN' : 'DELIVERY_CONTEXT_INVALID');
        }

        return $this->finish($row, $result);
    }

    private function claim(string $id): ?object
    {
        return DB::transaction(function () use ($id) {
            $row = DB::table('acquisition_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || in_array($row->state, ['simulated', 'api_accepted', 'failed', 'blocked'], true)
                || ($row->state === 'sending' && CarbonImmutable::parse($row->lease_until)->greaterThan(now()))
                || ($row->state !== 'sending' && $row->next_attempt_at && CarbonImmutable::parse($row->next_attempt_at)->greaterThan(now()))) {
                return null;
            }
            if ($row->attempts >= config('acquisition.max_attempts')) {
                DB::table('acquisition_deliveries')->where('id', $id)->update(['state' => 'failed', 'last_error' => 'RETRY_EXHAUSTED',
                    'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => null, 'updated_at' => now()]);

                return null;
            }
            $row->state = 'sending';
            $row->lease_token = AcquisitionStore::id();
            $row->lease_until = now()->addSeconds(60);
            DB::table('acquisition_deliveries')->where('id', $id)->update(['state' => $row->state, 'lease_token' => $row->lease_token,
                'lease_until' => $row->lease_until, 'updated_at' => now()]);

            return $row;
        });
    }

    private function adapter(string $platform): PlatformAdapter
    {
        return match ($platform) {
            'meta' => app(MetaAdapter::class), 'tiktok' => app(TikTokAdapter::class)
        };
    }

    private function context(object $row): array
    {
        $receipt = DB::table('acquisition_receipts')->where('event_id', $row->receipt_id)->first();
        $source = DB::table('acquisition_sources')->where('id', $row->source_id)->first();
        $pixel = DB::table('acquisition_pixels')->where('id', $row->pixel_id)->first();
        $context = $source?->context_encrypted ? json_decode(Crypt::decryptString($source->context_encrypted), true, flags: JSON_THROW_ON_ERROR) : [];

        return [$receipt, $source, $pixel, $context];
    }

    private function ownsLease(?object $row, string $token): bool
    {
        return $row && $row->state === 'sending' && $row->lease_token === $token
            && $row->lease_until && CarbonImmutable::parse($row->lease_until)->greaterThan(now());
    }

    /** @return array{object, array}|DeliveryResult|null Null means ownership was lost. */
    private function reserveAttempt(object $claim, ?array $draft, string $mode): array|DeliveryResult|null
    {
        return DB::transaction(function () use ($claim, $draft, $mode) {
            $row = DB::table('acquisition_deliveries')->where('id', $claim->id)->lockForUpdate()->first();
            // Inspect the persisted row after acquiring the lock, not the stale claim snapshot.
            if (! $this->ownsLease($row, $claim->lease_token)) {
                return null;
            }
            if ($row->attempts >= config('acquisition.max_attempts')) {
                return new DeliveryResult('failed', 'RETRY_EXHAUSTED');
            }
            if (($row->attempts > 0 && ! $row->first_attempted_at)
                || ($row->first_attempted_at && CarbonImmutable::parse($row->first_attempted_at)->addDay()->lessThanOrEqualTo(now()))) {
                return new DeliveryResult('blocked', 'RETRY_WINDOW_EXPIRED');
            }
            [$receipt, $source, $pixel, $context] = $this->context($row);
            $reason = $mode === config('acquisition.transport')
                ? app(DeliveryGate::class)->reason($row, $receipt, $source, $pixel, $context) : 'DELIVERY_CONFIGURATION_CHANGED';
            if ($reason) {
                return new DeliveryResult('blocked', $reason);
            }
            $payload = $row->payload_encrypted
                ? json_decode(Crypt::decryptString($row->payload_encrypted), true, flags: JSON_THROW_ON_ERROR)
                : $draft;
            if (! is_array($payload)) {
                return new DeliveryResult('blocked', 'DELIVERY_CONTEXT_INVALID');
            }
            $time = now();
            $row->payload_encrypted ??= Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
            $row->first_attempted_at ??= $time;
            $row->attempts++;
            $row->lease_until = $time->copy()->addSeconds(60)->min(CarbonImmutable::parse($row->first_attempted_at)->addDay());
            // Payload, immutable first time and budget reservation commit together before I/O.
            $updated = DB::table('acquisition_deliveries')->where('id', $row->id)->where('state', 'sending')
                ->where('lease_token', $claim->lease_token)->where('lease_until', '>', $time)->update([
                    'payload_encrypted' => $row->payload_encrypted, 'first_attempted_at' => $row->first_attempted_at,
                    'attempts' => $row->attempts, 'lease_until' => $row->lease_until, 'updated_at' => $time,
                ]);

            return $updated === 1 ? [$row, $payload] : null;
        });
    }

    private function finish(object $claim, DeliveryResult $result): bool
    {
        return DB::transaction(function () use ($claim, $result) {
            $row = DB::table('acquisition_deliveries')->where('id', $claim->id)->lockForUpdate()->first();
            if (! $this->ownsLease($row, $claim->lease_token)) {
                return false;
            }
            $state = $result->state === 'retry' && $row->attempts >= config('acquisition.max_attempts') ? 'failed' : $result->state;

            return DB::table('acquisition_deliveries')->where('id', $row->id)->where('lease_token', $claim->lease_token)->update([
                // Budget is never changed here: it was durably reserved before the call.
                'state' => $state, 'last_error' => $result->error,
                'next_attempt_at' => $state === 'retry' ? now()->addSeconds(min(3600, 60 * (2 ** max(0, $row->attempts - 1)))) : null,
                'accepted_at' => in_array($state, ['simulated', 'api_accepted'], true) ? now() : null,
                'lease_token' => null, 'lease_until' => null, 'updated_at' => now(),
            ]) === 1;
        });

    }
}
