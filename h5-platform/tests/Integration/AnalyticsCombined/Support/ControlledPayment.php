<?php

namespace Tests\Integration\AnalyticsCombined\Support;

use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\Support\FakeGateway;

/** Same protocol responses as 03 BillingTestCase, applied to the order created by real audience HTTP checkout. */
final class ControlledPayment
{
    public static function settle(string $orderId, FakeGateway $gateway): array
    {
        if (! app()->environment('testing') || config('database.connections.pgsql.database') !== getenv('H5_TEST_DATABASE')
            || ! str_ends_with((string) getenv('H5_TEST_DATABASE'), '_test') || config('billing.paypal.enabled') !== false) {
            throw new \LogicException('06 controlled payment requires own explicit test database with PayPal off.');
        }
        $order = DB::table('billing_orders')->find($orderId);
        if (! $order || ! $order->is_test || $order->provider !== 'stripe') {
            throw new \LogicException('Only an existing synthetic Stripe test order can be completed.');
        }
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $sid = 'sub_'.$sub->id;
        $iid = 'in_'.$order->id;
        $charge = 'ch_'.$order->id;
        $start = now()->startOfSecond();
        $end = $start->addWeek();
        $gateway->subscriptions[$sid] = ['id' => $sid, 'customer_id' => $sub->customer_id, 'status' => 'active',
            'cancel_at_period_end' => false, 'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end),
            'regular_amount_minor' => (int) $order->regular_amount_minor, 'currency' => 'USD', 'order_id' => $order->id,
            'latest_invoice_id' => $iid, 'is_test' => true];
        $gateway->checkouts[$order->checkout_id] = ['id' => $order->checkout_id, 'status' => 'complete', 'payment_status' => 'paid',
            'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id, 'is_test' => true];
        $gateway->invoices[$iid] = ['id' => $iid, 'subscription_id' => $sid, 'customer_id' => $sub->customer_id,
            'order_id' => $order->id, 'amount_minor' => (int) $order->amount_minor, 'currency' => 'USD',
            'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'paid_at' => BillingStore::iso($start),
            'status' => 'paid', 'paid' => true, 'verified_payment' => true, 'charge_id' => $charge, 'is_test' => true,
            'billing_reason' => 'subscription_create', 'regular_amount_minor' => (int) $order->regular_amount_minor,
            'discount_amount_minor' => (int) $order->regular_amount_minor - (int) $order->amount_minor, 'payment_state' => 'succeeded'];
        app(SettlementService::class)->syncCheckout($order->id);
        app(SettlementService::class)->settleInvoice($iid);
        return ['order_id' => $order->id, 'subscription_id' => $sub->id, 'invoice_id' => $iid, 'charge' => $charge,
            'payment_id' => DB::table('billing_payments')->where('order_id', $order->id)->sole()->id];
    }
}
