<?php

// Read-only release/process preflight. A successful result never authorizes public deployment.
use App\Contracts\RegionResolver;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;

$root = dirname(__DIR__, 2);
$options = getopt('', ['expect-env:', 'require-cache']);
$expected = $options['expect-env'] ?? null;
if (PHP_SAPI !== 'cli' || ! in_array($expected, ['local', 'testing', 'staging', 'production'], true)) {
    fwrite(STDERR, "Use --expect-env=staging [--require-cache].\n");
    exit(2);
}
require $root.'/h5-platform/vendor/autoload.php';
try {
    $app = require $root.'/h5-platform/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $guard = app(RuntimeEnvironmentGuard::class);
    $guard->assertSafeRuntime();
    if (app()->environment() !== $expected) {
        throw new LogicException('PREFLIGHT_ENVIRONMENT_MISMATCH');
    }
    if (array_key_exists('require-cache', $options) && ! app()->configurationIsCached()) {
        throw new LogicException('PREFLIGHT_CACHE_REQUIRED');
    }
    echo json_encode(['ok' => true, 'contract' => config('h5.contract'),
        'config_environment' => config('app.env'), 'container_environment' => app()->environment(),
        'process_environment' => Env::get('APP_ENV'), 'cached' => app()->configurationIsCached(),
        'controlled_staging' => config('h5.controlled_staging'),
        'origin' => config('h5.controlled_staging') ? $guard->controlledStagingOrigin() : null,
        'region' => app(RegionResolver::class)->resolve()->toArray(), 'billing_driver' => config('billing.driver'),
        'acquisition_ready' => config('h5.modules.acquisition.ready')], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Fixed diagnostics only; never print a configuration value or framework trace.
    $reason = preg_match('/\A[A-Z0-9_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'PREFLIGHT_BOOT_REJECTED';
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(42);
}
