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

    public function scope(string $environment = 'sandbox'): ProviderScope
    {
        try {
            $key = $environment === 'live' ? 'billing.paypal_live' : 'billing.paypal';
            $scope = new ProviderScope('paypal', $environment, config($key.'.merchant_scope', ''));
            $this->api->forScope($scope)->configurationStamp($scope);
            $id = config($scope->paypalConfig().'.product_id');
            if (! is_string($id) || preg_match('/\APROD-[A-Z0-9]{17}\z/D', $id) !== 1) {
                throw new \RuntimeException;
            }

            return $scope;
        } catch (Throwable) {
            throw new DependencyNotReady('Billing / PayPal environment configuration and product');
        }
    }

    public function assertPurchaseReady(string $environment = 'sandbox'): ProviderScope
    {
        $scope = $this->scope($environment);
        if (! $scope->isTest() && ! DB::table('billing_prices')->where('is_test', false)->where('currency', 'USD')->exists()) {
            throw new DependencyNotReady('Billing / explicit Live price');
        }
        $stamp = $this->api->forScope($scope)->configurationStamp($scope);
        $productId = config($scope->paypalConfig().'.product_id');
        try {
            $this->api->forScope($scope)->assertWebhookRegistration($scope);
            $product = $this->api->forScope($scope)->product($productId);
        } catch (Throwable) {
            throw new DependencyNotReady('Billing / PayPal merchant binding and webhook registration');
        }
        if (($product['id'] ?? null) !== $productId || ($product['type'] ?? null) !== 'DIGITAL'
            || $productId !== config($scope->paypalConfig().'.product_id') || ! hash_equals($stamp, $this->api->forScope($scope)->configurationStamp($scope))) {
            throw new DependencyNotReady('Billing / PayPal environment digital product');
        }

        return $scope;
    }

    public function checkout(string $orderId): void
    {
        $order = DB::table('billing_orders')->find($orderId);
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $scope = ProviderScope::fromRecord($order);
        $this->api->forScope($scope)->configurationStamp($scope);
        $this->api->forScope($scope)->assertWebhookRegistration($scope); // retries must recheck the actual seller as well
        if ($order->checkout_request === null) {
            $snapshot = new SandboxWeeklyPlan($sub->price_version, $sub->regular_amount_minor, $sub->first_week_amount_minor, $order->discount_applied, $scope->isTest());
            $product = config($scope->paypalConfig().'.product_id');
            $owner = Uuid::uuid5(Uuid::NAMESPACE_URL, json_encode([$scope->columns(), $snapshot->createRequest($product)], JSON_THROW_ON_ERROR))->toString();
            app(PayPalPlanProvisioner::class)->ensure($scope, $owner, $snapshot, $product);
            app(PayPalCheckout::class)->prepare($order->id, $owner);
        }
        app(PayPalCheckout::class)->execute($order->id);
    }
}
