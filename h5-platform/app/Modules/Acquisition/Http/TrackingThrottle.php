<?php

namespace App\Modules\Acquisition\Http;

use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;

final class TrackingThrottle extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        try {
            return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
        } catch (ThrottleRequestsException $e) {
            app(BehaviorRecorder::class)->diagnostic('http_rate_limited');

            return response()->json(['error' => 'TRACKING_RATE_LIMITED'], 429, $e->getHeaders())->header('Cache-Control', 'no-store');
        }
    }
}
