<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateway\StripeGateway;
use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\File;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** No DB transactions or successful transport stubs: isolated subprocesses inspect configuration only. */
final class BillingStagingPolicyTest extends TestCase
{
    private const SECRET = 'sk_test_SYNTHETIC_NOT_A_CREDENTIAL';

    private const WEBHOOK_SECRET = 'whsec_SYNTHETIC_NOT_A_CREDENTIAL';

    private const ENTRYPOINTS = ['assertAvailable', 'prepareCheckout', 'createCheckout', 'checkout',
        'subscription', 'setRenewal', 'invoice', 'refunds', 'verifyEvent', 'invoices'];

    private function safeConfiguration(): array
    {
        $configuration = config()->all();
        $changes = [
            'app.env' => 'staging', 'app.debug' => false, 'app.url' => 'https://staging.example.test',
            'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'h5.controlled_staging' => true, 'h5.simulation' => false, 'h5.local_us_test_region' => false,
            'h5.local_modules' => '', 'h5.modules.acquisition' => ['provider' => null, 'ready' => false],
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'trustedproxy.proxies' => null, 'billing.driver' => 'stripe_test',
            'billing.stripe_secret' => self::SECRET, 'billing.stripe_webhook_secret' => self::WEBHOOK_SECRET,
            'billing.api_version' => StripeGateway::API_VERSION, 'billing.test_clock_id' => null,
            'mail.default' => 'smtp', 'mail.driver' => null, 'mail.from.address' => 'noreply@example.test',
            'mail.mailers' => ['smtp' => [
                'transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
                'host' => 'smtp.example.test', 'port' => '587', 'timeout' => 10,
                'username' => 'SYNTHETIC_USER', 'password' => 'SYNTHETIC_SMTP_NOT_SENT',
                'require_tls' => true, 'verify_peer' => true, 'local_domain' => 'staging.example.test',
            ]],
        ];
        foreach ($changes as $key => $value) {
            data_set($configuration, $key, $value);
        }

        return $configuration;
    }

