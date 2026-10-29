<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\PayPalMerchantBinding;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PayPalMerchantBindingTest extends TestCase
{
    use DatabaseMigrations;

    private SandboxApi $api;

    private PayPalMerchantBinding $binding;

    private array $files = [];

    private array $calls = [];

    private bool $rejectAuth = false;

    private array $webhook = ['id' => '1AB23456CD789012E', 'url' => 'https://callback.example.test/webhooks/paypal'];

    private array $product = ['id' => 'PROD-ABCDEFGHIJKLMNOPQ', 'type' => 'DIGITAL'];

    private bool $failProduct = false;

    private bool $changeProductConfiguration = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(BillingServiceProvider::class);
        config(['billing.new_purchase_provider' => 'unconfigured', 'billing.paypal' => [
            'enabled' => true, 'client_id' => 'private-fixture-client', 'client_secret' => 'private-fixture-secret',
            'merchant_id' => 'SELLERFIXTURE1', 'merchant_scope' => 'fixture-seller', 'webhook_id' => null,
            'webhook_url' => null, 'product_id' => null]]);
        $this->travelTo(CarbonImmutable::parse('2026-10-28T06:00:00Z'));
        $handler = new MockHandler(array_fill(0, 100, function ($request) {
            $path = $request->getUri()->getPath();
            self::assertSame('api-m.sandbox.paypal.com', $request->getUri()->getHost());
            $this->calls[] = $request->getMethod().' '.$path;
            if ($path === '/v1/oauth2/token') {
                return new Response($this->rejectAuth ? 401 : 200, [], json_encode([
                    'access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
                    'app_id' => 'IGNORED-UNVERIFIED-ID', 'scope' => 'https://uri.paypal.com/services/subscriptions']));
            }
            if ($path === '/v1/notifications/webhooks/1AB23456CD789012E') {
                return new Response(200, [], json_encode($this->webhook));
            }
            if ($path === '/v1/catalogs/products/PROD-ABCDEFGHIJKLMNOPQ') {
                if ($this->changeProductConfiguration) {
                    config(['billing.paypal.product_id' => 'PROD-QPONMLKJIHGFEDCBA']);
                }

                return new Response($this->failProduct ? 503 : 200, [], json_encode($this->product));
            }
            throw new RuntimeException('UNEXPECTED_REQUEST');
        }));
        $this->api = new SandboxApi(new Client(['handler' => HandlerStack::create($handler)]));
        $this->app->instance(SandboxApi::class, $this->api);
        $this->binding = app(PayPalMerchantBinding::class);
        $this->webhook['event_types'] = array_map(fn ($name) => ['name' => $name], SandboxApi::REQUIRED_WEBHOOK_EVENTS);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        DB::table('billing_paypal_merchant_bindings')->delete();
        parent::tearDown();
    }

    public function test_offline_context_does_not_authenticate_or_claim_identity(): void
    {
        self::assertSame(0, Artisan::call('billing:paypal-prepare', ['--binding-context' => true]));
        $raw = Artisan::output();
        $output = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', 'private-fixture-client'), $output['binding_context']['client_id_sha256']);
        self::assertFalse($output['merchant_binding_valid']);
        self::assertFalse($output['api_merchant_identity_verified']);
        self::assertFalse($output['network_requested']);
        foreach (['private-fixture-client', 'private-fixture-secret', 'fixture-token'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
        self::assertSame([], $this->calls);
        self::assertDatabaseCount('billing_paypal_merchant_bindings', 0);
    }

    public function test_offline_context_works_with_api_disabled_and_merchant_not_yet_supplied(): void
    {
        config(['billing.paypal.enabled' => false, 'billing.paypal.merchant_id' => null]);
        self::assertSame(0, Artisan::call('billing:paypal-prepare', ['--binding-context' => true]));
        $output = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        self::assertNull($output['binding_context']['merchant_id']);
        self::assertSame(hash('sha256', 'private-fixture-client'), $output['binding_context']['client_id_sha256']);
        self::assertFalse($output['network_requested']);
        self::assertFalse($output['app_authenticated']);
        self::assertSame([], $this->calls);
        self::assertDatabaseCount('billing_paypal_merchant_bindings', 0);
        $this->rejected(fn () => $this->binding->context());
    }

    public function test_oauth_success_and_hand_filled_merchant_never_create_binding(): void
    {
        $this->api->authenticateApp();
        $this->artisan('billing:paypal-prepare', ['--check-merchant' => true])
            ->expectsOutputToContain('PAYPAL_MERCHANT_BINDING_REQUIRED_OR_MISMATCH')->assertExitCode(1);
        self::assertDatabaseCount('billing_paypal_merchant_bindings', 0);
        self::assertSame(['POST /v1/oauth2/token'], $this->calls);
    }

    public function test_explicit_private_receipt_and_oauth_bind_without_product_webhook_or_balances(): void
    {
        $file = $this->receipt();
        self::assertSame(0, Artisan::call('billing:paypal-prepare', ['--bind-merchant' => $file]));
        $output = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($output['merchant_binding_valid']);
        self::assertSame('operator_dashboard', $output['binding_source']);
        self::assertFalse($output['api_merchant_identity_verified']);
        self::assertFalse($output['merchant_verified']);
        $before = DB::table('billing_paypal_merchant_bindings')->first();
        $this->binding->bindReceipt($file);
        self::assertEquals($before, DB::table('billing_paypal_merchant_bindings')->first());
        self::assertSame(['POST /v1/oauth2/token', 'POST /v1/oauth2/token'], $this->calls);
        foreach (['billing_orders', 'billing_payments', 'billing_entitlements'] as $table) {
            self::assertDatabaseCount($table, 0);
        }
        self::assertSame('unconfigured', config('billing.new_purchase_provider'));
    }

    public function test_incomplete_wrong_or_stale_operator_evidence_cannot_bind(): void
    {
        foreach ([['binding_source' => 'oauth'], ['merchant_app_business_link_confirmed' => false],
            ['business_merchant_id_confirmed' => false], ['saved_client_id_matches_app' => false],
            ['client_id_sha256' => hash('sha256', 'another-app')], ['merchant_id' => 'OTHERSELLER'],
            ['merchant_scope' => 'another-scope'], ['environment' => 'live'], ['evidence_ref' => ''],
            ['verified_at' => '2026-10-20T06:00:00Z'], ['verified_at' => '2026-10-29T06:00:00Z'],
            ['extra_secret' => 'reject-unknown-field']] as $change) {
            $this->rejected(fn () => $this->binding->bindReceipt($this->receipt($change)));
        }
        self::assertSame([], $this->calls);
        self::assertDatabaseCount('billing_paypal_merchant_bindings', 0);
    }

    public function test_unsafe_receipt_files_are_rejected_before_authentication(): void
    {
        $file = $this->receipt();
        chmod($file, 0644);
        $this->rejected(fn () => $this->binding->bindReceipt($file));
        chmod($file, 0600);
        $link = $file.'.link';
        symlink($file, $link);
        $this->files[] = $link;
        $this->rejected(fn () => $this->binding->bindReceipt($link));
        $this->rejected(fn () => $this->binding->bindReceipt('/tmp/not-a-binding-receipt.json'));
        self::assertSame([], $this->calls);
    }

    public function test_failed_authentication_never_seals_operator_receipt(): void
    {
        $this->rejectAuth = true;
        $this->rejected(fn () => $this->binding->bindReceipt($this->receipt()));
        self::assertDatabaseCount('billing_paypal_merchant_bindings', 0);
    }

    public function test_client_merchant_and_scope_changes_never_rebind_existing_context(): void
    {
        $this->binding->bindReceipt($this->receipt());
        $original = config('billing.paypal');
        foreach (['client_id' => 'different-client', 'merchant_id' => 'OTHERSELLER', 'merchant_scope' => 'another-scope'] as $key => $value) {
            config(['billing.paypal' => array_replace($original, [$key => $value])]);
            $this->rejected(fn () => $this->binding->assertBound(new ProviderScope('paypal', 'sandbox', config('billing.paypal.merchant_scope'))));
            $this->rejected(fn () => $this->binding->bindReceipt($this->receipt()));
            self::assertDatabaseCount('billing_paypal_merchant_bindings', 1);
            self::assertSame('SELLERFIXTURE1', DB::table('billing_paypal_merchant_bindings')->first()->merchant_id);
        }
    }

    public function test_secret_rotation_requires_new_authentication_but_preserves_binding(): void
    {
        $this->binding->bindReceipt($this->receipt());
        config(['billing.paypal.client_secret' => 'rotated-fixture-secret']);
        $this->artisan('billing:paypal-prepare', ['--check-merchant' => true])->assertExitCode(0);
        self::assertCount(2, $this->calls);
        $this->rejectAuth = true;
        config(['billing.paypal.client_secret' => 'invalid-fixture-secret']);
        $this->artisan('billing:paypal-prepare', ['--check-merchant' => true])
            ->expectsOutputToContain('"merchant_binding_valid":false')->assertExitCode(1);
        self::assertDatabaseCount('billing_paypal_merchant_bindings', 1);
    }

    public function test_official_webhook_read_must_match_id_and_expected_callback(): void
    {
        $this->binding->bindReceipt($this->receipt());
        config(['billing.paypal.webhook_id' => '1AB23456CD789012E', 'billing.paypal.webhook_url' => $this->webhook['url']]);
        $scope = new ProviderScope('paypal', 'sandbox', 'fixture-seller');
        $this->api->assertWebhookRegistration($scope);
        $this->webhook['url'] = 'https://other.example.test/webhooks/paypal';
        $this->rejected(fn () => $this->api->assertWebhookRegistration($scope));
        $this->webhook['url'] = config('billing.paypal.webhook_url');
        $this->webhook['id'] = 'WH-OTHER';
        $this->rejected(fn () => $this->api->assertWebhookRegistration($scope));
        config(['billing.paypal.webhook_url' => null]);
        $this->rejected(fn () => $this->api->assertWebhookRegistration($scope));
        self::assertDatabaseCount('billing_inbox', 0);
    }

    public function test_missing_each_required_webhook_event_fails_and_official_wildcard_is_supported(): void
    {
        $this->binding->bindReceipt($this->receipt());
        config(['billing.paypal.webhook_id' => '1AB23456CD789012E', 'billing.paypal.webhook_url' => $this->webhook['url']]);
        $scope = new ProviderScope('paypal', 'sandbox', 'fixture-seller');
        foreach (SandboxApi::REQUIRED_WEBHOOK_EVENTS as $missing) {
            $this->webhook['event_types'] = array_values(array_map(fn ($name) => ['name' => $name], array_diff(SandboxApi::REQUIRED_WEBHOOK_EVENTS, [$missing])));
            $this->rejected(fn () => $this->api->assertWebhookRegistration($scope));
        }
        $this->webhook['event_types'] = [['name' => '*']];
        $this->api->assertWebhookRegistration($scope);
        $this->webhook['event_types'] = [];
        $this->rejected(fn () => $this->api->assertWebhookRegistration($scope));
    }

    public function test_readiness_works_before_new_purchase_enablement_with_fresh_auth_and_no_writes(): void
    {
        $this->prepareReadiness();
        $config = config('billing');
        $binding = DB::table('billing_paypal_merchant_bindings')->first();
        $this->calls = [];
        $writes = [];
        DB::listen(function ($event) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        for ($attempt = 0; $attempt < 2; $attempt++) {
            self::assertSame(0, Artisan::call('billing:paypal-prepare', ['--check-readiness' => true]));
            $output = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
            foreach (['purchase_readiness_valid', 'product_valid', 'merchant_binding_valid', 'app_authenticated', 'webhook_registration_valid'] as $key) {
                self::assertTrue($output[$key], $key);
            }
            self::assertSame('operator_dashboard', $output['binding_source']);
            self::assertFalse($output['api_merchant_identity_verified']);
            self::assertSame('not_performed', $output['official_payment_verification']);
        }
        self::assertSame(array_merge(...array_fill(0, 2, ['POST /v1/oauth2/token',
            'GET /v1/notifications/webhooks/1AB23456CD789012E', 'GET /v1/catalogs/products/PROD-ABCDEFGHIJKLMNOPQ'])), $this->calls);
        self::assertSame([], $writes);
        self::assertSame($config, config('billing'));
        self::assertEquals($binding, DB::table('billing_paypal_merchant_bindings')->first());
        foreach (['billing_orders', 'billing_payments', 'billing_entitlements', 'billing_subscriptions', 'billing_provider_intents'] as $table) {
            self::assertDatabaseCount($table, 0);
        }
    }

    public function test_readiness_rejects_bad_product_binding_authentication_or_webhook_without_writes(): void
    {
        $this->prepareReadiness();
        $configuration = config('billing.paypal');
        $product = $this->product;
        $webhook = $this->webhook;
        foreach (['product_id', 'product_type', 'product_error', 'webhook_events', 'webhook_url', 'binding', 'oauth', 'disabled', 'missing_product', 'configuration_changed'] as $fault) {
            config(['billing.paypal' => $configuration]);
            $this->product = $product;
            $this->webhook = $webhook;
            $this->rejectAuth = $fault === 'oauth';
            $this->failProduct = $fault === 'product_error';
            $this->changeProductConfiguration = $fault === 'configuration_changed';
            match ($fault) {
                'product_id' => $this->product['id'] = 'PROD-QPONMLKJIHGFEDCBA',
                'product_type' => $this->product['type'] = 'PHYSICAL',
                'webhook_events' => $this->webhook['event_types'] = [['name' => 'PAYMENT.SALE.COMPLETED']],
                'webhook_url' => $this->webhook['url'] = 'https://other.example.test/webhooks/paypal',
                'binding' => config(['billing.paypal.merchant_id' => 'OTHERSELLER']),
                'disabled' => config(['billing.paypal.enabled' => false]),
                'missing_product' => config(['billing.paypal.product_id' => null]),
                default => null,
            };
            self::assertSame(1, Artisan::call('billing:paypal-prepare', ['--check-readiness' => true]), $fault);
            $output = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
            self::assertFalse($output['purchase_readiness_valid'], $fault);
            self::assertFalse($output['product_valid'], $fault);
            self::assertNotNull($output['diagnostic_code']);
            self::assertSame('unconfigured', config('billing.new_purchase_provider'));
            foreach (['billing_orders', 'billing_subscriptions', 'billing_provider_intents'] as $table) {
                self::assertDatabaseCount($table, 0);
            }
        }
    }

    public function test_readiness_cannot_be_combined_with_any_other_setup_option(): void
    {
        foreach (['create-product' => true, 'bind-merchant' => '/not/read/receipt.json', 'binding-context' => true, 'check-merchant' => true, 'check-webhook' => true] as $key => $value) {
            self::assertSame(1, Artisan::call('billing:paypal-prepare', ['--check-readiness' => true, '--'.$key => $value]));
            $output = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('PAYPAL_READINESS_OPTIONS_CONFLICT', $output['diagnostic_code']);
            self::assertFalse($output['network_requested']);
            self::assertFalse($output['purchase_readiness_valid']);
        }
        self::assertSame([], $this->calls);
        self::assertDatabaseCount('billing_provider_intents', 0);
        self::assertDatabaseCount('billing_paypal_merchant_bindings', 0);
    }

    private function prepareReadiness(): void
    {
        $this->binding->bindReceipt($this->receipt());
        config(['billing.paypal.webhook_id' => $this->webhook['id'], 'billing.paypal.webhook_url' => $this->webhook['url'],
            'billing.paypal.product_id' => $this->product['id']]);
    }

    private function receipt(array $changes = []): string
    {
        $dir = base_path('../.runtime/billing-binding-tests');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $file = $dir.'/'.BillingStore::id().'.json';
        $value = array_replace($this->binding->context() + ['binding_source' => 'operator_dashboard',
            'verified_at' => now()->toIso8601ZuluString(), 'evidence_ref' => 'TEST-operator-20261028',
            'merchant_app_business_link_confirmed' => true, 'business_merchant_id_confirmed' => true,
            'saved_client_id_matches_app' => true], $changes);
        file_put_contents($file, json_encode($value));
        chmod($file, 0600);
        $this->files[] = $file;

        return $file;
    }

    private function rejected(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected rejection');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
