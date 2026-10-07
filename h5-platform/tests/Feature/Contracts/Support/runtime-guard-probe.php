<?php

use App\Contracts\RegionResolver;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Contracts\Console\Kernel;

// CLI-only boot/config inspection; no transport, request processing or database writes.
$base = dirname(__DIR__, 4);
$cachePath = getenv('APP_CONFIG_CACHE') ?: '';
$cache = realpath($cachePath);
$probeRoot = realpath($base.'/../.runtime/runtime-guard-probes');
$cacheParent = realpath(dirname($cachePath));
$uncached = getenv('H5_RUNTIME_GUARD_CACHE_MODE') === 'uncached';
$validCachePath = $probeRoot && $cacheParent && str_starts_with($cacheParent, $probeRoot.DIRECTORY_SEPARATOR)
    && ($uncached ? basename($cachePath) === 'uncached.php' && ! file_exists($cachePath)
        : $cache && str_starts_with($cache, $probeRoot.DIRECTORY_SEPARATOR));
$database = getenv('H5_TEST_DATABASE');
if (PHP_SAPI !== 'cli' || getenv('H5_RUNTIME_GUARD_PROBE') !== '1'
    || ! $validCachePath
    || ! is_string($database) || ! str_ends_with($database, '_test') || getenv('DB_DATABASE') !== $database
    || ! in_array($argv[1] ?? null, ['boot', 'origin'], true)) {
    exit(2);
}
require $base.'/vendor/autoload.php';
try {
    $app = require $base.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (config('database.connections.pgsql.database') !== $database) {
        throw new LogicException('PROBE_DATABASE_MISMATCH');
    }
    $guard = $app->make(RuntimeEnvironmentGuard::class);
    $guard->assertSafeRuntime();
    echo json_encode(['ok' => true, 'environment' => $app->environment(),
        'origin' => $argv[1] === 'origin' ? $guard->controlledStagingOrigin() : null,
        'region' => $app->make(RegionResolver::class)->resolve()->toArray(),
        'acquisition_ready' => config('h5.modules.acquisition.ready')], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $reason = preg_match('/\A[A-Z0-9_]+\z/', $error->getMessage()) ? $error->getMessage() : 'BOOT_REJECTED';
    echo json_encode(['ok' => false, 'origin' => null, 'reason' => $reason], JSON_THROW_ON_ERROR);
}
