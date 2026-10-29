<?php

namespace Tests\Integration\AnalyticsCombined;

use App\Contracts\BillingAnalyticsReader;
use App\Data\PaymentSettled;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Acquisition\Services\PaymentReceiver;
use App\Modules\Billing\Services\BillingBehaviorOutbox;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Integration\AnalyticsCombined\Support\CombinedTestCase;
use Tests\Integration\AnalyticsCombined\Support\ControlledPayment;

/** Written against received 01/03/04 and draft 05 ports. NOT executed until 00's complete candidate. */
final class PaymentAttributionTest extends CombinedTestCase
{
    public function test_real_source_registration_verification_checkout_payment_and_replay_share_one_subject(): void
    {
        $this->consent(); $this->landing();
        $this->postJson('/audience/dramas/'.$this->drama->id.'/episodes/'.$this->drama->episodes[5]->id.'/playback', ['play_session_id' => null])
            ->assertOk()->assertJsonPath('allowed', false);
        $before = DB::table('acquisition_behaviors')->where('event_name', 'landing_view')->sole();
        $viewer = $this->registerAndVerify();
        $order = $this->checkout();
        $snapshot = BillingStore::json($order->attribution_snapshot);
        $this->assertSame($this->link['id'], $snapshot['link_id']);
        $this->assertContains('registration_succeeded', $this->names());
        $this->assertContains('email_verified', $this->names());
        $payment = ControlledPayment::settle($order->id, $this->gateway);
        app(OutboxService::class)->dispatchPending();
        $payload = BillingStore::json(DB::table('billing_outbox')->where('payment_id', $payment['payment_id'])->sole()->payload);
        app(PaymentReceiver::class)->handle(PaymentSettled::fromArray($payload));
        app(OutboxService::class)->dispatchPending();
        $paid = DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->sole();
        $this->assertSame($before->subject_id, $paid->subject_id);
        $this->assertSame($viewer->id, $paid->viewer_id);
        $this->assertSame($this->link['id'], $paid->link_id);
        $this->assertDatabaseCount('acquisition_deliveries', 1);
        $this->postJson('/audience/dramas/'.$this->drama->id.'/episodes/'.$this->drama->episodes[5]->id.'/playback', ['play_session_id' => null])
            ->assertOk()->assertJsonPath('allowed', true);
        $this->assertDatabaseCount('billing_payments', 1); $this->assertDatabaseCount('billing_entitlements', 1);
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertExternalBlocked('EXTERNAL_DISABLED');
    }

    public function test_logout_is_not_withdrawal_for_a_delayed_payment_and_new_account_does_not_inherit(): void
    {
        $this->consent(); $this->landing(); $viewer = $this->registerAndVerify(); $order = $this->checkout();
        $payment = ControlledPayment::settle($order->id, $this->gateway);
        $before = DB::table('billing_outbox')->where('payment_id', $payment['payment_id'])->sole();
        $this->assertNull($before->delivered_at);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/tracking/context')->assertOk()->assertJsonPath('consent.analytics', 'unknown');
        $this->registerAndVerify();
        app(OutboxService::class)->dispatchPending();
        $paid = DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->sole();
        $this->assertSame($viewer->id, $paid->viewer_id);
        $this->assertSame($this->link['id'], $paid->link_id);
        $this->assertDatabaseCount('acquisition_deliveries', 1);
        $this->assertSame(1, DB::table('acquisition_behaviors')->where('event_name', 'registration_succeeded')->count());
        $this->assertSame($before->payload, DB::table('billing_outbox')->where('payment_id', $payment['payment_id'])->sole()->payload);
        Http::assertNothingSent();
    }

    public function test_explicit_withdrawal_prevents_old_delayed_conversion_but_keeps_financial_facts(): void
    {
        $this->consent(); $this->landing(); $this->registerAndVerify(); $order = $this->checkout();
        ControlledPayment::settle($order->id, $this->gateway);
        $this->consent(false, false);
        app(OutboxService::class)->dispatchPending();
        $this->assertSame(0, DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->count());
        $this->assertDatabaseCount('billing_payments', 1); $this->assertDatabaseCount('billing_entitlements', 1);
        $this->assertExternalBlocked('CONSENT_REQUIRED_OR_REVOKED');
    }

    public function test_refund_cumulative_versions_use_real_reader_not_sum_of_behavior_events(): void
    {
        $this->consent(); $this->landing(); $this->registerAndVerify(); $order = $this->checkout();
        $payment = ControlledPayment::settle($order->id, $this->gateway); app(OutboxService::class)->dispatchPending();
        foreach ([100, 300, 0] as $amount) {
            $this->gateway->refunds[$payment['charge']] = [
                ['id' => 're_06_first', 'amount_minor' => 100, 'currency' => 'USD', 'status' => $amount ? 'succeeded' : 'failed'],
                ['id' => 're_06_second', 'amount_minor' => 200, 'currency' => 'USD', 'status' => $amount === 300 ? 'succeeded' : 'failed'],
            ];
            app(SettlementService::class)->syncRefunds($payment['charge']);
            app(BillingBehaviorOutbox::class)->dispatchPending();
            app(BillingBehaviorOutbox::class)->dispatchPending();
            $facts = app(BillingAnalyticsReader::class)->payments([$payment['payment_id']]);
            $this->assertSame($amount, $facts[$payment['payment_id']]['refunded_amount_minor']);
            $finance = $this->finance();
            $this->assertSame('known', $finance['status']);
            $this->assertSame(300, $finance['gross_minor']); $this->assertSame($amount, $finance['refund_minor']);
            $this->assertSame(300 - $amount, $finance['net_minor']);
        }
        $this->assertDatabaseCount('billing_payments', 1); Http::assertNothingSent();
    }

    public function test_advertising_only_visible_landing_creates_source_without_an_analytics_subject(): void
    {
        $this->consent(false, true); $this->landing(false);
        $this->assertDatabaseCount('acquisition_sources', 1);
        $this->assertDatabaseCount('acquisition_subjects', 0); $this->assertDatabaseCount('acquisition_behaviors', 0);
        Http::assertNothingSent();
    }
    private function assertExternalBlocked(string $reason): void
    {
        $snapshot = [];
        foreach (['billing_payments', 'billing_entitlements', 'billing_refunds', 'billing_orders', 'billing_subscriptions'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        app(DeliveryWorker::class)->run(); // Count is processed/blocked records, NOT requests sent.
        $delivery = DB::table('acquisition_deliveries')->sole();
        $this->assertSame('blocked', $delivery->state);
        $this->assertSame($reason, $delivery->last_error);
        $this->assertSame(0, (int) $delivery->attempts);
        foreach ($snapshot as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
        Http::assertNothingSent();
    }

}
