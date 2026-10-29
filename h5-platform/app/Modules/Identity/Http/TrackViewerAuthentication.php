<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\ViewerBehavior;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class TrackViewerAuthentication
{
    public function handle(Request $request, Closure $next, string $operation)
    {
        // A logged-in visitor rejected by guest middleware is not a new authentication attempt.
        $alreadyAuthenticated = $request->user('web') !== null;
        try {
            // Wrap the existing named limiter so its rejected outcome is visible too.
            // A separate route throttle is priority-sorted outside custom middleware by Laravel.
            $response = app(ThrottleRequests::class)->handle($request, $next,
                $operation === 'login' ? 'identity-login' : 'identity-register');
        } catch (Throwable $exception) {
            $status = $exception instanceof ValidationException ? 422
                : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
            $this->finish($request, $operation, $status, $alreadyAuthenticated);
            throw $exception;
        }
        $this->finish($request, $operation, $response->getStatusCode(), $alreadyAuthenticated);

        return $response;
    }

    private function finish(Request $request, string $operation, int $status, bool $alreadyAuthenticated): void
    {
        if ($alreadyAuthenticated || ! in_array($operation, ['registration', 'login'], true)) {
            return;
        }
        $behavior = app(ViewerBehavior::class);
        if ($operation === 'registration' && ($id = $request->attributes->get('identity.created_viewer'))) {
            // The account exists even if a later notification failed; never misreport that as
            // failed creation or emit the registration's automatic login as a second funnel step.
            $behavior->registered($id);
        } elseif ($operation === 'login' && $status < 400 && $request->attributes->get('identity.viewer_login')
            && ($viewer = $request->user('web')) instanceof Viewer) {
            $behavior->loggedIn($viewer);
        } elseif ($status >= 400) {
            $code = match (true) {
                $status === 429 => 'RATE_LIMITED',
                $request->attributes->get('identity.invalid_credentials') === true => 'INVALID_CREDENTIALS',
                $status === 422 => 'VALIDATION_FAILED',
                $status === 401 => 'AUTHENTICATION_REQUIRED',
                $status === 403 => 'NOT_AUTHORIZED',
                $status >= 500 => 'INTERNAL_ERROR',
                default => 'REQUEST_REJECTED',
            };
            $behavior->failed($operation, $code, $request);
        }
    }
}
