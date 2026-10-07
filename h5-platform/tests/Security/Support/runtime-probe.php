<?php

use App\Contracts\RegionResolver;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;

// An isolated CLI probe: no request dispatch, database query, mail or payment operation.
$root = dirname(__DIR__, 4);
$dir = realpath($root.'/.runtime/06/runtime-probes');
$cache = getenv('APP_CONFIG_CACHE');
$parent = is_string($cache) ? realpath(dirname($cache)) : false;
$operation = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! $dir || ! $parent || ! str_starts_with($parent, $dir.DIRECTORY_SEPARATOR)
    || getenv('H5_06_RUNTIME_PROBE') !== '1' || ! str_ends_with((string) getenv('H5_TEST_DATABASE'), '_test')
    || getenv('DB_DATABASE') !== getenv('H5_TEST_DATABASE')
    || ! in_array($operation, ['boot', 'origin', 'cache', 'hold'], true)) {
    exit(2);
}
require $root.'/h5-platform/vendor/autoload.php';
try {
    $app = require $root.'/h5-platform/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (config('database.connections.pgsql.database') !== getenv('H5_TEST_DATABASE')) {
        throw new LogicException('PROBE_DATABASE_MISMATCH');
    }
    $guard = app(RuntimeEnvironmentGuard::class);
    $guard->assertSafeRuntime();
    if ($operation === 'cache') {
        $configuration = config()->all();
        foreach (json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR) as $key => $value) {
            data_set($configuration, $key, $value);
        }
        $file = fopen($parent.'/candidate.php', 'x');
        fwrite($file, '<?php return '.var_export($configuration, true).';');
        fclose($file);
        chmod($parent.'/candidate.php', 0600);
    }
    $summary = static fn () => ['ok' => true, 'contract' => config('h5.contract'),
        'config_environment' => config('app.env'), 'container_environment' => app()->environment(),
        'process_environment' => Env::get('APP_ENV'), 'cached' => app()->configurationIsCached(),
        'controlled_staging' => config('h5.controlled_staging'),
        'origin' => $operation === 'origin' ? $guard->controlledStagingOrigin() : null,
        'region' => app(RegionResolver::class)->resolve()->toArray(),
        'billing_driver' => config('billing.driver'), 'acquisition_ready' => config('h5.modules.acquisition.ready')];
    echo json_encode($summary(), JSON_THROW_ON_ERROR).PHP_EOL;
    if ($operation === 'hold') {
        while (($line = fgets(STDIN)) !== false) {
            if (trim($line) !== 'inspect') {
                break;
            }
            $guard->assertSafeRuntime();
            echo json_encode($summary(), JSON_THROW_ON_ERROR).PHP_EOL;
            flush();
        }
    }
} catch (Throwable $error) {
    $reason = $error->getMessage();
    $known = ['Billing only supports an explicitly configured isolated Stripe test environment.',
        'Local module loading is forbidden outside local/testing.'];
    if (! preg_match('/\A[A-Z0-9_]+\z/D', $reason) && ! in_array($reason, $known, true)) {
        $reason = 'UNEXPECTED_PROBE_FAILURE';
    }
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(42);
}
