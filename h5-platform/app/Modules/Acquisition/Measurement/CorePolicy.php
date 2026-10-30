<?php

namespace App\Modules\Acquisition\Measurement;

use Carbon\CarbonImmutable;

/** No real jurisdiction policy exists in c-core-1. Never consult client geography or local_us_test. */
final class CorePolicy
{
    public const VERSION = 'first-party-measurement-c-v1';

    public const NOTICE = 'c-notice-c-core-1';

    public function resolve(): array
    {
        $off = ['enabled' => false, 'eligibility' => 'unknown', 'basis_kind' => 'none', 'reason' => 'FEATURE_DISABLED', 'version' => null];
        if (! app()->environment('local', 'testing') || ! config('h5.modules.acquisition.ready', false)
            || ! config('acquisition.measurement.enabled', false)) {
            return $off;
        }
        $off['enabled'] = true;
        $off['reason'] = 'POLICY_UNRESOLVED';
        if (config('acquisition.measurement.synthetic_policy', false) !== true) {
            return $off;
        }
        $until = config('acquisition.measurement.synthetic_until');
        if (! is_string($until) || ! preg_match('/^\d{4}-\d\d-\d\dT.+(?:Z|[+-]\d\d:\d\d)$/D', $until)) {
            return $off;
        }
        try {
            if (CarbonImmutable::parse($until)->lessThanOrEqualTo(now())) {
                return $off;
            }
        } catch (\Throwable) {
            return $off;
        }

        return ['enabled' => true, 'eligibility' => 'eligible', 'basis_kind' => 'policy_permitted', 'reason' => null,
            'version' => 'c-core-1-synthetic-v1'];
    }
}
