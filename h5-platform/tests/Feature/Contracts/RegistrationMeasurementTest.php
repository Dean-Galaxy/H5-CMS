<?php

namespace Tests\Feature\Contracts;

use App\Contracts\RegistrationMeasurement;
use App\Data\RegistrationResult;
use App\Enums\BehaviorRecordingResult;
use App\Enums\RegistrationFailure;
use App\Enums\RegistrationMeasurementAvailability;
use App\Enums\RegistrationResultKind;
use App\Support\UnavailableRegistrationMeasurement;
use InvalidArgumentException;
use Tests\TestCase;

final class RegistrationMeasurementTest extends TestCase
{
    public function test_missing_provider_is_explicit_and_cannot_claim_a_recorded_result(): void
    {
        $port = app(RegistrationMeasurement::class);
        $this->assertInstanceOf(UnavailableRegistrationMeasurement::class, $port);
        $this->assertSame(RegistrationMeasurementAvailability::DependencyNotReady, $port->availability());
        $this->assertNull($port->capture('c-purpose-choice-only-r1-v1'));
        $this->assertSame(BehaviorRecordingResult::Skipped, $port->record(null, new RegistrationResult(RegistrationResultKind::Succeeded)));
        foreach (RegistrationFailure::cases() as $failure) {
            $this->assertSame(BehaviorRecordingResult::Skipped, $port->record(null, new RegistrationResult(RegistrationResultKind::Failed, $failure)));
        }
    }

    public function test_result_roundtrip_only_accepts_the_two_events_and_failure_enums(): void
    {
        $this->assertSame(['event_name' => 'registration_succeeded', 'failure' => null],
            RegistrationResult::fromArray(['event_name' => 'registration_succeeded'])->toArray());
        foreach (RegistrationFailure::cases() as $failure) {
            $data = ['event_name' => 'registration_failed', 'failure' => $failure->value];
            $this->assertSame($data, RegistrationResult::fromArray($data)->toArray());
        }
        foreach ([['registration_succeeded', RegistrationFailure::Unknown], ['registration_failed', null]] as [$event, $failure]) {
            try {
                new RegistrationResult(RegistrationResultKind::from($event), $failure);
                $this->fail('Invalid success/failure combination accepted');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(\ValueError::class);
        RegistrationResult::fromArray(['event_name' => 'email_verified']);
    }

    public function test_result_cannot_smuggle_identity_or_free_text_fields(): void
    {
        $this->expectException(\Error::class);
        RegistrationResult::fromArray(['event_name' => 'registration_succeeded', 'viewer_id' => 'forbidden']);
    }
}
