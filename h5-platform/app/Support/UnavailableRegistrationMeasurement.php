<?php

namespace App\Support;

use App\Contracts\RegistrationMeasurement;
use App\Contracts\RegistrationMeasurementCapture;
use App\Data\RegistrationResult;
use App\Enums\BehaviorRecordingResult;
use App\Enums\RegistrationMeasurementAvailability;

final class UnavailableRegistrationMeasurement implements RegistrationMeasurement
{
    public function availability(): RegistrationMeasurementAvailability
    {
        return RegistrationMeasurementAvailability::DependencyNotReady;
    }

    public function capture(string $deliveredNoticeVersion): ?RegistrationMeasurementCapture
    {
        return null;
    }

    public function record(?RegistrationMeasurementCapture $capture, RegistrationResult $result): BehaviorRecordingResult
    {
        // This ephemeral result has no durable queue and cannot be retried after this request.
        return BehaviorRecordingResult::Skipped;
    }
}
