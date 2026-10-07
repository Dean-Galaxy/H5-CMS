<?php

namespace App\Modules\Content\Http;

use App\Modules\Content\ContentServiceProvider;
use App\Support\DependencyNotReady;
use Closure;
use Illuminate\Http\Request;

final class ContentEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (! (app()->getLoadedProviders()[ContentServiceProvider::class] ?? false)) {
            throw new DependencyNotReady('Content');
        }

        return $next($request);
    }
}
