<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway\PayPal;

use App\Modules\Billing\Gateway\ProviderScope;
use App\Support\DependencyNotReady;
use App\Support\RuntimeEnvironmentGuard;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Sandbox protocol transport used by the PayPal-native runtime. */
final class SandboxApi
{
    public const ORIGIN = 'https://api-m.sandbox.paypal.com';

    private ?string $token = null;

    private int $tokenUntil = 0;

    private ?string $configurationFingerprint = null;

    public function __construct(private ?ClientInterface $testingTransport = null) {}

    public function plan(string $id): array
    {
        return $this->call('GET', '/v1/billing/plans/'.$this->identifier($id, 'P-'));
    }

    public function createPlan(SandboxWeeklyPlan $plan, string $productId, string $persistedRequestId): array
    {
        return $this->call('POST', '/v1/billing/plans', json_encode($plan->createRequest($productId), JSON_THROW_ON_ERROR), $persistedRequestId);
    }

    /** Scope and credential generation must stay fixed throughout a multi-call operation. */
    public function configurationStamp(ProviderScope $scope): string
    {
        $this->assertAvailable();
        if ($scope->provider !== 'paypal' || $scope->environment !== 'sandbox'
            || $scope->merchantScope !== config('billing.paypal.merchant_scope')) {
            throw new DependencyNotReady('Billing / PayPal persisted merchant scope');
        }

        return $this->configurationFingerprint;
    }

    public function createSubscription(SandboxSubscriptionRequest $request, string $persistedRequestId): array
    {
        return $this->call('POST', '/v1/billing/subscriptions', json_encode($request->payload(), JSON_THROW_ON_ERROR), $persistedRequestId);
    }

    public function subscription(string $id): array
    {
        return $this->call('GET', '/v1/billing/subscriptions/'.$this->identifier($id, 'I-'));
    }

    public function product(string $id): array
    {
        return $this->call('GET', '/v1/catalogs/products/'.$this->identifier($id, 'PROD-'), credentialsOnly: true);
    }

    public function createProduct(string $requestId): array
    {
        return $this->call('POST', '/v1/catalogs/products', json_encode(self::productRequest(), JSON_THROW_ON_ERROR), $requestId, credentialsOnly: true);
    }

    public static function productRequest(): array
    {
        return ['name' => 'TEST H5 weekly membership', 'type' => 'DIGITAL', 'description' => 'Sandbox-only H5 membership integration test'];
    }

    /** Read the account identity, never log or persist returned balances. */
    public function assertMerchant(ProviderScope $scope): void
    {
        $stamp = $this->configurationStamp($scope);
        $accountId = $this->merchantIdentity();
        if ($accountId !== config('billing.paypal.merchant_id')
            || ! hash_equals($stamp, $this->configurationStamp($scope))) {
            throw new RuntimeException('PAYPAL_MERCHANT_MISMATCH');
        }
    }

    /** Setup read requires only explicitly enabled sandbox Client ID/Secret, not a webhook. */
    public function merchantIdentity(): string
    {
        $this->assertAvailable(credentialsOnly: true);
        $stamp = $this->configurationFingerprint;
        $reply = $this->call('GET', '/v1/reporting/balances', credentialsOnly: true);
        $this->assertAvailable(credentialsOnly: true);
        $id = $reply['account_id'] ?? null;
        if (! is_string($id) || preg_match('/\A[A-Z0-9]{1,64}\z/D', $id) !== 1 || $stamp !== $this->configurationFingerprint) {
            throw new RuntimeException('PAYPAL_MERCHANT_IDENTITY_UNKNOWN');
        }

        return $id;
    }

