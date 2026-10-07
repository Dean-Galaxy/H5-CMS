<?php

namespace App\Modules\Audience;

use Illuminate\Support\ServiceProvider;

final class AudienceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'audience');
    }
}
