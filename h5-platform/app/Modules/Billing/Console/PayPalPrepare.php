<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Gateway\PayPal\LiveApi;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\PayPalMerchantBinding;
use App\Modules\Billing\Services\PayPalRuntime;
use App\Modules\Billing\Services\ProviderOperationStore;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Console\Command;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/** Explicit setup operations. Output is a fixed whitelist, never credentials or balances. */
final class PayPalPrepare extends Command
{
    protected $signature = 'billing:paypal-prepare {--environment=sandbox : Explicit sandbox or live scope} {--create-product : Explicitly create or recover a digital product in the selected environment} {--check-merchant : Check operator binding and authenticate the App} {--binding-context : Offline receipt context without credentials} {--bind-merchant= : Private operator Dashboard receipt file} {--check-webhook : Read actual App webhook and match configured URL} {--check-readiness : Read-only purchase admission check; never enables purchasing}';

    protected $description = 'Offline PayPal readiness or explicitly requested environment-scoped setup/checks; never charges.';

    public function handle(SandboxApi $api, ProviderOperationStore $operations): int
    {
        $result = ['configuration_valid' => false, 'missing' => [], 'network_requested' => false,
            'purchase_readiness_valid' => false, 'product_valid' => false, 'merchant_verified' => false, 'merchant_binding_valid' => false, 'binding_source' => null,
            'api_merchant_identity_verified' => false, 'app_authenticated' => false, 'webhook_registration_valid' => false, 'merchant_id' => null, 'product_id' => null, 'official_payment_verification' => 'not_performed', 'diagnostic_code' => null];
        try {
            $environment = $this->option('environment');
            if (! in_array($environment, ['sandbox', 'live'], true)) {
                throw new RuntimeException('PAYPAL_ENVIRONMENT_INVALID');
            }
            $api = $environment === 'live' ? app(LiveApi::class) : $api;
            $configKey = $api::CONFIG;
            $enabledKey = $environment === 'live' ? 'live_enabled' : 'sandbox_enabled';
            $result['environment'] = $environment;
            $result['offline_http_fake'] = $api->offlineFake();
            app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
            $result['configuration_valid'] = true;
            foreach (['client_id', 'client_secret', 'merchant_id', 'merchant_scope', 'webhook_id', 'webhook_url', 'product_id'] as $key) {
                if (! is_string(config($configKey.'.'.$key)) || config($configKey.'.'.$key) === '') {
                    $result['missing'][] = $key;
                }
            }
            if (config($configKey.'.enabled') !== true && ! $api->offlineFake()) {
                $result['missing'][] = $enabledKey;
            }
            $binding = new PayPalMerchantBinding($api);
            if ($this->option('check-readiness')) {
                foreach (['create-product', 'bind-merchant', 'binding-context', 'check-merchant', 'check-webhook'] as $option) {
                    if ($this->option($option)) {
                        throw new RuntimeException('PAYPAL_READINESS_OPTIONS_CONFLICT');
                    }
                }
                $result['network_requested'] = true;
                $api->authenticateApp(fresh: true);
                $scope = app(PayPalRuntime::class)->assertPurchaseReady($environment);
                $record = $binding->assertBound($scope);
                $result['purchase_readiness_valid'] = true;
                $result['product_valid'] = true;
                $result['merchant_binding_valid'] = true;
                $result['app_authenticated'] = true;
                $result['webhook_registration_valid'] = true;
                $result['binding_source'] = $record->binding_source;
                $result['merchant_id'] = $record->merchant_id;
                $result['product_id'] = config($configKey.'.product_id');
            }

            if ($this->option('binding-context')) {
                $result['binding_context'] = $binding->offlineContext();
            }
            if ($this->option('bind-merchant')) {
                $result['network_requested'] = true;
                $record = $binding->bindReceipt($this->option('bind-merchant'));
                $result['app_authenticated'] = true;
                $result['merchant_binding_valid'] = true;
                $result['binding_source'] = $record->binding_source;
                $result['merchant_id'] = $record->merchant_id;
            }
            if ($this->option('create-product')) {
                foreach (['client_id', 'client_secret', $enabledKey] as $required) {
                    if (in_array($required, $result['missing'], true)) {
                        throw new RuntimeException('PAYPAL_SETUP_CONFIGURATION_REQUIRED');
                    }
                }
                // Provisioning identity is tied to the App, never a browser-supplied merchant alias.
                $scope = new ProviderScope('paypal', $environment, 'setup-'.substr(hash('sha256', config($configKey.'.client_id')), 0, 40));
                $owner = Uuid::uuid5(Uuid::NAMESPACE_URL, 'h5-paypal-product:'.($environment === 'live' ? 'live:' : '').$scope->merchantScope)->toString();
                $intent = $operations->reserve($scope, 'create_product', $owner, 1, $api::productRequest());
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
            if ($this->option('check-merchant') || $this->option('check-webhook')) {
                $context = $binding->context();
                $scope = new ProviderScope('paypal', $environment, $context['merchant_scope']);
                $record = $binding->assertBound($scope);
                $result['network_requested'] = true;
                $api->authenticateApp(fresh: true);
                $result['app_authenticated'] = true;
                $result['merchant_binding_valid'] = true;
                $result['binding_source'] = $record->binding_source;
                $result['merchant_id'] = $record->merchant_id;
                if ($this->option('check-webhook')) {
                    $api->assertWebhookRegistration($scope);
                    $result['webhook_registration_valid'] = true;
                }
            }
            if (! $this->option('create-product') && ! $this->option('check-merchant') && ! $this->option('check-webhook')
                && ! $this->option('binding-context') && ! $this->option('bind-merchant') && ! $this->option('check-readiness') && $result['missing']) {
                $result['diagnostic_code'] = 'PAYPAL_NOT_CONFIGURED';
            }
        } catch (Throwable $e) {
            $known = ['PAYPAL_READINESS_OPTIONS_CONFLICT', 'PAYPAL_SETUP_CONFIGURATION_REQUIRED', 'PAYPAL_SETUP_RECONCILIATION_REQUIRED',
                'PAYPAL_PRODUCT_RECONCILIATION_REQUIRED', 'PAYPAL_MERCHANT_BINDING_REQUIRED_OR_MISMATCH', 'PAYPAL_WEBHOOK_REGISTRATION_MISMATCH'];
            $result['diagnostic_code'] = in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'PAYPAL_SETUP_UNAVAILABLE';
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['diagnostic_code'] === null ? self::SUCCESS : self::FAILURE;
    }
}
