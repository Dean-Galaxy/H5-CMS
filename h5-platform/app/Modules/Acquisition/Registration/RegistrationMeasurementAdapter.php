<?php

namespace App\Modules\Acquisition\Registration;

use App\Contracts\RegistrationMeasurement;
use App\Contracts\RegistrationMeasurementCapture;
use App\Data\RegistrationResult;
use App\Enums\BehaviorRecordingResult;
use App\Enums\RegistrationMeasurementAvailability;

/** Dependency adapter only; availability never grants a purpose or starts C. */
final class RegistrationMeasurementAdapter implements RegistrationMeasurement
{
    public function __construct(private RegistrationObservations $observations) {}

    public function availability(): RegistrationMeasurementAvailability
    {
        return config('h5.modules.acquisition.ready', false)
            ? RegistrationMeasurementAvailability::Available : RegistrationMeasurementAvailability::DependencyNotReady;
    }

    public function capture(string $deliveredNoticeVersion): ?RegistrationMeasurementCapture
    {
        try {
            return $this->availability() === RegistrationMeasurementAvailability::Available
                ? $this->observations->capture($deliveredNoticeVersion) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function record(?RegistrationMeasurementCapture $capture, RegistrationResult $result): BehaviorRecordingResult
    {
        try {
            if (! $capture instanceof RequestObservation || $this->availability() !== RegistrationMeasurementAvailability::Available) {
                return BehaviorRecordingResult::Skipped;
            }

            return $this->observations->record($capture, $result->event_name->value, $result->failure?->value);
        } catch (\Throwable) {
            // A broken optional observer cannot change the account/required receipt result.
            return BehaviorRecordingResult::Retry;
        }
    }
}
