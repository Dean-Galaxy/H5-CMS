<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\Verification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ViewerTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(array $extra = []): Viewer
    {
        return Viewer::create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'LongPassword1234', ...$extra]);
    }

    private function signIn(Viewer $viewer): static
    {
        return $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version]);
    }

    public function test_guest_entry_and_missing_business_are_truthful(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertExactJson(['viewer' => null, 'staff' => null]);
        $this->getJson('/api/v1/catalog')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
        $this->postJson('/api/v1/episodes/test/playback-grants')->assertStatus(503); // no forced sign-in for free-grant skeleton
        $this->postJson('/api/v1/billing/checkout')->assertUnauthorized();
    }

    public function test_registration_uses_fortify_and_cannot_create_staff(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => 'NEW@EXAMPLE.TEST', 'password' => 'LongPassword1234', 'password_confirmation' => 'LongPassword1234', 'role' => 'owner', 'active' => true, 'email_verified_at' => now(), 'return_to' => 'https://evil.example/'])
            ->assertOk()->assertJsonPath('identity.viewer.email_verified', false)->assertJsonPath('identity.staff', null)->assertJsonPath('redirect_to', '/identity/account');
        $viewer = Viewer::firstOrFail();
        $this->assertTrue(Hash::check('LongPassword1234', $viewer->password));
        $this->assertSame('new@example.test', $viewer->email);
        $this->assertSame(0, Staff::count());
        Notification::assertSentTo($viewer, VerifyEmail::class);
        $this->getJson('/api/v1/admin/staff')->assertUnauthorized();
    }

    public function test_verification_checkout_gate_and_link_replay(): void
    {
        Notification::fake();
        $v = $this->viewer();
        $v->sendEmailVerificationNotification();
        $v->refresh();
        $url = app(Verification::class)->url($v);
        $this->signIn($v)->postJson('/api/v1/billing/checkout')->assertForbidden()->assertJsonPath('error.code', 'EMAIL_UNVERIFIED');
        $this->getJson($url)->assertOk()->assertJsonPath('identity.viewer.email_verified', true);
        $this->getJson($url)->assertStatus(409)->assertJsonPath('error.code', 'TOKEN_REPLAYED');
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'security.denied', 'changes->error_code' => 'TOKEN_REPLAYED']);
        $this->postJson('/api/v1/billing/checkout')->assertStatus(503); // no fake payment success
        $this->postJson('/api/v1/billing/subscription/cancel')->assertStatus(503);
    }

    public function test_expired_and_tampered_verification_are_rejected(): void
    {
        Notification::fake();
        $v = $this->viewer();
        $v->sendEmailVerificationNotification();
        $v->refresh();
        $url = app(Verification::class)->url($v);
        $this->signIn($v);
        $this->getJson($url.'x')->assertForbidden()->assertJsonPath('error.code', 'TOKEN_INVALID');
        $this->travel(31)->minutes();
        $this->getJson($url)->assertForbidden()->assertJsonPath('error.code', 'TOKEN_EXPIRED');
        $this->assertFalse($v->fresh()->hasVerifiedEmail());
    }

    public function test_correct_email_invalidates_old_link_and_cannot_alter_verified_account(): void
    {
        Notification::fake();
        $v = $this->viewer();
        $v->sendEmailVerificationNotification();
        $v->refresh();
        $old = app(Verification::class)->url($v);
        $this->signIn($v)->patchJson('/api/v1/auth/email', ['email' => 'correct@example.test', 'password' => 'LongPassword1234'])->assertOk();
        $this->getJson($old)->assertForbidden();
        $new = app(Verification::class)->url($v->fresh());
        $this->getJson($new)->assertOk();
        $this->travel(61)->seconds();
        $this->patchJson('/api/v1/auth/email', ['email' => 'again@example.test', 'password' => 'LongPassword1234'])->assertForbidden();
    }

    public function test_email_resend_is_limited_with_retry_after(): void
    {
        Notification::fake();
        $v = $this->viewer();
        $this->signIn($v)->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_reset_link_is_single_use_and_revokes_old_session(): void
    {
        $v = $this->viewer();
        $token = Password::broker('viewers')->createToken($v);
        $this->signIn($v);
        $body = ['email' => $v->email, 'token' => $token, 'password' => 'NewPassword1234', 'password_confirmation' => 'NewPassword1234'];
        $this->postJson('/api/v1/auth/reset-password', $body)->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'SESSION_REVOKED');
        $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(422)->assertJsonPath('error.code', 'TOKEN_INVALID');
        $this->assertTrue(Hash::check('NewPassword1234', $v->fresh()->password));
    }

    public function test_expired_reset_does_not_change_password(): void
    {
        $v = $this->viewer();
        $token = Password::broker('viewers')->createToken($v);
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/auth/reset-password', ['email' => $v->email, 'token' => $token, 'password' => 'NewPassword1234', 'password_confirmation' => 'NewPassword1234'])->assertStatus(422);
        $this->assertTrue(Hash::check('LongPassword1234', $v->fresh()->password));
    }

    public function test_password_recovery_does_not_enumerate_accounts(): void
    {
        Notification::fake();
        $v = $this->viewer();
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $v->email])->assertOk()->json();
        $missing = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        unset($known['request_id'],$missing['request_id']);
        $this->assertSame($known, $missing);
        Notification::assertSentTo($v, ResetPassword::class);
    }

    public function test_login_rate_limit_and_controlled_return(): void
    {
        $this->viewer();
        $this->postJson('/api/v1/auth/login', ['email' => 'viewer@example.test', 'password' => 'LongPassword1234', 'return_to' => '/dramas/abc/episodes/def?position=90'])->assertOk()->assertJsonPath('redirect_to', '/dramas/abc/episodes/def?position=90');
        $this->postJson('/api/v1/auth/logout')->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'bad@example.test', 'password' => 'bad'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'bad@example.test', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_guest_verification_continues_after_login(): void
    {
        Notification::fake();
        $v = $this->viewer();
        $v->sendEmailVerificationNotification();
        $url = app(Verification::class)->url($v->fresh());
        $this->get($url)->assertRedirect('/identity/login');
        $response = $this->postJson('/api/v1/auth/login', ['email' => $v->email, 'password' => 'LongPassword1234'])->assertOk();
        $this->assertStringStartsWith('/api/v1/auth/email/verify/', $response->json('redirect_to'));
        $this->getJson($response->json('redirect_to'))->assertOk();
    }
}
