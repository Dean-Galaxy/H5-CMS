<?php

namespace App\Modules\Acquisition\Sending;

use Illuminate\Support\Facades\DB;

final class LocalSimulator
{
    public function send(object $delivery, array $payload): DeliveryResult
    {
        if (! app()->environment('local', 'testing') || $delivery->environment !== 'test') {
            return new DeliveryResult('blocked', 'SIMULATION_ENVIRONMENT_REQUIRED');
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($delivery, $hash) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.simulation:'.$delivery->event_key]);
            $previous = DB::table('acquisition_simulations')->where('event_key', $delivery->event_key)->first();
            if ($previous) {
                if ($previous->payload_digest !== $hash || $previous->platform !== $delivery->platform) {
                    return new DeliveryResult('failed', 'SIMULATION_PAYLOAD_CHANGED');
                }
                DB::table('acquisition_simulations')->where('event_key', $delivery->event_key)->increment('calls');
            } else {
                DB::table('acquisition_simulations')->insert(['event_key' => $delivery->event_key, 'platform' => $delivery->platform,
                    'payload_digest' => $hash, 'created_at' => now()]);
            }

            return new DeliveryResult('simulated');
        });
    }
}
