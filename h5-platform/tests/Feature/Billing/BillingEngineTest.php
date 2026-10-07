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
        $this->assertProblem('EMAIL_UNVERIFIED', fn () => $this->engine->checkout($v, $in, 'checkout-1', null));
        $v = $this->viewer();
        $offer = $this->engine->offer($v);
        $in = new CheckoutInput($offer->offer_id, 1, '/membership');
        $one = $this->engine->checkout($v, $in, 'checkout-1', null);
        $two = $this->engine->checkout($v, $in, 'checkout-1', null);
        $this->assertSame($one->order_id, $two->order_id);
        $this->assertSame(1, $this->gateway->createCalls);
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($v, $in, 'checkout-2', null));
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->checkout($v, new CheckoutInput($offer->offer_id, 1, '/identity/account'), 'checkout-1', null));
        $this->assertProblem('NOT_FOUND', fn () => $this->engine->order($this->viewer(), $one->order_id));
        $this->assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_checkout_unknown_is_persisted_and_retry_keeps_same_order(): void
    {
        $v = $this->viewer();
        $offer = $this->engine->offer($v);
        $in = new CheckoutInput($offer->offer_id, 1, '/membership');
        $this->gateway->failCreate = true;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->checkout($v, $in, 'checkout-1', null));
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertNull(DB::table('billing_accounts')->first()->first_paid_at);
        $this->gateway->failCreate = false;
        $this->engine->checkout($v, $in, 'checkout-1', null);
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_expired_offer_and_another_accounts_offer_cannot_create_orders(): void
    {
        $v = $this->viewer();
        $offer = $this->engine->offer($v);
        $in = new CheckoutInput($offer->offer_id, 1, '/membership');
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->checkout($this->viewer(), $in, 'checkout-1', null));
        $this->travel(30)->minutes();
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->checkout($v, $in, 'checkout-1', null));
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
        $this->assertProblem('SUBSCRIPTION_EXISTS', fn () => $this->engine->checkout($v, new CheckoutInput($offer->offer_id, 1, '/membership'), 'checkout-2', null));
    }

    public function test_http_rejects_client_money_and_preserves_order_ownership(): void
    {
        $v = $this->viewer();
        $this->actingAs(Viewer::find($v->id), 'web')->withSession(['identity.version.web' => 1]);
        $o = $this->engine->offer($v);
        $this->postJson('/api/v1/billing/checkout',['offer_id' => $o->offer_id, 'price_version' => 1, 'return_to' => '/membership', 'amount_minor' => 1],['Idempotency-Key' => 'checkout-1'])->assertStatus(422);
        $this->getJson('/api/v1/me/membership')->assertOk()->assertJsonPath('access_active',false);
    }
}
