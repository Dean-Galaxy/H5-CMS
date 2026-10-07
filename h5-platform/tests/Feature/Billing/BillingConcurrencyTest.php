<?php

namespace Tests\Feature\Billing;

use App\Contracts\RegionResolver;
use App\Data\CheckoutInput;
use App\Data\ResolvedRegion;
use App\Data\ResumeInput;
use App\Data\ViewerIdentity;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\Support\BillingRace;
use Tests\Feature\Billing\Support\FakeGateway;
use Tests\TestCase;

/** Run serially: child connections intentionally share the isolated PostgreSQL DB. */
final class BillingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private FakeGateway $gateway;

    private BillingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(BillingServiceProvider::class);
        config(['billing.quote_policy_approved' => true]);
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
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1,
            'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $this->assertSame(0, DB::transactionLevel(), 'Race fixtures must be committed, not wrapped in a parent test transaction.');
    }

    private function viewer(): ViewerIdentity
    {
        $user = Viewer::forceCreate(['name' => 'Local concurrency test', 'id' => BillingStore::id(),
            'email' => BillingStore::id().'@example.test', 'password' => bcrypt('local-fixture-only'),
            'active' => true, 'email_verified_at' => now()]);
        DB::table('billing_accounts')->insert(['viewer_id' => $user->id]);

        return new ViewerIdentity($user->id, $user->email, true);
    }

    private function race(ViewerIdentity $viewer, array $actions): array
    {
        $runner = new BillingRace;
        $results = $runner->run($viewer->id, [
            'subscriptions' => $this->gateway->subscriptions, 'invoices' => $this->gateway->invoices,
            'checkouts' => $this->gateway->checkouts, 'refunds' => $this->gateway->refunds,
        ], $actions);
        $report = $runner->report;
        $this->assertTrue($report['lock_observed'], 'Both workers must really wait on PostgreSQL locks.');
        $this->assertCount(2, $report['blocked_waiters']);
        $this->assertCount(3, array_unique([$report['parent_pg_pid'], ...array_column($report['workers'], 'pg_pid')]));
        $this->assertTrue($report['children_stopped']);
        $this->assertSame(0, $report['parent_transaction_level']);
        $this->assertFileExists($report['report_path']);
        $saved = json_decode(file_get_contents($report['report_path']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($saved['lock_observed']);
        $this->assertSame('completed', $saved['outcome']);

        return $results;
    }

    public function test_two_real_checkout_processes_create_only_one_open_subscription_and_one_checkout(): void
    {
        $viewer = $this->viewer();
        $offer = $this->engine->offer($viewer);
        $actions = [];
        foreach ([BillingStore::id(), BillingStore::id()] as $key) {
            $actions[] = ['action' => 'checkout', 'offer_id' => $offer->offer_id,
                'price_version' => $offer->price_version, 'idempotency_key' => $key];
        }
        $results = $this->race($viewer, $actions);
        $codes = array_column($results, 'code');
        $this->assertSame(1, count(array_filter($codes, fn ($code) => $code === 'OK')));
        $this->assertCount(1, array_filter($codes, fn ($code) => in_array($code, ['BILLING_PENDING', 'SUBSCRIPTION_EXISTS'], true)));
        $this->assertSame(1, array_sum(array_column($results, 'create_calls')));
        $this->assertSame(1, DB::table('billing_subscriptions')->where('viewer_id', $viewer->id)->where('state', '<>', 'ended')->count());
        $this->assertSame(1, DB::table('billing_orders')->where('viewer_id', $viewer->id)->count());
        $this->assertSame(0, DB::table('billing_payments')->count());
        $this->assertNull(DB::table('billing_accounts')->where('viewer_id', $viewer->id)->value('first_offer_used_at'));
    }

    /** A Checkout exists and Stripe-shaped facts are prepared, but nothing is settled yet. */
    private function payableFixture(ViewerIdentity $viewer): array
    {
        $offer = $this->engine->offer($viewer);
        $checkout = $this->engine->checkout($viewer, new CheckoutInput($offer->offer_id, $offer->price_version, '/membership'), BillingStore::id(), null);
        $order = DB::table('billing_orders')->find($checkout->order_id);
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $sid = 'sub_'.$sub->id;
        $iid = 'in_'.$order->id;
        $start = now()->startOfSecond();
        $end = $start->copy()->addWeek();
        $this->gateway->subscriptions[$sid] = ['id' => $sid, 'customer_id' => $sub->customer_id,
            'status' => 'active', 'cancel_at_period_end' => false, 'period_start' => BillingStore::iso($start),
            'period_end' => BillingStore::iso($end), 'regular_amount_minor' => 1200, 'currency' => 'USD',
            'order_id' => $order->id, 'latest_invoice_id' => $iid, 'is_test' => true];
        $this->gateway->invoices[$iid] = ['id' => $iid, 'subscription_id' => $sid, 'customer_id' => $sub->customer_id,
            'order_id' => $order->id, 'amount_minor' => 300, 'currency' => 'USD', 'period_start' => BillingStore::iso($start),
            'period_end' => BillingStore::iso($end), 'paid_at' => BillingStore::iso($start), 'status' => 'paid',
            'paid' => true, 'verified_payment' => true, 'charge_id' => 'ch_'.$order->id, 'is_test' => true,
            'billing_reason' => 'subscription_create', 'regular_amount_minor' => 1200, 'discount_amount_minor' => 900,
            'payment_state' => 'succeeded'];

        return [$order, $sub, $iid, $sid];
    }

    public function test_two_real_settlement_processes_commit_one_payment_interval_and_outbox_event(): void
    {
        $viewer = $this->viewer();
        [$order, $sub, $invoiceId] = $this->payableFixture($viewer);
        $action = ['action' => 'settle', 'invoice_id' => $invoiceId];
        $results = $this->race($viewer, [$action, $action]);
        $this->assertSame(['OK', 'OK'], array_column($results, 'code'));
        $this->assertSame(1, DB::table('billing_payments')->where('provider_invoice_id', $invoiceId)->count());
        $payment = DB::table('billing_payments')->where('provider_invoice_id', $invoiceId)->first();
        $this->assertSame(1, DB::table('billing_entitlements')->where('payment_id', $payment->id)->count());
        $this->assertSame(1, DB::table('billing_outbox')->where('payment_id', $payment->id)->count());
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame($sub->id, $payment->subscription_id);
        $this->assertSame(300, $payment->amount_minor);
        $this->assertSame($this->gateway->invoices[$invoiceId]['period_end'], BillingStore::iso($payment->period_end));
        $this->assertNotNull(DB::table('billing_accounts')->where('viewer_id', $viewer->id)->value('first_offer_used_at'));
    }

    public function test_two_real_resume_processes_cannot_duplicate_operation_or_replay_over_later_cancel(): void
    {
        $viewer = $this->viewer();
        [, $sub, $invoiceId, $sid] = $this->payableFixture($viewer);
        app(SettlementService::class)->settleInvoice($invoiceId);
        $this->engine->cancel($viewer);
        $quote = $this->engine->resumeQuote($viewer);
        $confirmation = new ResumeInput($quote->quote_id, $quote->confirmation_version,
            $quote->next_renewal_at, $quote->next_renewal_amount_minor, $quote->currency);
        $action = ['action' => 'resume', 'confirmation' => $confirmation->toArray()];
        $results = $this->race($viewer, [$action, $action]);
        $codes = array_column($results, 'code');
        $this->assertContains('OK', $codes);
        $this->assertSame([], array_values(array_diff($codes, ['OK', 'BILLING_PENDING'])));
        $this->assertSame(1, array_sum(array_column($results, 'renewal_calls')));
        $this->assertSame(1, DB::table('billing_operations')->where('quote_id', $quote->quote_id)->count());
        $this->assertSame('succeeded', DB::table('billing_operations')->where('quote_id', $quote->quote_id)->value('state'));
        $this->assertTrue((bool) DB::table('billing_subscriptions')->where('id', $sub->id)->value('renewal_enabled'));
        // Bring the parent's test provider to the state actually returned by the
        // one worker that performed the remote operation, then cancel anew.
        $writer = array_values(array_filter($results, fn ($result) => $result['renewal_calls'] === 1))[0];
        $this->gateway->subscriptions[$sid] = $writer['subscriptions'][$sid];
        $beforeEnd = DB::table('billing_subscriptions')->where('id', $sub->id)->value('period_end');
        $this->engine->cancel($viewer);
        $callsAfterCancel = $this->gateway->renewalCalls;
        try {
            $this->engine->resume($viewer, $confirmation);
            $this->fail('An old confirmation must not override a later cancellation.');
        } catch (ApiProblem $error) {
            $this->assertSame('STALE_VERSION', $error->errorCode);
        }
        $this->assertSame($callsAfterCancel, $this->gateway->renewalCalls);
        $this->assertTrue($this->gateway->subscriptions[$sid]['cancel_at_period_end']);
        $this->assertFalse((bool) DB::table('billing_subscriptions')->where('id', $sub->id)->value('renewal_enabled'));
        $this->assertSame($beforeEnd, DB::table('billing_subscriptions')->where('id', $sub->id)->value('period_end'));
        $this->assertSame(1, DB::table('billing_payments')->count());
        $this->assertSame(1, DB::table('billing_entitlements')->count());
    }
}
