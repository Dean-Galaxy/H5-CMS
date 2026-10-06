<?php

namespace App\Modules\Identity\Http;

use App\Contracts\IdentityContext;
use App\Modules\Identity\Services\SafeReturn;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;

final class AuthResponse implements LoginResponse, RegisterResponse
{
    public function toResponse($request)
    {
        $target = app(SafeReturn::class)->current();
        if ($pending = $request->session()->pull('identity.pending_verify')) {
            $target = $pending;
        }
        if (! $request->expectsJson()) {
            return redirect($target);
        }

        return response()->json(['identity' => ['viewer' => app(IdentityContext::class)->viewer()?->toArray(), 'staff' => null],
            'redirect_to' => $target, 'request_id' => $request->attributes->get('request_id')]);
    }
}
