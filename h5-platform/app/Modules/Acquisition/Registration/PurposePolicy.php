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
                $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';
                if (is_string($from) && is_string($until) && preg_match($iso, $from) && preg_match($iso, $until)
                    && date_parse($from)['warning_count'] === 0 && date_parse($from)['error_count'] === 0
                    && date_parse($until)['warning_count'] === 0 && date_parse($until)['error_count'] === 0) {
                    $start = CarbonImmutable::parse($from);
                    $end = CarbonImmutable::parse($until);
                    if ($start->lessThanOrEqualTo(now()) && $end->greaterThan(now())
                        && $end->greaterThan($start) && $start->diffInSeconds($end, false) <= 86400) {
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
