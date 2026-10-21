<?php

namespace Tests\Feature\Identity;

use App\Enums\Role;
use App\Modules\Identity\Actions\CreateViewer;
use App\Modules\Identity\Actions\ResetViewerPassword;
use App\Modules\Identity\Filament\StaffProfile;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\StaffPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffPasswordTest extends TestCase
{
    use RefreshDatabase;

    public static function passwords(): array
    {
        return [
            'eight' => ['abcdefg1', true],
            'seven' => ['abcdef1', false],
            'letters only' => ['abcdefgh', false],
            'numbers only' => ['12345678', false],
            'maximum' => [str_repeat('a', 127).'1', true],
            'too long' => [str_repeat('a', 128).'1', false],
        ];
    }

    private function staff(Role $role): Staff
    {
        $staff = Staff::create(['name' => 'Existing employee', 'email' => 'existing@example.test', 'password' => 'ExistingPassword123', 'role' => $role]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $staff->refresh();
    }

    private function signIn(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => $staff->session_version, 'identity.mfa_staff' => $staff->id]);
    }

    #[DataProvider('passwords')]
    public function test_staff_creation_api_enforces_staff_password_policy(string $password, bool $valid): void
    {
        $this->signIn($this->staff(Role::Owner));
        $response = $this->postJson('/api/v1/admin/staff', ['name' => 'New employee', 'email' => 'new@example.test', 'role' => 'support', 'password' => $password]);
        if ($valid) {
            $response->assertOk();
            $staff = Staff::where('email', 'new@example.test')->firstOrFail();
            $this->assertTrue(Hash::check($password, $staff->password));
            $this->assertNull($staff->getAppAuthenticationSecret());
        } else {
            $response->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['password']]]);
            $this->assertDatabaseMissing('identity_staff', ['email' => 'new@example.test']);
        }
    }

    #[DataProvider('passwords')]
    public function test_first_owner_command_enforces_the_same_policy(string $password, bool $valid): void
    {
        $this->artisan('identity:owner')
            ->expectsQuestion('负责人姓名', 'Owner')
            ->expectsQuestion('负责人邮箱', 'owner@example.test')
            ->expectsQuestion(StaffPassword::OWNER_PROMPT, $password)
            ->assertExitCode($valid ? 0 : 1);
        $staff = Staff::where('email', 'owner@example.test')->first();
        $this->assertSame($valid, $staff !== null);
        if ($valid) {
            $this->assertSame(Role::Owner, $staff->role);
            $this->assertTrue(Hash::check($password, $staff->password));
        }
    }

    #[DataProvider('passwords')]
    public function test_staff_self_password_change_enforces_same_policy(string $password, bool $valid): void
    {
        $staff = $this->staff(Role::Support);
        $this->signIn($staff);
        $page = Livewire::test(StaffProfile::class)
            ->set('data.password', $password)
            ->set('data.passwordConfirmation', $password)
            ->set('data.currentPassword', 'ExistingPassword123')
            ->call('save');
        if ($valid) {
            $page->assertHasNoErrors();
            $this->assertTrue(Hash::check($password, $staff->fresh()->password));
            $this->assertSame(2, $staff->fresh()->session_version);
            $this->getJson('/api/v1/admin/staff')->assertUnauthorized()->assertJsonPath('error.code', 'SESSION_REVOKED');
        } else {
            $page->assertHasErrors(['data.password']);
            $this->assertTrue(Hash::check('ExistingPassword123', $staff->fresh()->password));
            $this->assertSame(1, $staff->fresh()->session_version);
        }
    }

    public function test_admin_profile_route_uses_staff_policy_and_keeps_mfa_gate(): void
    {
        $staff = $this->staff(Role::Support);
        $this->signIn($staff);
        $session = app('session.store');
        $cookie = config('h5.workspace').'_admin';
        config(['session.cookie' => $cookie, 'session.encrypt' => true]);
        $session->setName($cookie);
        auth('staff')->login($staff);
        $session->put('identity.version.staff', $staff->session_version);
        $session->put('identity.mfa_staff', $staff->id);
        $session->save();
        $this->withCookie($cookie, $session->getId());
        $this->assertSame(StaffProfile::class, Filament::getPanel('admin')->getProfilePage());
        $this->get('/admin/profile')->assertOk()->assertSee(StaffPassword::HELP);
        session()->forget('identity.mfa_staff');
        session()->save();
        $this->get('/admin/profile')->assertRedirect('/admin/login');
    }

    public function test_staff_password_change_still_requires_current_password_and_confirmation(): void
    {
        $staff = $this->staff(Role::Owner);
        $this->signIn($staff);
        $page = Livewire::test(StaffProfile::class)
            ->set('data.password', 'abcdefg1')->set('data.passwordConfirmation', 'abcdefg1')
            ->set('data.currentPassword', 'incorrect')->call('save')->assertHasErrors(['data.currentPassword']);
        $page->set('data.currentPassword', 'ExistingPassword123')->set('data.passwordConfirmation', 'different1')
            ->call('save')->assertHasErrors(['data.password']);
        $this->assertTrue(Hash::check('ExistingPassword123', $staff->fresh()->password));
        $this->assertSame(1, $staff->fresh()->session_version);
    }

    public function test_viewer_registration_and_reset_keep_twelve_character_minimum(): void
    {
        Notification::fake();
        $viewer = Viewer::create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'ViewerPassword123']);
        foreach (['abcdefg1', 'abcdefghij1'] as $password) {
            foreach (['create', 'reset'] as $action) {
                try {
                    $input = ['name' => 'New viewer', 'email' => 'newviewer@example.test', 'password' => $password, 'password_confirmation' => $password];
                    $action === 'create' ? app(CreateViewer::class)->create($input) : app(ResetViewerPassword::class)->reset($viewer, $input);
                    $this->fail('Viewer must reject passwords shorter than 12 characters.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('password', $exception->errors());
                }
            }
        }
        $this->assertTrue(Hash::check('ViewerPassword123', $viewer->fresh()->password));
        $password = 'abcdefghijk1';
        $input = ['name' => 'New viewer', 'email' => 'newviewer@example.test', 'password' => $password, 'password_confirmation' => $password];
        $created = app(CreateViewer::class)->create($input);
        app(ResetViewerPassword::class)->reset($viewer, $input);
        $this->assertTrue(Hash::check($password, $created->password));
        $this->assertTrue(Hash::check($password, $viewer->fresh()->password));
    }
}
