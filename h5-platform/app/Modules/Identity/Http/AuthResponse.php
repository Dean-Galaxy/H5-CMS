<?php

namespace App\Modules\Identity\Http;

use App\Contracts\IdentityContext;
use App\Modules\Identity\Services\RegistrationTerms;
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
        if (RegistrationTerms::enabled() && $request->routeIs('register.store')) {
            // A confirms only the necessary account. Keep the original safe return in session;
            // purpose submission is not ready and must never be inferred from this redirect.
            $target = '/identity/account';
        }
        if ($request->routeIs('register.store') && $request->attributes->get('identity.registration_mail_unconfirmed') === true) {
            $target = '/identity/account'; // Keep the original safe return in session for later use.
        }
        if (! $request->expectsJson()) {
            return redirect($target);
        }

        return response()->json(['identity' => ['viewer' => app(IdentityContext::class)->viewer()?->toArray(), 'staff' => null],
            'redirect_to' => $target, 'request_id' => $request->attributes->get('request_id')]);
    }
}
