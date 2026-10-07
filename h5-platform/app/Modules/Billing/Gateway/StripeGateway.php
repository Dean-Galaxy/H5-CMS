<?php

namespace App\Modules\Billing\Gateway;

use App\Modules\Identity\Services\SafeReturn;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Webhook;

/** Test-account adapter; no Cashier default routes or second membership store. */
final class StripeGateway implements PaymentGateway
{
    public const API_VERSION = '2026-08-26.dahlia';

    public function assertAvailable(): void
    {
        if (! app()->environment(['local', 'testing'])
            || config('billing.driver') !== 'stripe_test'
            || ! str_starts_with((string) config('billing.stripe_secret'), 'sk_test_')
            || config('billing.api_version') !== self::API_VERSION) {
            throw new DependencyNotReady('Billing / Stripe 受控测试配置');
        }
    }

    private function client(): StripeClient
    {
        $this->assertAvailable();

        return Cashier::stripe([
            'api_key' => config('billing.stripe_secret'),
            'stripe_version' => self::API_VERSION,
            'api_base' => 'https://api.stripe.com',
        ]);
    }

    private function call(callable $operation): mixed
    {
        $this->assertAvailable();
        try {
            return $operation($this->client());
        } catch (ApiErrorException) {
            // Provider errors may contain credentials, URLs or personal information.
            throw $this->pending();
        }
    }

