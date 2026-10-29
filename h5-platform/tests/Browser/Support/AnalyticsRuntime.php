<?php

namespace Tests\Browser\Support;

use App\Modules\Billing\Gateway\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Feature\Billing\Support\FakeGateway;

/** Only the explicit 06 test-server entry point installs this protocol fixture. Never an application provider. */
final class AnalyticsRuntime
{
    public static function root(): string { return dirname(__DIR__, 4); }
    public static function directory(): string { return self::root().'/.runtime/06/analytics-browser'; }

    public static function boot(bool $http = false): object
    {
        $root = self::root();
        require_once $root.'/h5-platform/vendor/autoload.php';
        $app = require $root.'/h5-platform/bootstrap/app.php';
        if ($http) { $app->instance('request', \Illuminate\Http\Request::capture()); }
        $app->make($http ? \Illuminate\Contracts\Http\Kernel::class : \Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $c = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
        $candidate = getenv('H5_ANALYTICS_BROWSER_CANDIDATE');
        $git = new Process(['git', '-C', $root, 'rev-parse', 'HEAD']); $git->mustRun();
        if (! app()->environment('testing') || getenv('H5_ANALYTICS_BROWSER_FIXTURE') !== '1'
            || ! is_string($candidate) || ! preg_match('/^[a-f0-9]{40}$/D', $candidate) || trim($git->getOutput()) !== $candidate
            || $c['root'] !== $root || str_contains($root, 'paypal-sandbox')
            || in_array($c['port'], [8377, 8406, 8407, 8408, 8409, 8303], true) || in_array($c['db_port'], [5877, 5906], true)
            || config('database.connections.pgsql.database') !== $c['db'].'_test'
            || getenv('H5_TEST_DATABASE') !== $c['db'].'_test' || (int) config('database.connections.pgsql.port') !== $c['db_port']
            || config('billing.paypal.enabled') !== false || config('billing.new_purchase_provider') !== 'unconfigured'
            || config('acquisition.transport') !== 'disabled' || config('acquisition.external_enabled') !== false
            || config('acquisition.analytics_enabled') !== true || config('mail.default') !== 'preview'
            || ($http && (PHP_SAPI !== 'cli-server' || app()->runningInConsole()))) {
            throw new \LogicException('06 TEST FIXTURE BINDING REQUIRED');
        }
        foreach ([$root.'/.runtime', $root.'/.runtime/06', self::directory()] as $dir) {
            if (! is_dir($dir)) { mkdir($dir, 0700, true); }
            if (is_link($dir) || fileowner($dir) !== posix_geteuid() || (fileperms($dir) & 0077)) {
                throw new \LogicException('PRIVATE FIXTURE DIRECTORY REQUIRED');
            }
        }
        return $app;
    }

    public static function document(string $name): array
    {
        $path = self::directory().'/'.$name;
        if (! is_file($path) || is_link($path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0077) || filesize($path) > 2097152) {
            throw new \LogicException('PRIVATE FIXTURE RECORD REQUIRED');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function write(string $name, array $data): void
    {
        $path = self::directory().'/'.$name;
        if (is_link($path)) { throw new \LogicException('FIXTURE SYMLINK REFUSED'); }
        $tmp = tempnam(self::directory(), '.06-'); chmod($tmp, 0600);
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($tmp, $path);
    }

    public static function gateway(): array
    {
        $path = self::directory().'/gateway.json';
        if (! is_file($path) || is_link($path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0077) || filesize($path) > 2097152) {
            throw new \LogicException('PRIVATE GATEWAY RECORD REQUIRED');
        }
        $handle = fopen($path, 'r+');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('LOCAL FIXTURE BUSY'); }
        $info = fstat($handle); $stat = lstat($path);
        if ($info['ino'] !== $stat['ino'] || $info['nlink'] !== 1 || $info['uid'] !== posix_geteuid()) { throw new \LogicException('GATEWAY FILE INVALID'); }
        $state = json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
        $gateway = new FakeGateway;
        foreach (['subscriptions', 'invoices', 'checkouts', 'refunds', 'events'] as $key) { $gateway->$key = $state[$key] ?? []; }
        app()->instance(PaymentGateway::class, $gateway);
        config(['billing.quote_policy_approved' => true, 'billing.new_purchase_provider' => 'stripe']);
        return [$gateway, $handle];
    }

    public static function saveGateway(FakeGateway $gateway, $handle): void
    {
        $state = [];
        foreach (['subscriptions', 'invoices', 'checkouts', 'refunds', 'events'] as $key) { $state[$key] = $gateway->$key; }
        $data = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($data) > 2097152) { throw new \LogicException('FIXTURE STATE LIMIT'); }
        rewind($handle); ftruncate($handle, 0); fwrite($handle, $data); fflush($handle);
        flock($handle, LOCK_UN); fclose($handle);
    }
}
