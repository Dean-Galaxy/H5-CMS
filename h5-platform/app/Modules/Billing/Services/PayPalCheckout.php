<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\SandboxSubscriptionRequest;
use App\Modules\Billing\Gateway\PayPal\SandboxWeeklyPlan;
use App\Modules\Billing\Gateway\PayPal\WeeklyPlanVerifier;
use App\Modules\Billing\Gateway\ProviderScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Existing server-priced order only. No account, offer, public route or entitlement creation. */
final class PayPalCheckout
{
    public function __construct(private SandboxApi $api, private ProviderOperationStore $operations) {}

    public function prepare(string $orderId, string $planOwnerId): void
    {
        DB::transaction(function () use ($orderId, $planOwnerId) {
            $order = DB::table('billing_orders')->find($orderId) ?? throw new RuntimeException('PAYPAL_ORDER_INVALID');
            BillingStore::lock($order->viewer_id);
            [$order, $sub, $scope] = $this->records($orderId);
            $this->api->configurationStamp($scope);
            [$planId] = $this->plan($scope, $planOwnerId, $order, $sub);
            $request = new SandboxSubscriptionRequest($planId, $order->id, $order);
            $this->require($order->state === 'processing' && $order->payment_state === 'pending');
            $intent = $this->operations->reserve($scope, 'create_subscription', $order->id, 1, $request->payload());
            $prepared = ['plan_owner_id' => $planOwnerId, 'intent_id' => $intent->id, 'params' => $request->payload()];
            if ($order->checkout_request !== null) {
                $this->require(BillingStore::json($order->checkout_request) == $prepared);

                return;
            }
            $this->require($sub->provider_id === null && $order->checkout_id === null && $sub->desired_renewal !== false);
            DB::table('billing_orders')->where('id', $orderId)->update([
                'checkout_request' => json_encode($prepared, JSON_THROW_ON_ERROR),
                'checkout_prepared_at' => now(), 'expires_at' => null, 'updated_at' => now(),
            ]);
        });
    }

    /** Null means no safe approval link; it never means that payment or cancellation succeeded. */
    public function execute(string $orderId): ?string
    {
        $this->require(DB::transactionLevel() === 0);
        [$order, $sub, $scope] = $this->records($orderId);
        $stamp = $this->api->configurationStamp($scope);
        $prepared = BillingStore::json($order->checkout_request);
        $this->require(is_array($prepared) && is_string($prepared['plan_owner_id'] ?? null));
        [$planId, $snapshot, $productId] = $this->plan($scope, $prepared['plan_owner_id'], $order, $sub);
        $request = new SandboxSubscriptionRequest($planId, $order->id, $order);
        $this->require(($prepared['params'] ?? null) == $request->payload());
        $intent = $this->operations->reserve($scope, 'create_subscription', $order->id, 1, $request->payload());
        $this->require(($prepared['intent_id'] ?? null) === $intent->id);
        // Marker is committed before HTTP. An interrupted worker can only retry this original key.
        if ($intent->external_id === null) {
            $this->require($this->withinWindow($order));
            $intent = $this->operations->beginAttempt($scope, $intent->id);
        }
        try {
            return DB::transaction(function () use ($orderId, $scope, $stamp, $request, $intent, $planId, $snapshot, $productId) {
                $initial = DB::table('billing_orders')->find($orderId);
                BillingStore::lock($initial->viewer_id);
                [$order, $sub, $currentScope] = $this->records($orderId);
                $this->require($currentScope->columns() === $scope->columns());
                $this->sameConfiguration($scope, $stamp);
                $actualPlan = $this->api->plan($planId);
                $this->sameConfiguration($scope, $stamp);
                (new WeeklyPlanVerifier)->assertMatches($actualPlan, $planId, $snapshot, $productId);
                $currentIntent = DB::table('billing_provider_intents')->find($intent->id);
                $externalId = $currentIntent->external_id;
                $this->require(($sub->provider_id === null || $sub->provider_id === $externalId)
                    && ($order->checkout_id === null || $order->checkout_id === $externalId));
                if ($externalId === null) {
                    $this->require($this->withinWindow($order) && $order->state === 'processing'
                        && $order->payment_state === 'pending' && $sub->state === 'pending'
                        && $sub->provider_id === null && $sub->desired_renewal !== false && ! $sub->refund_stopped);
                    DB::table('billing_orders')->where('id', $orderId)->update(['checkout_attempted_at' => $order->checkout_attempted_at ?? now()]);
                    $reply = $this->api->createSubscription($request, $intent->request_key);
                    $externalId = $reply['id'] ?? null;
                    $this->require(is_string($externalId) && preg_match('/\AI-[A-Z0-9]{1,126}\z/D', $externalId) === 1);
                    $this->operations->complete($scope, $intent->id, $externalId);
                    $this->operations->link($scope, 'subscription', $externalId, 'subscription', $sub->id);
                    DB::table('billing_subscriptions')->where('id', $sub->id)->update(['provider_id' => $externalId, 'updated_at' => now()]);
                    DB::table('billing_orders')->where('id', $orderId)->update(['checkout_id' => $externalId, 'updated_at' => now()]);
                }
                // Catch after the identity write so a failed GET cannot roll that identity back.
                try {
                    $this->sameConfiguration($scope, $stamp);
                    $actual = $this->api->subscription($externalId);
                    $this->sameConfiguration($scope, $stamp);
                    $this->require(($actual['id'] ?? null) === $externalId && ($actual['plan_id'] ?? null) === $planId
                        && ($actual['custom_id'] ?? null) === $orderId && ($actual['plan_overridden'] ?? null) === false
                        && ($actual['quantity'] ?? null) === '1' && ! isset($actual['shipping_amount'])
                        && in_array($actual['status'] ?? null, ['APPROVAL_PENDING', 'APPROVED', 'ACTIVE', 'SUSPENDED', 'CANCELLED', 'EXPIRED'], true));
                    $url = $this->withinWindow($order) && in_array($order->state, ['processing', 'pending'], true)
                        && $order->payment_state === 'pending' && $sub->state === 'pending' && $sub->desired_renewal !== false && ! $sub->refund_stopped
                        ? SandboxSubscriptionRequest::approvalUrl($actual) : null;
                    if (in_array($order->state, ['processing', 'pending'], true)) {
                        DB::table('billing_orders')->where('id', $orderId)->update(['checkout_url' => $url,
                            'state' => 'pending', 'last_error' => $url === null ? 'PAYPAL_RECONCILIATION_REQUIRED' : null, 'updated_at' => now()]);
                    }

                    // ACTIVE/APPROVED alone grant nothing and do not clear resolution_required.
                    return $url;
                } catch (Throwable) {
                    $this->unresolved($orderId);

                    return null;
                }
            });
        } catch (Throwable) {
            $this->unresolved($orderId);
            throw new RuntimeException('PAYPAL_CHECKOUT_RECONCILIATION_REQUIRED');
        }
    }