    public function transactions(string $id, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        if ($until <= $from || $until->getTimestamp() - $from->getTimestamp() > 31 * 86400) {
            throw new InvalidArgumentException('PAYPAL_TRANSACTION_WINDOW_INVALID');
        }
        $utc = new \DateTimeZone('UTC');
        $query = http_build_query(['start_time' => $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $until->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')], '', '&', PHP_QUERY_RFC3986);

        return $this->call('GET', '/v1/billing/subscriptions/'.$this->identifier($id, 'I-').'/transactions?'.$query);
    }

    public function sale(string $id): array
    {
        return $this->call('GET', '/v1/payments/sale/'.$this->identifier($id, ''));
    }

    public function refund(string $id): array
    {
        return $this->call('GET', '/v1/payments/refund/'.$this->identifier($id, ''));
    }

    /** Hard cancellation only for expired approval/paid periods; never a resume substitute. */
    public function cancel(string $id): void
    {
        $this->call('POST', '/v1/billing/subscriptions/'.$this->identifier($id, 'I-').'/cancel', '{"reason":"Stop future renewal after local cancellation or checkout expiry"}');
    }

    /** Does not assert renewal stopped: the caller must re-read and confirm SUSPENDED. */
    public function suspend(string $id): void
    {
        // This endpoint has no documented PayPal-Request-Id parameter. Re-read before retry.
        $this->call('POST', '/v1/billing/subscriptions/'.$this->identifier($id, 'I-').'/suspend', '{"reason":"Customer requested stopping future renewal"}');
    }

    /** Deliberately no activate(), capture() or refund() in this first-stage transport. */
    public function verify(SandboxWebhookEnvelope $envelope): bool
    {
        $this->assertAvailable();
        if (! $envelope->belongsToWebhook(config('billing.paypal.webhook_id'))) {
            throw new InvalidArgumentException('PAYPAL_WEBHOOK_SCOPE_MISMATCH');
        }
        $reply = $this->call('POST', '/v1/notifications/verify-webhook-signature', $envelope->verificationRequestBody());
        if (! in_array($reply['verification_status'] ?? null, ['SUCCESS', 'FAILURE'], true)) {
            throw new RuntimeException('PAYPAL_VERIFICATION_UNKNOWN');
        }

        return $reply['verification_status'] === 'SUCCESS';
    }

    private function call(string $method, string $path, ?string $body = null, ?string $requestId = null, bool $credentialsOnly = false): array
    {
        $this->assertAvailable($credentialsOnly);
        if ($requestId !== null && preg_match('/\A[A-Za-z0-9-]{1,38}\z/D', $requestId) !== 1) {
            throw new InvalidArgumentException('PAYPAL_REQUEST_ID_INVALID');
        }
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer '.$this->accessToken()];
        if ($requestId !== null) {
            $headers['PayPal-Request-Id'] = $requestId;
            $headers['Prefer'] = 'return=representation';
        }

        return $this->send($method, $path, ['headers' => $headers, 'body' => $body]);
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenUntil) {
            return $this->token;
        }
        $data = $this->send('POST', '/v1/oauth2/token', [
            'auth' => [config('billing.paypal.client_id'), config('billing.paypal.client_secret')],
            'headers' => ['Accept' => 'application/json'], 'form_params' => ['grant_type' => 'client_credentials'],
        ]);
        if (! is_string($data['access_token'] ?? null) || preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $data['access_token']) !== 1
            || ($data['token_type'] ?? null) !== 'Bearer' || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 60) {
            throw new RuntimeException('PAYPAL_AUTHENTICATION_UNKNOWN');
        }
        $this->token = $data['access_token'];
        $this->tokenUntil = time() + min($data['expires_in'] - 60, 300);

        return $this->token;
    }

    private function send(string $method, string $path, array $options): array
    {
        try {
            $client = $this->testingTransport ?? new Client;
            $response = $client->request($method, self::ORIGIN.$path, $options + [
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => 5, 'timeout' => 15, 'proxy' => '',
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('PAYPAL_RECONCILIATION_REQUIRED');
            }
            if ($status === 204) {
                return [];
            }
            $body = $response->getBody()->read(1048577);
            if (strlen($body) > 1048576) {
                throw new RuntimeException('PAYPAL_RESPONSE_INVALID');
            }
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass) {
                throw new RuntimeException('PAYPAL_RESPONSE_INVALID');
            }

            return json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            // Do not chain vendor exceptions: requests may contain authorization or webhook bodies.
            throw new RuntimeException('PAYPAL_RECONCILIATION_REQUIRED');
        }
    }

    private function assertAvailable(bool $credentialsOnly = false): void
    {
        try {
            app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
            $environment = app()->environment();
            if (! in_array($environment, ['local', 'testing', 'staging'], true)
                || config('billing.paypal.enabled') !== true
                || ($this->testingTransport !== null && $environment !== 'testing')
                || ($this->testingTransport === null && $environment === 'testing')) {
                throw new RuntimeException;
            }
            if ($environment === 'staging') {
                if (config('h5.controlled_staging') !== true) {
                    throw new RuntimeException;
                }
                app(RuntimeEnvironmentGuard::class)->controlledStagingOrigin();
            }
            $configuration = [];
            foreach (['client_id', 'client_secret', 'webhook_id', 'merchant_id', 'merchant_scope'] as $key) {
                $value = config('billing.paypal.'.$key);
                if ((! $credentialsOnly || in_array($key, ['client_id', 'client_secret'], true))
                    && (! is_string($value) || preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $value) !== 1)) {
                    throw new RuntimeException;
                }
                $configuration[$key] = $value;
            }
            $fingerprint = hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR));
            if ($this->configurationFingerprint !== $fingerprint) {
                $this->token = null;
                $this->tokenUntil = 0;
                $this->configurationFingerprint = $fingerprint;
            }
        } catch (Throwable) {
            $this->token = null;
            $this->tokenUntil = 0;
            throw new DependencyNotReady('Billing / PayPal sandbox configuration');
        }
    }

    private function identifier(string $value, string $prefix): string
    {
        if (preg_match('/\A'.preg_quote($prefix, '/').'[A-Z0-9]{1,126}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('PAYPAL_RESOURCE_ID_INVALID');
        }

        return $value;
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'paypal', 'environment' => 'sandbox', 'official_verification' => 'not_performed'];
    }
}
