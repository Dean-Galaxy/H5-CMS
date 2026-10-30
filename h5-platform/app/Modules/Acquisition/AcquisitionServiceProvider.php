<?php

namespace App\Modules\Acquisition;

use App\Contracts\AttributionService;
use App\Contracts\BehaviorEventRecorder;
use App\Data\PaymentSettled;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Console\DispatchAcquisition;
use App\Modules\Acquisition\Console\PruneAnalytics;
use App\Modules\Acquisition\Console\PruneMeasurement;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Modules\Acquisition\Services\PaymentReceiver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AcquisitionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'acquisition');
        $this->app->bind(AttributionService::class, AttributionRecorder::class);
        $this->app->bind(BehaviorEventRecorder::class, BehaviorRecorder::class);
    }

    public function boot(): void
    {
        if (! app()->environment('local', 'testing') && (config('acquisition.measurement.enabled') || config('acquisition.measurement.synthetic_policy'))) {
            throw new \LogicException('C core is restricted to isolated local/testing.');
        }
        $mode = config('acquisition.transport');
        if (! in_array($mode, ['disabled', 'local_simulation', 'http'], true)
            || ($mode === 'local_simulation' && ! app()->environment('local', 'testing'))) {
            throw new \LogicException('Acquisition transport is invalid or simulation is forbidden outside local/testing.');
        }
        Event::listen(PaymentSettled::class, [PaymentReceiver::class, 'handle']);
        $this->commands([DispatchAcquisition::class, PruneAnalytics::class, PruneMeasurement::class]);
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('acquisition:dispatch')->everyMinute()->withoutOverlapping(5);
            $schedule->command('acquisition:analytics-prune')->daily()->withoutOverlapping(5);
            if (config('acquisition.measurement.enabled', false)) {
                $schedule->command('acquisition:c-prune')->daily()->withoutOverlapping(5);
            }
        });
    }
}
