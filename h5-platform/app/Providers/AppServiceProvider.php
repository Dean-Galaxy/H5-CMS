<?php

namespace App\Providers;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\AttributionService;
use App\Contracts\BillingService;
use App\Contracts\CatalogReader;
use App\Contracts\MembershipReader;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\ProgressService;
use App\Contracts\PromotableCatalog;
use App\Contracts\RegionResolver;
use App\Support\DisabledAttribution;
use App\Support\LocalModuleRegistry;
use App\Support\ServerRegionResolver;
use App\Support\UnavailablePromotableCatalog;
use App\Support\UnavailableServices;
use App\Support\UnavailableStaffDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([BillingService::class, CatalogReader::class, MembershipReader::class, PlaybackAuthorizer::class, ProgressService::class] as $contract) {
            $this->app->bind($contract, UnavailableServices::class);
        }
        $this->app->bind(AttributionService::class, DisabledAttribution::class);
        $this->app->bind(AcquisitionStaffDirectory::class, UnavailableStaffDirectory::class);
        $this->app->bind(PromotableCatalog::class, UnavailablePromotableCatalog::class);
        $this->app->bind(RegionResolver::class, fn ($app) => new ServerRegionResolver(
            $app['request'], $app->environment(), (bool) config('h5.local_us_test_region'), $app->runningInConsole(),
        ));
        config(['h5.modules' => (new LocalModuleRegistry)->resolve(
            config('h5.modules', []), (string) config('h5.local_modules', ''),
            $this->app->environment(), config('h5.local_module_providers', []),
        )]);
        foreach (config('h5.modules', []) as $module) {
            if ($module['provider']) {
                $this->app->register($module['provider']);
            }
        }
    }

    public function boot(): void
    {
        if (! app()->environment('local', 'testing') && (config('h5.simulation') || config('h5.local_us_test_region') || config('mail.default') === 'preview')) {
            throw new \LogicException('Non-local runtime forbids simulation, local test regions and preview mail.');
        }
        if (app()->environment('production') && (config('app.debug') || ! str_starts_with(config('app.url'), 'https://'))) {
            throw new \LogicException('Production requires HTTPS and disabled debug.');
        }
        Date::use(CarbonImmutable::class);
    }
}
