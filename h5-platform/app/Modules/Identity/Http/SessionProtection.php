<?php

namespace App\Modules\Identity\Http;

use App\Contracts\AuditRecorder;
use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SessionProtection
{
    public function handle(Request $request, Closure $next)
    {
        $name = $request->attributes->get('identity_guard', 'web');
        Auth::shouldUse($name);
        $guard = Auth::guard($name);
        if ($user = $guard->user()) {
            $user->refresh();
            $version = $request->session()->get('identity.version.'.$name);
            if (! $user->active || $version !== $user->session_version) {
                $code = $user->active ? 'SESSION_REVOKED' : 'ACCOUNT_DISABLED';
                app(AuditRecorder::class)->record('session.revoked', $name, $user->id, 'denied', ['error_code' => $code]);
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                throw new ApiProblem($code, 401, '会话已失效，请重新登录');
            }
        }

        return $next($request);
    }
}
