<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\RegistrationMeasurement;
use App\Contracts\RegistrationMeasurementCapture;
use App\Data\RegistrationResult;
use App\Enums\BehaviorRecordingResult as Result;
use App\Enums\RegistrationFailure;
use App\Enums\RegistrationMeasurementAvailability as Availability;
use App\Enums\RegistrationResultKind as Kind;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Measurement\CoreContext;
use App\Modules\Acquisition\Measurement\CorePolicy;
use App\Modules\Acquisition\Registration\PurposePolicy;
use App\Modules\Acquisition\Registration\RegistrationMeasurementAdapter;
use App\Modules\Acquisition\Registration\RequestObservation;
use App\Modules\Identity\Services\RegistrationTerms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class RegistrationMeasurementAdapterTest extends AcquisitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['h5.measurement_c_registration' => true, 'h5.measurement_c_experience' => true,
            'acquisition.registration.observations_enabled' => true, 'acquisition.registration.synthetic_policy' => true,
            'acquisition.registration.synthetic_from' => now()->toIso8601String(),
            'acquisition.registration.synthetic_until' => now()->addDay()->toIso8601String(),
            'acquisition.measurement.enabled' => true, 'acquisition.measurement.synthetic_policy' => true,
            'acquisition.measurement.synthetic_until' => now()->addDay()->toIso8601String()]);
    }

    private function start(): void
    {
        $this->postJson('/api/v2/measurement/start', ['notice_version' => CorePolicy::NOTICE,
            'page_instance_id' => (string) Str::uuid()])->assertOk();
    }

    public function test_container_resolves_actual_provider_but_available_is_not_permission(): void
    {
        $port = app(RegistrationMeasurement::class);
        $this->assertInstanceOf(RegistrationMeasurementAdapter::class, $port);
        config(['h5.measurement_c_registration' => false, 'acquisition.registration.observations_enabled' => false]);
        $this->assertSame(Availability::Available, $port->availability());
        $this->assertNull($port->capture(PurposePolicy::NOTICE));
        $this->assertDatabaseCount('acquisition_c_subjects', 0);
        config(['h5.modules.acquisition.ready' => false]);
        $this->assertSame(Availability::DependencyNotReady, $port->availability());
        $this->assertSame(Result::Skipped, $port->record(null, new RegistrationResult(Kind::Succeeded)));
    }

    public function test_no_context_missing_old_notice_or_disabled_result_cannot_capture(): void
    {
        $port = app(RegistrationMeasurement::class);
        $this->assertNull($port->capture(PurposePolicy::NOTICE));
        $this->start();
        $this->assertNull($port->capture(''));
        $this->assertNull($port->capture(CorePolicy::NOTICE));
        config(['acquisition.registration.observations_enabled' => false]);
        $this->assertNull($port->capture(PurposePolicy::NOTICE));
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_opaque_capture_records_once_through_new_resolutions_and_refuses_serialization(): void
    {
        $this->start();
        $capture = app(RegistrationMeasurement::class)->capture(PurposePolicy::NOTICE);
        $this->assertInstanceOf(RegistrationMeasurementCapture::class, $capture);
        $this->assertSame('{}', json_encode($capture));
        try {
            serialize($capture);
            $this->fail('Capture was serializable');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $fact = new RegistrationResult(Kind::Succeeded);
        $this->assertSame(Result::Accepted, app(RegistrationMeasurement::class)->record($capture, $fact));
        $this->assertSame(Result::Accepted, app(RegistrationMeasurement::class)->record($capture, $fact));
        $this->assertSame(Result::Skipped, app(RegistrationMeasurement::class)->record($capture, new RegistrationResult(Kind::Failed, RegistrationFailure::Validation)));
        $row = DB::table('acquisition_c_events')->first();
        $this->assertSame('[]', $row->properties);
        $this->assertTrue(Str::isUuid($row->id));
        $this->assertSame(PurposePolicy::NOTICE, $row->notice_version);
        foreach (['viewer_id', 'email', 'password', 'order_id', 'source_id'] as $field) {
            $this->assertFalse(property_exists($row, $field));
        }
        $this->assertDatabaseCount('acquisition_c_events', 1);
        $this->assertDatabaseCount('acquisition_r1_choices', 0);
        $this->assertDatabaseCount('acquisition_analytics_orders', 0);
    }

    public function test_fake_marker_constructed_provider_object_and_clone_are_not_issued_capabilities(): void
    {
        $this->start();
        $port = app(RegistrationMeasurement::class);
        $fact = new RegistrationResult(Kind::Succeeded);
        $fake = new class implements RegistrationMeasurementCapture
        {
            public function __serialize(): array
            {
                throw new \LogicException;
            }
        };
        $this->assertSame(Result::Skipped, $port->record($fake, $fact));
        $real = $port->capture(PurposePolicy::NOTICE);
        [$subject, $session] = app(CoreContext::class)->active();
        $forged = new RequestObservation(request(), (string) Str::uuid(), $subject->id, $session->id, 0);
        $this->assertSame(Result::Skipped, $port->record($forged, $fact));
        $this->assertSame(Result::Skipped, $port->record(clone $real, $fact));
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_identical_session_data_in_a_different_request_cannot_reuse_capture(): void
    {
        $this->start();
        $port = app(RegistrationMeasurement::class);
        $capture = $port->capture(PurposePolicy::NOTICE);
        $old = request();
        $this->app->instance('request', Request::create('/different-request'));
        $this->assertSame(Result::Skipped, $port->record($capture, new RegistrationResult(Kind::Succeeded)));
        $this->app->instance('request', $old);
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_deny_or_explicit_end_invalidates_same_request_capture(): void
    {
        $this->start();
        $port = app(RegistrationMeasurement::class);
        $capture = $port->capture(PurposePolicy::NOTICE);
        app(CoreContext::class)->choose('deny', 0);
        $this->assertSame(Result::Skipped, $port->record($capture, new RegistrationResult(Kind::Succeeded)));
        $this->assertNull($port->capture(PurposePolicy::NOTICE));
        app(BehaviorRecorder::class)->clearBrowserContext();
        $this->assertSame(Result::Skipped, $port->record($capture, new RegistrationResult(Kind::Failed, RegistrationFailure::Unknown)));
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_policy_expiry_and_result_switch_are_rechecked_at_record_time(): void
    {
        $this->start();
        $port = app(RegistrationMeasurement::class);
        $capture = $port->capture(PurposePolicy::NOTICE);
        config(['acquisition.registration.observations_enabled' => false]);
        $this->assertSame(Result::Skipped, $port->record($capture, new RegistrationResult(Kind::Succeeded)));
        config(['acquisition.registration.observations_enabled' => true, 'acquisition.registration.synthetic_until' => now()->toIso8601String()]);
        $this->assertSame(Result::Skipped, $port->record($capture, new RegistrationResult(Kind::Succeeded)));
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_retry_fault_does_not_poison_real_account_and_required_receipt(): void
    {
        $this->start();
        $port = app(RegistrationMeasurement::class);
        $capture = $port->capture(PurposePolicy::NOTICE);
        $viewer = app(CreatesNewUsers::class)->create(['name' => 'Adapter synthetic', 'email' => Str::uuid().'@example.test',
            'password' => 'SyntheticPass1234', 'password_confirmation' => 'SyntheticPass1234',
            'terms_accepted' => request()->isJson() ? true : '1', 'terms_version' => RegistrationTerms::TERMS,
            'privacy_notice_version' => RegistrationTerms::PRIVACY]);
        DB::statement('ALTER TABLE acquisition_c_events RENAME TO acquisition_c_events_adapter_fault');
        $this->assertSame(Result::Retry, $port->record($capture, new RegistrationResult(Kind::Succeeded)));
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_c_events_adapter_fault RENAME TO acquisition_c_events');
        $this->assertDatabaseHas('identity_viewers', ['id' => $viewer->id]);
        $this->assertDatabaseHas('identity_registration_receipts', ['viewer_id' => $viewer->id, 'is_test' => true]);
        $this->assertSame(Result::Accepted, $port->record($capture, new RegistrationResult(Kind::Succeeded)));
        $this->assertDatabaseCount('acquisition_c_event_keys', 1);
    }

    public function test_browser_still_cannot_submit_authoritative_registration_results(): void
    {
        $this->start();
        $this->postJson('/api/v2/measurement/events', ['events' => [['event_id' => (string) Str::uuid(),
            'event_name' => 'registration_succeeded', 'properties' => ['page_instance_id' => (string) Str::uuid(), 'page_type' => 'registration']]]])->assertUnprocessable();
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }
}
