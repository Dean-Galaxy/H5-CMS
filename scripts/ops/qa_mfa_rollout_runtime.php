<?php
// Read-only panel registration and safe runtime checks; never loads an account.
use Illuminate\Contracts\Console\Kernel;
use Filament\Facades\Filament;
use App\Modules\Identity\Filament\Auth\StaffAppAuthentication;
use App\Support\RuntimeEnvironmentGuard;
use App\Contracts\RegionResolver;
$root = dirname(__DIR__, 2);
try {
    if (PHP_SAPI !== 'cli' || $root !== '/Users/hejunxiang/.codex/worktrees/h5-06-f09-final/H5') {
        throw new RuntimeException('WRONG_WORKSPACE');
    }
    require $root.'/h5-platform/vendor/autoload.php';
    $app = require $root.'/h5-platform/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
    $panel = Filament::getPanel('admin');
    $providers = $panel->getMultiFactorAuthenticationProviders();
    $provider = $providers['app'] ?? null;
    $result = [
        'environment' => app()->environment(),
        'contract' => config('h5.contract'),
        'imagick' => extension_loaded('imagick'),
        'customProviderRegistered' => $provider instanceof StaffAppAuthentication,
        'mfaRequired' => $panel->isMultiFactorAuthenticationRequired(),
        'recoveryEnabled' => $provider?->isRecoverable(),
        'acquisitionReady' => config('h5.modules.acquisition.ready'),
        'acquisitionTransport' => config('acquisition.transport'),
        'externalEnabled' => config('acquisition.external_enabled'),
        'billingDriver' => config('billing.driver'),
        'mailDriver' => config('mail.default'),
        'debug' => config('app.debug'),
        'region' => app(RegionResolver::class)->resolve()->toArray(),
    ];
    if ($result['environment'] !== 'local' || !$result['customProviderRegistered'] || !$result['mfaRequired'] || !$result['recoveryEnabled'] || !$result['acquisitionReady'] || $result['acquisitionTransport'] !== 'disabled' || $result['externalEnabled'] || $result['debug']) {
        throw new RuntimeException('RUNTIME_EXPECTATION_FAILED');
    }
    echo json_encode(['passed' => true] + $result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['passed' => false, 'reason' => 'RUNTIME_CHECK_FAILED']), PHP_EOL;
    exit(1);
}
