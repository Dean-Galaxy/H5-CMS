<?php

namespace Tests\Feature\Contracts;

use App\Contracts\MembershipReader;
use App\Data\CheckoutInput;
use App\Data\ViewerIdentity;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\Support\FakeGateway;
use Tests\Feature\Content\ContentTestCase;

/** Real default Billing + Content; payment gateway and media bytes are testing fixtures. */
final class AcceptedBillingPlaybackTest extends ContentTestCase
{
    public function test_real_billing_controls_content_after_cancel_partial_and_full_refund(): void
    {
        $gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $this->assertInstanceOf(BillingEngine::class, app(MembershipReader::class));
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1,
            'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300,
            'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $drama = $this->fixture(6, 6, true);
        $viewer = Viewer::forceCreate(['id' => BillingStore::id(), 'name' => '00 local review',
            'email' => BillingStore::id().'@example.test', 'password' => bcrypt('ReviewFixture9!'),
            'active' => true, 'email_verified_at' => now()]);
        $this->asViewer($viewer);
        $identity = new ViewerIdentity($viewer->id, $viewer->email, true);
        $sixth = '/api/v1/episodes/'.$drama->episodes[5]->id.'/playback-grants';
        $fifth = '/api/v1/episodes/'.$drama->episodes[4]->id.'/playback-grants';
        $this->postJson($sixth, ['play_session_id' => null])->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('reason', 'MEMBERSHIP_REQUIRED');
        $billing = app(BillingEngine::class);
        $offer = $billing->offer($identity);
        $checkout = $billing->checkout($identity, new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), BillingStore::id(), null);
        $order = DB::table('billing_orders')->find($checkout->order_id);
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $sid = 'sub_'.$sub->id;
        $iid = 'in_'.$order->id;
        $charge = 'ch_'.$order->id;
        $start = now()->startOfSecond();
        $end = $start->addWeek();
        $gateway->subscriptions[$sid] = ['id' => $sid, 'customer_id' => $sub->customer_id,
            'status' => 'active', 'cancel_at_period_end' => false,
            'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end),
            'regular_amount_minor' => 1200, 'currency' => 'USD', 'order_id' => $order->id,
            'latest_invoice_id' => $iid, 'is_test' => true];
        $gateway->checkouts[$order->checkout_id] = ['id' => $order->checkout_id,
            'status' => 'complete', 'payment_status' => 'paid', 'subscription_id' => $sid,
            'customer_id' => $sub->customer_id, 'order_id' => $order->id, 'is_test' => true];
        $gateway->invoices[$iid] = ['id' => $iid, 'subscription_id' => $sid,
            'customer_id' => $sub->customer_id, 'order_id' => $order->id,
            'amount_minor' => 300, 'currency' => 'USD',
            'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end),
            'paid_at' => BillingStore::iso($start), 'status' => 'paid', 'paid' => true,
            'verified_payment' => true, 'charge_id' => $charge, 'is_test' => true,
            'billing_reason' => 'subscription_create', 'regular_amount_minor' => 1200,
            'discount_amount_minor' => 900, 'payment_state' => 'succeeded'];
        $settlement = app(SettlementService::class);
        $settlement->syncCheckout($order->id);
        $grant = $this->postJson($sixth, ['play_session_id' => null])->assertOk()->assertJsonPath('reason', 'allowed_member')->json();
        $this->get($grant['short_lived_url'])->assertOk();
        $canceled = $billing->cancel($identity);
        $this->assertTrue($canceled->access_active);
        $this->assertFalse($canceled->renewal_enabled);
        $this->assertSame(BillingStore::iso($end), $canceled->access_until);
        $this->postJson($sixth, ['play_session_id' => null])->assertOk()->assertJsonPath('allowed', true);
        $gateway->refunds[$charge] = [['id' => 're_review_a', 'amount_minor' => 100, 'status' => 'succeeded', 'currency' => 'USD']];
        $settlement->syncRefunds($charge);
        $this->postJson($sixth, ['play_session_id' => null])->assertOk()->assertJsonPath('allowed', true);
        $gateway->refunds[$charge][] = ['id' => 're_review_b', 'amount_minor' => 200, 'status' => 'succeeded', 'currency' => 'USD'];
        $settlement->syncRefunds($charge);
        $this->get($grant['short_lived_url'])->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
        $this->postJson($sixth, ['play_session_id' => null])->assertOk()->assertJsonPath('allowed', false);
        $this->postJson($fifth, ['play_session_id' => null])->assertOk()->assertJsonPath('reason', 'allowed_free');
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        $this->assertDatabaseCount('billing_outbox', 1);
    }
}
