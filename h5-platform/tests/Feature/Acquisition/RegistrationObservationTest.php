<?php

namespace Tests\Feature\Acquisition;

use App\Enums\BehaviorRecordingResult as Result;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Measurement\CorePolicy;
use App\Modules\Acquisition\Registration\PurposePolicy;
use App\Modules\Acquisition\Registration\RegistrationObservations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegistrationObservationTest extends AcquisitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['acquisition.registration.observations_enabled' => true, 'h5.measurement_c_registration' => true, 'h5.measurement_c_experience' => true,
            'acquisition.registration.synthetic_policy' => true, 'acquisition.registration.synthetic_from' => now()->toIso8601String(),
            'acquisition.registration.synthetic_until' => now()->addDay()->toIso8601String(), 'acquisition.measurement.enabled' => true,
            'acquisition.measurement.synthetic_policy' => true, 'acquisition.measurement.synthetic_until' => now()->addDay()->toIso8601String()]);
    }

    private function start(): void
    {
        $this->postJson('/api/v2/measurement/start', ['notice_version' => CorePolicy::NOTICE, 'page_instance_id' => (string) Str::uuid()])->assertOk();
    }

    public function test_no_context_or_unapproved_result_policy_never_creates_measurement(): void
    {
        $service = app(RegistrationObservations::class);
        $this->assertNull($service->capture(PurposePolicy::NOTICE));
        $this->assertSame(Result::Skipped, $service->record(null, 'registration_succeeded'));
        $this->start();
        $this->assertNull($service->capture());
        $this->assertNull($service->capture('stale-notice'));
        config(['acquisition.registration.observations_enabled' => false]);
        $this->assertNull($service->capture(PurposePolicy::NOTICE));
        config(['acquisition.registration.observations_enabled' => true, 'acquisition.registration.synthetic_policy' => false]);
        $this->assertNull($service->capture(PurposePolicy::NOTICE));
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_trusted_same_request_result_deduplicates_without_identity_or_session_derived_keys(): void
    {
        $this->start();
        $s = app(RegistrationObservations::class);
        $capture = $s->capture(PurposePolicy::NOTICE);
        $this->assertNotNull($capture);
        $this->assertTrue(Str::isUuid($capture->operation()));
        $this->assertSame('{}', json_encode($capture));
        $this->assertSame(Result::Accepted, $s->record($capture, 'registration_succeeded'));
        $this->assertSame(Result::Accepted, $s->record($capture, 'registration_succeeded'));
        $this->assertSame(Result::Skipped, $s->record($capture, 'registration_failed', 'validation'));
        $this->assertDatabaseCount('acquisition_c_events', 1);
        $this->assertDatabaseHas('acquisition_c_events', ['event_name' => 'registration_succeeded', 'is_test' => true, 'properties' => '[]', 'policy_version' => PurposePolicy::VERSION, 'notice_version' => PurposePolicy::NOTICE]);
        $this->assertDatabaseCount('acquisition_analytics_orders', 0);
        $this->assertDatabaseCount('acquisition_subjects', 0);
        $this->assertDatabaseCount('acquisition_r1_choices', 0);
    }

    public function test_capture_is_not_reusable_after_request_change_or_termination(): void
    {
        $this->start();
        $s = app(RegistrationObservations::class);
        $capture = $s->capture(PurposePolicy::NOTICE);
        $old = request();
        $this->app->instance('request', Request::create('/synthetic-next-request'));
        $this->assertSame(Result::Skipped, $s->record($capture, 'registration_succeeded'));
        $this->app->instance('request', $old);
        app(BehaviorRecorder::class)->clearBrowserContext();
        $this->assertSame(Result::Skipped, $s->record($capture, 'registration_succeeded'));
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_result_failure_storage_savepoint_does_not_poison_necessary_transaction(): void
    {
        $this->start();
        $s = app(RegistrationObservations::class);
        $capture = $s->capture(PurposePolicy::NOTICE);
        DB::statement('ALTER TABLE acquisition_c_events RENAME TO acquisition_c_events_r1_fault');
        $this->assertSame(Result::Retry, $s->record($capture, 'registration_failed', 'temporary'));
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_c_events_r1_fault RENAME TO acquisition_c_events');
        $this->assertSame(Result::Accepted, $s->record($capture, 'registration_failed', 'temporary'));
        $this->assertDatabaseCount('acquisition_c_event_keys', 1);
    }

    public function test_browser_success_other_identity_events_and_free_form_failures_are_never_accepted(): void
    {
        $this->start();
        $s = app(RegistrationObservations::class);
        $capture = $s->capture(PurposePolicy::NOTICE);
        $this->assertSame(Result::Skipped, $s->record($capture, 'login_succeeded'));
        $this->assertSame(Result::Skipped, $s->record($capture, 'registration_failed', 'email@example.test'));
        $this->assertSame(Result::Skipped, $s->record($capture, 'registration_succeeded', 'unknown'));
        foreach (['registration_succeeded', 'registration_failed'] as $name) {
            $this->postJson('/api/v2/measurement/events', ['events' => [['event_id' => (string) Str::uuid(), 'event_name' => $name,
                'properties' => ['page_instance_id' => (string) Str::uuid(), 'page_type' => 'registration']]]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }
}
