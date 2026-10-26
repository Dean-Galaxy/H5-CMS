<?php

namespace Tests\Feature\Billing\Support;

use App\Modules\Billing\Data\ExternalPayment;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Facades\DB;

/** Explicit local paid-fact fixtures; no provider call or official verification. */
final class ProviderLedgerFixture
{
    public static function create(ProviderScope $scope): array
    {
        $viewer = Viewer::forceCreate(['id' => BillingStore::id(), 'name' => 'Local scope fixture',
            'email' => BillingStore::id().'@example.test', 'password' => bcrypt('LocalScopeFixture9!'), 'active' => true, 'email_verified_at' => now()]);
        $subId = BillingStore::id();
        $orderId = BillingStore::id();
        DB::table('billing_accounts')->insert(['viewer_id' => $viewer->id]);
        DB::table('billing_subscriptions')->insert($scope->columns() + [
            'id' => $subId, 'viewer_id' => $viewer->id, 'first_order_id' => $orderId,
            'provider_id' => 'same-subscription', 'customer_id' => 'same-customer', 'state' => 'pending',
            'price_version' => 1, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300,
            'currency' => 'USD', 'is_test' => true, 'resolution_required' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_orders')->insert($scope->columns() + [
            'id' => $orderId, 'viewer_id' => $viewer->id, 'subscription_id' => $subId,
            'idempotency_key' => BillingStore::id(), 'fingerprint' => str_repeat('a', 64),
            'transaction_kind' => 'first_subscription', 'price_version' => 1, 'amount_minor' => 300,
            'regular_amount_minor' => 1200, 'discount_applied' => true, 'currency' => 'USD', 'is_test' => true,
            'state' => 'processing', 'payment_state' => 'pending', 'return_to' => '/', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $start = now()->startOfSecond();
        $fact = new ExternalPayment($scope, 'same-payment', 'same-charge', 'same-subscription', 'same-customer', $orderId,
            true, 300, 1200, 'USD', $start->toDateTimeImmutable(), $start->copy()->addWeek()->toDateTimeImmutable(), $start->toDateTimeImmutable());

        return [$viewer, DB::table('billing_orders')->find($orderId), DB::table('billing_subscriptions')->find($subId), $fact];
    }
}
