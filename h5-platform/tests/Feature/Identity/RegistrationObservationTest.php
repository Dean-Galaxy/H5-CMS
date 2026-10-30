<?php

namespace Tests\Feature\Identity;

use App\Contracts\RegistrationMeasurement;
use App\Contracts\RegistrationMeasurementCapture;
use App\Data\RegistrationResult;
use App\Enums\BehaviorRecordingResult;
use App\Enums\RegistrationMeasurementAvailability;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\RegistrationObservation;
use App\Modules\Identity\Services\RegistrationTerms;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class RegistrationObservationTest extends TestCase
{
    use RefreshDatabase;

    private ObservationPort $port;

    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.measurement_c_registration' => true, 'h5.measurement_c_experience' => true]);
        Notification::fake();
        $this->port = new ObservationPort;
        $this->app->instance(RegistrationMeasurement::class, $this->port);
    }

    private function payload(): array
    {
        return ['name' => 'Synthetic', 'email' => 'synthetic@example.test', 'password' => 'SyntheticPassword123',
            'password_confirmation' => 'SyntheticPassword123', 'terms_accepted' => true,
            'terms_version' => RegistrationTerms::TERMS, 'privacy_notice_version' => RegistrationTerms::PRIVACY];
    }

    public function test_hidden_notice_post_without_server_page_delivery_cannot_capture(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload() + ['notice_version' => RegistrationObservation::NOTICE])->assertOk();
        $this->assertSame([], $this->port->calls);
        $this->assertDatabaseCount('identity_viewers', 1);
    }

    public function test_real_notice_then_registration_records_after_receipt_commit_and_before_mail_login(): void
    {
        $this->get('/identity/register?lang=en')->assertOk()->assertSee('data-registration-notice', false)->assertSee(RegistrationObservation::NOTICE);
        Event::listen(Registered::class, function (): void {
            $this->port->sequence[] = 'registered';
        });
        Event::listen(Login::class, function ($event): void {
            if ($event->guard === 'web') {
                $this->port->sequence[] = 'login';
            }
        });
        $this->postJson('/api/v1/auth/register', $this->payload())->assertOk();
        $this->assertSame(['capture', 'record', 'registered', 'login'], $this->port->sequence);
        $this->assertSame(['event_name' => 'registration_succeeded', 'failure' => null], $this->port->results[0]);
        $this->assertSame(['accounts' => 1, 'receipts' => 1, 'authenticated' => false], $this->port->duringRecord[0]);
        $this->assertSame(1, count($this->port->calls));
        $this->assertSame(RegistrationObservation::NOTICE, $this->port->calls[0]);
        $this->assertFalse(collect(session()->all())->contains(fn ($value) => $value instanceof RegistrationMeasurementCapture));
    }

    public function test_creation_validation_failure_is_minimal_and_mail_failure_stays_succeeded(): void
    {
        $this->get('/identity/register')->assertOk();
        $payload = $this->payload();
        $payload['terms_accepted'] = false;
        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable();
        $this->assertSame([['event_name' => 'registration_failed', 'failure' => 'validation']], $this->port->results);
        $this->assertDatabaseCount('identity_viewers', 0);
        Event::listen(Registered::class, fn () => throw new RuntimeException('synthetic mail failure'));
        $this->postJson('/api/v1/auth/register', $this->payload())->assertOk();
        $this->assertSame(['event_name' => 'registration_succeeded', 'failure' => null], $this->port->results[1]);
        $this->assertCount(2, $this->port->results);
    }

    public function test_storage_creation_failure_records_only_unknown_and_rolls_back_required_account(): void
    {
        $this->get('/identity/register')->assertOk();
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "identity_registration_receipts"')) {
                throw new RuntimeException('synthetic failure');
            }
        });
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(500);
        $this->assertSame([['event_name' => 'registration_failed', 'failure' => 'unknown']], $this->port->results);
        $this->assertDatabaseCount('identity_viewers', 0);
    }

    public function test_port_unavailable_throwing_declined_or_retry_never_changes_account_result(): void
    {
        foreach (['unavailable', 'availability_throw', 'capture_throw', 'record_throw', 'skipped', 'retry', 'null'] as $mode) {
            $this->port->mode = $mode;
            $this->get('/identity/register')->assertOk();
            $payload = $this->payload();
            $payload['email'] = $mode.'@example.test';
            $this->postJson('/api/v1/auth/register', $payload)->assertOk();
            $this->assertNotNull(Auth::guard('web')->user());
            $this->postJson('/api/v1/auth/logout')->assertOk();
            $this->travel(61)->seconds();
        }
        $this->assertDatabaseCount('identity_viewers', 7);
        $this->assertDatabaseCount('identity_registration_receipts', 7);
    }

    public function test_database_failures_use_only_short_enums_and_each_capture_finishes_once(): void
    {
        $this->get('/identity/register')->assertOk();
        $request = Request::create('/api/v1/auth/register', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        foreach ([23505 => 'conflict', 8006 => 'temporary'] as $code => $failure) {
            $observation = new RegistrationObservation;
            $observation->begin($request);
            $observation->failed(new QueryException('pgsql', 'synthetic', [], new \PDOException('synthetic', $code)));
            $observation->succeeded(); // Cannot turn a finished failure into success or replay it.
            $this->assertSame(['event_name' => 'registration_failed', 'failure' => $failure], end($this->port->results));
        }
        $this->assertCount(2, $this->port->results);
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->expectException(\LogicException::class);
        serialize($observation);
    }

    public function test_old_or_unshown_notice_and_ordinary_mode_do_not_trigger_new_port(): void
    {
        $this->withSession(['identity.registration_notice_delivered' => 'old-notice']);
        $this->postJson('/api/v1/auth/register', $this->payload())->assertOk();
        $this->assertSame([], $this->port->calls);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        config(['h5.measurement_c_registration' => false]);
        $payload = $this->payload();
        $payload['email'] = 'ordinary@example.test';
        $this->get('/identity/register')->assertOk();
        $this->postJson('/api/v1/auth/register', $payload)->assertOk();
        $this->assertSame([], $this->port->calls);
    }
}

