<?php

namespace Tests\Feature\Billing;

use App\Contracts\RegionResolver;
use App\Data\CheckoutInput;
use App\Data\ResolvedRegion;
use App\Data\ViewerIdentity;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\Support\FakeGateway;
use Tests\TestCase;

abstract class BillingTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeGateway $gateway;

    protected BillingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(BillingServiceProvider::class);
        config(['billing.quote_policy_approved' => true, 'billing.new_purchase_provider' => 'stripe']);
        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->app->bind(RegionResolver::class, fn () => new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
        $this->engine = app(BillingEngine::class);
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
    }

    protected function viewer(bool $verified = true): ViewerIdentity
    {
        $user = Viewer::forceCreate(['name' => 'Billing local test', 'id' => BillingStore::id(), 'email' => BillingStore::id().'@example.test', 'password' => bcrypt('local-test-password'), 'active' => true, 'email_verified_at' => $verified ? now() : null]);

        return new ViewerIdentity($user->id, $user->email, $verified);
    }

    protected function checkoutFor(ViewerIdentity $viewer): object
    {
        $offer = $this->engine->offer($viewer);
        $result = $this->engine->checkout($viewer, new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), BillingStore::id(), null);

        return DB::table('billing_orders')->find($result->order_id);
    }

    protected function paidFixture(?ViewerIdentity $viewer = null): array
    {
        $viewer ??= $this->viewer();
        $order = $this->checkoutFor($viewer);
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $sid = 'sub_'.$sub->id;
        $iid = 'in_'.$order->id;
        $start = now()->startOfSecond();
        $end = $start->addWeek();
        $this->gateway->subscriptions[$sid] = ['id' => $sid, 'customer_id' => $sub->customer_id, 'status' => 'active', 'cancel_at_period_end' => false,
            'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'regular_amount_minor' => 1200, 'currency' => 'USD', 'order_id' => $order->id, 'latest_invoice_id' => $iid, 'is_test' => true];
        $this->gateway->checkouts[$order->checkout_id] = ['id' => $order->checkout_id, 'status' => 'complete', 'payment_status' => 'paid', 'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id, 'is_test' => true];
        $this->gateway->invoices[$iid] = ['id' => $iid, 'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id,
            'amount_minor' => 300, 'currency' => 'USD', 'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'paid_at' => BillingStore::iso($start),
            'status' => 'paid', 'paid' => true, 'verified_payment' => true, 'charge_id' => 'ch_'.$order->id, 'is_test' => true, 'billing_reason' => 'subscription_create', 'regular_amount_minor' => 1200, 'discount_amount_minor' => 900, 'payment_state' => 'succeeded'];
        app(SettlementService::class)->syncCheckout($order->id);
        app(SettlementService::class)->settleInvoice($iid);

        return [$viewer, DB::table('billing_orders')->find($order->id), DB::table('billing_subscriptions')->find($sub->id), $iid];
    }

    protected function assertProblem(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected '.$code);
        } catch (ApiProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
