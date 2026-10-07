<?php

namespace Tests\Feature\Billing;

use App\Data\CheckoutInput;
use App\Data\ResumeInput;
use App\Data\StaffIdentity;
use App\Enums\Role;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionState;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Staff;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

final class BillingReviewRegressionTest extends BillingTestCase
{
    public function test_expired_ambiguous_resume_becomes_terminal_and_does_not_lock_account_forever(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $this->engine->cancel($viewer);
        $quote = $this->engine->resumeQuote($viewer);
        $input = new ResumeInput($quote->quote_id, $quote->confirmation_version, $quote->next_renewal_at, $quote->next_renewal_amount_minor, $quote->currency);
        $this->gateway->failRenewal = true;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->resume($viewer, $input));
        $this->assertDatabaseHas('billing_resume_quotes', ['id' => $quote->quote_id, 'state' => 'pending']);
        $this->assertSame(1, DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->count());
        $calls = $this->gateway->renewalCalls;

        // Real provider termination is modeled separately from advancing local time.
        // This is a state-machine regression, not evidence of a Stripe Test Clock run.
        $this->travelTo(CarbonImmutable::parse($sub->period_end)->addSecond());
        $this->gateway->subscriptions[$sub->provider_id]['status'] = 'canceled';
        $this->gateway->subscriptions[$sub->provider_id]['cancel_at_period_end'] = true;
        $this->gateway->failRenewal = false;
        $this->engine->retryPendingOperations();
        app(SettlementService::class)->syncSubscription($sub->provider_id);

