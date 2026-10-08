<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\StripeGateway;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Symfony\Component\Console\Output\BufferedOutput;

// Real isolated PHP boot; positive scenarios retain the official SDK transport.
// Negative fixtures never return payment data. Native network functions are disabled
// by the parent, so a guard regression cannot contact Stripe or SMTP.
$base = dirname(__DIR__, 4);
$cachePath = getenv('APP_CONFIG_CACHE') ?: '';
$probeRoot = realpath($base.'/../.runtime/billing-runtime-probes');
$cacheParent = realpath(dirname($cachePath));
$uncached = getenv('H5_BILLING_RUNTIME_CACHE_MODE') === 'uncached';
$cache = realpath($cachePath);
$validPath = $probeRoot && $cacheParent && str_starts_with($cacheParent, $probeRoot.DIRECTORY_SEPARATOR)
    && ($uncached ? basename($cachePath) === 'uncached.php' && ! file_exists($cachePath)
        : $cache && str_starts_with($cache, $probeRoot.DIRECTORY_SEPARATOR));
$blockedFunctions = ['curl_exec', 'curl_multi_exec', 'stream_socket_client', 'fsockopen', 'pfsockopen'];
$networkBlocked = array_filter($blockedFunctions, 'function_exists') === [];
$database = getenv('H5_TEST_DATABASE');
$action = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || getenv('H5_BILLING_RUNTIME_PROBE') !== '1' || ! $validPath || ! $networkBlocked
    || ! is_string($database) || ! str_ends_with($database, '_test') || getenv('DB_DATABASE') !== $database
    || ! in_array($action, ['available', 'entrypoints', 'runtime', 'sdk_boot', 'sdk_runtime', 'binding_boot', 'binding_runtime', 'diagnostic'], true)) {
    exit(2);
}
require $base.'/vendor/autoload.php';

function inspectBillingProbe(callable $operation): array
{
    try {
        $operation();

        return ['ok' => true];
    } catch (DependencyNotReady $error) {
        return ['ok' => false, 'exception' => DependencyNotReady::class, 'code' => $error->errorCode, 'status' => $error->status];
    } catch (ApiProblem $error) {
        return ['ok' => false, 'exception' => ApiProblem::class, 'code' => $error->errorCode, 'status' => $error->status];
    } catch (LogicException $error) {
        return ['ok' => false, 'exception' => LogicException::class,
            'reason' => preg_match('/\A[A-Z0-9_]+\z/', $error->getMessage()) ? $error->getMessage() : 'UNSAFE_DIAGNOSTIC'];
    } catch (Throwable $error) {
        // A PHP error, SDK exception or network attempt is never a successful rejection.
        return ['ok' => false, 'exception' => get_class($error), 'reason' => 'UNEXPECTED_EXCEPTION'];
    }
}

/** Deliberately unusable adapter: resolution must reject it before any method call. */
final class BillingProbeRejectedGateway implements PaymentGateway
{
    public int $calls = 0;

    private function unexpected(): never
    {
        $this->calls++;
        throw new LogicException('PROBE_ADAPTER_WAS_CALLED');
    }

    public function assertAvailable(): void
    {
        $this->unexpected();
    }

    public function prepareCheckout(object $order, object $subscription): array
    {
        return $this->unexpected();
    }

    public function createCheckout(object $order, object $subscription): array
    {
        return $this->unexpected();
    }

    public function checkout(string $sessionId): array
    {
        return $this->unexpected();
    }

    public function subscription(string $id): array
    {
        return $this->unexpected();
    }

    public function setRenewal(object $subscription, bool $enabled, string $idempotencyKey): array
    {
        return $this->unexpected();
    }

    public function invoice(string $id): array
    {
        return $this->unexpected();
    }

    public function refunds(string $chargeId): array
    {
        return $this->unexpected();
    }

    public function verifyEvent(string $payload, string $signature): array
    {
        return $this->unexpected();
    }

    public function invoices(string $subscriptionId): array
    {
        return $this->unexpected();
    }
}

/** A negative fixture only; even an accidentally invoked request cannot reach a network. */
function injectBillingProbeSdkFault($app, string $kind): ?ClientInterface
{
    if ($kind === 'client') {
        $app->bind(StripeClient::class, fn ($app, $parameters) => new StripeClient([
            ...$parameters['config'], 'api_key' => 'sk_test_WRONG_SYNTHETIC',
        ]));

        return null;
    }
    if ($kind !== 'transport') {
        throw new LogicException('PROBE_SDK_FAULT_INVALID');
    }
    $http = new class implements ClientInterface
    {
        public int $calls = 0;

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->calls++;
            throw new LogicException('PROBE_TRANSPORT_WAS_CALLED');
        }
    };
    ApiRequestor::setHttpClient($http);

    return $http;
}

function injectBillingProbeGateway($app, string $kind, BillingProbeRejectedGateway $gateway): void
{
    match ($kind) {
        'bind' => $app->bind(PaymentGateway::class, fn () => $gateway),
        'singleton' => $app->singleton(PaymentGateway::class, fn () => $gateway),
        'instance' => $app->instance(PaymentGateway::class, $gateway),
        default => throw new LogicException('PROBE_BINDING_KIND_INVALID'),
    };
}

