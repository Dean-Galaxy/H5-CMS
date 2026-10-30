<?php

namespace Tests\Feature\Contracts;

use App\Contracts\RegistrationMeasurement;
use App\Enums\RegistrationMeasurementAvailability;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\RegistrationTerms;
use App\Modules\Identity\Services\Verification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real Identity controller + real Acquisition provider, without a recorder/HTTP replacement. */
final class AcceptedRegistrationMeasurementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(AcquisitionServiceProvider::class);
        config([
            'h5.modules.acquisition.ready' => true,
            'h5.measurement_c_experience' => true, 'h5.measurement_c_registration' => true,
            'acquisition.measurement.enabled' => true, 'acquisition.measurement.synthetic_policy' => true,
            'acquisition.measurement.synthetic_until' => now()->addHour()->toIso8601String(),
            'acquisition.registration.synthetic_policy' => true,
            'acquisition.registration.synthetic_from' => now()->subMinute()->toIso8601String(),
            'acquisition.registration.synthetic_until' => now()->addHour()->toIso8601String(),
            'acquisition.registration.observations_enabled' => true,
            'acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false,
        ]);
        Notification::fake();
        Http::preventStrayRequests();
        $this->assertSame(RegistrationMeasurementAvailability::Available, app(RegistrationMeasurement::class)->availability());
    }

    private function start(): void
    {
        $this->postJson('/api/v2/measurement/start', [
            'notice_version' => 'c-notice-c-core-1', 'page_instance_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('site_effective', 'on');
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'name' => 'Synthetic joint viewer', 'email' => Str::uuid().'@example.test',
            'password' => 'SyntheticJointPassword123', 'password_confirmation' => 'SyntheticJointPassword123',
            'terms_accepted' => true, 'terms_version' => RegistrationTerms::TERMS,
            'privacy_notice_version' => RegistrationTerms::PRIVACY,
        ], $changes);
    }

    public function test_real_success_is_recorded_after_necessary_rows_and_before_notification_and_login(): void
    {
        $this->start();
        $this->get('/identity/register')->assertOk();
        $observed = false;
        Event::listen(Registered::class, function () use (&$observed): void {
            $this->assertDatabaseCount('identity_viewers', 1);
            $this->assertDatabaseCount('identity_registration_receipts', 1);
            $this->assertDatabaseHas('acquisition_c_events', ['event_name' => 'registration_succeeded']);
            $this->assertFalse(Auth::guard('web')->check());
            $observed = true;
        });
        $this->postJson('/api/v1/auth/register', $this->input())->assertOk();
        $this->assertTrue($observed);
        $this->assertDatabaseCount('acquisition_c_events', 1);
        $event = DB::table('acquisition_c_events')->sole();
        $this->assertSame([], json_decode($event->properties, true));
        $this->assertTrue($event->is_test);
        $this->assertSame('c-purpose-choice-only-r1-v1', $event->notice_version);
        $this->assertDatabaseCount('acquisition_r1_choices', 0);
        $this->assertDatabaseCount('acquisition_analytics_orders', 0);
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertDatabaseCount('acquisition_subjects', 0);
    }

    public function test_existing_site_denial_survives_real_register_verify_logout_and_login(): void
    {
        $this->start();
        $denial = $this->patchJson('/api/v2/measurement/preferences', [
            'site_session' => 'deny', 'notice_version' => 'c-notice-c-core-1', 'expected_revision' => 0,
        ])->assertOk()->assertJsonPath('site_choice', 'deny');
        // Laravel's feature client is not a browser cookie jar. Preserve the actual server
        // preference cookie across logout's session invalidation, as a browser does.
        $cookieName = config('h5.workspace').'_c_preference';
        $cookie = $denial->getCookie($cookieName);
        $this->assertNotNull($cookie);
        $this->withCredentials()->withCookie($cookieName, $cookie->getValue());
        $expiry = DB::table('acquisition_c_preferences')->sole()->expires_at;
        $this->get('/identity/register')->assertOk();
        $input = $this->input();
        $this->postJson('/api/v1/auth/register', $input)->assertOk();
        $viewer = Viewer::sole();
        $this->getJson(app(Verification::class)->url($viewer->fresh()))->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertSuccessful();
        $this->postJson('/api/v1/auth/login', ['email' => $input['email'], 'password' => $input['password']])->assertOk();
        $this->getJson('/api/v2/measurement/context')->assertOk()->assertJsonPath('site_choice', 'deny')->assertJsonPath('site_effective', 'off');
        $this->assertSame($expiry, DB::table('acquisition_c_preferences')->sole()->expires_at);
        $this->assertTrue($viewer->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('identity_registration_receipts', 1);
        $this->assertDatabaseCount('acquisition_c_events', 0);
        $this->assertDatabaseCount('acquisition_r1_choices', 0);
        $this->assertDatabaseCount('acquisition_subjects', 0);
    }

    public function test_forged_post_notice_does_not_replace_real_server_delivery(): void
    {
        $this->start();
        $this->postJson('/api/v1/auth/register', $this->input(['notice_version' => 'c-purpose-choice-only-r1-v1']))->assertOk();
        $this->assertDatabaseCount('identity_viewers', 1);
        $this->assertDatabaseCount('identity_registration_receipts', 1);
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_real_validation_failure_then_success_has_separate_minimal_results(): void
    {
        $this->start();
        $this->get('/identity/register')->assertOk();
        $input = $this->input();
        $this->postJson('/api/v1/auth/register', array_replace($input, ['password' => 'short', 'password_confirmation' => 'short']))->assertUnprocessable();
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->assertDatabaseCount('identity_registration_receipts', 0);
        $this->postJson('/api/v1/auth/register', $input)->assertOk();
        $this->assertDatabaseCount('identity_viewers', 1);
        $this->assertDatabaseCount('identity_registration_receipts', 1);
        $this->assertDatabaseCount('acquisition_c_events', 2);
        $rows = DB::table('acquisition_c_events')->get()->keyBy('event_name');
        $this->assertSame(['error_code' => 'validation'], json_decode($rows['registration_failed']->properties, true));
        $this->assertSame([], json_decode($rows['registration_succeeded']->properties, true));
        $this->assertNotSame($rows['registration_failed']->id, $rows['registration_succeeded']->id);
        $this->assertDatabaseCount('billing_payments', 0);
    }
}
