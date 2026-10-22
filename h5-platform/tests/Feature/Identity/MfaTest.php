<?php

namespace Tests\Feature\Identity;

use App\Enums\Role;
use App\Modules\Identity\Filament\Auth\StaffAppAuthentication as AppAuthentication;
use App\Modules\Identity\Filament\StaffLogin;
use App\Modules\Identity\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    private function employee(bool $configured = true): Staff
    {
        // Test-only integration; the shared production panel is wired by task 00.
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true);
        Filament::setCurrentPanel($panel);
        $staff = Staff::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'LongPassword1234', 'role' => Role::Owner]);
        if ($configured) {
            $staff->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        }

        return $staff->refresh();
    }

    public function test_password_alone_and_empty_recovery_code_cannot_bypass_mfa(): void
    {
        $staff = $this->employee();
        $login = Livewire::test(StaffLogin::class)->set('data.email', $staff->email)->set('data.password', 'LongPassword1234')->call('authenticate');
        $this->assertFalse(Auth::guard('staff')->check());
        $login->set('data.multiFactor.app.useRecoveryCode', true)->set('data.multiFactor.app.recoveryCode', '')->set('data.multiFactor.app.code', '')->call('authenticate')->assertHasErrors();
        $this->assertFalse(Auth::guard('staff')->check());
    }

    public function test_real_totp_challenge_authenticates_and_sets_session_proof(): void
    {
        $staff = $this->employee();
        $login = Livewire::test(StaffLogin::class)->set('data.email', $staff->email)->set('data.password', 'LongPassword1234')->call('authenticate');
        $code = app(AppAuthentication::class)->getCurrentCode($staff);
        $login->set('data.multiFactor.app.code', $code)->call('authenticate')->assertHasNoErrors();
        $this->assertSame($staff->id, Auth::guard('staff')->id());
        $this->assertSame($staff->id, session('identity.mfa_staff'));
    }

    public function test_totp_and_recovery_codes_cannot_be_replayed(): void
    {
        $staff = $this->employee();
        $provider = app(AppAuthentication::class);
        $code = $provider->getCurrentCode($staff);
        $this->assertTrue($provider->verifyCode($code, $staff->getAppAuthenticationSecret(), shouldPreventCodeReuse: true));
        $this->assertFalse($provider->verifyCode($code, $staff->getAppAuthenticationSecret(), shouldPreventCodeReuse: true));
        $staff->saveAppAuthenticationRecoveryCodes([Hash::make('recovery-one'), Hash::make('recovery-two')]);
        $this->assertTrue($provider->verifyRecoveryCode('recovery-one', $staff));
        $this->assertFalse($provider->verifyRecoveryCode('recovery-one', $staff));
        $this->assertTrue($provider->verifyRecoveryCode('recovery-two', $staff));
        $this->assertSame([], $staff->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_mfa_secrets_are_encrypted_and_hidden(): void
    {
        $staff = $this->employee();
        $this->assertNotSame($staff->getAppAuthenticationSecret(), $staff->getRawOriginal('app_authentication_secret'));
        $this->assertArrayNotHasKey('app_authentication_secret', $staff->toArray());
    }

    public function test_disabled_employee_cannot_reach_challenge_or_login(): void
    {
        $staff = $this->employee();
        $staff->forceFill(['active' => false])->save();
        Livewire::test(StaffLogin::class)->set('data.email', $staff->email)->set('data.password', 'LongPassword1234')->call('authenticate')->assertHasErrors(['data.email']);
        $this->assertFalse(Auth::guard('staff')->check());
    }

    public function test_first_staff_login_requires_enrollment_before_dashboard(): void
    {
        $staff = $this->employee(false);
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => 1])->get('/admin')->assertRedirect('/admin/multi-factor-authentication/set-up');
    }

    public function test_configured_staff_without_challenge_proof_cannot_open_any_panel_page(): void
    {
        $staff = $this->employee();
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => 1])->get('/admin')->assertRedirect('/admin/login');
        $this->assertFalse(Auth::guard('staff')->check());
    }

    public function test_generic_staff_login_event_does_not_grant_mfa_proof(): void
    {
        $staff = $this->employee();
        Auth::guard('staff')->login($staff);
        $this->assertNull(session('identity.mfa_staff'));
    }

    public function test_staff_password_change_invalidates_old_session_version(): void
    {
        $staff = $this->employee();
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => 1, 'identity.mfa_staff' => $staff->id]);
        $staff->password = 'NewLongPassword1234';
        $staff->save();
        $this->getJson('/api/v1/admin/staff')->assertUnauthorized()->assertJsonPath('error.code', 'SESSION_REVOKED');
    }
}