class TestObservation implements RegistrationMeasurementCapture
{
    public function __serialize(): array
    {
        throw new RuntimeException('Never persist a capture');
    }
}

class ObservationPort implements RegistrationMeasurement
{
    public string $mode = 'accepted';

    public array $calls = [];

    public array $sequence = [];

    public array $results = [];

    public array $duringRecord = [];

    public function availability(): RegistrationMeasurementAvailability
    {
        if ($this->mode === 'availability_throw') {
            throw new RuntimeException('collector unavailable');
        }

        return $this->mode === 'unavailable' ? RegistrationMeasurementAvailability::DependencyNotReady : RegistrationMeasurementAvailability::Available;
    }

    public function capture(string $deliveredNoticeVersion): ?RegistrationMeasurementCapture
    {
        $this->calls[] = $deliveredNoticeVersion;
        $this->sequence[] = 'capture';
        if ($this->mode === 'capture_throw') {
            throw new RuntimeException('collector unavailable');
        }

        return $this->mode === 'null' ? null : new TestObservation;
    }

    public function record(?RegistrationMeasurementCapture $capture, RegistrationResult $result): BehaviorRecordingResult
    {
        $this->sequence[] = 'record';
        $this->results[] = $result->toArray();
        $this->duringRecord[] = ['accounts' => Viewer::count(), 'receipts' => DB::table('identity_registration_receipts')->count(), 'authenticated' => Auth::guard('web')->check()];
        if ($this->mode === 'record_throw') {
            throw new RuntimeException('collector unavailable');
        }

        return match ($this->mode) {
            'skipped' => BehaviorRecordingResult::Skipped, 'retry' => BehaviorRecordingResult::Retry, default => BehaviorRecordingResult::Accepted
        };
    }
}
