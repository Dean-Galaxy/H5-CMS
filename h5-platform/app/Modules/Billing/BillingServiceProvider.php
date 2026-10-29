<?php

namespace App\Modules\Billing;

use App\Contracts\BillingAnalyticsReader;
use App\Contracts\BillingService;
use App\Contracts\MembershipReader;
use App\Modules\Billing\Console\BillingConfigurationCheck;
use App\Modules\Billing\Console\DispatchBillingBehavior;
use App\Modules\Billing\Console\PayPalPrepare;
use App\Modules\Billing\Console\ReconcileBilling;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\StripeGateway;
use App\Modules\Billing\Gateway\UnconfiguredGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\FinancialAnalyticsReader;
use App\Modules\Billing\Support\BillingRuntimePolicy;
use App\Support\DependencyNotReady;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Throwable;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'billing');
        $this->app->singleton(BillingRuntimePolicy::class);
        $this->app->bind(BillingService::class, BillingEngine::class);
        $this->app->bind(BillingAnalyticsReader::class, FinancialAnalyticsReader::class);
        $this->app->bind(MembershipReader::class, BillingEngine::class);
        $this->app->bind(PaymentGateway::class, fn () => config('billing.driver') === 'stripe_test' ? new StripeGateway : new UnconfiguredGateway);
        $this->app->afterResolving(PaymentGateway::class, fn (mixed $gateway) => $this->assertGatewayBinding($gateway));
    }

    public function boot(): void
    {
        $this->app->make(BillingRuntimePolicy::class)->assertSafeConfiguration();
        try {
            if (config('billing.driver') === 'stripe_test') {
                // Construction and inspection only; missing credentials do not prevent a safe boot.
                (new StripeGateway)->assertSafeClientConfiguration();
            }
            if ($this->app->environment() !== 'testing') {
                // Container instance() bindings bypass afterResolving on retrieval.
                $this->assertGatewayBinding($this->app->make(PaymentGateway::class));
            }
        } catch (DependencyNotReady) {
            throw new LogicException('BILLING_CLIENT_CONFIGURATION_INVALID');
        }
        // instance() bypasses afterResolving; reject a later replacement as well.
        $this->app->rebinding(PaymentGateway::class, fn ($app, mixed $gateway) => $this->assertGatewayBinding($gateway));
        $this->commands([ReconcileBilling::class, BillingConfigurationCheck::class, PayPalPrepare::class, DispatchBillingBehavior::class]);
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('billing:reconcile')->everyMinute()->withoutOverlapping(5);
            $schedule->command('billing:behavior-dispatch')->everyMinute()->withoutOverlapping(5);
        });
    }

    private function assertGatewayBinding(mixed $gateway): void
    {
        if ($this->app->environment() === 'testing') {
            return;
        }
        try {
            $this->app->make(BillingRuntimePolicy::class)->assertSafeConfiguration();
            $expected = config('billing.driver') === 'stripe_test' ? StripeGateway::class : UnconfiguredGateway::class;
            if (! is_object($gateway) || get_class($gateway) !== $expected) {
                throw new LogicException('BILLING_GATEWAY_BINDING_INVALID');
            }
        } catch (Throwable) {
            // Both instance() and singleton resolution cache before invoking callbacks.
            // Never leave a rejected adapter available to a subsequent resolution.
            $this->app->forgetInstance(PaymentGateway::class);
            throw new DependencyNotReady('Billing / 支付适配器配置');
        }
    }
}
