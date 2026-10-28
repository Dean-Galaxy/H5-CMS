<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\SandboxWeeklyPlan;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/** PayPal-specific operations; never masquerades as Stripe invoices or Checkout Sessions. */
final class PayPalRuntime
{
    public function __construct(private SandboxApi $api) {}

    public function scope(): ProviderScope
    {
        try {
            $scope = new ProviderScope('paypal', 'sandbox', config('billing.paypal.merchant_scope', ''));
            $this->api->configurationStamp($scope);
            $id = config('billing.paypal.product_id');
            if (! is_string($id) || preg_match('/\APROD-[A-Z0-9]{17}\z/D', $id) !== 1) {
                throw new \RuntimeException;
            }

            return $scope;
        } catch (Throwable) {
            throw new DependencyNotReady('Billing / PayPal sandbox configuration and product');
        }
    }

    public function assertPurchaseReady(): ProviderScope
    {
        $scope = $this->scope();
        try {
            $this->api->assertWebhookRegistration($scope);
            $product = $this->api->product(config('billing.paypal.product_id'));
        } catch (Throwable) {
            throw new DependencyNotReady('Billing / PayPal merchant binding and webhook registration');
        }
        if (($product['id'] ?? null) !== config('billing.paypal.product_id') || ($product['type'] ?? null) !== 'DIGITAL') {
            throw new DependencyNotReady('Billing / PayPal sandbox digital product');
        }

        return $scope;
    }

    public function checkout(string $orderId): void
    {
        $order = DB::table('billing_orders')->find($orderId);
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $scope = ProviderScope::fromRecord($order);
        $this->api->configurationStamp($scope);
        $this->api->assertWebhookRegistration($scope); // retries must recheck the actual seller as well
        if ($order->checkout_request === null) {
            $snapshot = new SandboxWeeklyPlan($sub->price_version, $sub->regular_amount_minor, $sub->first_week_amount_minor, $order->discount_applied);
            $product = config('billing.paypal.product_id');
            $owner = Uuid::uuid5(Uuid::NAMESPACE_URL, json_encode([$scope->columns(), $snapshot->createRequest($product)], JSON_THROW_ON_ERROR))->toString();
            app(PayPalPlanProvisioner::class)->ensure($scope, $owner, $snapshot, $product);
            app(PayPalCheckout::class)->prepare($order->id, $owner);
        }
        app(PayPalCheckout::class)->execute($order->id);
    }
}