        $this->assertSame(0, DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->count());
        $this->assertNotSame('pending', DB::table('billing_resume_quotes')->where('id', $quote->quote_id)->value('state'));
        $membership = $this->engine->membership($viewer->id, now()->toDateTimeImmutable());
        $this->assertSame(SubscriptionState::Ended, $membership->subscription_state);
        $this->assertFalse($membership->payment_resolution_required);
        $this->assertFalse($membership->renewal_enabled);
        $this->assertContains(SubscriptionAction::Checkout, $membership->allowed_actions);
        $this->assertSame($calls, $this->gateway->renewalCalls);
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
    }

    public function test_owner_direct_support_cancel_cannot_bypass_verified_request_business_rule(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $owner = $this->staff(Role::Owner);
        $caseId = $this->caseFor($order, $owner, false);
        try {
            $this->engine->cancelForSupport(new StaffIdentity($owner->id, $owner->name, $owner->role), $viewer->id, $caseId);
            $this->fail('Even an owner must have a verified cancellation request.');
        } catch (ApiProblem $problem) {
            $this->assertSame(403, $problem->status);
        }
        $this->assertSame(0, $this->gateway->renewalCalls);
        $this->assertDatabaseCount('billing_operations', 0);
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $sub->id, 'renewal_enabled' => true, 'state' => 'active']);
    }

    public function test_case_verification_is_rechecked_after_account_lock_before_cancel_reservation(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $support = $this->staff(Role::Support);
        $caseId = $this->caseFor($order, $support, true);
        $injected = false;
        DB::listen(function (QueryExecuted $query) use ($caseId, &$injected): void {
            $sql = strtolower($query->sql);
            if ($injected || ! str_contains($sql, 'billing_accounts') || ! str_contains($sql, 'for update')) {
                return;
            }
            $injected = true;
            // Deterministically model verification cleared while cancellation awaited
            // the account lock. This tests the reread boundary, not process concurrency.
            DB::table('billing_cases')->where('id', $caseId)->update([
                'identity_verified' => false, 'cancel_requested' => false, 'verified_at' => null,
            ]);
        });

        try {
            $this->engine->cancelForSupport(new StaffIdentity($support->id, $support->name, $support->role), $viewer->id, $caseId);
            $this->fail('A verification snapshot taken before the account lock cannot authorize cancellation.');
        } catch (ApiProblem $problem) {
            $this->assertSame(403, $problem->status);
        }
        $this->assertTrue($injected, 'The regression must reach the account reservation lock.');
        $this->assertSame(0, $this->gateway->renewalCalls);
        $this->assertDatabaseCount('billing_operations', 0);
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $sub->id, 'state' => 'active', 'renewal_enabled' => true]);
    }

    public function test_unresolved_ended_subscription_blocks_checkout_and_membership_does_not_offer_it(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        DB::table('billing_subscriptions')->where('id', $sub->id)->update(['state' => 'ended', 'renewal_enabled' => false, 'resolution_required' => true]);
        $offer = $this->engine->offer($viewer);
        $calls = $this->gateway->createCalls;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer,
            new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), 'review-new-checkout', null));
        $membership = $this->engine->membership($viewer->id, now()->toDateTimeImmutable());
        $this->assertTrue($membership->payment_resolution_required);
        $this->assertNotContains(SubscriptionAction::Checkout, $membership->allowed_actions);
        $this->assertContains(SubscriptionAction::ResolvePayment, $membership->allowed_actions);
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertSame($calls, $this->gateway->createCalls);
    }

    public function test_consumed_support_case_cannot_cancel_viewer_later_explicit_resume(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $support = $this->staff(Role::Support);
        $caseId = $this->caseFor($order, $support, true);
        $actor = new StaffIdentity($support->id, $support->name, $support->role);
        $canceled = $this->engine->cancelForSupport($actor, $viewer->id, $caseId);
        $this->assertFalse($canceled->renewal_enabled);
        $this->assertDatabaseHas('billing_cases', ['id' => $caseId, 'identity_verified' => false, 'cancel_requested' => false, 'verified_at' => null]);
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'billing.support.cancel_requested', 'subject_id' => $caseId]);

        $quote = $this->engine->resumeQuote($viewer);
        $resumed = $this->engine->resume($viewer, new ResumeInput($quote->quote_id, $quote->confirmation_version,
            $quote->next_renewal_at, $quote->next_renewal_amount_minor, $quote->currency));
        $this->assertTrue($resumed->renewal_enabled);
        $calls = $this->gateway->renewalCalls;
        $operations = DB::table('billing_operations')->count();
        try {
            $this->engine->cancelForSupport($actor, $viewer->id, $caseId);
            $this->fail('A consumed support request cannot undo the viewer later explicit resume.');
        } catch (ApiProblem $problem) {
            $this->assertSame(403, $problem->status);
        }
        $this->assertTrue($this->engine->membership($viewer->id, now()->toDateTimeImmutable())->renewal_enabled);
        $this->assertSame($calls, $this->gateway->renewalCalls);
        $this->assertSame($operations, DB::table('billing_operations')->count());
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $sub->id, 'state' => 'active', 'renewal_enabled' => true]);
    }

    public function test_verified_old_case_cannot_cancel_a_later_subscription_for_same_viewer(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $support = $this->staff(Role::Support);
        $caseId = $this->caseFor($order, $support, true);
        $this->engine->cancel($viewer);
        $this->travelTo(CarbonImmutable::parse($sub->period_end)->addSecond());
        $this->gateway->subscriptions[$sub->provider_id]['status'] = 'canceled';
        app(SettlementService::class)->syncSubscription($sub->provider_id);
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $sub->id, 'state' => 'ended']);
        $newOrder = $this->checkoutFor($viewer);
        $calls = $this->gateway->renewalCalls;

        $this->assertProblem('STALE_VERSION', fn () => $this->engine->cancelForSupport(
            new StaffIdentity($support->id, $support->name, $support->role), $viewer->id, $caseId));
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $newOrder->subscription_id, 'state' => 'pending']);
        $this->assertSame(0, DB::table('billing_operations')->where('subscription_id', $newOrder->subscription_id)->count());
        $this->assertSame($calls, $this->gateway->renewalCalls);
    }

    public function test_pending_resume_keeps_original_quote_boundary_when_invoice_sync_advances_cycle(): void
    {
        [$viewer, $order, $sub, $invoiceId] = $this->paidFixture();
        $this->engine->cancel($viewer);
        $quote = $this->engine->resumeQuote($viewer);
        $input = new ResumeInput($quote->quote_id, $quote->confirmation_version,
            $quote->next_renewal_at, $quote->next_renewal_amount_minor, $quote->currency);
        $this->gateway->failRenewal = true;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->resume($viewer, $input));
        $pending = DB::table('billing_operations')->where('subscription_id', $sub->id)->where('state', 'pending')->first();
        $calls = $this->gateway->renewalCalls;
        $this->gateway->failRenewal = false;

        // The earlier remote resume succeeded despite its lost response; a real renewal
        // then occurred and a later platform cancellation is now the current provider fact.
        // These are isolated provider fixtures, not a Stripe Test Clock acceptance result.
        $start = CarbonImmutable::parse($quote->next_renewal_at);
        $end = $start->addWeek();
        $this->travelTo($start->addSecond());
        $renewalId = 'in_next_'.$order->id;
        $this->gateway->subscriptions[$sub->provider_id] = [
            ...$this->gateway->subscriptions[$sub->provider_id], 'status' => 'active', 'cancel_at_period_end' => true,
            'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'latest_invoice_id' => $renewalId,
        ];
        $this->gateway->invoices[$renewalId] = [
            ...$this->gateway->invoices[$invoiceId], 'id' => $renewalId, 'charge_id' => 'ch_next_'.$order->id,
            'amount_minor' => 1200, 'discount_amount_minor' => 0, 'billing_reason' => 'subscription_cycle',
            'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'paid_at' => BillingStore::iso($start),
        ];
        app(SettlementService::class)->settleInvoice($renewalId);
        $this->assertSame(BillingStore::iso($end), BillingStore::iso(DB::table('billing_subscriptions')->find($sub->id)->period_end));
        $this->assertDatabaseHas('billing_operations', ['id' => $pending->id, 'state' => 'pending']);

        $this->engine->retryPendingOperations();
        app(SettlementService::class)->syncSubscription($sub->provider_id);
        $this->assertSame($calls, $this->gateway->renewalCalls);
        $this->assertDatabaseHas('billing_operations', ['id' => $pending->id, 'state' => 'superseded', 'last_error' => 'RESUME_WINDOW_CLOSED']);
        $this->assertDatabaseHas('billing_resume_quotes', ['id' => $quote->quote_id, 'state' => 'superseded']);
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $sub->id, 'renewal_enabled' => false, 'resolution_required' => false]);
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->resume($viewer, $input));
        $this->assertDatabaseCount('billing_payments', 2);
        $this->assertDatabaseCount('billing_entitlements', 2);
    }

    public function test_provider_terminated_subscription_keeps_paid_access_but_cannot_issue_resume_quote(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $this->gateway->subscriptions[$sub->provider_id]['status'] = 'canceled';
        $this->gateway->subscriptions[$sub->provider_id]['cancel_at_period_end'] = true;
        app(SettlementService::class)->syncSubscription($sub->provider_id);
        $membership = $this->engine->membership($viewer->id, now()->toDateTimeImmutable());
        $this->assertTrue($membership->access_active);
        $this->assertFalse($membership->renewal_enabled);
        $this->assertFalse($membership->payment_resolution_required);
        $this->assertNotContains(SubscriptionAction::Resume, $membership->allowed_actions);
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->resumeQuote($viewer));
        $this->assertDatabaseCount('billing_resume_quotes', 0);
        $this->assertSame(0, $this->gateway->renewalCalls);
    }

    public function test_confirmed_cancel_does_not_clear_independent_refund_review_or_allow_resume_and_checkout(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $chargeId = 'ch_'.$order->id;
        $this->gateway->refunds[$chargeId] = [['id' => 're_mismatch', 'amount_minor' => 301, 'currency' => 'USD', 'status' => 'succeeded']];
        try {
            app(SettlementService::class)->syncRefunds($chargeId);
            $this->fail('A contradictory refund must require review.');
        } catch (\RuntimeException $error) {
            $this->assertSame('BILLING_FACT_MISMATCH', $error->getMessage());
        }
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->cancel($viewer));
        $this->assertDatabaseHas('billing_operations', ['subscription_id' => $sub->id, 'kind' => 'cancel', 'state' => 'succeeded']);
        $this->assertDatabaseHas('billing_subscriptions', ['id' => $sub->id, 'renewal_enabled' => false, 'resolution_required' => true]);
        $this->assertDatabaseHas('billing_orders', ['id' => $order->id, 'state' => 'review_required']);
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->resumeQuote($viewer));

        $this->travelTo(CarbonImmutable::parse($sub->period_end)->addSecond());
        $this->gateway->subscriptions[$sub->provider_id]['status'] = 'canceled';
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->cancel($viewer));
        $offer = $this->engine->offer($viewer);
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer,
            new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), 'refund-review-checkout', null));
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertNotContains(SubscriptionAction::Checkout, $this->engine->membership($viewer->id, now()->toDateTimeImmutable())->allowed_actions);
    }

    private function staff(Role $role): Staff
    {
        $staff = Staff::create(['name' => 'Billing review staff', 'email' => BillingStore::id().'@example.test', 'password' => 'TestPassword1234', 'role' => $role]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->refresh();
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => $staff->session_version, 'identity.mfa_staff' => $staff->id]);
        request()->setLaravelSession(app('session.store'));

        return $staff;
    }

    private function caseFor(object $order, Staff $staff, bool $verified): string
    {
        $id = BillingStore::id();
        DB::table('billing_cases')->insert([
            'id' => $id, 'viewer_id' => $order->viewer_id, 'order_id' => $order->id, 'staff_id' => $staff->id,
            'identity_verified' => $verified, 'cancel_requested' => $verified,
            'verified_at' => $verified ? now() : null, 'created_at' => now(),
        ]);

        return $id;
    }
}
