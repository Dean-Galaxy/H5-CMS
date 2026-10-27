<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\ProviderOperationStore;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Console\Command;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/** Explicit setup operations. Output is a fixed whitelist, never credentials or balances. */
final class PayPalPrepare extends Command
{
    protected $signature = 'billing:paypal-prepare {--create-product : Create or recover the one TEST digital sandbox product} {--check-merchant : Read and match the configured sandbox seller identity}';

    protected $description = 'Offline PayPal readiness or explicitly requested sandbox setup/checks; never charges.';

    public function handle(SandboxApi $api, ProviderOperationStore $operations): int
    {
        $result = ['configuration_valid' => false, 'missing' => [], 'network_requested' => false,
            'merchant_verified' => false, 'merchant_id' => null, 'product_id' => null, 'official_payment_verification' => 'not_performed', 'diagnostic_code' => null];
        try {
            app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
            $result['configuration_valid'] = true;
            foreach (['client_id', 'client_secret', 'merchant_id', 'merchant_scope', 'webhook_id', 'product_id'] as $key) {
                if (! is_string(config('billing.paypal.'.$key)) || config('billing.paypal.'.$key) === '') {
                    $result['missing'][] = $key;
                }
            }
            if (config('billing.paypal.enabled') !== true) {
                $result['missing'][] = 'sandbox_enabled';
            }
            if ($this->option('create-product')) {
                foreach (['client_id', 'client_secret', 'sandbox_enabled'] as $required) {
                    if (in_array($required, $result['missing'], true)) {
                        throw new RuntimeException('PAYPAL_SETUP_CONFIGURATION_REQUIRED');
                    }
                }
                // Provisioning identity is tied to the App, never a browser-supplied merchant alias.
                $scope = new ProviderScope('paypal', 'sandbox', 'setup-'.substr(hash('sha256', config('billing.paypal.client_id')), 0, 40));
                $owner = Uuid::uuid5(Uuid::NAMESPACE_URL, 'h5-paypal-product:'.$scope->merchantScope)->toString();
                $intent = $operations->reserve($scope, 'create_product', $owner, 1, SandboxApi::productRequest());
                $id = $intent->external_id;
                if ($id === null) {
                    $intent = $operations->beginAttempt($scope, $intent->id);
                    $result['network_requested'] = true;
                    $response = $api->createProduct($intent->request_key);
                    $id = $response['id'] ?? null;
                    if (! is_string($id) || preg_match('/\APROD-[A-Z0-9]{17}\z/D', $id) !== 1) {
                        throw new RuntimeException('PAYPAL_SETUP_RECONCILIATION_REQUIRED');
                    }
                    $operations->complete($scope, $intent->id, $id);
                }
                $result['network_requested'] = true;
                $product = $api->product($id);
                if (($product['id'] ?? null) !== $id || ($product['type'] ?? null) !== 'DIGITAL') {
                    throw new RuntimeException('PAYPAL_PRODUCT_RECONCILIATION_REQUIRED');
                }
                $result['product_id'] = $id;
            }
            if ($this->option('check-merchant')) {
                // No payment creation. Failure stays a diagnostic, not an instruction to request extra access.
                $result['network_requested'] = true;
                try {
                    $actual = $api->merchantIdentity();
                    $configured = config('billing.paypal.merchant_id');
                    if (is_string($configured) && $configured !== '' && $configured !== $actual) {
                        throw new RuntimeException;
                    }
                    $result['merchant_id'] = $actual;
                    $result['merchant_verified'] = $configured === $actual;
                } catch (Throwable) {
                    throw new RuntimeException('PAYPAL_MERCHANT_CHECK_UNAVAILABLE_OR_MISMATCH');
                }
            }
            if (! $this->option('create-product') && ! $this->option('check-merchant') && $result['missing']) {
                $result['diagnostic_code'] = 'PAYPAL_NOT_CONFIGURED';
            }
        } catch (Throwable $e) {
            $known = ['PAYPAL_SETUP_CONFIGURATION_REQUIRED', 'PAYPAL_SETUP_RECONCILIATION_REQUIRED',
                'PAYPAL_PRODUCT_RECONCILIATION_REQUIRED', 'PAYPAL_MERCHANT_CHECK_UNAVAILABLE_OR_MISMATCH'];
            $result['diagnostic_code'] = in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'PAYPAL_SETUP_UNAVAILABLE';
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['diagnostic_code'] === null ? self::SUCCESS : self::FAILURE;
    }
}
