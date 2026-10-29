<?php

namespace Tests\Feature\Billing;

use App\Contracts\BehaviorEventRecorder;
use App\Contracts\BillingAnalyticsReader;
use App\Data\ResumeInput;
use App\Modules\Billing\Services\BillingBehaviorOutbox;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\FinancialAnalyticsReader;
use App\Modules\Billing\Services\SettlementService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Billing\Support\BehaviorRecorderFixture;

final class BillingAnalyticsTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_internal_reader_returns_only_authoritative_whitelisted_payment_facts(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $payment = DB::table('billing_payments')->sole();
        $snapshot = ['source_id' => BillingStore::id(), 'link_id' => BillingStore::id(), 'channel' => 'meta',
            'pixel_config_version' => 2, 'captured_at' => '2026-10-29T09:00:00+08:00', 'consent' => 'granted'];
        DB::table('billing_orders')->where('id', $order->id)->update(['attribution_snapshot' => json_encode($snapshot + ['secret' => 'private', 'email' => 'private@example.test'])]);
        $reader = app(BillingAnalyticsReader::class);
        self::assertInstanceOf(FinancialAnalyticsReader::class, $reader);
        $rows = $reader->payments([$payment->id, BillingStore::id(), $payment->id]);
        self::assertSame([$payment->id], array_keys($rows));
        $row = $rows[$payment->id];
        self::assertSame(['payment_id', 'viewer_id', 'order_id', 'subscription_id', 'transaction_kind', 'amount_minor',
            'refunded_amount_minor', 'currency', 'is_test', 'paid_at', 'attribution_snapshot'], array_keys($row));
        self::assertSame([$viewer->id, $order->id, $sub->id, 300, 0, 'USD', true],
            [$row['viewer_id'], $row['order_id'], $row['subscription_id'], $row['amount_minor'], $row['refunded_amount_minor'], $row['currency'], $row['is_test']]);
        self::assertSame('first_subscription', $row['transaction_kind']);
        self::assertSame(BillingStore::iso($payment->paid_at), $row['paid_at']);
        $snapshot['captured_at'] = '2026-10-29T01:00:00Z';
        self::assertSame($snapshot, $row['attribution_snapshot']);
        self::assertStringNotContainsString('private', json_encode($rows));
        self::assertStringNotContainsString($payment->provider_charge_id, json_encode($rows));
        self::assertSame([], $reader->payments([]));
        self::assertCount(1, $reader->payments(array_fill(0, 200, $payment->id)));
    }

    public function test_reader_rejects_invalid_or_over_limit_original_inputs_without_silent_truncation(): void
    {
        foreach ([['not-a-uuid'], [null], [1], array_fill(0, 201, BillingStore::id())] as $ids) {
            try {
                app(BillingAnalyticsReader::class)->payments($ids);
                self::fail('Expected strict validation');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_damaged_snapshot_returns_null_and_safe_diagnostic(): void
    {
        [, $order] = $this->paidFixture();
        $payment = DB::table('billing_payments')->sole();
        DB::table('billing_orders')->where('id', $order->id)->update(['attribution_snapshot' => json_encode(['source_id' => 'broken-private-token'])]);
        Log::spy();
        self::assertNull(app(BillingAnalyticsReader::class)->payments([$payment->id])[$payment->id]['attribution_snapshot']);
        Log::shouldHaveReceived('warning')->once()->with('billing.analytics_snapshot_invalid');
    }

    public static function invalidSnapshotTimes(): array
    {
        return array_map(fn ($value) => [$value], [
            'today', 'now', '+1 day', '2026-10-29', '2026-10-29T09:00:00', '2026-10-29 09:00:00Z',
            '2026-02-30T09:00:00Z', '2026-02-29T09:00:00Z', '2026-13-01T09:00:00Z',
            '2026-10-29T24:00:00Z', '2026-10-29T09:60:00Z', '2026-10-29T09:00:60Z',
            '2026-10-29T09:00:00+24:00', '2026-10-29T09:00:00+08:60',
            '2026-10-29T09:00:00+0800', '2026-10-29T09:00:00 America/New_York',
            '2026-10-29T09:00:00.1234567Z', '2026-10-29T09:00:00Z ', "2026-10-29T09:00:00Z\n", 1793226000,
        ]);
    }

    #[DataProvider('invalidSnapshotTimes')]
    public function test_snapshot_rejects_relative_unzoned_or_invalid_calendar_time_without_reinterpreting_it(mixed $capturedAt): void
    {
        [, $order] = $this->paidFixture();
        $payment = DB::table('billing_payments')->sole();
        $snapshot = ['source_id' => BillingStore::id(), 'link_id' => BillingStore::id(), 'channel' => 'meta',
            'pixel_config_version' => 1, 'captured_at' => $capturedAt, 'consent' => 'granted'];
        DB::table('billing_orders')->where('id', $order->id)->update(['attribution_snapshot' => json_encode($snapshot)]);
        Log::spy();
        $row = app(BillingAnalyticsReader::class)->payments([$payment->id])[$payment->id];
        self::assertNull($row['attribution_snapshot']);
        self::assertSame(300, $row['amount_minor']);
        self::assertSame(BillingStore::iso($payment->paid_at), $row['paid_at']);
        Log::shouldHaveReceived('warning')->once()->with('billing.analytics_snapshot_invalid');
    }

    public static function validSnapshotTimes(): array
    {
        return [
            ['2026-10-29T09:00:00Z', '2026-10-29T09:00:00Z'],
            ['2026-10-29T09:00:00+08:00', '2026-10-29T01:00:00Z'],
            ['2026-10-29T09:00:00-07:00', '2026-10-29T16:00:00Z'],
            ['2026-10-29T09:00:00.123456Z', '2026-10-29T09:00:00Z'],
            ['2024-02-29T09:00:00.1+00:00', '2024-02-29T09:00:00Z'],
        ];
    }

    #[DataProvider('validSnapshotTimes')]
    public function test_snapshot_keeps_valid_explicit_zone_time_as_the_same_utc_instant(string $capturedAt, string $expected): void
    {
        $snapshot = ['source_id' => BillingStore::id(), 'link_id' => BillingStore::id(), 'channel' => 'tiktok',
            'pixel_config_version' => 1, 'captured_at' => $capturedAt, 'consent' => 'granted'];
        Log::spy();
        self::assertSame($expected, FinancialAnalyticsReader::snapshot($snapshot)['captured_at']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_successful_refund_snapshots_are_versioned_and_corrections_can_reduce_to_zero(): void
    {
        [, $order, $sub] = $this->paidFixture();
        self::assertDatabaseCount('billing_behavior_outbox', 0); // Existing PaymentSettled is still the only payment event.
        $payment = DB::table('billing_payments')->sole();
        $charge = $payment->provider_charge_id;
        $this->gateway->refunds[$charge] = [['id' => 're_local_1', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'pending']];
        app(SettlementService::class)->syncRefunds($charge);
        self::assertDatabaseCount('billing_behavior_outbox', 0);
        $this->gateway->refunds[$charge][0]['status'] = 'succeeded';
        app(SettlementService::class)->syncRefunds($charge);
        app(SettlementService::class)->syncRefunds($charge);
        $this->gateway->refunds[$charge][] = ['id' => 're_local_2', 'amount_minor' => 200, 'currency' => 'USD', 'status' => 'succeeded'];
        app(SettlementService::class)->syncRefunds($charge);
        $this->gateway->refunds[$charge][1]['status'] = 'failed';
        app(SettlementService::class)->syncRefunds($charge);
        $this->gateway->refunds[$charge][0]['status'] = 'failed';
        app(SettlementService::class)->syncRefunds($charge);
        $events = DB::table('billing_behavior_outbox')->where('event_name', 'refund_confirmed')->orderBy('fact_version')->get();
        self::assertCount(4, $events);
        self::assertSame([100, 300, 100, 0], $events->map(fn ($event) => BillingStore::json($event->properties)['refunded_amount_minor'])->all());
        self::assertSame([1, 2, 3, 4], $events->map(fn ($event) => BillingStore::json($event->properties)['fact_version'])->all());
        foreach ($events as $event) {
            $props = BillingStore::json($event->properties);
            self::assertSame($payment->id, $props['payment_id']);
            self::assertSame($order->id, $props['order_id']);
            self::assertSame($sub->id, $props['subscription_id']);
            self::assertSame('USD', $props['currency']);
            self::assertTrue($props['is_test']);
            self::assertSame(BillingStore::iso($event->occurred_at), $props['occurred_at']);
        }
        self::assertSame(0, app(BillingAnalyticsReader::class)->payments([$payment->id])[$payment->id]['refunded_amount_minor']);
        self::assertNull(DB::table('billing_entitlements')->sole()->revoked_at);
        self::assertNotNull(DB::table('billing_accounts')->sole()->first_offer_used_at);
        self::assertFalse(DB::table('billing_subscriptions')->sole()->renewal_enabled);
        self::assertDatabaseCount('billing_payments', 1);
        self::assertDatabaseCount('billing_outbox', 1);
    }

    public function test_cancel_resume_confirmations_are_durable_and_replay_does_not_create_events(): void
    {
        [$viewer, , $sub] = $this->paidFixture();
        $original = DB::table('billing_entitlements')->sole();
        self::assertDatabaseCount('billing_behavior_outbox', 0);
        $this->engine->cancel($viewer);
        $this->engine->cancel($viewer);
        $quote = $this->engine->resumeQuote($viewer);
        $input = new ResumeInput($quote->quote_id, $quote->confirmation_version, $quote->next_renewal_at, $quote->next_renewal_amount_minor, $quote->currency);
        $this->engine->resume($viewer, $input);
        $this->engine->resume($viewer, $input);
        $this->engine->cancel($viewer);
        $this->assertProblem('STALE_VERSION', fn () => $this->engine->resume($viewer, $input));
        self::assertSame(['subscription_cancelled', 'subscription_resumed', 'subscription_cancelled'],
            DB::table('billing_behavior_outbox')->where('aggregate_key', 'subscription:'.$sub->id)->orderBy('fact_version')->pluck('event_name')->all());
        self::assertSame([1, 2, 3], DB::table('billing_behavior_outbox')->orderBy('fact_version')->pluck('fact_version')->all());
        self::assertSame($original->access_until, DB::table('billing_entitlements')->sole()->access_until);
        self::assertSame($original->access_from, DB::table('billing_entitlements')->sole()->access_from);
    }

    public function test_unknown_cancel_does_not_emit_success_until_the_original_operation_is_confirmed(): void
    {
        [$viewer] = $this->paidFixture();
        $this->gateway->failRenewal = true;
        $this->assertProblem('BILLING_PENDING', fn () => $this->engine->cancel($viewer));
        self::assertDatabaseCount('billing_behavior_outbox', 0);
        $this->gateway->failRenewal = false;
        $this->engine->executeOperation(DB::table('billing_operations')->sole()->id);
        self::assertDatabaseCount('billing_behavior_outbox', 1);
        self::assertSame('subscription_cancelled', DB::table('billing_behavior_outbox')->sole()->event_name);
    }

    public static function unavailableModes(): array
    {
        return [['retry_after_write'], ['sql_failure']];
    }

    #[DataProvider('unavailableModes')]
    public function test_recorder_failure_isolated_from_finance_backoffs_and_retries_with_same_key(string $mode): void
    {
        [$viewer] = $this->paidFixture();
        $recorder = new BehaviorRecorderFixture;
        $recorder->mode = $mode;
        app()->instance(BehaviorEventRecorder::class, $recorder);
        $this->engine->cancel($viewer);
        self::assertSame([], $recorder->calls); // No consumer code in the financial transaction.
        $financial = $this->financialSnapshot();
        $worker = app(BillingBehaviorOutbox::class);
        self::assertSame(0, $worker->dispatchPending());
        self::assertSame($financial, $this->financialSnapshot());
        self::assertSame(0, DB::table('cache')->where('key', 'like', 'billing:%')->count()); // Consumer partial write rolled back.
        $row = DB::table('billing_behavior_outbox')->sole();
        self::assertSame('pending', $row->state);
        self::assertSame(1, $row->attempts);
        self::assertSame('RECORDER_RETRY_REQUIRED', $row->last_error);
        self::assertSame(60.0, now()->diffInSeconds($row->next_attempt_at, true));
        self::assertSame(0, $worker->dispatchPending());
        self::assertCount(1, $recorder->calls);
        $this->travel(61)->seconds();
        $recorder->mode = 'accepted';
        self::assertSame(1, $worker->dispatchPending());
        self::assertSame($recorder->calls[0], $recorder->calls[1]);
        self::assertSame('delivered', DB::table('billing_behavior_outbox')->sole()->state);
        self::assertSame(0, $worker->dispatchPending());
        self::assertSame(1, DB::table('cache')->where('key', 'like', 'billing:%')->count());
        self::assertSame($financial, $this->financialSnapshot());
    }

    public function test_refused_consent_is_final_and_replay_cannot_retrack_after_later_consent(): void
    {
        [$viewer] = $this->paidFixture();
        $this->engine->cancel($viewer);
        $recorder = new BehaviorRecorderFixture;
        $recorder->mode = 'skipped';
        app()->instance(BehaviorEventRecorder::class, $recorder);
        self::assertSame(1, app(BillingBehaviorOutbox::class)->dispatchPending());
        self::assertSame('skipped', DB::table('billing_behavior_outbox')->sole()->state);
        self::assertSame('NOT_TRACKABLE', DB::table('billing_behavior_outbox')->sole()->last_error);
        $recorder->mode = 'accepted';
        $this->travel(2)->days();
        self::assertSame(0, Artisan::call('billing:behavior-dispatch'));
        self::assertCount(1, $recorder->calls);
        self::assertSame(0, DB::table('cache')->where('key', 'like', 'billing:%')->count());
    }

    public function test_missing_consumer_is_retryable_and_versions_are_delivered_in_order(): void
    {
        [$viewer] = $this->paidFixture();
        $this->engine->cancel($viewer);
        self::assertSame(0, app(BillingBehaviorOutbox::class)->dispatchPending()); // Default disabled recorder -> Retry.
        $quote = $this->engine->resumeQuote($viewer);
        $this->engine->resume($viewer, new ResumeInput($quote->quote_id, $quote->confirmation_version, $quote->next_renewal_at, $quote->next_renewal_amount_minor, $quote->currency));
        $recorder = new BehaviorRecorderFixture;
        app()->instance(BehaviorEventRecorder::class, $recorder);
        self::assertSame(0, app(BillingBehaviorOutbox::class)->dispatchPending()); // Newer version waits behind delayed older one.
        $this->travel(61)->seconds();
        self::assertSame(1, app(BillingBehaviorOutbox::class)->dispatchPending());
        self::assertSame(1, app(BillingBehaviorOutbox::class)->dispatchPending());
        self::assertSame(['subscription_cancelled', 'subscription_resumed'], array_column($recorder->calls, 0));
    }

    public function test_financial_rollback_does_not_leave_a_phantom_behavior_fact(): void
    {
        $this->paidFixture();
        $payment = DB::table('billing_payments')->sole();
        try {
            DB::transaction(function () use ($payment) {
                $this->gateway->refunds[$payment->provider_charge_id] = [['id' => 're_rollback', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded']];
                app(SettlementService::class)->syncRefunds($payment->provider_charge_id);
                throw new RuntimeException('Explicit local transaction rollback');
            });
        } catch (RuntimeException) {
        }
        self::assertDatabaseCount('billing_behavior_outbox', 0);
        self::assertSame(0, DB::table('billing_payments')->sole()->refunded_amount_minor);
        self::assertNull(DB::table('billing_entitlements')->sole()->revoked_at);
    }

    public function test_upgrade_baselines_existing_refunds_and_cancellations_without_retroactive_events(): void
    {
        [, , $sub] = $this->paidFixture();
        $payment = DB::table('billing_payments')->sole();
        $this->gateway->refunds[$payment->provider_charge_id] = [['id' => 're_old', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded']];
        app(SettlementService::class)->syncRefunds($payment->provider_charge_id);
        $financial = $this->financialSnapshot();
        $migration = require database_path('migrations/2026_10_29_200800_create_billing_behavior_outbox.php');
        $migration->down();
        $migration->up();
        self::assertSame($financial, $this->financialSnapshot());
        self::assertDatabaseCount('billing_behavior_outbox', 0);
        self::assertSame('100', DB::table('billing_analytics_states')->where('aggregate_key', 'refund:'.$payment->id)->value('value'));
        self::assertSame('disabled', DB::table('billing_analytics_states')->where('aggregate_key', 'subscription:'.$sub->id)->value('value'));
        app(SettlementService::class)->syncRefunds($payment->provider_charge_id);
        app(SettlementService::class)->syncSubscription($sub->provider_id);
        self::assertDatabaseCount('billing_behavior_outbox', 0);
        $this->gateway->refunds[$payment->provider_charge_id][] = ['id' => 're_new', 'amount_minor' => 200, 'currency' => 'USD', 'status' => 'succeeded'];
        app(SettlementService::class)->syncRefunds($payment->provider_charge_id);
        self::assertDatabaseCount('billing_behavior_outbox', 1);
        $properties = BillingStore::json(DB::table('billing_behavior_outbox')->sole()->properties);
        self::assertSame(1, $properties['fact_version']);
        self::assertSame(300, $properties['refunded_amount_minor']);
    }

    public function test_blocked_aggregate_cannot_starve_others_at_limit_one_and_backoff_caps_at_a_day(): void
    {
        [$first] = $this->paidFixture();
        $this->engine->cancel($first);
        app(BillingBehaviorOutbox::class)->dispatchPending(1);
        $older = DB::table('billing_behavior_outbox')->sole();
        $quote = $this->engine->resumeQuote($first);
        $this->engine->resume($first, new ResumeInput($quote->quote_id, $quote->confirmation_version, $quote->next_renewal_at, $quote->next_renewal_amount_minor, $quote->currency));
        [$second] = $this->paidFixture();
        $this->engine->cancel($second);
        $recorder = new BehaviorRecorderFixture;
        app()->instance(BehaviorEventRecorder::class, $recorder);
        self::assertSame(1, app(BillingBehaviorOutbox::class)->dispatchPending(1));
        self::assertSame($second->id, $recorder->calls[0][1]['viewer_id']);
        $recorder->mode = 'retry_after_write';
        DB::table('billing_behavior_outbox')->where('id', $older->id)->update(['attempts' => 50, 'next_attempt_at' => now()]);
        self::assertSame(0, app(BillingBehaviorOutbox::class)->dispatchPending(1));
        $row = DB::table('billing_behavior_outbox')->find($older->id);
        self::assertSame(86400.0, now()->diffInSeconds($row->next_attempt_at, true));
        self::assertSame('pending', $row->state);
        self::assertSame(51, $row->attempts);
    }

    private function financialSnapshot(): array
    {
        $result = [];
        foreach (['billing_payments', 'billing_entitlements', 'billing_subscriptions', 'billing_orders', 'billing_operations', 'billing_refunds', 'billing_accounts', 'billing_outbox'] as $table) {
            $result[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
