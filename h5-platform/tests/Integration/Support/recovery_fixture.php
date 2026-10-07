<?php

use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use Illuminate\Contracts\Console\Kernel;
use Tests\Integration\Support\RecoveryScenario;

$root = dirname(__DIR__, 4);
require $root.'/h5-platform/vendor/autoload.php';
$app = require $root.'/h5-platform/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = config('database.connections.pgsql.database');
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || $db !== getenv('H5_TEST_DATABASE') || ! preg_match('/^h5_[a-f0-9]{10}_recovery_[a-f0-9]{8}_test$/D', $db)) {
    throw new LogicException('Only a newly created isolated recovery fixture database is accepted.');
}
app()->bind(RegionResolver::class, fn () => new class implements RegionResolver
{
    public function resolve(): ResolvedRegion
    {
        return new ResolvedRegion('US', 'local_us_test');
    }
});
$result = match ($argv[1] ?? '') {
    'prepare-unpaid' => ['viewer_ids' => RecoveryScenario::prepareUnpaidAccounts()],
    'seed' => RecoveryScenario::seed(),
    'verify' => RecoveryScenario::verify(json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR)),
    default => throw new LogicException('Specify prepare-unpaid, seed, or verify with the seed manifest on stdin.'),
};
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
