<?php

namespace Tests\Feature\Identity;

use App\Enums\Role;
use App\Modules\Identity\Filament\Pages\StaffAccounts;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Services\StaffManager;
use App\Support\ApiProblem;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class StaffAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Livewire's synthetic requests disable web middleware; attach the real test session.
        $session = app('session.store');
        request()->setLaravelSession($session);
        $this->app->rebinding('request', static function ($app, $request) use ($session): void {
            $request->setLaravelSession($session);
        });
    }

    private function employee(Role $role = Role::Owner, string $email = 'owner@example.test'): Staff
    {
        $staff = Staff::create(['name' => 'Employee', 'email' => $email, 'password' => 'ExistingPassword123', 'role' => $role]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $staff->refresh();
    }

    private function signIn(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => $staff->session_version, 'identity.mfa_staff' => $staff->id]);
        $session = app('session.store');
        $cookie = config('h5.workspace').'_admin';
        config(['session.cookie' => $cookie, 'session.encrypt' => true]);
        $session->setName($cookie);
        auth('staff')->login($staff);
        $session->put('identity.version.staff', $staff->session_version);
        $session->put('identity.mfa_staff', $staff->id);
        $session->save();
        request()->setLaravelSession($session);
        $this->withCookie($cookie, $session->getId());
    }

    public function test_tabs_restore_from_url_and_only_render_selected_business_table(): void
    {
        $this->signIn($this->employee());
        $page = Livewire::withQueryParams(['tab' => 'audit'])->test(StaffAccounts::class)
            ->assertSet('tab', 'audit')->assertSee('最近 30 条脱敏记录')->assertDontSee('owner@example.test');
        $page->call('selectTab', 'accounts')->assertSet('tab', 'accounts')->assertSee('owner@example.test')->assertDontSee('最近 30 条脱敏记录');
        $page->call('selectTab', 'invalid')->assertHasErrors('tab')->assertSet('tab', 'accounts');
        Livewire::withQueryParams(['tab' => 'invalid'])->test(StaffAccounts::class)->assertSet('tab', 'accounts');
    }

    public function test_create_dialog_keeps_invalid_input_and_clears_secrets_only_after_save_or_discard(): void
    {
        $this->signIn($this->employee());
        $page = Livewire::test(StaffAccounts::class)->call('openCreate')
            ->set('newStaff', ['name' => 'New employee', 'email' => 'new@example.test', 'role' => 'support', 'password' => 'abcdef1'])
            ->call('saveEditor')->assertReturned(false)->assertHasErrors('newStaff.password')
            ->assertSet('newStaff.name', 'New employee');
        $this->assertDatabaseMissing('identity_staff', ['email' => 'new@example.test']);
        $page->set('newStaff.password', 'abcdefg1')->call('saveEditor')->assertReturned(true)->assertHasNoErrors()->assertSet('newStaff.password', '');
        $created = Staff::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame(Role::Support, $created->role);
        $this->assertTrue(Hash::check('abcdefg1', $created->password));
        $this->assertNull($created->getAppAuthenticationSecret());
        $audit = DB::table('identity_audit_events')->where('action', 'staff.created')->first();
        $this->assertSame(['role' => 'support'], json_decode($audit->changes, true));
        $page->call('openCreate')->set('newStaff.password', 'discard1')->call('discardEditor')->assertReturned(true)->assertSet('newStaff.password', '');
        $this->assertSame(2, Staff::count());
    }

    public function test_edit_dialog_revokes_target_sessions_and_detects_concurrent_updates(): void
    {
        $this->signIn($this->employee());
        $target = $this->employee(Role::ContentOperator, 'operator@example.test');
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $page = Livewire::test(StaffAccounts::class)->call('openStaff', $target->id)
            ->assertSet('editingId', $target->id)->set('editStaff.role', 'support')->set('editStaff.active', false)
            ->call('saveEditor')->assertReturned(true)->assertHasNoErrors()->assertSet('editingId', null);
        $this->assertFalse($target->fresh()->active);
        $this->assertSame(Role::Support, $target->fresh()->role);
        $this->assertSame(2, $target->fresh()->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $page->call('openStaff', $target->id)->set('editStaff.role', 'acquisition');
        app(StaffManager::class)->update($target->id, ['version' => 2, 'role' => 'content_operator', 'active' => true]);
        $page->call('saveEditor')->assertReturned(false)->assertHasErrors('editor')
            ->assertSet('editingId', $target->id)->assertSet('editStaff.role', 'acquisition');
        $this->assertSame(Role::ContentOperator, $target->fresh()->role);
        $this->assertSame(3, $target->fresh()->version);
        $page->call('discardEditor')->call('openStaff', $target->id)->assertSet('editStaff.version', 3);
    }

    public function test_non_owner_direct_page_access_is_denied_for_each_other_role(): void
    {
        foreach ([Role::ContentOperator, Role::Support, Role::Acquisition] as $role) {
            $this->signIn($this->employee($role, $role->value.'@example.test'));
            $this->get('/admin/staff-accounts')->assertForbidden();
        }
    }

    public function test_missing_mfa_cannot_load_staff_page(): void
    {
        $this->signIn($this->employee());
        session()->forget('identity.mfa_staff');
        session()->save();
        $this->get('/admin/staff-accounts')->assertRedirect('/admin/login');
    }

    public function test_each_public_mutation_rechecks_owner_permission(): void
    {
        $this->signIn($this->employee(Role::Support));
        $page = new StaffAccounts;
        foreach (['openCreate' => [], 'openStaff' => ['unknown'], 'discardEditor' => [], 'saveEditor' => [], 'selectTab' => ['audit']] as $method => $arguments) {
            try {
                $page->{$method}(...$arguments);
                $this->fail('Every public action must authorize on the server.');
            } catch (ApiProblem $exception) {
                $this->assertSame('NOT_AUTHORIZED', $exception->errorCode);
            }
        }
    }

    public function test_editor_target_cannot_be_changed_by_a_forged_property_update(): void
    {
        $owner = $this->employee();
        $this->signIn($owner);
        $target = $this->employee(Role::Support, 'target@example.test');
        $page = Livewire::test(StaffAccounts::class)->call('openStaff', $target->id);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('editingId', $owner->id);
    }

    public function test_current_owner_cannot_disable_or_demote_self_through_editor(): void
    {
        $owner = $this->employee();
        $this->signIn($owner);
        try {
            Livewire::test(StaffAccounts::class)->call('openStaff', $owner->id)
                ->set('editStaff.role', 'support')->set('editStaff.active', false)->call('saveEditor');
            $this->fail('Self-demotion must be denied.');
        } catch (ApiProblem $exception) {
            $this->assertSame('NOT_AUTHORIZED', $exception->errorCode);
        }
        $this->assertTrue($owner->fresh()->active);
        $this->assertSame(Role::Owner, $owner->fresh()->role);
    }
}
