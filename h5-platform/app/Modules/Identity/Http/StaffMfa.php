<?php

namespace App\Modules\Identity\Http;

use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;

final class StaffMfa
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('staff');
        if (! $user) {
            throw new ApiProblem('AUTHENTICATION_REQUIRED', 401, '请先登录后台');
        }
        if (! $user->active) {
            throw new ApiProblem('ACCOUNT_DISABLED', 401, '账号已停用');
        }
        if (! filled($user->getAppAuthenticationSecret()) || $request->session()->get('identity.mfa_staff') !== $user->id) {
            throw new ApiProblem('MFA_REQUIRED', 403, '请完成后台二次验证；初次配置后请重新登录');
        }

        return $next($request);
    }
}
