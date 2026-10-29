<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateway\PayPal\PayPalAmount;
use App\Modules\Billing\Gateway\PayPal\SandboxWebhookEnvelope;
use App\Modules\Billing\Gateway\PayPal\SandboxWeeklyPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure offline protocol cases: no Laravel boot, database, secrets or HTTP. */
final class PayPalProtocolTest extends TestCase
{
    public function test_usd_conversion_never_uses_floating_point_or_rounds_an_invalid_amount(): void
    {
        foreach (['0' => 0, '0.01' => 1, '12' => 1200, '12.3' => 1230, '12.34' => 1234, '999999.99' => 99999999] as $input => $expected) {
            self::assertSame($expected, PayPalAmount::minor((string) $input, 'USD'));
            self::assertSame($expected, PayPalAmount::minor(PayPalAmount::decimal($expected), 'USD'));
        }
    }

    public static function invalidMoney(): array
    {
        return array_map(fn ($value) => [$value, 'USD'], [null, 12, 12.34, true, [], '', ' 12.00', '12.00 ', '+12', '-0.01', '1e2', '01.00', '.5', '1.', '1.001', '1000000', 'NaN', "12.00\n"])
            + [30 => ['12.00', 'usd'], 31 => ['12.00', 'EUR'], 32 => ['12.00', null]];
    }

    #[DataProvider('invalidMoney')]
    public function test_invalid_money_is_rejected_without_coercion(mixed $amount, mixed $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayPalAmount::minor($amount, $currency);
    }

    public function test_first_week_is_one_paid_week_followed_by_original_regular_weekly_price(): void
    {
        $plan = new SandboxWeeklyPlan(2, 1234, 321, true);
        $payload = $plan->createRequest('PROD-ABCDEFGHIJKLMNOPQ');
        self::assertStringStartsWith('TEST ', $payload['name']);
        self::assertSame(false, $payload['quantity_supported']);
        self::assertSame(['auto_bill_outstanding' => false, 'payment_failure_threshold' => 1], $payload['payment_preferences']);
        self::assertCount(2, $payload['billing_cycles']);
        $trial = $payload['billing_cycles'][0];
        $regular = $payload['billing_cycles'][1];
        self::assertSame(['interval_unit' => 'WEEK', 'interval_count' => 1], $trial['frequency']);
        self::assertSame($trial['frequency'], $regular['frequency']);
        self::assertSame(['TRIAL', 1, 1, '3.21'], [$trial['tenure_type'], $trial['sequence'], $trial['total_cycles'], $trial['pricing_scheme']['fixed_price']['value']]);
        self::assertSame(['REGULAR', 2, 0, '12.34'], [$regular['tenure_type'], $regular['sequence'], $regular['total_cycles'], $regular['pricing_scheme']['fixed_price']['value']]);
        self::assertSame('USD', $regular['pricing_scheme']['fixed_price']['currency_code']);
        self::assertArrayNotHasKey('setup_fee', $payload['payment_preferences']);
        self::assertArrayNotHasKey('taxes', $payload);
    }

    public function test_returning_customer_has_no_trial_and_changed_prices_have_new_fingerprints(): void
    {
        $old = new SandboxWeeklyPlan(1, 1200, 300, false);
        $oldPayload = $old->createRequest('PROD-ABCDEFGHIJKLMNOPQ');
        $changed = new SandboxWeeklyPlan(2, 1500, 400, false);
        self::assertCount(1, $oldPayload['billing_cycles']);
        self::assertSame('REGULAR', $oldPayload['billing_cycles'][0]['tenure_type']);
        self::assertSame(1, $oldPayload['billing_cycles'][0]['sequence']);
        self::assertSame('12.00', $oldPayload['billing_cycles'][0]['pricing_scheme']['fixed_price']['value']);
        self::assertNotSame($old->fingerprint('PROD-ABCDEFGHIJKLMNOPQ'), $changed->fingerprint('PROD-ABCDEFGHIJKLMNOPQ'));
        self::assertSame($oldPayload, $old->createRequest('PROD-ABCDEFGHIJKLMNOPQ'));
        self::assertNotSame($old->fingerprint('PROD-ABCDEFGHIJKLMNOPQ'), $old->fingerprint('PROD-ABCDEFGHIJKLMNOPR'));
    }

    public static function invalidPlan(): array
    {
        return [[0, 1200, 300], [1, 0, 0], [1, 1200, 0], [1, 1200, 1201], [1, 100000000, 300], [1, -10, -10]];
    }

