<?php

namespace Tests\Integration\AnalyticsCombined;

use App\Modules\Billing\Services\BillingBehaviorOutbox;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Integration\AnalyticsCombined\Support\CombinedTestCase;
use Tests\Integration\AnalyticsCombined\Support\ControlledPayment;

/** Pending 03's NEW precision migration and 00's complete candidate. No old result is relabeled. */
final class LifecycleMicrosecondsTest extends CombinedTestCase
{
    public function test_refund_after_consent_in_the_same_second_keeps_real_microseconds_and_is_recorded_once(): void
    {
        $base = CarbonImmutable::parse('2026-10-29T05:00:00.100000Z');
        $this->travelTo($base);
        $this->consent(); $this->landing(); $this->registerAndVerify(); $order = $this->checkout();
        $payment = ControlledPayment::settle($order->id, $this->gateway);
        app(OutboxService::class)->dispatchPending();
        $paidBefore = DB::table('billing_payments')->where('id', $payment['payment_id'])->first(['paid_at', 'period_start', 'period_end']);
        $accessBefore = DB::table('billing_entitlements')->first(['access_from', 'access_until']);
        $this->assertSame('100000', CarbonImmutable::parse(DB::table('acquisition_privacy')->sole()->analytics_granted_at)->format('u'));

        // This is when the real local SettlementService sees the confirmed provider response, not a forged event timestamp.
        $this->travelTo($base->addMicroseconds(100000));
        $this->gateway->refunds[$payment['charge']] = [['id' => 're_06_same_second', 'amount_minor' => 100,
            'currency' => 'USD', 'status' => 'succeeded']];
        app(SettlementService::class)->syncRefunds($payment['charge']);
        $fact = DB::table('billing_behavior_outbox')->where('event_name', 'refund_confirmed')->sole();
        $properties = BillingStore::json($fact->properties);
        $this->assertSame('200000', CarbonImmutable::parse($fact->occurred_at)->format('u'));
        $this->assertSame('200000', CarbonImmutable::parse($properties['occurred_at'])->format('u'));
        $this->assertTrue(CarbonImmutable::parse($properties['occurred_at'])->greaterThan($base));
        app(BillingBehaviorOutbox::class)->dispatchPending();
        app(BillingBehaviorOutbox::class)->dispatchPending();
        $this->assertSame('delivered', DB::table('billing_behavior_outbox')->find($fact->id)->state);
        $behavior = DB::table('acquisition_behaviors')->where('event_name', 'refund_confirmed')->sole();
        $this->assertSame('200000', CarbonImmutable::parse($behavior->occurred_at)->format('u'));
        $this->assertSame(100, (int) DB::table('billing_payments')->where('id', $payment['payment_id'])->value('refunded_amount_minor'));
        $this->assertEquals($paidBefore, DB::table('billing_payments')->where('id', $payment['payment_id'])->first(['paid_at', 'period_start', 'period_end']));
        $this->assertEquals($accessBefore, DB::table('billing_entitlements')->first(['access_from', 'access_until']));
        Http::assertNothingSent();
    }

    public function test_schema_has_precision_six_via_a_new_migration_without_rewriting_adopted_migration_or_financial_times(): void
    {
        $this->assertSame('51ea007985109d0719a6405217b1753f4de221bb2c6e6f4c18bb155510edbf4c',
            hash_file('sha256', database_path('migrations/2026_10_29_200800_create_billing_behavior_outbox.php')),
            'Already adopted 200800 must not be rewritten to hide the old schema.');
        $precision = fn (string $table, string $column) => DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('table_name', $table)->where('column_name', $column)->value('datetime_precision');
        $this->assertSame(6, (int) $precision('billing_behavior_outbox', 'occurred_at'), 'The complete candidate must include the NEW precision migration.');
        foreach ([['billing_payments', 'paid_at'], ['billing_entitlements', 'access_from'], ['billing_entitlements', 'access_until']] as [$table, $column]) {
            $this->assertSame(0, (int) $precision($table, $column), 'Lifecycle correction must not alter existing financial time column declarations.');
        }
        // Old second-resolution rows cannot recover missing microseconds from a schema change; never backfill invented times.
    }
}
