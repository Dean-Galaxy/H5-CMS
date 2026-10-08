<?php

namespace App\Modules\Billing\Support;

use App\Modules\Identity\Services\SafeReturn;

/** One policy for new, reconciled and already-persisted Checkout links. */
final class CheckoutSafety
{
    public function expectedReturnUrl(object $order): string
    {
        $origin = app(BillingRuntimePolicy::class)->checkoutOrigin();
        $return = parse_url(app(SafeReturn::class)->path($order->return_to ?? null));
        parse_str($return['query'] ?? '', $query);
        // The caller's query never supplies the authoritative order reference.
        $query['order_id'] = (string) $order->id;

        return $origin.$return['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            .(isset($return['fragment']) ? '#'.$return['fragment'] : '');
    }

    public function requestIsSafe(object $order, mixed $request): bool
    {
        try {
            if (is_string($request)) {
                $request = json_decode($request, true, flags: JSON_THROW_ON_ERROR);
            }
            if (! is_array($request) || ! is_array($request['params'] ?? null)) {
                return false;
            }
            $params = $request['params'];
            $returnUrl = $this->expectedReturnUrl($order);
            $metadata = [
                'order_id' => (string) $order->id,
                'viewer_id' => (string) $order->viewer_id,
                'subscription_id' => (string) $order->subscription_id,
                'price_version' => (string) $order->price_version,
            ];
            foreach ($metadata as $key => $expected) {
                if ($expected === '' || ($params['metadata'][$key] ?? null) !== $expected
                    || ($params['subscription_data']['metadata'][$key] ?? null) !== $expected) {
                    return false;
                }
            }
            $line = $params['line_items'][0] ?? null;
            $regular = (int) $order->regular_amount_minor;
            $amount = (int) $order->amount_minor;
            $discounted = $amount < $regular;
            $discounts = $params['discounts'] ?? [];

            return ($order->currency ?? null) === 'USD' && (bool) ($order->is_test ?? false)
                && (int) $order->price_version > 0 && $amount > 0 && $amount <= $regular
                && (bool) $order->discount_applied === $discounted
                && ($request['idempotency_key'] ?? null) === 'h5-checkout-'.$order->id
                && is_string($request['customer_id'] ?? null) && $request['customer_id'] !== ''
                && ($params['customer'] ?? null) === $request['customer_id']
                && ($params['mode'] ?? null) === 'subscription'
                && ($params['client_reference_id'] ?? null) === (string) $order->id
                && ($params['success_url'] ?? null) === $returnUrl
                && ($params['cancel_url'] ?? null) === $returnUrl
                && is_array($params['line_items'] ?? null) && count($params['line_items']) === 1
                && is_array($line) && ($line['quantity'] ?? null) === 1
                && ($line['price_data']['unit_amount'] ?? null) === $regular
                && ($line['price_data']['currency'] ?? null) === 'usd'
                && ($line['price_data']['recurring'] ?? null) === ['interval' => 'week', 'interval_count' => 1]
                && ($line['price_data']['product_data']['metadata']['price_version'] ?? null) === (string) $order->price_version
                && ($params['payment_method_types'] ?? null) === ['card']
                && ($params['adaptive_pricing']['enabled'] ?? null) === false
                && ($params['automatic_tax']['enabled'] ?? null) === false
                // Legacy false is readable; the gateway separately prohibits replaying
                // a coupon-plus-flag request as a creating POST (F05).
                && (! array_key_exists('allow_promotion_codes', $params) || $params['allow_promotion_codes'] === false)
                && is_array($discounts)
                && ($discounted
                    ? (count($discounts) === 1 && is_string($discounts[0]['coupon'] ?? null) && $discounts[0]['coupon'] !== '')
                    : $discounts === []);
        } catch (\Throwable) {
            // Invalid historical data or changed runtime configuration hides the link;
            // it never changes the immutable request or releases its placeholder.
            return false;
        }
    }

    public function checkoutUrlIsSafe(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }
        $decoded = $url;
        for ($i = 0; $i < 4; $i++) {
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
                return false;
            }
            $decoded = rawurldecode($decoded);
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)
            || ! preg_match('~\Ahttps://checkout\.stripe\.com(?::443)?(?:/|[?#]|$)~iD', $url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === 'checkout.stripe.com'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }
}
