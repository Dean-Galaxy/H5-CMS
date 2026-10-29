<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\WeeklyCycleEvidence;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Operator evidence is a trust bootstrap, never an API assertion about merchant identity. */
final class PayPalMerchantBinding
{
    public function __construct(private SandboxApi $api) {}

    public function offlineContext(): array
    {
        app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        $client = config('billing.paypal.client_id');

        return ['environment' => 'sandbox', 'merchant_scope' => config('billing.paypal.merchant_scope') ?: null,
            'merchant_id' => config('billing.paypal.merchant_id') ?: null,
            'client_id_sha256' => is_string($client) && $client !== '' ? hash('sha256', $client) : null];
    }

    public function context(): array
    {
        $fingerprint = $this->api->appFingerprint();
        $scope = new ProviderScope('paypal', 'sandbox', config('billing.paypal.merchant_scope', ''));
        $merchant = config('billing.paypal.merchant_id');
        $this->require(is_string($merchant) && preg_match('/\A[A-Z0-9]{1,64}\z/D', $merchant) === 1);

        return ['environment' => 'sandbox', 'merchant_scope' => $scope->merchantScope,
            'merchant_id' => $merchant, 'client_id_sha256' => $fingerprint];
    }

    public function assertBound(ProviderScope $scope): object
    {
        $context = $this->context();
        $this->require($scope->provider === 'paypal' && $scope->environment === 'sandbox' && $scope->merchantScope === $context['merchant_scope']);
        $binding = DB::table('billing_paypal_merchant_bindings')->where('merchant_scope', $scope->merchantScope)->first();
        $this->require($binding !== null && $binding->binding_source === 'operator_dashboard');
        foreach ($context as $field => $value) {
            $this->require(hash_equals((string) $binding->$field, $value));
        }

        return $binding;
    }

    public function bindReceipt(string $path): object
    {
        $context = $this->context();
        $raw = $this->readReceipt($path);
        $receipt = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $expected = array_keys($context);
        $fields = ['binding_source', 'verified_at', 'evidence_ref', 'merchant_app_business_link_confirmed', 'business_merchant_id_confirmed', 'saved_client_id_matches_app'];
        $this->require(is_array($receipt) && ! array_diff(array_keys($receipt), array_merge($expected, $fields)));
        foreach ($context as $key => $value) {
            $this->require(($receipt[$key] ?? null) === $value);
        }
        $this->require(($receipt['binding_source'] ?? null) === 'operator_dashboard');
        foreach (['merchant_app_business_link_confirmed', 'business_merchant_id_confirmed', 'saved_client_id_matches_app'] as $key) {
            $this->require(($receipt[$key] ?? null) === true);
        }
        $ref = $receipt['evidence_ref'] ?? null;
        $this->require(is_string($ref) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $ref) === 1);
        $time = WeeklyCycleEvidence::time($receipt['verified_at'] ?? null);
        $this->require($time->lte(now()) && $time->gte(now()->subDays(7)));
        // Receipt alone cannot pass: authenticate the exact saved client/secret with PayPal.
        $this->api->authenticateApp(fresh: true);
        $this->require($context === $this->context());

        return DB::transaction(function () use ($context, $ref, $time, $raw) {
            DB::table('billing_paypal_merchant_bindings')->insertOrIgnore($context + [
                'binding_source' => 'operator_dashboard', 'evidence_ref' => $ref,
                'receipt_sha256' => hash('sha256', $raw), 'verified_at' => $time, 'created_at' => now(),
            ]);
            $binding = DB::table('billing_paypal_merchant_bindings')->where('merchant_scope', $context['merchant_scope'])->lockForUpdate()->first();
            $this->require($binding !== null && $binding->receipt_sha256 === hash('sha256', $raw));
            foreach ($context as $field => $value) {
                $this->require($value === $binding->$field);
            }

            return $binding;
        });
    }

    private function readReceipt(string $path): string
    {
        $root = realpath(base_path('../.runtime'));
        $real = realpath($path);
        $this->require(is_string($root) && is_string($real) && str_starts_with($real, $root.DIRECTORY_SEPARATOR));
        $this->require(str_starts_with($path, DIRECTORY_SEPARATOR));
        // Reject symlinks in the supplied path, including intermediate directories.
        $walk = $path;
        while ($walk !== dirname($walk)) {
            $this->require(! is_link($walk));
            $walk = dirname($walk);
        }
        $handle = @fopen($path, 'rb');
        $this->require($handle !== false);
        try {
            $stat = fstat($handle);
            $this->require(($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 0777) === 0600
                && $stat['uid'] === posix_geteuid() && $stat['size'] > 0 && $stat['size'] <= 4096);
            $raw = stream_get_contents($handle, 4097);
            $this->require(is_string($raw) && strlen($raw) <= 4096);

            return $raw;
        } finally {
            fclose($handle);
        }
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('PAYPAL_MERCHANT_BINDING_REQUIRED_OR_MISMATCH');
        }
    }
}
