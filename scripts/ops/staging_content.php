<?php

// Fixed staging content operations. All configuration must belong to this release.
use App\Support\RuntimeEnvironmentGuard;
use H5Ops\StagingContentPolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$mode = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || ! in_array($mode, ['check', 'media', 'publish'], true)) {
    fwrite(STDERR, "STAGING_CONTENT_ARGUMENT_INVALID\n");
    exit(2);
}

try {
    require __DIR__.'/staging_content_policy.php';
    $root = dirname(__DIR__, 2);
    require $root.'/h5-platform/vendor/autoload.php';
    $app = require $root.'/h5-platform/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
    $media = realpath('/srv/h5-page-test/shared/media');
    $storage = realpath('/srv/h5-page-test/shared/storage');
    if (realpath('/srv/h5-page-test') !== '/srv/h5-page-test'
        || is_link('/srv/h5-page-test/shared') || is_link('/srv/h5-page-test/shared/media')
        || is_link('/srv/h5-page-test/shared/storage') || ! is_string($media) || ! is_string($storage)
        || realpath($app->storagePath()) !== $storage
        || realpath('/srv/h5-page-test/current') !== $root
        || ! $app->environment('staging') || ! $app->configurationIsCached()
        || realpath($app->getCachedConfigPath()) !== realpath('/srv/h5-page-test/shared/bootstrap/config.php')
        || config('h5.workspace') !== 'h5_page_test'
        || config('h5.controlled_staging') !== true
        || config('h5.acquisition_staging_test') !== true
        || ! in_array(config('mail.default'), ['array', 'smtp'], true)
        || realpath(config('h5.media_root', '')) !== $media) {
        throw new RuntimeException;
    }
    $db = DB::connection();
    $configuration = $db->getConfig();
    if ($db->getDriverName() !== 'pgsql' || ($configuration['database'] ?? '') !== 'h5_page_test'
        || ($configuration['username'] ?? '') !== 'h5_page_test' || ($configuration['host'] ?? '') !== '127.0.0.1') {
        throw new RuntimeException;
    }
    $identity = $db->selectOne("SELECT current_database() AS db, current_user AS usr, current_setting('TimeZone') AS zone");
    if ($identity->db !== 'h5_page_test' || $identity->usr !== 'h5_page_test'
        || ! in_array($identity->zone, ['UTC', 'Etc/UTC'], true)) {
        throw new RuntimeException;
    }
    $ads = [];
    foreach (['transport', 'external_enabled', 'platform_reviewed', 'analytics_enabled', 'measurement.enabled',
        'measurement.synthetic_policy', 'registration.observations_enabled', 'registration.synthetic_policy'] as $key) {
        $ads[$key] = config('acquisition.'.$key);
    }
    StagingContentPolicy::check([
        'environment' => $app->environment(), 'workspace' => config('h5.workspace'),
        'controlled' => config('h5.controlled_staging'), 'acquisition_test' => config('h5.acquisition_staging_test'),
        'cached' => $app->configurationIsCached(), 'mail' => config('mail.default'), 'origin' => config('app.url'),
        'driver' => config('billing.driver'), 'provider' => config('billing.new_purchase_provider'),
        'sandbox' => config('billing.paypal'), 'live' => config('billing.paypal_live'),
        'stripe_secret' => config('billing.stripe_secret'), 'stripe_webhook_secret' => config('billing.stripe_webhook_secret'),
        'ads' => $ads,
    ], [StagingContentPolicy::class, 'assertLocalSandboxBinding'], [StagingContentPolicy::class, 'assertLocalSmtpRecipients']);
    $exit = 0;
    $counts = [];
    if ($mode !== 'check') {
        $exit = $kernel->call($mode === 'media' ? 'content:process-media' : 'content:publish-due',
            $mode === 'media' ? ['--limit' => 1] : []);
        foreach (preg_split('/\R/', trim($kernel->output())) as $line) {
            if (preg_match('/\A[0-9a-f-]{36} (ready|failed|processing|pending|published|held|blocked|duplicate|obsolete)(?: |$)/D', $line, $match)) {
                $counts[$match[1]] = ($counts[$match[1]] ?? 0) + 1;
            }
        }
    }
    echo json_encode(['mode' => $mode, 'command_exit' => $exit, 'results' => $counts], JSON_THROW_ON_ERROR).PHP_EOL;
    exit($exit);
} catch (Throwable) {
    fwrite(STDERR, "STAGING_CONTENT_UNAVAILABLE\n");
    exit(1);
}
