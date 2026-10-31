<?php

namespace App\Providers;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\AttributionService;
use App\Contracts\BehaviorEventRecorder;
use App\Contracts\BillingAnalyticsReader;
use App\Contracts\BillingService;
use App\Contracts\CatalogReader;
use App\Contracts\CheckoutAttribution;
use App\Contracts\MembershipReader;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\ProgressService;
use App\Contracts\PromotableCatalog;
use App\Contracts\RegionResolver;
use App\Contracts\RegistrationMeasurement;
use App\Support\DisabledAttribution;
use App\Support\DisabledBehaviorEventRecorder;
use App\Support\DisabledCheckoutAttribution;
use App\Support\LocalModuleRegistry;
use App\Support\RuntimeEnvironmentGuard;
use App\Support\ServerRegionResolver;
use App\Support\UnavailableBillingAnalyticsReader;
use App\Support\UnavailablePromotableCatalog;
use App\Support\UnavailableRegistrationMeasurement;
use App\Support\UnavailableServices;
use App\Support\UnavailableStaffDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuntimeEnvironmentGuard::class);
        $this->app->make(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        foreach ([BillingService::class, CatalogReader::class, MembershipReader::class, PlaybackAuthorizer::class, ProgressService::class] as $contract) {
            $this->app->bind($contract, UnavailableServices::class);
        }
        $this->app->bind(AttributionService::class, DisabledAttribution::class);
        $this->app->bind(CheckoutAttribution::class, DisabledCheckoutAttribution::class);
        $this->app->bind(BehaviorEventRecorder::class, DisabledBehaviorEventRecorder::class);
        $this->app->bind(RegistrationMeasurement::class, UnavailableRegistrationMeasurement::class);
        $this->app->bind(BillingAnalyticsReader::class, UnavailableBillingAnalyticsReader::class);
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
        $this->app->make(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        Date::use(CarbonImmutable::class);
    }
}
