<?php

namespace Tests\Feature\Identity;

use App\Contracts\AuditRecorder;
use App\Data\ResourceScope;
use App\Data\StaffIdentity;
use App\Enums\Permission as P;
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Services\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role, string $email = 'staff@example.test'): Staff
    {
        $s = Staff::create(['name' => 'Staff', 'email' => $email, 'password' => 'LongPassword1234', 'role' => $role]);
        $s->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $s->refresh();
    }

    private function signIn(Staff $s): static
    {
        return $this->actingAs($s, 'staff')->withSession(['identity.version.staff' => $s->session_version, 'identity.mfa_staff' => $s->id]);
    }

    public function test_fixed_roles_and_resource_scopes_match_business_rules(): void
    {
        $p = app(Permissions::class);
        $staff = fn ($role) => new StaffIdentity('actor', 'A', $role);
        foreach (P::cases() as $permission) {
            $this->assertTrue($p->allows($staff(Role::Owner), $permission));
        }
        $this->assertTrue($p->allows($staff(Role::ContentOperator), P::ContentManage));
        foreach ([P::PriceManage, P::FreeDefaultManage, P::SupportLookup, P::StaffManage, P::AcquisitionManage] as $permission) {
            $this->assertFalse($p->allows($staff(Role::ContentOperator), $permission));
        }
        $this->assertFalse($p->allows($staff(Role::Support), P::SupportLookup));
        $this->assertTrue($p->allows($staff(Role::Support), P::SupportLookup, new ResourceScope(caseLookup: true)));
        $this->assertFalse($p->allows($staff(Role::Support), P::SubscriptionCancel, new ResourceScope(caseLookup: true)));
        $this->assertTrue($p->allows($staff(Role::Support), P::SubscriptionCancel, new ResourceScope(caseLookup: true, verifiedCancellationRequest: true)));
        foreach ([P::SubscriptionResume, P::PaymentException, P::AudienceExport, P::PriceManage] as $permission) {
            $this->assertFalse($p->allows($staff(Role::Support), $permission, new ResourceScope(caseLookup: true, verifiedCancellationRequest: true)));
        }
        $this->assertTrue($p->allows($staff(Role::Acquisition), P::AcquisitionManage, new ResourceScope(ownerId: 'actor')));
        $this->assertTrue($p->allows($staff(Role::Acquisition), P::AcquisitionSummary, new ResourceScope(ownerId: 'other', assignedStaffIds: ['actor'])));
        $this->assertFalse($p->allows($staff(Role::Acquisition), P::AcquisitionManage, new ResourceScope(ownerId: 'other')));
        $this->assertFalse($p->allows($staff(Role::Acquisition), P::SupportLookup, new ResourceScope(caseLookup: true)));
    }

    public function test_staff_api_rejects_non_owner_direct_requests_and_audits_denial(): void
    {
        foreach ([Role::ContentOperator, Role::Support, Role::Acquisition] as $role) {
            $s = $this->staff($role, $role->value.'@example.test');
            $this->signIn($s)->getJson('/api/v1/admin/staff')->assertForbidden()->assertJsonPath('error.code', 'NOT_AUTHORIZED');
        }
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'permission.denied', 'result' => 'denied']);
    }

    public function test_owner_can_create_change_and_disable_staff_with_version_control(): void
    {
        $owner = $this->staff(Role::Owner);
        $this->signIn($owner);
        $id = $this->postJson('/api/v1/admin/staff', ['name' => 'Operator', 'email' => 'op@example.test', 'password' => 'LongPassword1234', 'role' => 'content_operator'])->assertOk()->json('id');
        $this->patchJson('/api/v1/admin/staff/'.$id, ['version' => 1, 'role' => 'support', 'active' => false])->assertOk()->assertJsonPath('active', false)->assertJsonPath('version', 2);
        $this->patchJson('/api/v1/admin/staff/'.$id, ['version' => 1, 'role' => 'owner', 'active' => true])->assertStatus(409)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->patchJson('/api/v1/admin/staff/'.$owner->id, ['version' => 1, 'role' => 'support', 'active' => false])->assertForbidden();
    }

    public function test_missing_mfa_proof_does_not_unlock_staff_api(): void
    {
        $s = $this->staff(Role::Owner);
        $this->actingAs($s, 'staff')->withSession(['identity.version.staff' => 1])->getJson('/api/v1/admin/staff')->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
    }

    public function test_disabled_account_and_old_version_session_are_revoked(): void
    {
        $s = $this->staff(Role::Owner);
        $this->signIn($s);
        $s->forceFill(['active' => false])->save();
        $this->getJson('/api/v1/admin/staff')->assertUnauthorized()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
        $s->forceFill(['active' => true, 'session_version' => 2])->save();
        $this->actingAs($s, 'staff')->withSession(['identity.version.staff' => 1])->getJson('/api/v1/admin/staff')->assertUnauthorized()->assertJsonPath('error.code', 'SESSION_REVOKED');
    }

    public function test_audit_uses_allowlisted_changes_and_request_ids(): void
    {
        app(AuditRecorder::class)->record('test.action', 'staff', null, 'success', ['role' => 'support', 'password' => 'secret', 'token' => 'secret', 'email' => 'private@example.test', 'url' => 'https://signed.example.test']);
        $row = DB::table('identity_audit_events')->first();
        $this->assertSame(['role' => 'support'], json_decode($row->changes, true));
        $this->getJson('/not-a-route')->assertNotFound()->assertHeader('X-Request-ID')->assertJsonStructure(['error' => ['code', 'message', 'request_id', 'fields', 'retryable']]);
    }
}
