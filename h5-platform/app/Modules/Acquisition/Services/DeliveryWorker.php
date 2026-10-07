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
        $row = DB::transaction(function () use ($id) {
            $row = DB::table('acquisition_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || in_array($row->state, ['simulated', 'api_accepted', 'failed', 'blocked'], true)
                || ($row->state === 'sending' && CarbonImmutable::parse($row->lease_until)->greaterThan(now()))
                || ($row->state !== 'sending' && $row->next_attempt_at && CarbonImmutable::parse($row->next_attempt_at)->greaterThan(now()))) {
                return null;
            }
            if ($row->attempts >= config('acquisition.max_attempts')) {
                DB::table('acquisition_deliveries')->where('id', $id)->update(['state' => 'failed', 'last_error' => 'RETRY_EXHAUSTED', 'updated_at' => now()]);

                return null;
            }
            $row->lease_token = AcquisitionStore::id();
            DB::table('acquisition_deliveries')->where('id', $id)->update(['state' => 'sending', 'lease_token' => $row->lease_token,
                'lease_until' => now()->addSeconds(60), 'updated_at' => now()]);

            return $row;
        });
        if (! $row) {
            return false;
        }
        $attempted = false;
        try {
            $receipt = DB::table('acquisition_receipts')->where('event_id', $row->receipt_id)->first();
            $source = DB::table('acquisition_sources')->where('id', $row->source_id)->first();
            $pixel = DB::table('acquisition_pixels')->where('id', $row->pixel_id)->first();
            $context = $source?->context_encrypted ? json_decode(Crypt::decryptString($source->context_encrypted), true, flags: JSON_THROW_ON_ERROR) : [];
            $reason = app(DeliveryGate::class)->reason($row, $receipt, $source, $pixel, $context);
            if ($reason) {
                $result = new DeliveryResult('blocked', $reason);
            } elseif (config('acquisition.transport') === 'local_simulation') {
                $adapter = $this->adapter($row->platform);
                $payload = $this->payload($row, $adapter, $receipt, $context, 'LOCAL_SIMULATION');
                $attempted = true;
                $result = app(LocalSimulator::class)->send($row, $payload);
            } else {
                $credential = app(Credentials::class)->forDelivery($row);
                if (! $credential) {
                    $result = new DeliveryResult('blocked', 'CREDENTIALS_REQUIRED');
                } else {
                    $adapter = $this->adapter($row->platform);
                    $payload = $this->payload($row, $adapter, $receipt, $context, $row->environment === 'test' ? $credential['test_event_code'] : null);
                    $attempted = true;
                    $result = $adapter->send($row, $payload, $credential['token']);
                }
            }
        } catch (Throwable) {
            // Never persist response bodies, payloads, request URLs, tokens, or exception messages.
            $result = new DeliveryResult($attempted ? 'retry' : 'blocked', $attempted ? 'TRANSPORT_UNCERTAIN' : 'DELIVERY_CONTEXT_INVALID');
        }
        $attempts = $row->attempts + (int) $attempted;
        $state = $result->state === 'retry' && $attempts >= config('acquisition.max_attempts') ? 'failed' : $result->state;
        DB::table('acquisition_deliveries')->where('id', $id)->where('lease_token', $row->lease_token)->update([
            'state' => $state, 'attempts' => $attempts, 'last_error' => $result->error,
            'next_attempt_at' => $state === 'retry' ? now()->addSeconds(min(3600, 60 * (2 ** max(0, $attempts - 1)))) : null,
            'accepted_at' => in_array($state, ['simulated', 'api_accepted'], true) ? now() : null,
            'lease_token' => null, 'lease_until' => null, 'updated_at' => now(),
        ]);

        return true;
    }

    private function adapter(string $platform): PlatformAdapter
    {
        return match ($platform) {
            'meta' => app(MetaAdapter::class), 'tiktok' => app(TikTokAdapter::class)
        };
    }

    private function payload(object $row, PlatformAdapter $adapter, object $receipt, array $context, ?string $testCode): array
    {
        if ($row->payload_encrypted) {
            return json_decode(Crypt::decryptString($row->payload_encrypted), true, flags: JSON_THROW_ON_ERROR);
        }
        $body = $adapter->payload($row, $receipt, $context, $testCode);
        DB::table('acquisition_deliveries')->where('id', $row->id)->where('lease_token', $row->lease_token)
            ->update(['payload_encrypted' => Crypt::encryptString(json_encode($body, JSON_THROW_ON_ERROR))]);

        return $body;
    }
}