    private function pending(): ApiProblem
    {
        return new ApiProblem('BILLING_PENDING', 409, '付款平台状态待核对，请稍后重新读取状态', true);
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw $this->pending();
        }
    }

    private function data(mixed $value): array
    {
        return $value instanceof StripeObject ? $value->toArray() : (is_array($value) ? $value : []);
    }

    private function id(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : ($this->data($value)['id'] ?? null);
    }

    private function testObject(array $object): void
    {
        $this->require(($object['livemode'] ?? null) === false);
    }

    private function iso(mixed $timestamp): string
    {
        $this->require(is_int($timestamp) && $timestamp > 0);

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function weeklyPrice(array $price): int
    {
        $this->testObject($price);
        $this->require(($price['currency'] ?? null) === 'usd'
            && ($price['recurring']['interval'] ?? null) === 'week'
            && ($price['recurring']['interval_count'] ?? null) === 1
            && is_int($price['unit_amount'] ?? null) && $price['unit_amount'] > 0);

        return $price['unit_amount'];
    }

    private function assertCheckoutOrder(object $order, object $subscription): void
    {
        $this->assertAvailable();
        $this->require((bool) $order->is_test && (bool) $subscription->is_test
            && $order->currency === 'USD' && $subscription->currency === 'USD'
            && $order->viewer_id === $subscription->viewer_id
            && $order->subscription_id === $subscription->id
            && (int) $order->regular_amount_minor === (int) $subscription->regular_amount_minor
            && (int) $order->amount_minor > 0
            && (int) $order->amount_minor <= (int) $order->regular_amount_minor
            && ((bool) $order->discount_applied || (int) $order->amount_minor === (int) $order->regular_amount_minor));
    }

    public function prepareCheckout(object $order, object $subscription): array
    {
        $this->assertCheckoutOrder($order, $subscription);
        if (! empty($order->checkout_request)) {
            return $this->preparedRequest($order, $subscription);
        }
        $metadata = [
            'order_id' => (string) $order->id,
            'viewer_id' => (string) $order->viewer_id,
            'subscription_id' => (string) $subscription->id,
            'price_version' => (string) $order->price_version,
        ];
        $testClock = config('billing.test_clock_id');
        $this->require($testClock === null || (is_string($testClock) && preg_match('/^clock_[A-Za-z0-9]+$/D', $testClock) === 1));
        $customerId = $subscription->customer_id ?? null;
        if (! $customerId) {
            $customerParams = ['metadata' => ['viewer_id' => (string) $order->viewer_id]];
            if ($testClock !== null) {
                $customerParams['test_clock'] = $testClock;
            }
            $customer = $this->data($this->call(fn ($stripe) => $stripe->customers->create(
                $customerParams, ['idempotency_key' => 'h5-customer-'.$order->id]
            )));
            $this->testObject($customer);
            $customerId = $this->id($customer);
        } else {
            $customer = $this->data($this->call(fn ($stripe) => $stripe->customers->retrieve($customerId)));
            $this->testObject($customer);
        }
        // A clock belongs to this controlled test scenario. Never attach or
        // rebind an existing Customer to another clock as a side effect.
        $this->require($customerId !== null
            && $this->id($customer['test_clock'] ?? null) === $testClock
            && ($customer['metadata']['viewer_id'] ?? null) === (string) $order->viewer_id);
        $appUrl = rtrim((string) config('app.url'), '/');
        $this->require(filter_var($appUrl, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true));
        $return = parse_url(app(SafeReturn::class)->path($order->return_to));
        parse_str($return['query'] ?? '', $query);
        $query['order_id'] = (string) $order->id;
        $returnUrl = $appUrl.$return['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            .(isset($return['fragment']) ? '#'.$return['fragment'] : '');
        $params = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $order->id,
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => (int) $order->regular_amount_minor,
                    'recurring' => ['interval' => 'week', 'interval_count' => 1],
                    'product_data' => ['name' => 'TEST — H5 weekly membership', 'metadata' => ['price_version' => (string) $order->price_version]],
                ],
                'quantity' => 1,
            ]],
            'payment_method_types' => ['card'],
            // Omission keeps manual promotion codes disabled and avoids the coupon conflict.
            'adaptive_pricing' => ['enabled' => false],
            'automatic_tax' => ['enabled' => false],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ];
        $discount = (int) $order->regular_amount_minor - (int) $order->amount_minor;
        if ($discount > 0) {
            $coupon = $this->data($this->call(fn ($stripe) => $stripe->coupons->create([
                'duration' => 'once', 'currency' => 'usd', 'amount_off' => $discount,
                'name' => 'TEST — first paid week', 'metadata' => $metadata,
            ], ['idempotency_key' => 'h5-coupon-'.$order->id])));
            $this->testObject($coupon);
            $this->require(($coupon['duration'] ?? null) === 'once'
                && ($coupon['currency'] ?? null) === 'usd' && ($coupon['amount_off'] ?? null) === $discount);
            $params['discounts'] = [['coupon' => $coupon['id']]];
        }
        // Customer/coupon work is complete. Persist this whole intent before the
        // Session POST; retries must not derive new parameters or a new deadline.
        $preparedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $params['expires_at'] = $preparedAt->timestamp + 1860;

        return [
            'params' => $params, 'idempotency_key' => 'h5-checkout-'.$order->id,
            'customer_id' => $customerId, 'prepared_at' => $this->iso($preparedAt->timestamp),
            'expires_at' => $this->iso($params['expires_at']),
        ];
    }

    private function preparedRequest(object $order, object $subscription): array
    {
        $this->assertCheckoutOrder($order, $subscription);
        $request = $order->checkout_request ?? null;
        if (is_string($request)) {
            try {
                $request = json_decode($request, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw $this->pending();
            }
        }
        $this->require(is_array($request));
        $params = $request['params'] ?? [];
        $this->require(is_array($params)
            && ($request['idempotency_key'] ?? null) === 'h5-checkout-'.$order->id
            && is_string($request['customer_id'] ?? null) && $request['customer_id'] !== ''
            && ($params['customer'] ?? null) === $request['customer_id']
            && ($subscription->customer_id === null || $subscription->customer_id === $request['customer_id'])
            && ($params['mode'] ?? null) === 'subscription'
            && count($params['line_items'] ?? []) === 1
            && ($params['line_items'][0]['quantity'] ?? null) === 1
            && ($params['line_items'][0]['price_data']['unit_amount'] ?? null) === (int) $order->regular_amount_minor
            && ($params['line_items'][0]['price_data']['currency'] ?? null) === 'usd'
            && ($params['line_items'][0]['price_data']['recurring'] ?? null) === ['interval' => 'week', 'interval_count' => 1]
            // Read old immutable intents without silently normalizing them.
            && (! array_key_exists('allow_promotion_codes', $params) || $params['allow_promotion_codes'] === false)
            && ($params['client_reference_id'] ?? null) === (string) $order->id
            && ($params['metadata']['order_id'] ?? null) === (string) $order->id
            && ($params['metadata']['viewer_id'] ?? null) === (string) $order->viewer_id
            && ($params['metadata']['subscription_id'] ?? null) === (string) $subscription->id
            && ($params['subscription_data']['metadata'] ?? null) === ($params['metadata'] ?? null)
            && is_int($params['expires_at'] ?? null)
            && is_string($request['prepared_at'] ?? null) && is_string($request['expires_at'] ?? null));
        $discount = (int) $order->regular_amount_minor - (int) $order->amount_minor;
        if ($discount > 0) {
            $discounts = $params['discounts'] ?? null;
            $this->require(is_array($discounts) && count($discounts) === 1
                && is_array($discounts[0] ?? null) && array_keys($discounts[0]) === ['coupon']
                && is_string($discounts[0]['coupon']) && $discounts[0]['coupon'] !== '');
        } else {
            $this->require(! array_key_exists('discounts', $params));
        }
        try {
            $prepared = CarbonImmutable::parse($request['prepared_at'])->timestamp;
            $expires = CarbonImmutable::parse($request['expires_at'])->timestamp;
            $storedPrepared = empty($order->checkout_prepared_at) ? $prepared : CarbonImmutable::parse($order->checkout_prepared_at)->timestamp;
        } catch (\Throwable) {
            throw $this->pending();
        }
        $this->require($storedPrepared === $prepared && $expires === $prepared + 1860 && $params['expires_at'] === $expires);

        return $request;
    }

    public function createCheckout(object $order, object $subscription): array
    {
        $request = $this->preparedRequest($order, $subscription);
        $params = $request['params'];
        $unknown = ! empty($order->checkout_attempted_at);
        $late = $params['expires_at'] - CarbonImmutable::now('UTC')->timestamp <= 1800;
        // F05: legacy coupon + false is readable for reconciliation, never sendable.
        // Even an unattempted old intent must not be edited or re-POSTed with new parameters.
        $legacyPromotionConflict = array_key_exists('discounts', $params) && array_key_exists('allow_promotion_codes', $params);
        if ($unknown || $late || $legacyPromotionConflict) {
            // List by the persisted Customer, not by a newly minted Customer.
            // This also finds Sessions omitted by a lost create response.
            $sessions = $this->all(fn ($stripe, $args) => $stripe->checkout->sessions->all([
                'customer' => $request['customer_id'], ...$args,
            ]));
            $matches = [];
            foreach ($sessions as $session) {
                $this->testObject($session);
                if (($session['metadata']['order_id'] ?? null) === (string) $order->id) {
                    $matches[] = $session;
                }
            }
            $this->require(count($matches) === 1);
            $id = $matches[0]['id'];
            $session = $this->data($this->call(fn ($stripe) => $stripe->checkout->sessions->retrieve($id)));
            $this->require(($session['id'] ?? null) === $id);
        } else {
            $session = $this->data($this->call(fn ($stripe) => $stripe->checkout->sessions->create(
                $params, ['idempotency_key' => $request['idempotency_key']],
            )));
        }
        $this->testObject($session);
        $status = $session['status'] ?? null;
        $this->require(($session['mode'] ?? null) === 'subscription'
            && in_array($status, ['open', 'complete', 'expired'], true)
            && $this->id($session['customer'] ?? null) === $request['customer_id']
            && ($session['client_reference_id'] ?? null) === (string) $order->id
            && ($session['metadata']['order_id'] ?? null) === (string) $order->id
            && ($session['amount_total'] ?? null) === (int) $order->amount_minor
            && ($session['currency'] ?? null) === 'usd'
            && ($session['expires_at'] ?? null) === $params['expires_at']);
        $url = null;
        if ($status === 'open' && $params['expires_at'] > CarbonImmutable::now('UTC')->timestamp) {
            $this->require(is_string($session['url'] ?? null)
                && parse_url($session['url'], PHP_URL_HOST) === 'checkout.stripe.com'
                && parse_url($session['url'], PHP_URL_SCHEME) === 'https');
            $url = $session['url'];
        }

        return [
            'session_id' => $session['id'], 'url' => $url, 'status' => $status,
            'payment_status' => $session['payment_status'] ?? 'unpaid',
            'expires_at' => $this->iso($session['expires_at']),
            'customer_id' => $request['customer_id'], 'subscription_id' => $this->id($session['subscription'] ?? null),
        ];
    }

    public function checkout(string $sessionId): array
    {
        $session = $this->data($this->call(fn ($stripe) => $stripe->checkout->sessions->retrieve($sessionId)));
        $this->testObject($session);
        $this->require(($session['id'] ?? null) === $sessionId && ($session['mode'] ?? null) === 'subscription'
            && in_array($session['status'] ?? null, ['open', 'complete', 'expired'], true));

        return [
            'id' => $sessionId, 'status' => $session['status'], 'payment_status' => $session['payment_status'] ?? 'unpaid',
            'subscription_id' => $this->id($session['subscription'] ?? null),
            'customer_id' => $this->id($session['customer'] ?? null),
            'order_id' => $session['metadata']['order_id'] ?? null,
        ];
    }

    public function subscription(string $id): array
    {
        $raw = $this->data($this->call(fn ($stripe) => $stripe->subscriptions->retrieve($id)));
        $this->require(($raw['id'] ?? null) === $id);

        return $this->normalizeSubscription($raw);
    }

    private function normalizeSubscription(array $raw): array
    {
        $this->testObject($raw);
        $items = $raw['items']['data'] ?? [];
        $this->require(count($items) === 1 && ! ($raw['items']['has_more'] ?? true)
            && ($items[0]['quantity'] ?? null) === 1
            && in_array($raw['status'] ?? null, ['incomplete', 'incomplete_expired', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'paused'], true));
        $item = $items[0];
        $this->require(is_int($item['current_period_start'] ?? null) && is_int($item['current_period_end'] ?? null)
            && $item['current_period_end'] > $item['current_period_start']);

        return [
            'id' => $raw['id'], 'customer_id' => $this->id($raw['customer'] ?? null),
            'status' => $raw['status'], 'cancel_at_period_end' => ($raw['cancel_at_period_end'] ?? false) === true,
            'period_start' => $this->iso($item['current_period_start']), 'period_end' => $this->iso($item['current_period_end']),
            'regular_amount_minor' => $this->weeklyPrice($item['price'] ?? []), 'currency' => 'USD',
            'order_id' => $raw['metadata']['order_id'] ?? null,
            'latest_invoice_id' => $this->id($raw['latest_invoice'] ?? null), 'is_test' => true,
        ];
    }

    public function setRenewal(object $subscription, bool $enabled, string $idempotencyKey): array
    {
        $this->assertAvailable();
        $this->require((bool) $subscription->is_test && is_string($subscription->provider_id) && $idempotencyKey !== '');
        $before = $this->subscription($subscription->provider_id);
        $this->require($before['customer_id'] === $subscription->customer_id
            && $before['regular_amount_minor'] === (int) $subscription->regular_amount_minor
            && $before['currency'] === $subscription->currency
            && (! $enabled || $before['status'] === 'active'));
        $this->call(fn ($stripe) => $stripe->subscriptions->update($subscription->provider_id, [
            'cancel_at_period_end' => ! $enabled,
        ], ['idempotency_key' => $idempotencyKey]));
        // A timeout never reports success. Reconciliation uses this same subscription.
        $after = $this->subscription($subscription->provider_id);
        $this->require($after['cancel_at_period_end'] === ! $enabled
            && $after['customer_id'] === $before['customer_id']
            && $after['period_start'] === $before['period_start'] && $after['period_end'] === $before['period_end']
            && $after['regular_amount_minor'] === $before['regular_amount_minor']);

        return $after;
    }

    public function invoice(string $id): array
    {
        $invoice = $this->data($this->call(fn ($stripe) => $stripe->invoices->retrieve($id)));
        $this->testObject($invoice);
        $this->require(($invoice['id'] ?? null) === $id && ($invoice['currency'] ?? null) === 'usd');
        $subscriptionId = $this->id($invoice['parent']['subscription_details']['subscription'] ?? null);
        $this->require($subscriptionId !== null);
        $subscription = $this->subscription($subscriptionId);
        $lines = $this->all(fn ($stripe, $args) => $stripe->invoices->allLines($id, $args));
        $this->require(count($lines) === 1);
        $line = $lines[0];
        $this->testObject($line);
        $details = $line['parent']['subscription_item_details'] ?? [];
        $this->require(($line['parent']['type'] ?? null) === 'subscription_item_details'
            && ($details['subscription'] ?? null) === $subscriptionId
            && ($details['proration'] ?? null) === false
            && ($line['quantity'] ?? null) === 1 && ($line['currency'] ?? null) === 'usd');
        $priceId = $this->id($line['pricing']['price_details']['price'] ?? null);
        $this->require($priceId !== null);
        $price = $this->data($this->call(fn ($stripe) => $stripe->prices->retrieve($priceId)));
        $regular = $this->weeklyPrice($price);
        $discount = array_sum(array_column($line['discount_amounts'] ?? [], 'amount'));
        $start = $line['period']['start'] ?? null;
        $end = $line['period']['end'] ?? null;
        $this->require(is_int($start) && is_int($end) && $end > $start
            && $end - $start === 7 * 24 * 3600
            && $subscription['customer_id'] === $this->id($invoice['customer'] ?? null)
            && $regular === $subscription['regular_amount_minor']
            && is_int($invoice['amount_paid'] ?? null)
            && $discount >= 0 && $discount < $regular);
        $paid = ($invoice['status'] ?? null) === 'paid';
        $amount = $paid ? $invoice['amount_paid'] : ($invoice['amount_due'] ?? 0);
        $payment = $paid ? $this->verifiedPayment($invoice) : ['verified' => false, 'charge_id' => null];
        $paymentState = $paid ? 'succeeded' : $this->unpaidState($invoice);
        $verified = $payment['verified'] && $amount === $regular - $discount
            && ($invoice['total'] ?? null) === $amount && ($invoice['amount_due'] ?? null) === $amount
            && ($invoice['amount_remaining'] ?? null) === 0
            && ($invoice['amount_paid_off_stripe'] ?? 0) === 0
            && ($invoice['starting_balance'] ?? 0) === 0
            && ($invoice['ending_balance'] ?? 0) === 0;

        return [
            'id' => $id, 'subscription_id' => $subscriptionId, 'customer_id' => $subscription['customer_id'],
            'order_id' => $invoice['parent']['subscription_details']['metadata']['order_id'] ?? $subscription['order_id'],
            'amount_minor' => $amount, 'currency' => 'USD',
            'period_start' => $this->iso($start), 'period_end' => $this->iso($end),
            'paid_at' => $paid && is_int($invoice['status_transitions']['paid_at'] ?? null) ? $this->iso($invoice['status_transitions']['paid_at']) : null,
            'status' => $invoice['status'] ?? 'unknown', 'paid' => $paid, 'verified_payment' => $verified,
            'payment_state' => $verified ? 'succeeded' : ($paid ? 'pending' : $paymentState),
            'charge_id' => $payment['charge_id'], 'is_test' => true,
            'billing_reason' => $invoice['billing_reason'] ?? 'unknown',
            'regular_amount_minor' => $regular, 'discount_amount_minor' => $discount,
        ];
    }

    private function unpaidState(array $invoice): string
    {
        $payments = $this->all(fn ($stripe, $args) => $stripe->invoicePayments->all(['invoice' => $invoice['id'], ...$args]));
        $default = array_values(array_filter($payments, fn ($payment) => ($payment['is_default'] ?? false) === true && ($payment['status'] ?? null) === 'open'));
        if (count($default) !== 1) {
            return 'pending';
        }
        $payment = $default[0];
        $this->testObject($payment);
        $intentId = $this->id($payment['payment']['payment_intent'] ?? null);
        if (($payment['payment']['type'] ?? null) !== 'payment_intent' || ! $intentId
            || $this->id($payment['invoice'] ?? null) !== $invoice['id']) {
            return 'pending';
        }
        $intent = $this->data($this->call(fn ($stripe) => $stripe->paymentIntents->retrieve($intentId)));
        $this->testObject($intent);
        $this->require(($intent['currency'] ?? null) === 'usd'
            && $this->id($intent['customer'] ?? null) === $this->id($invoice['customer'] ?? null));

        return match ($intent['status'] ?? null) {
            'requires_action' => 'requires_action',
            'requires_payment_method', 'canceled' => 'failed',
            default => 'pending',
        };
    }

    private function verifiedPayment(array $invoice): array
    {
        $unverified = ['verified' => false, 'charge_id' => null];
        $payments = $this->all(fn ($stripe, $args) => $stripe->invoicePayments->all(['invoice' => $invoice['id'], ...$args]));
        $paid = array_values(array_filter($payments, fn ($payment) => ($payment['status'] ?? null) === 'paid'));
        if (count($paid) !== 1) {
            return $unverified;
        }
        $payment = $paid[0];
        $this->testObject($payment);
        if (($payment['payment']['type'] ?? null) !== 'payment_intent'
            || $this->id($payment['invoice'] ?? null) !== $invoice['id']
            || ($payment['amount_paid'] ?? null) !== $invoice['amount_paid']
            || ($payment['currency'] ?? null) !== 'usd') {
            return $unverified;
        }
        $intentId = $this->id($payment['payment']['payment_intent'] ?? null);
        if (! $intentId) {
            return $unverified;
        }
        $intent = $this->data($this->call(fn ($stripe) => $stripe->paymentIntents->retrieve($intentId)));
        $this->testObject($intent);
        $chargeId = $this->id($intent['latest_charge'] ?? null);
        if (($intent['status'] ?? null) !== 'succeeded' || ($intent['amount_received'] ?? null) !== $invoice['amount_paid']
            || ($intent['currency'] ?? null) !== 'usd'
            || $this->id($intent['customer'] ?? null) !== $this->id($invoice['customer'] ?? null) || ! $chargeId) {
            return $unverified;
        }
        $charge = $this->data($this->call(fn ($stripe) => $stripe->charges->retrieve($chargeId)));
        $this->testObject($charge);
        if (($charge['paid'] ?? null) !== true || ($charge['captured'] ?? null) !== true
            || ($charge['amount_captured'] ?? null) !== $invoice['amount_paid']
            || ($charge['currency'] ?? null) !== 'usd'
            || $this->id($charge['payment_intent'] ?? null) !== $intentId
            || $this->id($charge['customer'] ?? null) !== $this->id($invoice['customer'] ?? null)) {
            return $unverified;
        }

        return ['verified' => true, 'charge_id' => $chargeId];
    }

    /** Explicit pagination prevents quietly dropping old renewals or refunds. */
    private function all(callable $request): array
    {
        $items = [];
        $cursor = null;
        do {
            $args = ['limit' => 100];
            if ($cursor !== null) {
                $args['starting_after'] = $cursor;
            }
            $page = $this->data($this->call(fn ($stripe) => $request($stripe, $args)));
            $rows = $page['data'] ?? [];
            $this->require(is_array($rows) && is_bool($page['has_more'] ?? null));
            $items = [...$items, ...$rows];
            $next = $rows === [] ? null : ($rows[array_key_last($rows)]['id'] ?? null);
            $this->require(! $page['has_more'] || ($next !== null && $next !== $cursor));
            $cursor = $next;
        } while ($page['has_more']);

        return $items;
    }

    public function refunds(string $chargeId): array
    {
        $charge = $this->data($this->call(fn ($stripe) => $stripe->charges->retrieve($chargeId)));
        $this->testObject($charge);
        $rows = $this->all(fn ($stripe, $args) => $stripe->refunds->all(['charge' => $chargeId, ...$args]));

        return array_map(function ($refund) use ($chargeId): array {
            // Refund has no livemode in this pinned schema; its retrieved charge does.
            $this->require($this->id($refund['charge'] ?? null) === $chargeId && ($refund['currency'] ?? null) === 'usd'
                && is_int($refund['amount'] ?? null) && $refund['amount'] > 0
                && in_array($refund['status'] ?? null, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true));

            return ['id' => $refund['id'], 'amount_minor' => $refund['amount'], 'status' => $refund['status'], 'currency' => 'USD'];
        }, $rows);
    }

    public function verifyEvent(string $payload, string $signature): array
    {
        $this->assertAvailable();
        $secret = (string) config('billing.stripe_webhook_secret');
        if (! str_starts_with($secret, 'whsec_')) {
            throw new DependencyNotReady('Billing / Stripe 通知验证');
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret, 300)->toArray();
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            throw new ApiProblem('VALIDATION_FAILED', 400, '无效的付款平台通知');
        }
        $this->testObject($event);
        $this->require(($event['api_version'] ?? null) === self::API_VERSION
            && is_string($event['id'] ?? null) && is_string($event['type'] ?? null)
            && is_string($event['data']['object']['id'] ?? null));

        return [
            'id' => $event['id'], 'type' => $event['type'], 'object_id' => $event['data']['object']['id'],
            'created' => $this->iso($event['created'] ?? null), 'is_test' => true,
            'related_object_id' => str_starts_with($event['type'], 'refund.') ? $this->id($event['data']['object']['charge'] ?? null) : null,
        ];
    }

    public function invoices(string $subscriptionId): array
    {
        $this->subscription($subscriptionId);
        $rows = $this->all(fn ($stripe, $args) => $stripe->invoices->all(['subscription' => $subscriptionId, ...$args]));

        return array_map(function ($invoice) use ($subscriptionId): string {
            $this->testObject($invoice);
            $this->require($this->id($invoice['parent']['subscription_details']['subscription'] ?? null) === $subscriptionId);

            return $invoice['id'];
        }, $rows);
    }
}
