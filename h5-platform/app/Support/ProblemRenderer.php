<?php

namespace App\Support;

use App\Contracts\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ProblemRenderer
{
    public static function render(\Throwable $e, Request $request)
    {
        if (! $request->expectsJson() && ! $request->is('api/*', 'webhooks/*') && $e instanceof ValidationException) {
            return null;
        }
        if (! $request->expectsJson() && $e instanceof AuthenticationException) {
            return null;
        }
        $status = match (true) {
            $e instanceof ApiProblem => $e->status, $e instanceof ValidationException => 422,
            $e instanceof AuthenticationException => 401, $e instanceof AuthorizationException => 403,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            $e instanceof ModelNotFoundException => 404, default => 500,
        };
        $code = $e instanceof ApiProblem ? $e->errorCode : match ($status) {
            401 => 'AUTHENTICATION_REQUIRED', 403 => 'NOT_AUTHORIZED', 404 => 'NOT_FOUND',
            419 => 'CSRF_MISMATCH', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED', default => 'INTERNAL_ERROR',
        };
        $message = $e instanceof ApiProblem ? $e->getMessage() : match ($status) {
            401 => '请先登录', 403 => '无权访问或链接无效', 404 => '资源不存在', 419 => '页面已过期，请刷新后重试',
            422 => '请检查输入', 429 => '操作过于频繁，请稍后再试', default => '暂时无法处理，请凭请求编号联系负责人',
        };
        $id = $request->attributes->get('request_id', (string) Str::uuid7());
        if (in_array($code, ['TOKEN_INVALID', 'TOKEN_EXPIRED', 'TOKEN_REPLAYED', 'MFA_REQUIRED', 'CSRF_MISMATCH'], true)) {
            app(AuditRecorder::class)->record('security.denied', 'request', null, 'denied', ['error_code' => $code]);
        }
        $payload = ['error' => ['code' => $code, 'message' => $message, 'request_id' => $id,
            'fields' => $e instanceof ValidationException ? (object) $e->errors() : null,
            'retryable' => $e instanceof ApiProblem ? $e->retryable : in_array($status, [429, 500], true)]];
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
        $headers['X-Request-ID'] = $id;
        $headers['Cache-Control'] = 'no-store, private';
        if ($request->expectsJson() || $request->is('api/*', 'webhooks/*')) {
            return response()->json($payload, $status, $headers);
        }

        return response()->view('identity.error', $payload['error'], $status, $headers);
    }
}
