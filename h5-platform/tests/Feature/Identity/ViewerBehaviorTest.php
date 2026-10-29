<?php

namespace Tests\Feature\Identity;

use App\Contracts\BehaviorEventRecorder;
use App\Enums\BehaviorRecordingResult;
use App\Enums\Role;
use App\Modules\Identity\Filament\StaffLogin;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\Verification;
use App\Modules\Identity\Services\ViewerBehavior;
use App\Support\DisabledBehaviorEventRecorder;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ViewerBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private ConsentAwareIdentityRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->recorder = new ConsentAwareIdentityRecorder;
        $this->app->instance(BehaviorEventRecorder::class, $this->recorder);
    }

    private function viewer(string $email = 'viewer@example.test'): Viewer
    {
        return Viewer::create(['name' => 'Viewer', 'email' => $email, 'password' => 'ViewerPassword123']);
    }

    private function consent(): static
    {
        // Contract consumer fixture only: production Identity never knows 05's session keys.
        return $this->withSession(['test.analytics_consent' => true, 'test.analytics_anon' => 'synthetic-anon', 'test.analytics_source' => 'synthetic-source']);
    }

    private function login(Viewer $viewer): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $viewer->email, 'password' => 'ViewerPassword123'])->assertOk();
    }

    public function test_real_registration_preserves_anonymous_source_across_session_regeneration_and_records_one_fact(): void
    {
        $this->consent();
        $previousSession = session()->getId();
        $this->postJson('/api/v1/auth/register', ['name' => 'New viewer', 'email' => 'new@example.test',
            'password' => 'ViewerPassword123', 'password_confirmation' => 'ViewerPassword123', 'role' => 'owner',
            'viewer_id' => 'forged', 'source_id' => 'forged', 'return_to' => '/membership'])
            ->assertOk()->assertJsonPath('redirect_to', '/membership')
            ->assertSessionHas('test.analytics_anon', 'synthetic-anon')->assertSessionHas('test.analytics_source', 'synthetic-source');
        $viewer = Viewer::sole();
        $this->assertSame(0, Staff::count());
        $this->assertNotSame($previousSession, session()->getId());
        Notification::assertSentTo($viewer, VerifyEmail::class);
        $this->assertSame(['registration_succeeded'], $this->recorder->names());
        $fact = array_values($this->recorder->accepted)[0];
        $this->assertSame(['viewer_id' => $viewer->id], $fact['properties']);
        $this->assertSame('identity:registration:'.$viewer->id, $fact['key']);
        app(ViewerBehavior::class)->registered($viewer->id);
        $this->assertCount(1, $this->recorder->accepted); // Same persisted fact, identical key.
    }

    public function test_real_registration_validation_failure_has_only_safe_error_enum(): void
    {
        $this->consent()->postJson('/api/v1/auth/register', ['name' => 'Private name', 'email' => 'private@example.test',
            'password' => 'private-token', 'password_confirmation' => 'private-token'])->assertUnprocessable();
        $this->assertSame(0, Viewer::count());
        $this->assertSame(['registration_failed'], $this->recorder->names());
        $this->assertSame(['error_code' => 'VALIDATION_FAILED'], array_values($this->recorder->accepted)[0]['properties']);
        $payload = json_encode($this->recorder->accepted);
        foreach (['private@example.test', 'Private name', 'private-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
        app(ViewerBehavior::class)->failed('registration', 'VALIDATION_FAILED', request());
        $this->assertCount(1, $this->recorder->accepted); // Retried delivery for one server request.
    }

    public function test_notification_failure_after_creation_still_records_creation_not_a_false_registration_failure(): void
    {
        $this->consent();
        Event::listen(Registered::class, fn () => throw new RuntimeException('synthetic notification failure'));
        $this->postJson('/api/v1/auth/register', ['name' => 'Created', 'email' => 'created@example.test',
            'password' => 'ViewerPassword123', 'password_confirmation' => 'ViewerPassword123'])->assertStatus(500);
        $this->assertSame(1, Viewer::count());
        $this->assertSame(['registration_succeeded'], $this->recorder->names());
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_login_failures_do_not_expose_account_existence_and_success_uses_final_session_and_real_actor(): void
    {
        $viewer = $this->viewer();
        $this->consent();
        foreach ([$viewer->email, 'missing@example.test'] as $email) {
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'WrongSecret123'])->assertUnprocessable();
        }
        $previousSession = session()->getId();
        $this->login($viewer);
        $this->assertNotSame($previousSession, session()->getId());
        $this->assertSame(['login_failed', 'login_failed', 'login_succeeded'], $this->recorder->names());
        $facts = array_values($this->recorder->accepted);
        $this->assertSame(['error_code' => 'INVALID_CREDENTIALS'], $facts[0]['properties']);
        $this->assertSame($facts[0]['properties'], $facts[1]['properties']);
        $this->assertNotSame($facts[0]['key'], $facts[1]['key']); // Separate actual attempts.
        $this->assertSame($viewer->id, $facts[2]['authenticated_viewer']);
        $this->assertSame(session()->getId(), $facts[2]['session']);
        $this->assertSame('synthetic-anon', $facts[2]['anon']);
        $this->assertSame('synthetic-source', $facts[2]['source']);
        $this->assertStringNotContainsString(session()->getId(), $facts[2]['key']);
        app(ViewerBehavior::class)->loggedIn($viewer);
        $this->assertCount(3, $this->recorder->accepted);
        $this->postJson('/api/v1/auth/login', ['email' => $viewer->email, 'password' => 'ViewerPassword123'])->assertRedirect();
        $this->assertCount(3, $this->recorder->accepted); // Already authenticated, no new outcome.
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'login.failed', 'result' => 'denied']);
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'login', 'result' => 'success']);
    }

    public function test_malformed_login_and_registration_rate_limit_are_safe_failed_outcomes(): void
    {
        $this->consent()->postJson('/api/v1/auth/login', [])->assertUnprocessable();
        $this->assertSame(['error_code' => 'VALIDATION_FAILED'], array_values($this->recorder->accepted)[0]['properties']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register', [])->assertStatus(429);
        $this->assertSame(['error_code' => 'RATE_LIMITED'], array_values($this->recorder->accepted)[6]['properties']);
    }

    public function test_real_email_verification_is_separate_from_registration_and_replay_is_not_counted(): void
    {
        $viewer = $this->viewer();
        $viewer->sendEmailVerificationNotification();
        $this->consent();
        $this->login($viewer);
        $url = app(Verification::class)->url($viewer->fresh());
        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertStatus(409)->assertJsonPath('error.code', 'TOKEN_REPLAYED');
        $this->assertTrue($viewer->fresh()->hasVerifiedEmail());
        $this->assertSame(['login_succeeded', 'email_verified'], $this->recorder->names());
        app(ViewerBehavior::class)->verified($viewer);
        $this->assertCount(2, $this->recorder->accepted);
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'email.verified', 'result' => 'success']);
    }

    public function test_rolled_back_verification_does_not_record_email_verified(): void
    {
        $viewer = $this->viewer();
        $this->consent();
        $this->login($viewer); // Establish the real web request/guard before the transaction.
        try {
            DB::transaction(function () use ($viewer): void {
                $viewer->forceFill(['email_verified_at' => now()])->save();
                event(new Verified($viewer));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertFalse($viewer->fresh()->hasVerifiedEmail());
        $this->assertSame(['login_succeeded'], $this->recorder->names());
    }

    public function test_logout_records_before_context_clear_and_invalidation_and_new_account_is_not_linked(): void
    {
        $first = $this->viewer();
        $second = $this->viewer('other@example.test');
        $this->consent();
        $this->login($first);
        $this->postJson('/api/v1/auth/logout')->assertOk()
            ->assertSessionMissing('test.analytics_anon')->assertSessionMissing('test.analytics_source');
        $this->assertSame(['login_succeeded', 'logout'], $this->recorder->names());
        $logout = array_values($this->recorder->accepted)[1];
        $this->assertSame($first->id, $logout['authenticated_viewer']);
        $this->assertSame('synthetic-anon', $logout['anon']);
        $this->assertSame($this->recorder->clearSnapshots[0]['session'], $logout['session']);
        $this->assertSame(['record:login_succeeded', 'record:logout', 'clear'], $this->recorder->operations);
        $this->assertNotSame($logout['session'], session()->getId());
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->assertSame(['login_succeeded', 'logout'], $this->recorder->names());
        $this->login($second);
        $last = end($this->recorder->calls);
        $this->assertSame($second->id, $last['properties']['viewer_id']);
        $this->assertNull($last['anon']);
        $this->assertNull($last['source']);
        $this->assertSame(1, $this->recorder->clearCalls);
    }

    public function test_account_switch_without_explicit_logout_clears_context_before_any_new_actor_fact(): void
    {
        $first = $this->viewer();
        $second = $this->viewer('other@example.test');
        $this->consent();
        $this->login($first);
        // Guard-level switch: an integration must not carry the previous viewer's source.
        Auth::guard('web')->login($second);
        $this->assertNull(session('test.analytics_anon'));
        $this->assertNull(session('test.analytics_source'));
        $this->assertSame(1, $this->recorder->clearCalls);
        app(ViewerBehavior::class)->loggedIn($second);
        $last = end($this->recorder->calls);
        $this->assertSame($second->id, $last['properties']['viewer_id']);
        $this->assertNull($last['source']);
    }

    public function test_throwing_context_clear_on_guard_account_switch_rotates_session_without_losing_new_login(): void
    {
        $first = $this->viewer();
        $second = $this->viewer('other@example.test');
        $this->consent();
        $this->login($first);
        $oldSession = session()->getId();
        $this->recorder->throwOnClear = true;
        Auth::guard('web')->login($second);
        $this->assertSame($second->id, Auth::guard('web')->id());
        $this->assertSame($second->id, session(Auth::guard('web')->getName()));
        $this->assertNull(session('test.analytics_anon'));
        $this->assertNull(session('test.analytics_source'));
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertSame(1, $this->recorder->clearCalls);
    }

    public function test_staff_login_failed_login_verification_and_mfa_are_not_viewer_behavior(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'StaffPassword123', 'role' => Role::Owner]);
        $this->consent();
        Livewire::test(StaffLogin::class)->set('data.email', $staff->email)->set('data.password', 'WrongPassword123')->call('authenticate')->assertHasErrors();
        Event::dispatch(new Failed('staff', $staff, ['password' => 'not-an-analytics-property']));
        Auth::guard('staff')->login($staff);
        Event::dispatch(new Verified($staff));
        $this->assertSame([], $this->recorder->calls);
        $this->assertSame(0, $this->recorder->clearCalls);
    }

    public function test_false_or_throwing_collector_never_changes_registration_verification_login_or_logout(): void
    {
        foreach (['false', 'throw'] as $mode) {
            $this->recorder->mode = $mode;
            $this->recorder->throwOnClear = $mode === 'throw';
            $this->consent()->postJson('/api/v1/auth/register', [])->assertUnprocessable();
            $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'WrongPassword123'])->assertUnprocessable();
            $this->postJson('/api/v1/auth/register', ['name' => 'Available', 'email' => $mode.'@example.test',
                'password' => 'ViewerPassword123', 'password_confirmation' => 'ViewerPassword123'])->assertOk();
            $viewer = Viewer::where('email', $mode.'@example.test')->sole();
            $this->getJson(app(Verification::class)->url($viewer->fresh()))->assertOk();
            $this->assertTrue($viewer->fresh()->hasVerifiedEmail());
            $this->postJson('/api/v1/auth/logout')->assertOk()->assertSessionMissing('test.analytics_anon');
            $this->login($viewer);
            $this->assertSame($viewer->id, Auth::guard('web')->id());
            $this->postJson('/api/v1/auth/logout')->assertOk();
        }
        $this->assertSame([], $this->recorder->accepted);
    }

    public function test_unknown_or_denied_consent_and_default_noop_do_not_block_business_or_create_subjects(): void
    {
        $viewer = $this->viewer();
        $this->login($viewer); // No consent: the consumer declines; Identity does not fabricate a subject.
        $this->assertSame([], $this->recorder->accepted);
        $this->assertNull(session('test.analytics_anon'));
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->withSession(['test.analytics_consent' => false]);
        $this->login($viewer);
        $this->assertSame([], $this->recorder->accepted);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app->bind(BehaviorEventRecorder::class, DisabledBehaviorEventRecorder::class);
        $this->login($viewer);
        $this->postJson('/api/v1/auth/logout')->assertOk();
    }
}

