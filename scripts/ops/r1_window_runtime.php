<?php
/** 06-only synthetic R1 bootstrap. Not registered with the public application. */
final class H5R1Runtime
{
    public static function root(): string { return dirname(__DIR__, 2); }
    public static function directory(): string { return self::root().'/.runtime/06/r1'; }

    public static function document(string $path): array
    {
        $root = self::root();
        if (realpath($path) !== $path || ! str_starts_with($path, $root.'/.runtime/')) { throw new LogicException('OWN RUNTIME FILE REQUIRED'); }
        $s = lstat($path);
        if (! is_file($path) || is_link($path) || $s['uid'] !== posix_geteuid() || ($s['mode'] & 0077) || $s['nlink'] !== 1 || $s['size'] > 2097152) {
            throw new LogicException('PRIVATE FILE REQUIRED');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function write(string $relative, array $value): void
    {
        if (! preg_match('#^[a-zA-Z0-9_/-]+\.json$#D', $relative) || str_contains($relative, '..')) { throw new LogicException('SAFE PRIVATE NAME REQUIRED'); }
        $path = self::directory().'/'.$relative;
        if (! is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        if (is_link($path) || (is_file($path) && lstat($path)['nlink'] !== 1)) { throw new LogicException('NO PRIVATE LINKS'); }
        $temporary = tempnam(dirname($path), '.r1-'); chmod($temporary, 0600);
        file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($temporary, $path);
    }

    public static function boot(bool $http = false): object
    {
        $root = self::root();
        if (realpath($root) !== $root || basename($root) !== 'H5' || basename(dirname($root)) !== 'h5-r1-registration-manual'
            || getenv('H5_R1_WINDOW_ONLY') !== '1') { throw new LogicException('NEW OWN R1 ROOT REQUIRED'); }
        foreach ([$root.'/.runtime', $root.'/.runtime/06', self::directory()] as $directory) {
            if (! is_dir($directory) || is_link($directory) || fileowner($directory) !== posix_geteuid() || (fileperms($directory) & 0077)) { throw new LogicException('PRIVATE DIRECTORIES REQUIRED'); }
        }
        $cfg = self::document($root.'/.runtime/workspace.json');
        $manifest = self::document(self::directory().'/manifest.json');
        $registry = self::document(self::directory().'/owned-databases.json');
        $suffix = substr(hash('sha256', $root), 0, 10);
        $expected = ['root'=>$root,'workspace'=>'h5_'.$suffix,'db'=>'h5_'.$suffix,'user'=>'h5_app_'.$suffix,'port'=>8412,'db_port'=>5912];
        $actual = $cfg; ksort($actual); ksort($expected); $declared = $manifest['workspace']; ksort($declared);
        $envFile = $root.'/h5-platform/.env';
        if (is_link($envFile) || lstat($envFile)['nlink'] !== 1 || fileowner($envFile) !== posix_geteuid() || (fileperms($envFile) & 0077) || ! hash_equals($manifest['environment_sha256'], hash_file('sha256', $envFile))) { throw new LogicException('OWN UNCHANGED ENVIRONMENT REQUIRED'); }
        $candidate = (string) getenv('H5_R1_CANDIDATE');
        $role = (string) getenv('H5_R1_DB_ROLE');
        if ($actual !== $expected || $declared !== $actual || ($registry['root'] ?? null) !== $root || ($registry['pg_port'] ?? null) !== 5912
            || ! in_array($role, ['manual','regression','upgrade','lifecycle','lifecycle_retry1','restore'], true)
            || ($http && ! in_array($role, ['manual','restore'], true))
            || ! preg_match('/^[a-f0-9]{40}$/D', $candidate) || $manifest['candidate'] !== $candidate
            || ($manifest['approved'] ?? null) !== true || empty($manifest['00_reference'])
            || ! hash_equals(hash_file('sha256', self::directory().'/manifest.json'), (string) getenv('H5_R1_MANIFEST_SHA256'))) {
            throw new LogicException('EXACT MANIFEST ROLE BINDING REQUIRED');
        }
        $required = ['scripts/ops/analytics_acceptance.py','scripts/ops/r1_window.py','scripts/ops/r1_window_runtime.php','scripts/ops/r1_window_boundary.php','scripts/ops/r1_window_router.php','scripts/ops/r1_window_control.php','scripts/ops/r1_browser.cjs','scripts/ops/r1_rehearsal.py','scripts/ops/r1_probe.php','scripts/ops/tests/test_r1_window.py'];
        if (count($manifest['tools_sha256'] ?? []) !== count($required)) { throw new LogicException('EXACT TOOL FILE SET REQUIRED'); }
        foreach ($required as $relative) {
            if (! isset($manifest['tools_sha256'][$relative]) || is_link($root.'/'.$relative)
                || ! hash_equals($manifest['tools_sha256'][$relative], hash_file('sha256', $root.'/'.$relative))) { throw new LogicException('FIXED TOOL BYTES REQUIRED'); }
        }
        $git = new Symfony\Component\Process\Process(['git','-C',$root,'rev-parse','HEAD']); $git->mustRun();
        if (trim($git->getOutput()) !== $candidate) { throw new LogicException('FIXED HEAD REQUIRED'); }
        foreach ($required as $relative) {
            $committed = new Symfony\Component\Process\Process(['git','-C',$root,'show',$candidate.':'.$relative]); $committed->mustRun();
            if (! hash_equals($manifest['tools_sha256'][$relative], hash('sha256', $committed->getOutput()))) { throw new LogicException('COMMITTED TOOL BYTES REQUIRED'); }
        }
        $app = require $root.'/h5-platform/bootstrap/app.php';
        if ($http) { $app->instance('request', Illuminate\Http\Request::capture()); }
        $app->make($http ? Illuminate\Contracts\Http\Kernel::class : Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = $cfg['db'].'_r1_'.$role.'_test';
        $scope = $cfg['workspace'].(in_array($role, ['manual','restore'], true) ? '_r1' : '_r1_'.$role);
        $gateway = app(App\Modules\Billing\Gateway\PaymentGateway::class);
        $enabled = $role !== 'regression';
        if (! app()->environment('testing') || ($registry['databases'][$role] ?? null) !== $db
            || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== $db
            || config('database.connections.pgsql.host') !== '127.0.0.1' || (int) config('database.connections.pgsql.port') !== 5912
            || config('database.connections.pgsql.username') !== $cfg['user'] || getenv('H5_TEST_DATABASE') !== $db
            || config('h5.workspace') !== $scope || config('h5.measurement_c_experience') !== $enabled || config('h5.measurement_c_registration') !== $enabled
            || config('acquisition.measurement.enabled') !== $enabled || config('acquisition.measurement.synthetic_policy') !== $enabled
            || config('acquisition.measurement.synthetic_until') !== $manifest['policy_until']
            || config('acquisition.registration.synthetic_policy') !== $enabled || config('acquisition.registration.synthetic_from') !== $manifest['policy_from']
            || config('acquisition.registration.synthetic_until') !== $manifest['policy_until'] || config('acquisition.registration.observations_enabled') !== $enabled
            || config('billing.paypal.enabled') !== false || config('billing.new_purchase_provider') !== 'unconfigured'
            || config('billing.driver') !== 'unconfigured' || get_class($gateway) !== App\Modules\Billing\Gateway\UnconfiguredGateway::class
            || config('acquisition.external_enabled') !== false || config('acquisition.transport') !== 'disabled'
            || config('mail.default') !== 'preview' || realpath(config('h5.mail_preview_root')) !== $root.'/.runtime/mail'
            || config('app.debug') !== false || ($http && (PHP_SAPI !== 'cli-server' || app()->runningInConsole()))) {
            throw new LogicException('OWN SYNTHETIC R1 FLAGS REQUIRED');
        }
        // Do not reject an expired policy here: necessary registration/deny must remain usable while collection is off.
        Illuminate\Support\Facades\Http::preventStrayRequests();
        return $app;
    }
}
