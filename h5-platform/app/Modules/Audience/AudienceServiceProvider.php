<?php

namespace App\Modules\Audience;

use App\Contracts\ProgressService;
use App\Modules\Audience\Progress\ProgressRecorder;
use Illuminate\Support\ServiceProvider;

final class AudienceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProgressService::class, ProgressRecorder::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'audience');
    }
}
