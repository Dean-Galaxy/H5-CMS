<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Candidate A: no proxy headers or framework hosting-platform autodetection. */
final class DirectConnectionContext
{
    public function handle(Request $request, Closure $next)
    {
        Request::setTrustedProxies([], Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX);
        if (! app()->environment('local', 'testing')) {
            app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
            if (! $request->isSecure() || $request->getPort() !== 443
                || $request->getHost() !== strtolower(parse_url(config('app.url'), PHP_URL_HOST))) {
                throw new BadRequestHttpException('APPLICATION_INGRESS_INVALID');
            }
        }

        return $next($request);
    }
}
