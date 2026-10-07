<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Services\DeliveryWorker;
use Illuminate\Console\Command;

final class DispatchAcquisition extends Command
{
    protected $signature = 'acquisition:dispatch {--limit=50}';

    protected $description = 'Process Acquisition deliveries; default disabled, external results require separate verification';

    public function handle(DeliveryWorker $worker): int
    {
        $this->info('Processed: '.$worker->run((int) $this->option('limit')).'; transport: '.config('acquisition.transport'));

        return self::SUCCESS;
    }
}
