<?php

namespace Tests\Feature\Contracts;

use App\Enums\Role;
use App\Modules\Identity\Filament\StaffProfile;
use App\Modules\Identity\Models\Staff;
use App\Support\Admin\StaffAvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $session = app('session.store');
        $session->flush();
        $cookie = config('h5.workspace').'_admin';
        config(['session.cookie' => $cookie, 'session.encrypt' => true]);
        $session->setName($cookie);
        $this->actingAs($staff, 'staff');
        auth('staff')->login($staff);
        $session->put('identity.version.staff', $staff->session_version);
        $session->put('identity.mfa_staff', $staff->id);
        $session->save();
        $this->withCookie($cookie, $session->getId());
    }

    public function test_actual_staff_profile_route_uses_staff_policy_and_same_origin_avatar(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertSame(StaffProfile::class, $panel->getProfilePage());
        $this->assertSame(StaffAvatarProvider::class, $panel->getDefaultAvatarProvider());
        foreach (Role::cases() as $role) {
            $staff = Staff::create(['name' => 'Private Staff Name', 'email' => $role->value.'@example.test', 'password' => 'ExistingPassword123', 'role' => $role]);
            $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $staff->refresh();
            $this->signIn($staff);
            $this->get('/admin/profile')->assertOk()->assertSee('minlength="8"', false)->assertSee('maxlength="128"', false)
                ->assertDontSee('ui-avatars.com', false);
            $this->get('/admin')->assertOk()
                ->assertSee('/admin-ui/staff-avatar.svg', false)->assertDontSee('ui-avatars.com', false);
            $this->assertSame(asset('admin-ui/staff-avatar.svg'), app(StaffAvatarProvider::class)->get($staff));
        }
        $this->assertFileExists(public_path('admin-ui/staff-avatar.svg'));
    }

    public function test_guest_cannot_open_staff_profile_and_fixture_is_not_a_production_route(): void
    {
        $this->get('/admin/profile')->assertRedirect('/admin/login');
        $this->get('/admin-ui-livewire')->assertNotFound();
    }

    public function test_staff_contract_is_relaxed_without_changing_viewer_passwords(): void
    {
        $schemas = json_decode(file_get_contents(base_path('../docs/contracts/openapi.yaml')), true)['components']['schemas'];
        $this->assertSame(8, $schemas['StaffCreate']['properties']['password']['minLength']);
        $this->assertSame(128, $schemas['StaffCreate']['properties']['password']['maxLength']);
        foreach (['Register', 'ResetPassword'] as $name) {
            $this->assertSame(12, $schemas[$name]['properties']['password']['minLength']);
        }
        // Email correction verifies an existing password, it does not create one.
        $this->assertSame(1, $schemas['EmailCorrection']['properties']['password']['minLength']);
    }
}
