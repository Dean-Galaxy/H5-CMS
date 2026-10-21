<?php

namespace Tests\Feature\Identity;

use App\Enums\Role;
use App\Modules\Identity\Filament\Pages\StaffAccounts;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\StaffAudit;
use App\Modules\Identity\Services\StaffManager;
use App\Modules\Identity\Services\Verification;
use App\Support\ApiProblem;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

    private function auditEvent(array $attributes = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('identity_audit_events')->insert(array_replace([
            'id' => $id, 'actor_kind' => 'system', 'actor_id' => null,
            'action' => 'permission.denied', 'subject_type' => 'permission', 'subject_id' => null,
            'result' => 'denied', 'changes' => '{}', 'request_id' => (string) Str::uuid7(),
            'occurred_at' => '2026-10-21 16:05:00+00',
        ], $attributes));

        return $id;
    }

    public function test_audit_renders_beijing_time_and_masked_real_staff_without_changing_utc_facts(): void
    {
        $this->signIn($this->employee());
        $actor = $this->employee(Role::ContentOperator, 'private-actor@example.test');
        $actor->update(['name' => '张真实姓名', 'active' => false]);
        $id = $this->auditEvent(['actor_kind' => 'staff', 'actor_id' => $actor->id,
            'action' => 'staff.updated', 'result' => 'success', 'changes' => json_encode(['password' => 'never-expose', 'email' => $actor->email])]);
        $page = Livewire::withQueryParams(['tab' => 'audit'])->test(StaffAccounts::class)
            ->assertSee('2026-10-22 00:05')->assertSee('修改员工权限或状态')->assertSee('成功')
            ->assertSee('张＊＊ · '.substr($actor->id, -6))->assertSee('操作人')->assertSee('排查编号')
            ->assertDontSee('张真实姓名')->assertDontSee($actor->email)->assertDontSee($actor->id)
            ->assertDontSee('never-expose')->assertDontSee('staff.updated');
        $this->assertSame('2026-10-21 16:05:00+00', DB::table('identity_audit_events')->where('id', $id)->value('occurred_at'));
        $this->assertSame(['id', 'time', 'action', 'result', 'actor', 'request_id'], array_keys(app(StaffAudit::class)->recent()->firstWhere('id', $id)));
    }

    public function test_audit_does_not_invent_or_cross_resolve_missing_viewer_and_unknown_actors(): void
    {
        $owner = $this->employee();
        $this->signIn($owner);
        $owner->update(['name' => '保密负责人']);
        $missing = $this->auditEvent(['actor_kind' => 'staff', 'actor_id' => (string) Str::uuid7()]);
        $viewer = $this->auditEvent(['actor_kind' => 'viewer', 'actor_id' => $owner->id]);
        $anonymous = $this->auditEvent(['action' => 'login.failed']);
        $unknown = $this->auditEvent(['actor_kind' => 'future-kind', 'actor_id' => $owner->id,
            'action' => '<script>private-operation</script>', 'result' => 'secret-result']);
        $rows = app(StaffAudit::class)->recent()->keyBy('id');
        $this->assertStringStartsWith('员工（账号不可用）', $rows[$missing]['actor']);
        $this->assertSame('观众（已脱敏）', $rows[$viewer]['actor']);
        $this->assertSame('未记录操作者', $rows[$anonymous]['actor']);
        $this->assertSame('未记录操作者', $rows[$unknown]['actor']);
        Livewire::withQueryParams(['tab' => 'audit'])->test(StaffAccounts::class)
            ->assertSee('其他操作（未识别类型）')->assertSee('结果未识别')
            ->assertDontSee('保密负责人')->assertDontSee('private-operation')->assertDontSee('secret-result');
    }

    public function test_audit_distinguishes_mail_preview_accepted_request_and_simulation_from_completion(): void
    {
        $this->signIn($this->employee());
        $preview = $this->auditEvent(['action' => 'verification.sent', 'result' => 'success', 'changes' => '{"mail_mode":"log"}']);
        $send = $this->auditEvent(['action' => 'verification.sent', 'result' => 'success', 'changes' => '{"mail_mode":"smtp"}']);
        $pending = $this->auditEvent(['action' => 'billing.support.cancel_requested', 'result' => 'accepted']);
        $simulated = $this->auditEvent(['action' => 'acquisition.pixel.local_probe', 'result' => 'simulated']);
        $revoked = $this->auditEvent(['action' => 'session.revoked', 'result' => 'denied']);
        $rows = app(StaffAudit::class)->recent()->keyBy('id');
        $this->assertSame('旧会话已失效', $rows[$revoked]['result']);
        $this->assertSame('已生成本地预览', $rows[$preview]['result']);
        $this->assertSame('已提交发送（送达未确认）', $rows[$send]['result']);
        $this->assertSame('请求已受理', $rows[$pending]['result']);
        $this->assertSame('仅本地模拟', $rows[$simulated]['result']);
    }

    public function test_real_verification_preview_is_recorded_and_displayed_without_claiming_external_send(): void
    {
        $this->signIn($this->employee());
        $directory = storage_path('framework/testing/mail-preview-'.Str::uuid7());
        config(['mail.default' => 'preview', 'h5.mail_preview_root' => $directory]);
        Mail::purge('preview');
        $viewer = Viewer::create(['name' => 'Preview viewer', 'email' => 'preview@example.test', 'password' => 'PreviewPassword123']);

        try {
            // Real notification and PreviewTransport, with no mail fake or external transport.
            app(Verification::class)->send($viewer);
            $this->assertCount(1, glob($directory.'/*.eml'));
            $previews = glob($directory.'/*.html');
            $this->assertCount(1, $previews);
            $this->assertStringContainsString('仅本地邮件预览：未发送到真实邮箱。', File::get($previews[0]));
            $event = DB::table('identity_audit_events')->where('action', 'verification.sent')
                ->where('subject_id', $viewer->id)->sole();
            $this->assertSame('success', $event->result);
            $this->assertSame(['mail_mode' => 'preview'], json_decode($event->changes, true));
            $this->assertSame('已生成本地预览', app(StaffAudit::class)->recent()->firstWhere('id', $event->id)['result']);
            Livewire::withQueryParams(['tab' => 'audit'])->test(StaffAccounts::class)
                ->assertSee('已生成本地预览')->assertDontSee('已提交发送')->assertDontSee($viewer->email);
        } finally {
            Mail::purge('preview');
            File::deleteDirectory($directory);
        }
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_audit_missing_unknown_and_aggregate_mail_modes_remain_uncertain(): void
    {
        $this->signIn($this->employee());
        $ids = [];
        foreach ([[], ['mail_mode' => null], ['mail_mode' => 'unknown-mailer'],
            ['mail_mode' => 'failover'], ['mail_mode' => 'roundrobin'], ['mail_mode' => false]] as $changes) {
            $ids[] = $this->auditEvent(['action' => 'verification.sent', 'result' => 'success', 'changes' => json_encode($changes)]);
        }
        // A current SMTP setting cannot turn missing historical evidence into a send claim.
        config(['mail.default' => 'smtp']);
        $rows = app(StaffAudit::class)->recent()->keyBy('id');
        foreach ($ids as $id) {
            $this->assertSame('邮件处理已记录（发送方式未确认）', $rows[$id]['result']);
        }
        Livewire::withQueryParams(['tab' => 'audit'])->test(StaffAccounts::class)
            ->assertSee('邮件处理已记录（发送方式未确认）')->assertDontSee('已提交发送')->assertDontSee('已生成本地预览');
    }

    public function test_audit_keeps_latest_thirty_and_rechecks_owner_and_mfa_before_reading(): void
    {
        $owner = $this->employee();
        $this->signIn($owner);
        $ids = [];
        for ($i = 0; $i < 31; $i++) {
            $ids[] = $this->auditEvent();
        }
        rsort($ids);
        $this->assertSame(array_slice($ids, 0, 30), app(StaffAudit::class)->recent()->pluck('id')->all());
        foreach ([Role::ContentOperator, Role::Support, Role::Acquisition] as $role) {
            $this->signIn($this->employee($role, $role->value.'-audit@example.test'));
            try {
                app(StaffAudit::class)->recent();
                $this->fail('Audit must remain owner-only.');
            } catch (ApiProblem $exception) {
                $this->assertSame('NOT_AUTHORIZED', $exception->errorCode);
            }
            $this->get('/admin/staff-accounts?tab=audit')->assertForbidden();
        }
        $this->signIn($owner);
        session()->forget('identity.mfa_staff');
        try {
            app(StaffAudit::class)->recent();
            $this->fail('Audit must require MFA.');
        } catch (ApiProblem $exception) {
            $this->assertSame('MFA_REQUIRED', $exception->errorCode);
        }
    }
}
