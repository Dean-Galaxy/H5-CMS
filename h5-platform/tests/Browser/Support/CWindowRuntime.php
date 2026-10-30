<?php

namespace Tests\Browser\Support;

use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\UnconfiguredGateway;
use Illuminate\Support\Facades\Http;

/** Local synthetic C window only. No gateway(), controlled settlement, or provider fixture. */
final class CWindowRuntime
{
    public static function boot(bool $http = false): object
    {
        $root = AnalyticsRuntime::root();
        if (basename(dirname($root)) !== 'h5-c-ui-manual' || getenv('H5_C_WINDOW_ONLY') !== '1') {
            throw new \LogicException('NEW C WORKSPACE REQUIRED');
        }
        $manifest = $root.'/.runtime/06/c-window/manifest.json';
        foreach ([$root.'/.runtime', $root.'/.runtime/06', dirname($manifest)] as $dir) {
            if (! is_dir($dir) || is_link($dir) || fileowner($dir) !== posix_geteuid() || (fileperms($dir) & 0077)) {
                throw new \LogicException('PRIVATE C DIRECTORY REQUIRED');
            }
        }
        if (! is_file($manifest) || is_link($manifest) || fileowner($manifest) !== posix_geteuid()
            || (fileperms($manifest) & 0077) || lstat($manifest)['nlink'] !== 1 || filesize($manifest) > 131072
            || ! hash_equals(hash_file('sha256', $manifest), (string) getenv('H5_C_WINDOW_MANIFEST_SHA256'))) {
            throw new \LogicException('EXACT PRIVATE C MANIFEST REQUIRED');
        }
        $doc = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $config = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
        $suffix = substr(hash('sha256', $root), 0, 10);
        $declared = $doc['workspace']; $actual = $config; ksort($declared); ksort($actual);
        if ($doc['candidate'] !== getenv('H5_ANALYTICS_BROWSER_CANDIDATE') || $declared !== $actual
            || $config['root'] !== $root || $config['db'] !== 'h5_'.$suffix || $config['workspace'] !== 'h5_'.$suffix
            || $config['user'] !== 'h5_app_'.$suffix || $config['port'] === $config['db_port']) {
            throw new \LogicException('C ROOT DATABASE MANIFEST BINDING REQUIRED');
        }
        foreach (['port', 'db_port'] as $key) {
            if (! is_int($config[$key]) || $config[$key] < 1024 || $config[$key] > 65535
                || in_array($config[$key], [8206, 8303, 5606, 8377, 5877, 8406, 5906, 8407, 8408, 8409, 8410, 5910], true)) {
                throw new \LogicException('NEW C PORTS REQUIRED');
            }
        }
        $app = AnalyticsRuntime::boot($http); // bootstrap/binding only; never calls AnalyticsRuntime::gateway().
        if (config('h5.measurement_c_experience') !== true || config('acquisition.measurement.enabled') !== $doc['measurement_enabled']
            || config('acquisition.measurement.synthetic_policy') !== true
            || config('acquisition.measurement.synthetic_until') !== $doc['synthetic_until']
            || config('billing.driver') !== 'unconfigured' || get_class(app(PaymentGateway::class)) !== UnconfiguredGateway::class
            || config('app.debug') !== false || config('database.connections.pgsql.host') !== '127.0.0.1'
            || config('database.connections.pgsql.username') !== $config['user']
            || config('h5.workspace') !== $config['workspace'].'_test') {
            throw new \LogicException('C FLAGS OR OWN DATABASE REQUIRED');
        }
        // Expiry is deliberately NOT a bootstrap error: CorePolicy must turn measurement off while the C page/free video remains usable.
        Http::preventStrayRequests();
        return $app;
    }
}
