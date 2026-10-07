<?php

namespace App\Modules\Billing;

use App\Contracts\BillingService;
use App\Contracts\MembershipReader;
use App\Modules\Billing\Console\ReconcileBilling;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\StripeGateway;
use App\Modules\Billing\Gateway\UnconfiguredGateway;
use App\Modules\Billing\Services\BillingEngine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'billing');
        $this->app->bind(BillingService::class, BillingEngine::class);
        $this->app->bind(MembershipReader::class, BillingEngine::class);
        $this->app->bind(PaymentGateway::class, fn () => config('billing.driver') === 'stripe_test' ? new StripeGateway : new UnconfiguredGateway);
    }

    public function boot(): void
    {
        if (! in_array(config('billing.driver'), ['unconfigured', 'stripe_test'], true)
            || (! app()->environment('local', 'testing') && config('billing.driver') !== 'unconfigured')) {
            throw new \LogicException('Billing only supports an explicitly configured isolated Stripe test environment.');
        }
        $this->commands([ReconcileBilling::class]);
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('billing:reconcile')->everyMinute()->withoutOverlapping(5);
        });
    }
}
