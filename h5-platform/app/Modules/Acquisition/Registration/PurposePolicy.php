<?php

namespace App\Modules\Acquisition\Registration;

use Carbon\CarbonImmutable;

final class PurposePolicy
{
    public const VERSION = 'registration-purpose-r1';

    public const NOTICE = 'c-purpose-choice-only-r1-v1';

    public const PURPOSES = ['account_link', 'advertising'];

    public function resolve(): array
    {
        $reason = 'POLICY_UNRESOLVED';
        if (! config('h5.modules.acquisition.ready', false)) {
            $reason = 'DEPENDENCY_NOT_READY';
        } elseif (! config('h5.measurement_c_registration', false) || ! config('h5.measurement_c_experience', false)) {
            $reason = 'FEATURE_DISABLED';
        } elseif (app()->environment('local', 'testing') && config('acquisition.registration.synthetic_policy', false) === true) {
            try {
                $from = config('acquisition.registration.synthetic_from');
                $until = config('acquisition.registration.synthetic_until');
                if (is_string($from) && is_string($until) && $from !== '' && $until !== '') {
                    $start = CarbonImmutable::parse($from);
                    $end = CarbonImmutable::parse($until);
                    if ($start->lessThanOrEqualTo(now()) && $end->greaterThan(now())
                        && $end->greaterThan($start) && $end->lessThanOrEqualTo($start->addDay())) {
                        return ['eligibility' => 'eligible', 'reason' => null];
                    }
                }
            } catch (\Throwable) {
                // Unknown policy is never an implicit permission.
            }
        }

        return ['eligibility' => 'unknown', 'reason' => $reason];
    }
}
