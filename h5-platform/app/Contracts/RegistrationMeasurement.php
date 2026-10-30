<?php

namespace App\Contracts;

use App\Data\RegistrationResult;
use App\Enums\BehaviorRecordingResult;
use App\Enums\RegistrationMeasurementAvailability;

/** Trusted same-request result port; never an account link, browser event or durable outbox. */
interface RegistrationMeasurement
{
    /** Provider availability only, never policy/consent eligibility. */
    public function availability(): RegistrationMeasurementAvailability;

    /** Only after the server has actually delivered this notice; no context means no creation. */
    public function capture(string $deliveredNoticeVersion): ?RegistrationMeasurementCapture;

    /** Must not throw into identity. Retry is transient, not permission to persist/replay identity. */
    public function record(?RegistrationMeasurementCapture $capture, RegistrationResult $result): BehaviorRecordingResult;
}
