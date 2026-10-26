<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\ProviderOperationStore;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ProviderOperationStoreTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // Remove only this suite's evidence before the migration trait's rollback.
        DB::table('billing_provider_resources')->delete();
        DB::table('billing_provider_intents')->delete();
        parent::tearDown();
    }

    public function test_same_intent_reuses_payload_key_and_deadline_after_unknown_result(): void
    {
        $store = new ProviderOperationStore;
        $scope = new ProviderScope('paypal', 'sandbox', 'merchant-a');
        $owner = BillingStore::id();
        $first = $store->reserve($scope, 'create_subscription', $owner, 1, ['plan_id' => 'P-LOCAL', 'custom_id' => $owner]);
        $again = $store->reserve($scope, 'create_subscription', $owner, 1, ['custom_id' => $owner, 'plan_id' => 'P-LOCAL']);
        self::assertSame($first->id, $again->id);
        self::assertSame($first->request_key, $again->request_key);
        $sent = $store->beginAttempt($scope, $first->id);
        self::assertSame('unknown', $sent->state);
        $this->travel(1)->hours();
        $retry = $store->beginAttempt($scope, $first->id);
        self::assertSame($sent->retry_before, $retry->retry_before);
        self::assertSame($sent->first_attempted_at, $retry->first_attempted_at);
        self::assertSame($sent->request_key, $retry->request_key);
        self::assertSame($sent->fingerprint, $retry->fingerprint);
        $store->complete($scope, $first->id, 'I-LOCAL');
        $store->complete($scope, $first->id, 'I-LOCAL');
        self::assertSame('succeeded', DB::table('billing_provider_intents')->find($first->id)->state);
        self::assertDatabaseCount('billing_provider_intents', 1);
    }

    public function test_expired_provider_idempotency_window_requires_reconciliation_not_new_key(): void
    {
        $store = new ProviderOperationStore;
        $scope = new ProviderScope('paypal', 'sandbox', 'merchant-a');
        $intent = $store->reserve($scope, 'create_plan', BillingStore::id(), 1, ['name' => 'TEST']);
        $store->beginAttempt($scope, $intent->id);
        $this->travel(72)->hours();
        $this->expectExceptionMessage('BILLING_PROVIDER_INTENT_RECONCILE_ONLY');
        $store->beginAttempt($scope, $intent->id);
    }

    public function test_mutated_payload_cannot_reuse_the_original_idempotency_intent(): void
    {
        $store = new ProviderOperationStore;
        $scope = new ProviderScope('paypal', 'sandbox', 'merchant-a');
        $owner = BillingStore::id();
        $store->reserve($scope, 'create_subscription', $owner, 1, ['plan_id' => 'P-OLD']);
        $this->expectExceptionMessage('BILLING_PROVIDER_INTENT_CONFLICT');
        $store->reserve($scope, 'create_subscription', $owner, 1, ['plan_id' => 'P-NEW']);
    }

    public function test_cross_merchant_cannot_complete_another_scope_intent(): void
    {
        $store = new ProviderOperationStore;
        $a = new ProviderScope('paypal', 'sandbox', 'merchant-a');
        $intent = $store->reserve($a, 'create_subscription', BillingStore::id(), 1, ['plan_id' => 'P-LOCAL']);
        $store->beginAttempt($a, $intent->id);
        $this->expectExceptionMessage('BILLING_PROVIDER_INTENT_CONFLICT');
        $store->complete(new ProviderScope('paypal', 'sandbox', 'merchant-b'), $intent->id, 'I-LOCAL');
    }

    public function test_uncommitted_intent_cannot_be_dispatched(): void
    {
        $store = new ProviderOperationStore;
        $scope = new ProviderScope('paypal', 'sandbox', 'merchant-a');
        $intent = $store->reserve($scope, 'create_subscription', BillingStore::id(), 1, ['plan_id' => 'P-LOCAL']);
        try {
            DB::transaction(function () use ($store, $scope, $intent) {
                $store->beginAttempt($scope, $intent->id);
            });
            self::fail('Expected commit boundary rejection');
        } catch (RuntimeException $e) {
            self::assertSame('BILLING_PROVIDER_INTENT_NOT_COMMITTED', $e->getMessage());
            self::assertSame('prepared', DB::table('billing_provider_intents')->find($intent->id)->state);
        }
    }

    public function test_same_external_id_is_isolated_by_provider_and_merchant(): void
    {
        $store = new ProviderOperationStore;
        $a = new ProviderScope('paypal', 'sandbox', 'merchant-a');
        $b = new ProviderScope('paypal', 'sandbox', 'merchant-b');
        $stripe = new ProviderScope('stripe', 'test', 'legacy-stripe-test');
        $owner = BillingStore::id();
        $first = $store->link($a, 'subscription', 'same-id', 'subscription', $owner);
        self::assertSame($first->id, $store->link($a, 'subscription', 'same-id', 'subscription', $owner)->id);
        self::assertNotSame($first->id, $store->link($b, 'subscription', 'same-id', 'subscription', BillingStore::id())->id);
        $store->link($stripe, 'subscription', 'same-id', 'subscription', BillingStore::id());
        self::assertDatabaseCount('billing_provider_resources', 3);
        $this->expectExceptionMessage('BILLING_PROVIDER_RESOURCE_CONFLICT');
        $store->link($a, 'subscription', 'same-id', 'subscription', BillingStore::id());
    }

    public function test_one_owner_cannot_acquire_a_second_external_subscription_in_the_same_scope(): void
    {
        $store = new ProviderOperationStore;
        $scope = new ProviderScope('paypal', 'sandbox', 'merchant-a');
        $owner = BillingStore::id();
        $store->link($scope, 'subscription', 'I-FIRST', 'subscription', $owner);
        $this->expectExceptionMessage('BILLING_PROVIDER_RESOURCE_CONFLICT');
        $store->link($scope, 'subscription', 'I-SECOND', 'subscription', $owner);
    }

    public function test_upgrade_preserves_legacy_subscription_and_cross_channel_one_open_constraint(): void
    {
        $migration = require database_path('migrations/2026_10_26_200400_add_billing_provider_scopes.php');
        $migration->down();
        $viewer = BillingStore::id();
        $id = BillingStore::id();
        $legacy = ['id' => $id, 'viewer_id' => $viewer, 'first_order_id' => BillingStore::id(), 'provider_id' => 'sub_local_legacy',
            'state' => 'active', 'price_version' => 1, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300,
            'currency' => 'USD', 'is_test' => true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('billing_subscriptions')->insert($legacy);
        $before = (array) DB::table('billing_subscriptions')->find($id);
        $migration->up();
        $after = (array) DB::table('billing_subscriptions')->find($id);
        self::assertSame($before, array_diff_key($after, array_flip(['provider', 'provider_environment', 'merchant_scope'])));
        self::assertSame(['provider' => 'stripe', 'provider_environment' => 'test', 'merchant_scope' => 'legacy-stripe-test'], ProviderScope::fromRecord((object) $after)->columns());
        $second = array_replace($legacy, ['id' => BillingStore::id(), 'provider_id' => 'I-PAYPAL'], (new ProviderScope('paypal', 'sandbox', 'merchant-a'))->columns());
        self::assertSame(0, DB::table('billing_subscriptions')->insertOrIgnore($second));
        self::assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_rollback_refuses_to_drop_new_provider_evidence(): void
    {
        $store = new ProviderOperationStore;
        $store->reserve(new ProviderScope('paypal', 'sandbox', 'merchant-a'), 'create_plan', BillingStore::id(), 1, ['name' => 'TEST']);
        $migration = require database_path('migrations/2026_10_26_200400_add_billing_provider_scopes.php');
        $this->expectExceptionMessage('BILLING_PROVIDER_ROLLBACK_REQUIRES_REVIEW');
        $migration->down();
    }
}
