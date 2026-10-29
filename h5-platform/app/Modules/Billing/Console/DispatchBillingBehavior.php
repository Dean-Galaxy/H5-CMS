<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\BillingBehaviorOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchBillingBehavior extends Command
{
    protected $signature = 'billing:behavior-dispatch {--limit=100}';

    protected $description = 'Deliver durable financial lifecycle facts locally; never calls a payment or advertising API.';

    public function handle(BillingBehaviorOutbox $outbox): int
    {
        $count = $outbox->dispatchPending((int) $this->option('limit'));
        $pending = DB::table('billing_behavior_outbox')->where('state', 'pending')->count();
        $this->line('Lifecycle facts completed: '.$count.'; pending: '.$pending.'.');

        return self::SUCCESS;
    }
}
