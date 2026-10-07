<?php

namespace App\Modules\Content\Console;

use App\Modules\Content\Models\Plan;
use App\Modules\Content\Services\PublicationService;
use Illuminate\Console\Command;

final class PublishDue extends Command
{
    protected $signature = 'content:publish-due';

    protected $description = 'Execute versioned due Content occurrences, holding unapproved missed schedules';

    public function handle(PublicationService $service): int
    {
        foreach (Plan::where('state', 'active')->where('next_at', '<=', now())->orderBy('next_at')->cursor() as $plan) {
            $this->line($plan->id.' '.$service->execute($plan->id, $plan->version, $plan->next_at));
        }

        return self::SUCCESS;
    }
}
