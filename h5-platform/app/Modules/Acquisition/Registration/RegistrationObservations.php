<?php

namespace App\Modules\Acquisition\Registration;

use App\Enums\BehaviorRecordingResult as Result;
use App\Modules\Acquisition\Measurement\CoreContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal module responsibility only. 00's public PHP facade and the 01 adapter are not wired yet. */
final class RegistrationObservations
{
    public function capture(?string $notice = null): ?RequestObservation
    {
        if ($notice !== PurposePolicy::NOTICE || config('acquisition.registration.observations_enabled', false) !== true) {
            return null;
        }
        try {
            return DB::transaction(function () {
                $core = app(CoreContext::class);
                if (config('acquisition.registration.observations_enabled', false) !== true
                    || app(PurposePolicy::class)->resolve()['eligibility'] !== 'eligible' || ! ($active = $core->active())) {
                    return null;
                }
                $r = request();
                $operation = $r->attributes->get('acquisition.r1.registration_operation');
                if (! is_string($operation)) {
                    $operation = (string) Str::uuid();
                    $r->attributes->set('acquisition.r1.registration_operation', $operation);
                }

                return new RequestObservation($r, $operation, $active[0]->id, $active[1]->id, (int) $core->preference()->revision);
            });
        } catch (\Throwable) {
            return null;
        }
    }

    public function record(?RequestObservation $capture, string $event, ?string $failure = null): Result
    {
        if (! $capture || ! $capture->belongsTo(request()) || ! request()->hasSession() || ! in_array($event, ['registration_succeeded', 'registration_failed'], true)
            || ($event === 'registration_succeeded' && $failure !== null)
            || ($event === 'registration_failed' && ! in_array($failure, ['validation', 'conflict', 'temporary', 'unknown'], true))) {
            return Result::Skipped;
        }
        try {
            return app(CoreContext::class)->serialized(function () use ($capture, $event, $failure) {
                $core = app(CoreContext::class);
                if (config('acquisition.registration.observations_enabled', false) !== true
                    || app(PurposePolicy::class)->resolve()['eligibility'] !== 'eligible' || ! ($active = $core->active())
                    || ! $capture->matches(request(), $active, (int) $core->preference()->revision)) {
                    return Result::Skipped;
                }
                [$subject, $session] = $active;
                $id = $capture->operation();
                $properties = $failure === null ? [] : ['error_code' => $failure];
                $fingerprint = hash('sha256', json_encode([$event, $properties], JSON_THROW_ON_ERROR));
                $dedup = hash('sha256', 'r1.registration:'.$id);
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.r1.registration:'.$id]);
                $old = DB::table('acquisition_c_events')->where('dedup_key', $dedup)->first();
                if ($old) {
                    return $old->subject_id === $subject->id && hash_equals($old->fingerprint, $fingerprint) ? Result::Accepted : Result::Skipped;
                }
                DB::table('acquisition_c_event_keys')->insert(['id' => $id, 'subject_id' => $subject->id, 'fingerprint' => $fingerprint, 'created_at' => now()]);
                DB::table('acquisition_c_events')->insert(['id' => $id, 'dedup_key' => $dedup, 'fingerprint' => $fingerprint,
                    'subject_id' => $subject->id, 'measurement_session_id' => $session->id, 'event_name' => $event,
                    'schema_version' => 2, 'basis_kind' => 'policy_permitted', 'policy_version' => PurposePolicy::VERSION,
                    'notice_version' => PurposePolicy::NOTICE, 'is_test' => true, 'properties' => json_encode($properties, JSON_THROW_ON_ERROR), 'received_at' => now()]);

                return Result::Accepted;
            });
        } catch (\Throwable) {
            // Savepoint already rolled back. No exception, identity payload or retry mapping escapes.
            return Result::Retry;
        }
    }
}
