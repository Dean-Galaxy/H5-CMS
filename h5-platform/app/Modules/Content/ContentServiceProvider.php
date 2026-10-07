<?php

namespace App\Modules\Content;

use App\Contracts\CatalogReader;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\PromotableCatalog;
use App\Modules\Content\Console\ProcessMedia;
use App\Modules\Content\Console\PublishDue;
use App\Modules\Content\Services\ContentCatalog;
use App\Modules\Content\Services\ContentPromotableCatalog;
use App\Modules\Content\Services\PlaybackService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'content');
        $this->app->bind(CatalogReader::class, ContentCatalog::class);
        $this->app->bind(PromotableCatalog::class, ContentPromotableCatalog::class);
        $this->app->bind(PlaybackAuthorizer::class, PlaybackService::class);
    }

    public function boot(): void
    {
        $this->commands([ProcessMedia::class, PublishDue::class]);
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('content:publish-due')->everyMinute()->withoutOverlapping(5);
            $schedule->command('content:process-media --limit=1')->everyMinute()->withoutOverlapping(25)->runInBackground();
        });
    }
}
