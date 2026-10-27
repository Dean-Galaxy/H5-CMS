<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway\PayPal;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Untrusted input only. Building this request does NOT verify a notification. */
final readonly class SandboxWebhookEnvelope
{
    private function __construct(private string $body, private array $verificationFields, private stdClass $event) {}

    /** Header arrays are accepted only when single-valued; duplicate names are rejected. */
    public static function fromRequest(string $body, array $headers, string $configuredWebhookId): self
    {
        if (strlen($body) > 262144 || preg_match('/\A[A-Z0-9-]{1,128}\z/D', $configuredWebhookId) !== 1) {
            throw new InvalidArgumentException('PAYPAL_WEBHOOK_INPUT_INVALID');
        }
        $names = [
            'paypal-auth-algo' => 'auth_algo',
            'paypal-cert-url' => 'cert_url',
            'paypal-transmission-id' => 'transmission_id',
            'paypal-transmission-sig' => 'transmission_sig',
            'paypal-transmission-time' => 'transmission_time',
        ];
        $fields = [];
        foreach ($headers as $name => $value) {
            $key = $names[strtolower((string) $name)] ?? null;
            if ($key === null) {
                continue;
            }
            if (is_array($value)) {
                $value = count($value) === 1 ? array_values($value)[0] : null;
            }
            if (isset($fields[$key]) || ! is_string($value) || $value === '' || strlen($value) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new InvalidArgumentException('PAYPAL_WEBHOOK_HEADERS_INVALID');
            }
            $fields[$key] = $value;
        }
        if (count($fields) !== 5 || $fields['auth_algo'] !== 'SHA256withRSA'
            || preg_match('/\A[A-Za-z0-9-]{1,128}\z/D', $fields['transmission_id']) !== 1
            || preg_match('~\Ahttps://(?:api|api-m)\.sandbox\.paypal\.com/v1/notifications/certs/CERT-[A-Za-z0-9-]+\z~D', $fields['cert_url']) !== 1
            || base64_decode($fields['transmission_sig'], true) === false
            || base64_encode(base64_decode($fields['transmission_sig'], true)) !== $fields['transmission_sig']) {
            throw new InvalidArgumentException('PAYPAL_WEBHOOK_HEADERS_INVALID');
        }
        self::assertTimestamp($fields['transmission_time']);
        try {
            $event = json_decode($body, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new InvalidArgumentException('PAYPAL_WEBHOOK_JSON_INVALID');
        }
        if (! $event instanceof stdClass || ! self::identifier($event->id ?? null)
            || ! is_string($event->event_type ?? null)
            || preg_match('/\A[A-Z][A-Z0-9_.]{1,127}\z/D', $event->event_type) !== 1
            || ! ($event->resource ?? null) instanceof stdClass) {
            throw new InvalidArgumentException('PAYPAL_WEBHOOK_EVENT_INVALID');
        }
        self::assertTimestamp($event->create_time ?? null);
        $fields['webhook_id'] = $configuredWebhookId;

        return new self($body, $fields, $event);
    }

    /** Send only to the fixed sandbox verification endpoint. Preserve original JSON bytes. */
    public function verificationRequestBody(): string
    {
        $prefix = json_encode($this->verificationFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return substr($prefix, 0, -1).',"webhook_event":'.$this->body.'}';
    }

    /** A scheduling hint, never a payment or an entitlement. Must be used only after verification. */
    public function unverifiedHint(): array
    {
        $type = $this->event->event_type;
        $kind = self::classify($type);
        $id = $this->event->resource->id ?? null;
        if ($kind !== 'ignore' && ! self::identifier($id)) {
            throw new InvalidArgumentException('PAYPAL_WEBHOOK_RESOURCE_INVALID');
        }

        return ['event_id' => $this->event->id, 'event_type' => $type, 'kind' => $kind,
            'resource_id' => self::identifier($id) ? $id : null, 'occurred_at' => $this->event->create_time];
    }

    public static function classify(string $type): string
    {
        return match ($type) {
            'PAYMENT.SALE.COMPLETED' => 'payment_lookup',
            'PAYMENT.SALE.REFUNDED' => 'refund_lookup',
            'PAYMENT.SALE.REVERSED' => 'reversal_review',
            'BILLING.SUBSCRIPTION.CREATED', 'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.UPDATED', 'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'subscription_lookup',
            default => 'ignore',
        };
    }

    public function belongsToWebhook(string $configuredWebhookId): bool
    {
        return hash_equals($this->verificationFields['webhook_id'], $configuredWebhookId);
    }

    public function __debugInfo(): array
    {
        return ['verification' => 'not_performed'];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9-]{1,128}\z/D', $value) === 1;
    }

    private static function assertTimestamp(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?Z\z/D', $value) !== 1) {
            throw new InvalidArgumentException('PAYPAL_WEBHOOK_TIME_INVALID');
        }
        // Do not reject an authentic delayed delivery based on age; facts are re-read later.
        $parsed = DateTimeImmutable::createFromFormat(str_contains($value, '.') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s\Z', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException('PAYPAL_WEBHOOK_TIME_INVALID');
        }
    }
}
