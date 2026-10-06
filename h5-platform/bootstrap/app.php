<?php

use App\Modules\Identity\Http\RequestContext;
use App\Modules\Identity\Http\SessionProtection;
use App\Support\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(at: fn () => ['^'.preg_quote(parse_url(config('app.url'), PHP_URL_HOST), '/').'$'], subdomains: false);
        $middleware->prepend(RequestContext::class);
        $middleware->web(append: [SessionProtection::class]);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('admin*', 'api/v1/admin/*') ? '/admin/login' : '/identity/login');
        $middleware->validateCsrfTokens(except: ['webhooks/stripe']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*', 'webhooks/*') || $r->expectsJson());
        $exceptions->render(fn (Throwable $e, Request $r) => ProblemRenderer::render($e, $r));
        $exceptions->report(function (Throwable $e) {
            Log::warning('request.failed', ['exception_type' => get_class($e), 'request_id' => request()->attributes->get('request_id')]);

            return false;
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token', 'recovery_code', 'code']);
    })->create();
