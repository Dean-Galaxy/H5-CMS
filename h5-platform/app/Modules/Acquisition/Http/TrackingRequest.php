<?php

namespace App\Modules\Acquisition\Http;

use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TrackingRequest
{
    public function handle(Request $r, Closure $next)
    {
        $origin = $r->header('Origin');
        if ($origin && rtrim($origin, '/') !== rtrim(preg_replace('~^(https?://[^/]+).*~', '$1', config('app.url')), '/')) {
            app(BehaviorRecorder::class)->diagnostic('http_origin_rejected');

            return response()->json(['error' => 'SAME_ORIGIN_REQUIRED'], 403)->header('Cache-Control', 'no-store');
        }
        try {
            $response = $next($r);
        } catch (ValidationException) {
            $response = response()->json(['error' => 'VALIDATION_FAILED'], 422);
        } catch (HttpExceptionInterface $e) {
            $response = response()->json(['error' => 'TRACKING_REQUEST_REJECTED'], $e->getStatusCode(), $e->getHeaders());
        }
        // Routing pipelines can turn inner exceptions into responses before they reach this middleware.
        $reason = match ($response->getStatusCode()) {
            413 => 'http_payload_rejected', 422 => 'http_schema_rejected', default => null
        };
        if ($reason) {
            app(BehaviorRecorder::class)->diagnostic($reason);
        }

        return $response->header('Cache-Control', 'no-store');
    }
}
