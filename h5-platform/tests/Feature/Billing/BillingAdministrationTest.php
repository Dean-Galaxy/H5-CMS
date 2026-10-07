<?php

namespace Tests\Feature\Billing;

use App\Contracts\BillingService;
use App\Contracts\MembershipReader;
use App\Data\Membership;
use App\Enums\Role;
use App\Enums\SubscriptionState;
use App\Modules\Billing\Services\BillingAdministration;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Staff;
use App\Support\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BillingAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_prices_append_versions_and_never_overwrite_prior_terms(): void
    {
        $this->signIn(Role::Owner);
        $admin = app(BillingAdministration::class);
        $this->assertNull($admin->currentPrice());
        $first = $admin->savePrice($this->price());
        $second = $admin->savePrice($this->price(['version' => 1, 'regular_amount_minor' => 1500]));
        $this->assertSame(2, $second['version']);
        $this->assertDatabaseHas('billing_prices', ['id' => $first['id'], 'version' => 1, 'regular_amount_minor' => 1200, 'is_test' => true]);
        $this->assertDatabaseCount('billing_prices', 2);
        $this->problem('STALE_VERSION', fn () => $admin->savePrice($this->price(['version' => 1])));
        $this->assertDatabaseCount('billing_prices', 2);
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'billing.price.created', 'subject_id' => $second['id']]);
    }

    public function test_invalid_amounts_and_non_test_prices_cannot_be_saved(): void
    {
        $this->signIn(Role::Owner);
        foreach ([['regular_amount_minor' => 0], ['first_week_amount_minor' => 0], ['first_week_amount_minor' => 1300],
            ['regular_amount_minor' => 12.5], ['is_test' => false], ['version' => -1]] as $invalid) {
            try {
                app(BillingAdministration::class)->savePrice($this->price($invalid));
                $this->fail('Invalid price must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('billing_prices', 0);
            }
        }
    }

    public function test_every_administration_action_requires_allowed_staff_and_mfa(): void
    {
        $admin = app(BillingAdministration::class);
        $id = BillingStore::id();
        foreach ([Role::ContentOperator, Role::Acquisition] as $role) {
            $this->signIn($role);
            foreach ([fn () => $admin->dashboard(), fn () => $admin->currentPrice(), fn () => $admin->savePrice($this->price()),
                fn () => $admin->openCase($id), fn () => $admin->caseDetail($id),
                fn () => $admin->recordVerification($id, ['identity_verified' => true, 'cancel_requested' => true]),
                fn () => $admin->addNote($id, 'Test note'), fn () => $admin->cancelForCase($id)] as $action) {
                $this->problem('NOT_AUTHORIZED', $action);
            }
        }
        $this->signIn(Role::Support);
        $this->problem('NOT_AUTHORIZED', fn () => $admin->currentPrice());
        $this->problem('NOT_AUTHORIZED', fn () => $admin->savePrice($this->price()));
        request()->session()->forget('identity.mfa_staff');
        $this->problem('MFA_REQUIRED', fn () => $admin->dashboard());
        $this->problem('MFA_REQUIRED', fn () => $admin->openCase($id));
    }

    public function test_case_scope_is_derived_from_order_and_cannot_be_replaced_with_another_staff_case(): void
    {
        $admin = app(BillingAdministration::class);
        $staffA = $this->signIn(Role::Support);
        $order = $this->order();
        $caseId = $admin->openCase($order['id']);
        $this->assertSame($caseId, $admin->openCase($order['id']));
        $this->assertDatabaseHas('billing_cases', ['id' => $caseId, 'viewer_id' => $order['viewer_id'], 'staff_id' => $staffA->id]);
        $this->signIn(Role::Support);
        $this->assertSame([], $admin->dashboard()['cases']);
        $this->assertSame([], $admin->dashboard()['orders']);
        foreach ([fn () => $admin->caseDetail($caseId), fn () => $admin->addNote($caseId, 'Not my case'),
            fn () => $admin->recordVerification($caseId, ['identity_verified' => true, 'cancel_requested' => true]),
            fn () => $admin->cancelForCase($caseId)] as $action) {
            $this->problem('NOT_FOUND', $action);
        }
        $this->assertDatabaseCount('billing_notes', 0);
        $this->assertDatabaseHas('billing_cases', ['id' => $caseId, 'identity_verified' => false, 'cancel_requested' => false]);
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'billing.case.access_denied', 'result' => 'denied']);
    }

    public function test_support_view_and_notes_use_minimal_scoped_fields_and_safe_audit(): void
    {
        $this->signIn(Role::Support);
        $admin = app(BillingAdministration::class);
        $order = $this->order();
        $caseId = $admin->openCase($order['id']);
        $this->mock(MembershipReader::class, function ($mock) use ($order) {
            $mock->shouldReceive('membership')->once()->withArgs(fn ($viewerId, $at) => $viewerId === $order['viewer_id'] && $at instanceof \DateTimeImmutable)
                ->andReturn($this->membership());
        });
        $admin->addNote($caseId, 'User requested cancellation; no payment credentials retained.');
        $detail = $admin->caseDetail($caseId);
        $this->assertArrayNotHasKey('checkout_url', $detail['order']);
        $this->assertArrayNotHasKey('checkout_id', $detail['order']);
        $this->assertArrayNotHasKey('attribution_snapshot', $detail['order']);
        $this->assertSame($order['viewer_id'], $detail['case']['viewer_id']);
        $this->assertCount(1, $detail['notes']);
        $audit = DB::table('identity_audit_events')->where('action', 'billing.case.note_added')->first();
        $this->assertSame([], json_decode($audit->changes, true));
        $this->assertStringNotContainsString('credentials', $audit->changes);
    }

    public function test_verified_cancel_dispatches_only_persisted_identity_and_returns_processing_unchanged(): void
    {
        $staff = $this->signIn(Role::Support);
        $admin = app(BillingAdministration::class);
        $order = $this->order();
        $caseId = $admin->openCase($order['id']);
        $this->problem('NOT_AUTHORIZED', fn () => $admin->cancelForCase($caseId));
        $admin->recordVerification($caseId, ['identity_verified' => true, 'cancel_requested' => false]);
        $this->problem('NOT_AUTHORIZED', fn () => $admin->cancelForCase($caseId));
        $admin->recordVerification($caseId, ['identity_verified' => true, 'cancel_requested' => true]);
        $pending = $this->membership(true);
        $this->mock(BillingService::class, function ($mock) use ($staff, $order, $caseId, $pending) {
            $mock->shouldReceive('cancelForSupport')->once()->withArgs(fn ($actor, $viewerId, $id) => $actor->id === $staff->id && $viewerId === $order['viewer_id'] && $id === $caseId)
                ->andReturn($pending);
        });
        $this->assertSame($pending, $admin->cancelForCase($caseId));
        $admin->recordVerification($caseId, ['identity_verified' => false, 'cancel_requested' => true]);
        $this->assertDatabaseHas('billing_cases', ['id' => $caseId, 'verified_at' => null]);
        $this->problem('NOT_AUTHORIZED', fn () => $admin->cancelForCase($caseId));
    }

    public function test_owner_still_needs_verified_request_and_malformed_case_link_is_rejected(): void
    {
        $this->signIn(Role::Owner);
        $admin = app(BillingAdministration::class);
        $order = $this->order();
        $caseId = $admin->openCase($order['id']);
        $this->problem('CANCELLATION_NOT_VERIFIED', fn () => $admin->cancelForCase($caseId));
        DB::table('billing_cases')->where('id', $caseId)->update(['viewer_id' => BillingStore::id()]);
        $this->problem('NOT_FOUND', fn () => $admin->addNote($caseId, 'Cannot alter cross-account case.'));
        $this->assertDatabaseCount('billing_notes', 0);
    }

    private function signIn(Role $role): Staff
    {
        $staff = Staff::create(['name' => 'Billing test staff', 'email' => BillingStore::id().'@example.test', 'password' => 'TestPassword1234', 'role' => $role]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->refresh();
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => $staff->session_version, 'identity.mfa_staff' => $staff->id]);
        request()->setLaravelSession(app('session.store'));

        return $staff;
    }

    private function price(array $changes = []): array
    {
        return array_replace(['version' => 0, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'is_test' => true], $changes);
    }

    private function order(): array
    {
        $order = [
            'id' => BillingStore::id(), 'viewer_id' => BillingStore::id(), 'subscription_id' => BillingStore::id(),
            'idempotency_key' => BillingStore::id(), 'fingerprint' => str_repeat('a', 64), 'transaction_kind' => 'first_purchase',
            'price_version' => 1, 'amount_minor' => 300, 'regular_amount_minor' => 1200, 'discount_applied' => true,
            'currency' => 'USD', 'is_test' => true, 'state' => 'pending', 'payment_state' => 'pending',
            'checkout_id' => 'test_checkout_'.BillingStore::id(), 'checkout_url' => 'https://example.test/private-checkout',
            'return_to' => '/', 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('billing_orders')->insert($order);

        return $order;
    }

    private function membership(bool $pending = false): Membership
    {
        return new Membership(false, null, null, false, $pending ? SubscriptionState::Unknown : SubscriptionState::None, $pending, null, null, []);
    }

    private function problem(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected '.$code);
        } catch (ApiProblem $problem) {
            $this->assertSame($code, $problem->errorCode);
        }
    }
}
