<?php

namespace App\Modules\Audience\Http;

use App\Modules\Audience\AudienceServiceProvider;
use App\Support\DependencyNotReady;
use Closure;
use Illuminate\Http\Request;

final class AudienceEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (! (app()->getLoadedProviders()[AudienceServiceProvider::class] ?? false)) {
            throw new DependencyNotReady('Audience');
        }

        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