$result = ['boot' => false, 'network_functions_disabled' => true, 'checks' => []];
$rejectedGateway = new BillingProbeRejectedGateway;
$rejectedHttp = null;
try {
    $app = require $base.'/bootstrap/app.php';
    // Never load the developer's ordinary .env, even in the uncached scenarios.
    $app->useEnvironmentPath($cacheParent);
    $app->loadEnvironmentFrom('empty-billing-probe.env');
    $mutation = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
    if ($action === 'sdk_boot') {
        $app->booting(function () use ($app, $mutation, &$rejectedHttp): void {
            $rejectedHttp = injectBillingProbeSdkFault($app, $mutation['sdk']);
        });
    } elseif ($action === 'binding_boot') {
        $app->booting(fn () => injectBillingProbeGateway($app, $mutation['binding'], $rejectedGateway));
    }
    $boot = inspectBillingProbe(fn () => $app->make(Kernel::class)->bootstrap());
    if (! $boot['ok']) {
        $result['boot_error'] = $boot;
    } else {
        if (config('database.connections.pgsql.database') !== $database) {
            throw new LogicException('PROBE_DATABASE_MISMATCH');
        }
        $result['boot'] = true;
        $result['environment'] = ['config' => config('app.env'), 'container' => $app->environment(), 'process' => Env::get('APP_ENV')];
        $result['official_transport'] = get_class(ApiRequestor::httpClient()) === CurlClient::class;
        $result['acquisition_ready'] = config('h5.modules.acquisition.ready');
        $gateway = new StripeGateway;
        if (in_array($action, ['runtime', 'sdk_runtime', 'binding_runtime', 'diagnostic'], true)) {
            $result['initial_available'] = inspectBillingProbe(fn () => $gateway->assertAvailable());
            config($mutation['config'] ?? []);
            if (array_key_exists('container_env', $mutation)) {
                $app->instance('env', $mutation['container_env']);
            }
            if (array_key_exists('process_env', $mutation)) {
                $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $mutation['process_env'];
                putenv('APP_ENV='.$mutation['process_env']);
            }
        }
        if (in_array($action, ['sdk_runtime', 'diagnostic'], true) && isset($mutation['sdk'])) {
            $rejectedHttp = injectBillingProbeSdkFault($app, $mutation['sdk']);
            $result['official_transport'] = get_class(ApiRequestor::httpClient()) === CurlClient::class;
        }
        if ($action === 'binding_runtime') {
            $result['replacement'] = inspectBillingProbe(fn () => injectBillingProbeGateway($app, $mutation['binding'], $rejectedGateway));
            $result['resolved_classes'] = [];
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $result['resolutions'][] = inspectBillingProbe(function () use ($app, &$result): void {
                    $resolved = $app->make(PaymentGateway::class);
                    $result['resolved_classes'][] = get_class($resolved);
                    if (get_class($resolved) !== StripeGateway::class) {
                        throw new LogicException('PROBE_UNSAFE_ADAPTER_RESOLVED');
                    }
                });
            }
        } elseif ($action === 'diagnostic') {
            $output = new BufferedOutput;
            $exit = $app->make(Kernel::class)->call('billing:check-config', [], $output);
            $result['diagnostic'] = ['exit_code' => $exit,
                'report' => json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR)];
        } else {
            $result['provider'] = inspectBillingProbe(fn () => (new BillingServiceProvider($app))->boot());
            $operations = ['assertAvailable' => fn () => $gateway->assertAvailable()];
            if ($action !== 'available') {
                $operations += [
                    'prepareCheckout' => fn () => $gateway->prepareCheckout((object) [], (object) []),
                    'createCheckout' => fn () => $gateway->createCheckout((object) [], (object) []),
                    'checkout' => fn () => $gateway->checkout('cs_synthetic'),
                    'subscription' => fn () => $gateway->subscription('sub_synthetic'),
                    'setRenewal' => fn () => $gateway->setRenewal((object) [], false, 'synthetic_idempotency'),
                    'invoice' => fn () => $gateway->invoice('in_synthetic'),
                    'refunds' => fn () => $gateway->refunds('ch_synthetic'),
                    'verifyEvent' => fn () => $gateway->verifyEvent('{}', 'invalid_synthetic_signature'),
                    'invoices' => fn () => $gateway->invoices('sub_synthetic'),
                ];
            }
            foreach ($operations as $name => $operation) {
                $result['checks'][$name] = inspectBillingProbe($operation);
            }
        }
    }
} catch (Throwable $error) {
    $result['probe_error'] = ['exception' => get_class($error), 'reason' => 'UNEXPECTED_PROBE_FAILURE'];
}
$result['rejected_adapter_calls'] = $rejectedGateway->calls;
$result['rejected_transport_calls'] = $rejectedHttp?->calls ?? 0;
echo json_encode($result, JSON_THROW_ON_ERROR);
