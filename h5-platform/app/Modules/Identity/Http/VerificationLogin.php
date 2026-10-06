<?php

namespace App\Modules\Identity\Http;

use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;

final class VerificationLogin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user('web')) {
            if ($request->expectsJson()) {
                throw new ApiProblem('AUTHENTICATION_REQUIRED', 401, '请登录收件邮箱对应账号');
            }
            if (! $request->hasValidSignature()) {
                throw new ApiProblem('TOKEN_INVALID', 403, '验证链接无效或过期');
            }
            $request->session()->put('identity.pending_verify', $request->getRequestUri());

            return redirect('/identity/login');
        }

        return $next($request);
    }
}
