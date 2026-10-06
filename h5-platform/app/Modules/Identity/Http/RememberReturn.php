<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Services\SafeReturn;
use Closure;
use Illuminate\Http\Request;

final class RememberReturn
{
    public function handle(Request $request, Closure $next)
    {
        $request->validate(['return_to' => ['nullable', 'string', 'max:1024']]);
        app(SafeReturn::class)->remember($request->input('return_to'));

        return $next($request);
    }
}