/** In-process contract consumer fixture, never bound by application code. No 05 table access. */
class ConsentAwareIdentityRecorder implements BehaviorEventRecorder
{
    public array $calls = [];

    public array $accepted = [];

    public array $operations = [];

    public string $mode = 'normal';

    public bool $throwOnClear = false;

    public int $clearCalls = 0;

    public array $clearSnapshots = [];

    public function names(): array
    {
        return array_column(array_values($this->accepted), 'event');
    }

    public function record(string $eventName, array $properties = [], ?string $deduplicationKey = null): bool
    {
        $session = request()->session();
        $this->calls[] = $fact = ['event' => $eventName, 'properties' => $properties, 'key' => $deduplicationKey,
            'session' => $session->getId(), 'authenticated_viewer' => Auth::guard('web')->id(),
            'anon' => $session->get('test.analytics_anon'), 'source' => $session->get('test.analytics_source')];
        $this->operations[] = 'record:'.$eventName;
        if ($this->mode === 'throw') {
            throw new RuntimeException('synthetic collector unavailable');
        }
        if ($this->mode === 'false' || $session->get('test.analytics_consent') !== true) {
            return false;
        }
        $this->accepted[$deduplicationKey] ??= $fact;

        return true;
    }

    public function recordFact(string $eventName, array $properties, string $deduplicationKey): BehaviorRecordingResult
    {
        return $this->record($eventName, $properties, $deduplicationKey) ? BehaviorRecordingResult::Accepted : BehaviorRecordingResult::Skipped;
    }

    public function clearBrowserContext(): void
    {
        $this->clearCalls++;
        $this->operations[] = 'clear';
        $this->clearSnapshots[] = ['session' => request()->session()->getId(), 'viewer' => Auth::guard('web')->id()];
        if ($this->throwOnClear) {
            throw new RuntimeException('synthetic clear unavailable');
        }
        request()->session()->forget(['test.analytics_anon', 'test.analytics_source']);
    }
}