    private function records(string $orderId): array
    {
        $order = DB::table('billing_orders')->find($orderId) ?? throw new RuntimeException('PAYPAL_ORDER_INVALID');
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $scope = ProviderScope::fromRecord($order);
        $this->require($scope->provider === 'paypal' && $sub !== null && $scope->matches($sub)
            && $sub->first_order_id === $order->id && $sub->viewer_id === $order->viewer_id
            && $sub->price_version === $order->price_version && $sub->regular_amount_minor === $order->regular_amount_minor
            && $sub->currency === 'USD' && $order->currency === 'USD' && $sub->is_test && $order->is_test
            && $order->amount_minor === ($order->discount_applied ? $sub->first_week_amount_minor : $sub->regular_amount_minor)
            && $order->discount_applied === ($order->amount_minor < $order->regular_amount_minor));

        return [$order, $sub, $scope];
    }

    private function plan(ProviderScope $scope, string $ownerId, object $order, object $sub): array
    {
        $mapping = DB::table('billing_provider_resources')->where($scope->columns())
            ->where(['resource_type' => 'plan', 'owner_type' => 'plan', 'owner_id' => $ownerId])->first();
        $intent = DB::table('billing_provider_intents')->where($scope->columns())
            ->where(['operation' => 'create_plan', 'owner_id' => $ownerId, 'operation_version' => 1, 'state' => 'succeeded'])->first();
        $this->require($mapping !== null && $intent !== null && $intent->external_id === $mapping->external_id);
        $payload = BillingStore::json($intent->request_payload);
        $this->require(is_string($payload['product_id'] ?? null));
        $snapshot = new SandboxWeeklyPlan($order->price_version, $order->regular_amount_minor, $sub->first_week_amount_minor, $order->discount_applied);
        $this->require($payload == $snapshot->createRequest($payload['product_id']));

        return [$mapping->external_id, $snapshot, $payload['product_id']];
    }

    private function withinWindow(object $order): bool
    {
        return $order->checkout_prepared_at !== null && now()->lt(CarbonImmutable::parse($order->checkout_prepared_at)->addMinutes(31));
    }

    private function sameConfiguration(ProviderScope $scope, string $stamp): void
    {
        $this->require(hash_equals($stamp, $this->api->configurationStamp($scope)));
    }

    private function unresolved(string $orderId): void
    {
        DB::table('billing_orders')->where('id', $orderId)->update(['checkout_url' => null, 'last_error' => 'PAYPAL_RECONCILIATION_REQUIRED', 'updated_at' => now()]);
    }

    private function require(bool $valid): void
    {
        if (! $valid) {
            throw new RuntimeException('PAYPAL_CHECKOUT_RECONCILIATION_REQUIRED');
        }
    }
}
