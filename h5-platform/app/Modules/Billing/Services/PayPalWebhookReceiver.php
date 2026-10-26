<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\SandboxWebhookEnvelope;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use InvalidArgumentException;

/** Internal receiver, intentionally not publicly routed before 00's shared wiring. */
final class PayPalWebhookReceiver
{
    public function __construct(private SandboxApi $api, private ProviderInboxStore $inbox) {}

    public function receive(string $body, array $headers): string
    {
        $configuration = config('billing.paypal');
        if (! is_array($configuration) || ! is_string($configuration['merchant_scope'] ?? null)
            || ! is_string($configuration['webhook_id'] ?? null)) {
            throw new DependencyNotReady('Billing / PayPal sandbox webhook');
        }
        try {
            $scope = new ProviderScope('paypal', 'sandbox', $configuration['merchant_scope']);
            $envelope = SandboxWebhookEnvelope::fromRequest($body, $headers, $configuration['webhook_id']);
            $hint = $envelope->unverifiedHint();
        } catch (InvalidArgumentException) {
            throw new ApiProblem('VALIDATION_FAILED', 400, '无效的付款平台通知');
        }
        if (! $this->api->verify($envelope)) {
            throw new ApiProblem('VALIDATION_FAILED', 400, '无效的付款平台通知');
        }
        if ($configuration !== config('billing.paypal')) {
            throw new DependencyNotReady('Billing / PayPal sandbox webhook configuration changed');
        }

        return $this->inbox->receive($scope, [
            'id' => $hint['event_id'], 'type' => $hint['event_type'],
            'object_id' => $hint['resource_id'] ?? $hint['event_id'], 'created' => $hint['occurred_at'],
            // Test identity comes from the fixed sandbox verifier, never an incoming flag.
            'is_test' => true,
        ]);
    }
}
