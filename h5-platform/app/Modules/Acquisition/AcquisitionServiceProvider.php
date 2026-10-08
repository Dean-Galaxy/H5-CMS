<?php

namespace App\Modules\Acquisition;

use App\Contracts\AttributionService;
use App\Data\PaymentSettled;
use App\Modules\Acquisition\Console\DispatchAcquisition;
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
    }

    public function boot(): void
    {
        $mode = config('acquisition.transport');
        if (! in_array($mode, ['disabled', 'local_simulation', 'http'], true)
            || ($mode === 'local_simulation' && ! app()->environment('local', 'testing'))) {
            throw new \LogicException('Acquisition transport is invalid or simulation is forbidden outside local/testing.');
        }
        Event::listen(PaymentSettled::class, [PaymentReceiver::class, 'handle']);
        $this->commands([DispatchAcquisition::class]);
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('acquisition:dispatch')->everyMinute()->withoutOverlapping(5);
        });
    }
}
