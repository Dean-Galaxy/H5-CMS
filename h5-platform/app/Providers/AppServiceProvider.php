<?php

namespace App\Providers;

use App\Contracts\AttributionService;
use App\Contracts\BillingService;
use App\Contracts\CatalogReader;
use App\Contracts\MembershipReader;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\ProgressService;
use App\Support\DisabledAttribution;
use App\Support\UnavailableServices;
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
        foreach (config('h5.modules', []) as $module) {
            if ($module['provider']) {
                $this->app->register($module['provider']);
            }
        }
    }

    public function boot(): void
    {
        if (! app()->environment('local', 'testing') && (config('h5.simulation') || config('mail.default') === 'preview')) {
            throw new \LogicException('Non-local runtime forbids simulation and preview mail.');
        }
        if (app()->environment('production') && (config('app.debug') || ! str_starts_with(config('app.url'), 'https://'))) {
            throw new \LogicException('Production requires HTTPS and disabled debug.');
        }
        Date::use(CarbonImmutable::class);
    }
}