    private function probe(array $configurationChanges = [], string $environment = 'staging', string $action = 'available', array $mutation = [], ?array $uncachedEnvironment = null): array
    {
        $directory = base_path('../.runtime/billing-runtime-probes/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory, 0700);
        try {
            $cache = $directory.($uncachedEnvironment === null ? '/config.php' : '/uncached.php');
            if ($uncachedEnvironment === null) {
                $configuration = $this->safeConfiguration();
                foreach ($configurationChanges as $key => $value) {
                    data_set($configuration, $key, $value);
                }
                file_put_contents($cache, '<?php return '.var_export($configuration, true).';');
                chmod($cache, 0600);
            }
            $environmentVariables = array_replace([
                'APP_ENV' => $environment, 'APP_CONFIG_CACHE' => $cache,
                'H5_BILLING_RUNTIME_PROBE' => '1', 'H5_LOCAL_MODULES' => '',
                'H5_BILLING_RUNTIME_CACHE_MODE' => $uncachedEnvironment === null ? 'cached' : 'uncached',
            ], $uncachedEnvironment ?? []);
            $process = new Process([PHP_BINARY, '-d',
                'disable_functions=curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen',
                __DIR__.'/Support/billing-runtime-probe.php', $action, json_encode($mutation, JSON_THROW_ON_ERROR)],
                base_path(), $environmentVariables);
            $process->setTimeout(25);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), 'Billing runtime probe did not complete.');
            $this->assertSame('', $process->getErrorOutput(), 'Billing runtime probe emitted an unexpected diagnostic.');
            foreach ([self::SECRET, self::WEBHOOK_SECRET, 'SYNTHETIC_SMTP_NOT_SENT', 'sk_test_WRONG_SYNTHETIC'] as $secret) {
                $this->assertStringNotContainsString($secret, $process->getOutput());
            }
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('probe_error', $result);
            $this->assertTrue($result['network_functions_disabled']);
            $this->assertSame(0, $result['rejected_adapter_calls']);
            $this->assertSame(0, $result['rejected_transport_calls']);

            return $result;
        } finally {
            File::deleteDirectory($directory);
            $this->assertDirectoryDoesNotExist($directory);
        }
    }

    private function assertBootRejected(array $result, string $reason): void
    {
        $this->assertFalse($result['boot']);
        $this->assertSame(['ok' => false, 'exception' => LogicException::class, 'reason' => $reason], $result['boot_error']);
        $this->assertSame([], $result['checks']);
    }

    private function assertEntrypointsUnavailable(array $result, bool $officialTransport = true): void
    {
        $this->assertTrue($result['boot']);
        $this->assertSame($officialTransport, $result['official_transport']);
        $this->assertSame(self::ENTRYPOINTS, array_keys($result['checks']));
        foreach ($result['checks'] as $method => $check) {
            $this->assertSame(['ok' => false, 'exception' => DependencyNotReady::class,
                'code' => 'DEPENDENCY_NOT_READY', 'status' => 503], $check, $method);
        }
    }

    public function test_safe_staging_boots_with_official_transport_but_never_makes_a_payment_request(): void
    {
        $result = $this->probe();
        $this->assertTrue($result['boot']);
        $this->assertTrue($result['official_transport']);
        $this->assertSame(['config' => 'staging', 'container' => 'staging', 'process' => 'staging'], $result['environment']);
        $this->assertSame(['ok' => true], $result['provider']);
        $this->assertSame(['assertAvailable' => ['ok' => true]], $result['checks']);
        $this->assertFalse($result['acquisition_ready']);
    }

    public function test_cached_missing_credentials_and_unconfigured_driver_allow_boot_but_block_every_gateway_entry(): void
    {
        foreach ([
            ['billing.stripe_secret' => null], ['billing.stripe_webhook_secret' => ''],
            ['billing.stripe_secret' => '', 'billing.stripe_webhook_secret' => null],
            ['billing.driver' => 'unconfigured'],
            ['billing.driver' => 'unconfigured', 'h5.controlled_staging' => false],
        ] as $changes) {
            $result = $this->probe($changes, action: 'entrypoints');
            $this->assertSame(['ok' => true], $result['provider']);
            $this->assertEntrypointsUnavailable($result);
        }
    }

    public function test_cached_provider_rejects_disallowed_payment_modes_credentials_and_versions(): void
    {
        $cases = [
            ['staging', ['h5.controlled_staging' => false], 'BILLING_STAGING_NOT_ENABLED'],
            ['production', ['app.env' => 'production', 'h5.controlled_staging' => false], 'BILLING_ENVIRONMENT_FORBIDDEN'],
            ['staging', ['billing.driver' => 'live'], 'BILLING_DRIVER_INVALID'],
            ['staging', ['billing.stripe_secret' => 'sk_live_SYNTHETIC'], 'BILLING_STRIPE_SECRET_INVALID'],
            ['staging', ['billing.stripe_secret' => 'pk_test_SYNTHETIC'], 'BILLING_STRIPE_SECRET_INVALID'],
            ['staging', ['billing.stripe_secret' => 'rk_test_SYNTHETIC'], 'BILLING_STRIPE_SECRET_INVALID'],
            ['staging', ['billing.stripe_secret' => 'sk_test_'], 'BILLING_STRIPE_SECRET_INVALID'],
            ['staging', ['billing.stripe_secret' => "sk_test_SYNTHETIC\n"], 'BILLING_STRIPE_SECRET_INVALID'],
            ['staging', ['billing.stripe_secret' => []], 'BILLING_STRIPE_SECRET_INVALID'],
            ['staging', ['billing.stripe_webhook_secret' => 'sk_test_SYNTHETIC'], 'BILLING_WEBHOOK_SECRET_INVALID'],
            ['staging', ['billing.stripe_webhook_secret' => 'whsec_'], 'BILLING_WEBHOOK_SECRET_INVALID'],
            ['staging', ['billing.api_version' => '2020-08-27'], 'BILLING_API_VERSION_INVALID'],
            ['staging', ['billing.api_base' => 'https://untrusted.example.test'], 'BILLING_API_BASE_INVALID'],
            ['staging', ['billing.test_clock_id' => 'not_a_clock'], 'BILLING_TEST_CLOCK_INVALID'],
            ['staging', ['app.env' => 'testing'], 'RUNTIME_ENVIRONMENT_INVALID'],
            ['testing', [], 'RUNTIME_ENVIRONMENT_INVALID'],
            ['stage', ['app.env' => 'stage'], 'RUNTIME_ENVIRONMENT_INVALID'],
            ['staging', ['h5.controlled_staging' => 'false'], 'RUNTIME_BOOLEAN_INVALID'],
        ];
        foreach ($cases as [$environment, $changes, $reason]) {
            $this->assertBootRejected($this->probe($changes, $environment), $reason);
        }
    }

    public function test_same_gateway_and_provider_recheck_loaded_configuration_after_success(): void
    {
        $cases = [
            [['h5.controlled_staging' => false], 'BILLING_STAGING_NOT_ENABLED'],
            [['billing.driver' => 'live'], 'BILLING_DRIVER_INVALID'],
            [['billing.stripe_secret' => 'sk_live_SYNTHETIC'], 'BILLING_STRIPE_SECRET_INVALID'],
            [['billing.stripe_webhook_secret' => 'invalid'], 'BILLING_WEBHOOK_SECRET_INVALID'],
            [['billing.api_version' => 'invalid'], 'BILLING_API_VERSION_INVALID'],
            [['app.debug' => true], 'RUNTIME_DEBUG_FORBIDDEN'],
            [['h5.simulation' => true], 'RUNTIME_LOCAL_OVERRIDE_FORBIDDEN'],
            [['h5.local_us_test_region' => true], 'RUNTIME_LOCAL_OVERRIDE_FORBIDDEN'],
            [['session.secure' => false], 'RUNTIME_COOKIE_UNSAFE'],
            [['trustedproxy.proxies' => '*'], 'RUNTIME_PROXY_FORBIDDEN'],
            [['mail.mailers.smtp.require_tls' => false], 'RUNTIME_MAIL_TLS_REQUIRED'],
            [['mail.mailers.smtp.verify_peer' => false], 'RUNTIME_MAIL_TLS_REQUIRED'],
            [['mail.default' => 'log', 'mail.mailers.log' => ['transport' => 'log']], 'RUNTIME_MAIL_TRANSPORT_FORBIDDEN'],
            [['app.url' => 'http://staging.example.test'], 'RUNTIME_ORIGIN_INVALID'],
            [['app.env' => 'testing'], 'RUNTIME_ENVIRONMENT_INVALID'],
        ];
        foreach ($cases as [$changes, $reason]) {
            $result = $this->probe(action: 'runtime', mutation: ['config' => $changes]);
            $this->assertSame(['ok' => true], $result['initial_available']);
            $this->assertSame(['ok' => false, 'exception' => LogicException::class, 'reason' => $reason], $result['provider']);
            $this->assertEntrypointsUnavailable($result);
        }
        foreach ([['container_env' => 'testing'], ['process_env' => 'testing']] as $mutation) {
            $result = $this->probe(action: 'runtime', mutation: $mutation);
            $this->assertSame(['ok' => true], $result['initial_available']);
            $this->assertSame('RUNTIME_ENVIRONMENT_INVALID', $result['provider']['reason']);
            $this->assertEntrypointsUnavailable($result);
        }
        $result = $this->probe(action: 'runtime', mutation: [
            'config' => ['app.env' => 'production', 'h5.controlled_staging' => false],
            'container_env' => 'production', 'process_env' => 'production',
        ]);
        $this->assertSame(['ok' => true], $result['initial_available']);
        $this->assertSame(['ok' => false, 'exception' => LogicException::class,
            'reason' => 'BILLING_ENVIRONMENT_FORBIDDEN'], $result['provider']);
        $this->assertEntrypointsUnavailable($result);
    }

    public function test_missing_credentials_after_boot_disable_existing_gateway_without_failing_safe_provider_configuration(): void
    {
        foreach (['billing.stripe_secret', 'billing.stripe_webhook_secret'] as $key) {
            $result = $this->probe(action: 'runtime', mutation: ['config' => [$key => null]]);
            $this->assertSame(['ok' => true], $result['initial_available']);
            $this->assertSame(['ok' => true], $result['provider']);
            $this->assertEntrypointsUnavailable($result);
        }
    }

    public function test_staging_rejects_replaced_sdk_at_boot_and_on_an_existing_gateway(): void
    {
        foreach (['transport', 'client'] as $fault) {
            $this->assertBootRejected($this->probe(action: 'sdk_boot', mutation: ['sdk' => $fault]),
                'BILLING_CLIENT_CONFIGURATION_INVALID');
            $result = $this->probe(action: 'sdk_runtime', mutation: ['sdk' => $fault]);
            $this->assertSame(['ok' => true], $result['initial_available']);
            $this->assertSame(['ok' => false, 'exception' => LogicException::class,
                'reason' => 'BILLING_CLIENT_CONFIGURATION_INVALID'], $result['provider']);
            $this->assertEntrypointsUnavailable($result, officialTransport: $fault !== 'transport');
        }
    }

    public function test_staging_rejects_fake_gateway_bindings_and_cannot_retrieve_a_rejected_cached_instance(): void
    {
        $unavailable = ['ok' => false, 'exception' => DependencyNotReady::class,
            'code' => 'DEPENDENCY_NOT_READY', 'status' => 503];
        foreach (['bind', 'singleton', 'instance'] as $kind) {
            $this->assertBootRejected($this->probe(action: 'binding_boot', mutation: ['binding' => $kind]),
                'BILLING_CLIENT_CONFIGURATION_INVALID');
            $result = $this->probe(action: 'binding_runtime', mutation: ['binding' => $kind]);
            $this->assertTrue($result['boot']);
            $this->assertTrue($result['official_transport']);
            $this->assertSame(['ok' => true], $result['initial_available']);
            $this->assertSame($unavailable, $result['replacement']);
            if ($kind === 'instance') {
                // Rejection removes the injected object, exposing the original official binding.
                $this->assertSame([['ok' => true], ['ok' => true]], $result['resolutions']);
                $this->assertSame([StripeGateway::class, StripeGateway::class], $result['resolved_classes']);
            } else {
                $this->assertSame([$unavailable, $unavailable], $result['resolutions']);
                $this->assertSame([], $result['resolved_classes']);
            }
        }
    }

    public function test_offline_diagnostic_reports_configuration_readiness_without_claiming_official_verification(): void
    {
        $cases = [
            [[], [], true, true, [], null],
            [['billing.stripe_secret' => null, 'billing.stripe_webhook_secret' => ''], [],
                true, false, ['stripe_secret', 'stripe_webhook_secret'], 'BILLING_NOT_CONFIGURED'],
            [['billing.driver' => 'unconfigured', 'billing.stripe_secret' => null, 'billing.stripe_webhook_secret' => null], [],
                true, false, ['stripe_test_driver', 'stripe_secret', 'stripe_webhook_secret'], 'BILLING_NOT_CONFIGURED'],
            [[], ['config' => ['billing.api_version' => 'invalid']], false, false, [], 'BILLING_CONFIGURATION_INVALID'],
            [[], ['sdk' => 'client'], false, false, [], 'BILLING_CLIENT_CONFIGURATION_INVALID'],
        ];
        foreach ($cases as [$configuration, $mutation, $valid, $ready, $missing, $code]) {
            $result = $this->probe($configuration, action: 'diagnostic', mutation: $mutation);
            $this->assertTrue($result['boot']);
            $this->assertTrue($result['official_transport']);
            $this->assertSame([], $result['checks']);
            $this->assertSame(['exit_code' => $ready ? 0 : 1, 'report' => [
                'configuration_valid' => $valid, 'payment_configuration_ready' => $ready,
                'missing' => $missing, 'diagnostic_code' => $code,
                'official_verification' => 'not_performed', 'network_performed' => false,
            ]], $result['diagnostic']);
        }
    }

    public function test_uncached_real_environment_loading_respects_opt_in_and_credential_boundaries(): void
    {
        $safe = [
            'APP_ENV' => 'staging', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://staging.example.test',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'H5_CONTROLLED_STAGING' => 'true', 'H5_SIMULATION' => 'false', 'H5_LOCAL_US_TEST_REGION' => 'false',
            'H5_LOCAL_MODULES' => '', 'SESSION_SECURE_COOKIE' => 'true', 'SESSION_HTTP_ONLY' => 'true',
            'SESSION_SAME_SITE' => 'lax', 'H5_BILLING_DRIVER' => 'stripe_test',
            'H5_BILLING_STRIPE_TEST_SECRET' => self::SECRET,
            'H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET' => self::WEBHOOK_SECRET, 'H5_BILLING_TEST_CLOCK_ID' => 'null',
            'MAIL_MAILER' => 'smtp', 'MAIL_SCHEME' => 'smtp', 'MAIL_URL' => 'null',
            'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587', 'MAIL_TIMEOUT' => '10',
            'MAIL_USERNAME' => 'SYNTHETIC_USER', 'MAIL_PASSWORD' => 'SYNTHETIC_SMTP_NOT_SENT',
            'MAIL_REQUIRE_TLS' => 'true', 'MAIL_EHLO_DOMAIN' => 'staging.example.test',
            'MAIL_FROM_ADDRESS' => 'noreply@example.test',
        ];
        $result = $this->probe(uncachedEnvironment: $safe);
        $this->assertTrue($result['boot']);
        $this->assertTrue($result['official_transport']);
        $this->assertSame(['config' => 'staging', 'container' => 'staging', 'process' => 'staging'], $result['environment']);
        $this->assertSame(['assertAvailable' => ['ok' => true]], $result['checks']);
        foreach (['H5_BILLING_STRIPE_TEST_SECRET', 'H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET'] as $key) {
            $this->assertEntrypointsUnavailable($this->probe(action: 'entrypoints', uncachedEnvironment: [...$safe, $key => '']));
        }
        foreach ([
            // Process env=false removes the variable: prove the default is closed too.
            [['H5_CONTROLLED_STAGING' => false], 'BILLING_STAGING_NOT_ENABLED'],
            [['H5_CONTROLLED_STAGING' => 'false'], 'BILLING_STAGING_NOT_ENABLED'],
            [['H5_CONTROLLED_STAGING' => 'junk'], 'RUNTIME_BOOLEAN_INVALID'],
            [['H5_CONTROLLED_STAGING' => '0'], 'RUNTIME_BOOLEAN_INVALID'],
            [['APP_ENV' => 'production', 'H5_CONTROLLED_STAGING' => 'false'], 'BILLING_ENVIRONMENT_FORBIDDEN'],
            [['H5_BILLING_DRIVER' => 'live'], 'BILLING_DRIVER_INVALID'],
            [['H5_BILLING_STRIPE_TEST_SECRET' => 'sk_live_SYNTHETIC'], 'BILLING_STRIPE_SECRET_INVALID'],
            [['H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET' => 'invalid'], 'BILLING_WEBHOOK_SECRET_INVALID'],
            [['APP_DEBUG' => 'true'], 'RUNTIME_DEBUG_FORBIDDEN'],
            [['MAIL_REQUIRE_TLS' => 'false'], 'RUNTIME_MAIL_TLS_REQUIRED'],
            [['MAIL_MAILER' => 'array'], 'RUNTIME_MAIL_TRANSPORT_FORBIDDEN'],
            [['SESSION_SECURE_COOKIE' => 'false'], 'RUNTIME_COOKIE_UNSAFE'],
        ] as [$changes, $reason]) {
            $this->assertBootRejected($this->probe(uncachedEnvironment: array_replace($safe, $changes)), $reason);
        }
    }
}
