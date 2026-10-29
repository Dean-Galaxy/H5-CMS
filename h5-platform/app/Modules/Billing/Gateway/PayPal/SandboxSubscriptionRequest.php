<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway\PayPal;

use App\Modules\Billing\Support\CheckoutSafety;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Internal server-order builder. Never accept a browser-provided plan, price or return origin. */
final readonly class SandboxSubscriptionRequest
{
    public string $returnUrl;

    public function __construct(public string $planId, public string $orderId, object $order)
    {
        if (preg_match('/\AP-[A-Z0-9]{24}\z/D', $planId) !== 1 || ! Str::isUuid($orderId) || $orderId !== $order->id) {
            throw new InvalidArgumentException('PAYPAL_SUBSCRIPTION_REQUEST_INVALID');
        }
        $this->returnUrl = app(CheckoutSafety::class)->expectedReturnUrl($order);
    }

    public function payload(): array
    {
        return ['plan_id' => $this->planId, 'custom_id' => $this->orderId, 'quantity' => '1',
            'application_context' => ['shipping_preference' => 'NO_SHIPPING', 'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $this->returnUrl, 'cancel_url' => $this->returnUrl]];
    }

    /** Only a server-read approve relation, for this sandbox flow, may be displayed. */
    public static function approvalUrl(array $subscription): ?string
    {
        if (($subscription['status'] ?? null) !== 'APPROVAL_PENDING' || ! is_array($subscription['links'] ?? null)) {
            return null;
        }
        $links = array_values(array_filter($subscription['links'], fn ($link) => is_array($link) && ($link['rel'] ?? null) === 'approve'));
        if (count($links) !== 1 || ($links[0]['method'] ?? null) !== 'GET' || ! is_string($links[0]['href'] ?? null)) {
            return null;
        }
        $url = $links[0]['href'];

        return preg_match('~\Ahttps://www\.sandbox\.paypal\.com/webapps/billing/subscriptions\?ba_token=BA-[A-Z0-9]{1,128}\z~D', $url) === 1 ? $url : null;
    }
}
