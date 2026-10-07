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
