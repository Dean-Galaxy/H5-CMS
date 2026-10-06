<?php

namespace App\Modules\Identity\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class StaffPanelMfa
{
    public function handle(Request $request, Closure $next)
    {
        $staff = Auth::guard('staff')->user();
        if (! $staff || $request->is('admin/multi-factor-authentication/set-up', 'admin/logout')) {
            return $next($request);
        }
        if (! filled($staff->getAppAuthenticationSecret())) {
            return redirect('/admin/multi-factor-authentication/set-up');
        }
        if (session('identity.mfa_staff') !== $staff->id) {
            Auth::guard('staff')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/admin/login');
        }

        return $next($request);
    }
}
