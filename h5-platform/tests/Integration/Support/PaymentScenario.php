<?php

namespace Tests\Integration\Support;

use App\Data\CheckoutInput;
use App\Data\ViewerIdentity;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\Support\FakeGateway;

/** Testing-only payment API responses; real Billing persistence. Never official Stripe evidence. */
final class PaymentScenario
{
    public static function paid(): array
    {
        if (! app()->environment('testing') || config('database.connections.pgsql.database') !== getenv('H5_TEST_DATABASE')) {
            throw new \LogicException('06 payment scenario requires an explicitly isolated test database.');
        }
        // This legacy cross-module fixture deliberately models Stripe, never PayPal.
        config(['billing.new_purchase_provider' => 'stripe']);
        $gateway = new FakeGateway;
        app()->instance(PaymentGateway::class, $gateway);
        if (! DB::table('billing_prices')->exists()) {
            DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        }
        $viewer = Viewer::forceCreate(['id' => BillingStore::id(), 'name' => '06 testing fixture', 'email' => BillingStore::id().'@example.test', 'password' => bcrypt(bin2hex(random_bytes(24))), 'active' => true, 'email_verified_at' => now()]);
        $identity = new ViewerIdentity($viewer->id, $viewer->email, true);
        $engine = app(BillingEngine::class);
        $offer = $engine->offer($identity);
        $checkout = $engine->checkout($identity, new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), BillingStore::id(), null);
        $order = DB::table('billing_orders')->find($checkout->order_id);
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $sid = 'sub_'.$sub->id;
        $iid = 'in_'.$order->id;
        $charge = 'ch_'.$order->id;
        $start = now()->startOfSecond();
        $end = $start->addWeek();
        $gateway->subscriptions[$sid] = ['id' => $sid, 'customer_id' => $sub->customer_id, 'status' => 'active', 'cancel_at_period_end' => false, 'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'regular_amount_minor' => 1200, 'currency' => 'USD', 'order_id' => $order->id, 'latest_invoice_id' => $iid, 'is_test' => true];
        $gateway->checkouts[$order->checkout_id] = ['id' => $order->checkout_id, 'status' => 'complete', 'payment_status' => 'paid', 'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id, 'is_test' => true];
        $gateway->invoices[$iid] = ['id' => $iid, 'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id, 'amount_minor' => 300, 'currency' => 'USD', 'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'paid_at' => BillingStore::iso($start), 'status' => 'paid', 'paid' => true, 'verified_payment' => true, 'charge_id' => $charge, 'is_test' => true, 'billing_reason' => 'subscription_create', 'regular_amount_minor' => 1200, 'discount_amount_minor' => 900, 'payment_state' => 'succeeded'];
        app(SettlementService::class)->syncCheckout($order->id);

        return compact('viewer', 'identity', 'engine', 'gateway', 'order', 'sub', 'sid', 'iid', 'charge', 'end');
    }
}
