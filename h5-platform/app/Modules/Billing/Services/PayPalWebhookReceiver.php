<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\SandboxWebhookEnvelope;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Support\ProviderActivity;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use InvalidArgumentException;

/** Signature-verified receiver behind the exact shared PayPal callback route. */
final class PayPalWebhookReceiver
{
    public function __construct(private SandboxApi $api, private ProviderInboxStore $inbox) {}

    public function receive(string $body, array $headers, string $environment = 'sandbox'): string
    {
        if (ProviderActivity::disabled('paypal', $environment)) {
            throw new DependencyNotReady('Billing / PayPal API explicitly disabled');
        }
        $key = match ($environment) {
            'sandbox' => 'billing.paypal', 'live' => 'billing.paypal_live', default => throw new InvalidArgumentException('PAYPAL_ENVIRONMENT_INVALID')
        };
        $configuration = config($key);
        if (! is_array($configuration) || ! is_string($configuration['merchant_scope'] ?? null)
            || ! is_string($configuration['webhook_id'] ?? null)) {
            throw new DependencyNotReady('Billing / PayPal environment webhook');
        }
        try {
            $scope = new ProviderScope('paypal', $environment, $configuration['merchant_scope']);
            $envelope = SandboxWebhookEnvelope::fromRequest($body, $headers, $configuration['webhook_id'], $environment);
            $hint = $envelope->unverifiedHint();
        } catch (InvalidArgumentException) {
            throw new ApiProblem('VALIDATION_FAILED', 400, '无效的付款平台通知');
        }
        try {
            $this->api->forScope($scope)->assertWebhookRegistration($scope);
        } catch (\Throwable) {
            throw new DependencyNotReady('Billing / PayPal merchant binding and webhook registration');
        }
        if (! $this->api->forScope($scope)->verify($envelope)) {
            throw new ApiProblem('VALIDATION_FAILED', 400, '无效的付款平台通知');
        }
        if ($configuration !== config($key)) {
            throw new DependencyNotReady('Billing / PayPal environment webhook configuration changed');
        }

        return $this->inbox->receive($scope, [
            'id' => $hint['event_id'], 'type' => $hint['event_type'],
            'object_id' => $hint['resource_id'] ?? $hint['event_id'], 'created' => $hint['occurred_at'],
            // Test identity comes from the fixed environment verifier, never an incoming flag.
            'is_test' => $scope->isTest(),
        ]);
    }
}
