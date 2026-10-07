<?php

namespace Tests\Feature\Billing;

use App\Data\CheckoutInput;
use App\Data\ResumeInput;
use App\Enums\SubscriptionAction;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Viewer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BillingEngineTest extends BillingTestCase
{
    public function test_verified_account_snapshot_and_idempotency_control_checkout(): void
    {
        $v = $this->viewer(false);
        $offer = $this->engine->offer($v);
        $in = new CheckoutInput($offer->offer_id, 1, '/membership');
        $this->assertProblem('EMAIL_UNVERIFIED', fn () => $this->engine->checkout($v, $in, 'checkout-test-001', null));
        $v = $this->viewer();
        $offer = $this->engine->offer($v);
        $in = new CheckoutInput($offer->offer_id, 1, '/membership');
        $one = $this->engine->checkout($v, $in, 'checkout-test-001', null);
        $two = $this->engine->checkout($v, $in, 'checkout-test-001', null);
        $this->assertSame($one->order_id, $two->order_id);
        $this->assertSame(1, $this->gateway->createCalls);
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($v, $in, 'checkout-test-002', null));
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->checkout($v, new CheckoutInput($offer->offer_id, 1, '/identity/account'), 'checkout-test-001', null));
        $this->assertProblem('NOT_FOUND', fn () => $this->engine->order($this->viewer(), $one->order_id));
        $this->assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_checkout_unknown_is_persisted_and_retry_keeps_same_order(): void
    {
        $v = $this->viewer();
        $offer = $this->engine->offer($v);
        $in = new CheckoutInput($offer->offer_id, 1, '/membership');
        $this->gateway->failCreate = true;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($v, $in, 'checkout-test-001', null));
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertNull(DB::table('billing_accounts')->first()->first_paid_at);
        $this->gateway->failCreate = false;
        $this->engine->checkout($v, $in, 'checkout-test-001', null);
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_expired_offer_and_another_accounts_offer_cannot_create_orders(): void
    {
        $v = $this->viewer();
        $offer = $this->engine->offer($v);
        $in = new CheckoutInput($offer->offer_id, 1, '/membership');
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->checkout($this->viewer(), $in, 'checkout-test-001', null));
        $this->travel(30)->minutes();
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->checkout($v, $in, 'checkout-test-001', null));
        $this->assertDatabaseCount('billing_orders', 0);
    }

    public function test_cancel_resume_uses_original_price_and_old_retry_cannot_undo_later_cancel(): void
    {
        [$v,$order,$sub] = $this->paidFixture();
        $before = $this->engine->membership($v->id, now()->toDateTimeImmutable());
        $canceled = $this->engine->cancel($v);
        $this->assertTrue($canceled->access_active);
        $this->assertFalse($canceled->renewal_enabled);
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 2, 'regular_amount_minor' => 1500, 'first_week_amount_minor' => 500, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $q = $this->engine->resumeQuote($v);
        $this->assertSame(1200, $q->next_renewal_amount_minor);
        $input = new ResumeInput($q->quote_id, $q->confirmation_version, $q->next_renewal_at, 1200, 'USD');
        $this->assertProblem('NOT_FOUND', fn () => $this->engine->resume($this->viewer(), $input));
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->resume($v, new ResumeInput($q->quote_id, $q->confirmation_version, $q->next_renewal_at, 1500, 'USD')));
        $resumed = $this->engine->resume($v, $input);
        $this->assertTrue($resumed->renewal_enabled);
        $this->assertSame($before->access_until, $resumed->access_until);
        $calls = $this->gateway->renewalCalls;
        $this->travel(6)->minutes();
        $this->engine->resume($v, $input);
        $this->assertSame($calls, $this->gateway->renewalCalls);
        $this->engine->cancel($v);
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->resume($v, $input));
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        $this->assertFalse($this->engine->offer($v)->eligible_for_first_offer);
    }

    public function test_unexecuted_resume_expires_at_boundary_and_cancel_timeout_reconciles(): void
    {
        [$v] = $this->paidFixture();
        $this->gateway->failRenewal = true;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->cancel($v));
        $this->assertTrue($this->engine->membership($v->id, now()->toDateTimeImmutable())->payment_resolution_required);
        $this->gateway->failRenewal = false;
        $this->engine->retryPendingOperations();
        $this->assertSame(1, $this->gateway->renewalCalls); // Remote result found; no duplicate mutation.
        $q = $this->engine->resumeQuote($v);
        $this->travel(5)->minutes();
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->resume($v, new ResumeInput($q->quote_id, $q->confirmation_version, $q->next_renewal_at, 1200, 'USD')));
    }

    public function test_paid_interval_is_half_open_and_pending_original_blocks_repurchase(): void
    {
        [$v,$o,$s] = $this->paidFixture();
        $this->travelTo(CarbonImmutable::parse($s->period_end));
        $m = $this->engine->membership($v->id, now()->toDateTimeImmutable());
        $this->assertFalse($m->access_active);
        $this->assertNotContains(SubscriptionAction::Checkout, $m->allowed_actions);
        $offer = $this->engine->offer($v);
        $this->assertProblem('SUBSCRIPTION_EXISTS', fn () => $this->engine->checkout($v, new CheckoutInput($offer->offer_id, 1, '/membership'), 'checkout-test-002', null));
    }

    public function test_http_rejects_client_money_and_preserves_order_ownership(): void
    {
        $v = $this->viewer();
        $this->actingAs(Viewer::find($v->id), 'web')->withSession(['identity.version.web' => 1]);
        $o = $this->engine->offer($v);
        $this->postJson('/api/v1/billing/checkout', ['offer_id' => $o->offer_id, 'price_version' => 1, 'return_to' => '/membership', 'amount_minor' => 1], ['Idempotency-Key' => 'checkout-test-001'])->assertStatus(422);
        $this->getJson('/api/v1/me/membership')->assertOk()->assertJsonPath('access_active', false);
    }

    public function test_http_enforces_frozen_idempotency_key_bounds_before_creating_orders(): void
    {
        $viewer = $this->viewer();
        $this->actingAs(Viewer::find($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $offer = $this->engine->offer($viewer);
        $input = ['offer_id' => $offer->offer_id, 'price_version' => 1, 'return_to' => '/membership'];
        foreach ([str_repeat('a', 15), str_repeat('a', 129)] as $key) {
            $this->postJson('/api/v1/billing/checkout', $input, ['Idempotency-Key' => $key])->assertStatus(422);
        }
        $this->assertDatabaseCount('billing_orders', 0);
        $this->postJson('/api/v1/billing/checkout', $input, ['Idempotency-Key' => str_repeat('a', 128)])->assertSuccessful();
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_guest_offer_requires_new_personal_offer_and_changed_eligibility_requires_confirmation(): void
    {
        $viewer = $this->viewer();
        $guest = $this->engine->offer(null);
        $this->assertNull($guest->eligible_for_first_offer);
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->checkout($viewer, new CheckoutInput($guest->offer_id, 1, '/membership'), 'guest-offer-test-1', null));
        $offer = $this->engine->offer($viewer);
        DB::table('billing_accounts')->insert(['viewer_id' => $viewer->id, 'first_paid_at' => now(), 'first_offer_used_at' => now()]);
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->checkout($viewer, new CheckoutInput($offer->offer_id, 1, '/membership'), 'changed-eligibility-1', null));
        $fresh = $this->engine->offer($viewer);
        $checkout = $this->engine->checkout($viewer, new CheckoutInput($fresh->offer_id, 1, '/membership'), 'confirmed-current-1', null);
        $order = DB::table('billing_orders')->find($checkout->order_id);
        $this->assertSame(1200, $order->amount_minor);
        $this->assertSame('resubscription', $order->transaction_kind);
        $this->assertFalse($order->discount_applied);
    }

    public function test_checkout_creation_intent_survives_unknown_result_with_fixed_parameters(): void
    {
        $viewer = $this->viewer();
        $offer = $this->engine->offer($viewer);
        $this->gateway->failCreate = true;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer, new CheckoutInput($offer->offer_id, 1, '/membership'), 'durable-intent-test-1', null));
        $order = DB::table('billing_orders')->first();
        $intent = BillingStore::json($order->checkout_request);
        $this->assertNotNull($order->checkout_attempted_at);
        $this->assertSame(1860, CarbonImmutable::parse($intent['expires_at'])->timestamp - CarbonImmutable::parse($intent['prepared_at'])->timestamp);
        $this->travel(10)->minutes();
        $this->engine->retryPendingOperations();
        $again = DB::table('billing_orders')->find($order->id);
        $this->assertSame($order->checkout_request, $again->checkout_request);
        $this->assertSame($order->expires_at, $again->expires_at);
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_testing_gateway_does_not_hide_conflicting_coupon_parameters(): void
    {
        $viewer = $this->viewer();
        $offer = $this->engine->offer($viewer);
        $this->gateway->failCreate = true;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($viewer, new CheckoutInput($offer->offer_id, 1, '/membership'), 'fixture-parameter-conflict', null));
        $order = DB::table('billing_orders')->first();
        $intent = BillingStore::json($order->checkout_request);
        $this->assertArrayHasKey('discounts', $intent['params']);
        $this->assertArrayNotHasKey('allow_promotion_codes', $intent['params']);
        // Model the old stored request; the local fake must not invent success for it.
        $intent['params']['allow_promotion_codes'] = false;
        DB::table('billing_orders')->where('id', $order->id)->update(['checkout_request' => json_encode($intent, JSON_THROW_ON_ERROR)]);
        $before = DB::table('billing_orders')->find($order->id);
        $this->gateway->failCreate = false;
        $this->engine->retryPendingOperations();
        $after = DB::table('billing_orders')->find($order->id);
        $this->assertNull($after->checkout_id);
        $this->assertSame('processing', $after->state);
        $this->assertSame($before->checkout_request, $after->checkout_request);
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertDatabaseCount('billing_entitlements', 0);
    }
}
