<?php

namespace App\Modules\Acquisition\Measurement;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CoreRequest
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');
        if (($origin && rtrim($origin, '/') !== rtrim(preg_replace('~^(https?://[^/]+).*~', '$1', config('app.url')), '/'))
            || $request->header('Sec-Fetch-Site') === 'cross-site') {
            return response()->json(['error' => 'SAME_ORIGIN_REQUIRED'], 403)->header('Cache-Control', 'no-store');
        }
        try {
            abort_if($request->query->count() > 0, 422);
            $response = $next($request);
        } catch (ValidationException) {
            $response = response()->json(['error' => 'VALIDATION_FAILED'], 422);
        } catch (HttpExceptionInterface $e) {
            $response = response()->json(['error' => 'MEASUREMENT_REQUEST_REJECTED'], $e->getStatusCode(), $e->getHeaders());
        } catch (Throwable) {
            // No exception/input/SQL text is logged or returned by this purpose-specific boundary.
            $response = self::failure($request);
        }

        return $response->header('Cache-Control', 'no-store');
    }

    public static function failure(Request $request)
    {
        return response()->json(['schema_version' => 2, 'enabled' => false, 'policy_version' => CorePolicy::VERSION,
            'eligibility' => 'unknown', 'basis_kind' => 'none', 'site_choice' => 'unset', 'site_effective' => 'off',
            'notice_version' => CorePolicy::NOTICE, 'reason' => 'MEASUREMENT_UNAVAILABLE',
            'account_link' => ['choice' => 'unknown', 'effective' => false, 'reason' => 'DEPENDENCY_NOT_READY'],
            'conversion_link' => ['mode' => 'off', 'effective' => false],
            'advertising' => ['choice' => 'unknown', 'effective' => false, 'opt_out_signal' => $request->header('Sec-GPC') === '1'], 'revision' => 0], 503);
    }
}
