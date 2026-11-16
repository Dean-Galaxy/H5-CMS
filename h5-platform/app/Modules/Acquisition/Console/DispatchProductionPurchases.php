<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Production\AutomaticPurchaseWorker;
use Illuminate\Console\Command;
use Throwable;

final class DispatchProductionPurchases extends Command
{
    protected $signature = 'acquisition:dispatch-production-purchases {--limit=2}';

    protected $description = 'Process newly settled eligible PayPal Live purchases outside the billing timer.';

    public function handle(AutomaticPurchaseWorker $worker): int
    {
        try {
            $result = $worker->run((int) $this->option('limit'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return $result['rejected'] || $result['unconfirmed'] ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('AUTOMATIC_PURCHASE_WORKER_UNAVAILABLE');

            return self::FAILURE;
        }
    }
}