    #[DataProvider('invalidPlan')]
    public function test_invalid_plan_never_becomes_a_free_or_overpriced_trial(int $version, int $regular, int $first): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SandboxWeeklyPlan($version, $regular, $first, true);
    }

    public function test_verification_embeds_original_json_bytes_and_does_not_claim_verified_or_paid(): void
    {
        $raw = " \n".$this->event('PAYMENT.SALE.COMPLETED', ['is_test' => false, 'paid' => true, 'amount' => ['total' => '999.00']])."\t ";
        $envelope = SandboxWebhookEnvelope::fromRequest($raw, $this->headers(), 'LOCAL-TEST-WEBHOOK');
        $body = $envelope->verificationRequestBody();
        self::assertStringEndsWith(',"webhook_event":'.$raw.'}', $body);
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('LOCAL-TEST-WEBHOOK', $decoded['webhook_id']);
        self::assertSame('event-local', $decoded['webhook_event']['id']);
        self::assertSame(['event_id', 'event_type', 'kind', 'resource_id', 'occurred_at'], array_keys($envelope->unverifiedHint()));
        self::assertSame('payment_lookup', $envelope->unverifiedHint()['kind']);
        self::assertSame(['verification' => 'not_performed'], $envelope->__debugInfo());
    }

    public static function eventKinds(): array
    {
        return [
            ['PAYMENT.SALE.COMPLETED', 'payment_lookup'], ['PAYMENT.SALE.REFUNDED', 'refund_lookup'],
            ['PAYMENT.SALE.REVERSED', 'reversal_review'], ['BILLING.SUBSCRIPTION.ACTIVATED', 'subscription_lookup'],
            ['BILLING.SUBSCRIPTION.CANCELLED', 'subscription_lookup'], ['BILLING.SUBSCRIPTION.SUSPENDED', 'subscription_lookup'],
            ['BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'subscription_lookup'], ['PAYMENT.CAPTURE.COMPLETED', 'ignore'],
            ['CATALOG.PRODUCT.CREATED', 'ignore'],
        ];
    }

    #[DataProvider('eventKinds')]
    public function test_notifications_only_schedule_rechecks_and_orders_capture_does_not_fulfil_subscriptions(string $event, string $kind): void
    {
        $envelope = SandboxWebhookEnvelope::fromRequest($this->event($event), $this->headers(), 'LOCAL-TEST-WEBHOOK');
        self::assertSame($kind, $envelope->unverifiedHint()['kind']);
    }

    public static function invalidHeader(): array
    {
        return [
            ['paypal-cert-url', 'http://api.sandbox.paypal.com/v1/notifications/certs/CERT-local'],
            ['paypal-cert-url', 'https://api.paypal.com/v1/notifications/certs/CERT-local'],
            ['paypal-cert-url', 'https://api.sandbox.paypal.com.attacker.test/v1/notifications/certs/CERT-local'],
            ['paypal-cert-url', 'https://user@api.sandbox.paypal.com/v1/notifications/certs/CERT-local'],
            ['paypal-cert-url', 'https://api.sandbox.paypal.com:8443/v1/notifications/certs/CERT-local'],
            ['paypal-cert-url', 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-local?url=localhost'],
            ['paypal-cert-url', 'https://api.sandbox.paypal.com/v1/notifications/certs/../CERT-local'],
            ['paypal-cert-url', 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-%2f-local'],
            ['paypal-auth-algo', 'none'], ['paypal-auth-algo', "SHA256withRSA\r\nInjected:yes"],
            ['paypal-transmission-sig', '!!!'], ['paypal-transmission-sig', 'YWJjZ'],
            ['paypal-transmission-time', '2026-02-30T12:00:00Z'],
            ['paypal-transmission-time', '2026-10-26T25:00:00Z'],
            ['paypal-transmission-time', 'next monday'],
            ['paypal-transmission-id', ['one', 'two']], ['paypal-transmission-id', 12],
        ];
    }

    #[DataProvider('invalidHeader')]
    public function test_malformed_or_unsafe_headers_fail_before_verification(string $name, mixed $value): void
    {
        $headers = $this->headers();
        $headers[$name] = $value;
        $this->expectException(InvalidArgumentException::class);
        SandboxWebhookEnvelope::fromRequest($this->event('PAYMENT.SALE.COMPLETED'), $headers, 'LOCAL-TEST-WEBHOOK');
    }

    public function test_duplicate_case_variant_headers_are_rejected(): void
    {
        $headers = $this->headers();
        $headers['PayPal-Transmission-Id'] = 'second-value';
        $this->expectException(InvalidArgumentException::class);
        SandboxWebhookEnvelope::fromRequest($this->event('PAYMENT.SALE.COMPLETED'), $headers, 'LOCAL-TEST-WEBHOOK');
    }

    public function test_missing_header_is_rejected(): void
    {
        $headers = $this->headers();
        unset($headers['paypal-auth-algo']);
        $this->expectException(InvalidArgumentException::class);
        SandboxWebhookEnvelope::fromRequest($this->event('PAYMENT.SALE.COMPLETED'), $headers, 'LOCAL-TEST-WEBHOOK');
    }

    public static function invalidBodies(): array
    {
        return [['[]'], ['null'], ['{}'], ['{"id":'], [str_repeat(' ', 262145)], ['{"id":"event","event_type":"PAYMENT.SALE.COMPLETED","create_time":"2026-10-26T00:00:00Z","resource":[]}']];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_json_or_event_shape_is_rejected(string $body): void
    {
        $this->expectException(InvalidArgumentException::class);
        SandboxWebhookEnvelope::fromRequest($body, $this->headers(), 'LOCAL-TEST-WEBHOOK');
    }

    public function test_old_delivery_and_fractional_seconds_are_retained_for_fact_reconciliation(): void
    {
        $headers = $this->headers();
        $headers['paypal-transmission-time'] = ['2020-01-01T00:00:00.123Z'];
        $envelope = SandboxWebhookEnvelope::fromRequest($this->event('PAYMENT.SALE.COMPLETED'), $headers, 'LOCAL-TEST-WEBHOOK');
        self::assertStringContainsString('2020-01-01T00:00:00.123Z', $envelope->verificationRequestBody());
    }

    private function headers(): array
    {
        return [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-local-fixture',
            'paypal-transmission-id' => 'local-transmission',
            'paypal-transmission-sig' => base64_encode('local fixture - not an official signature'),
            'paypal-transmission-time' => '2026-10-26T12:00:00Z',
        ];
    }

    private function event(string $type, array $resource = []): string
    {
        return json_encode(['id' => 'event-local', 'event_type' => $type, 'create_time' => '2026-10-26T12:00:00Z',
            'resource' => ['id' => 'resource-local'] + $resource], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
