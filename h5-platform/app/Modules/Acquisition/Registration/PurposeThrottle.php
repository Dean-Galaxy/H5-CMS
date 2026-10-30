<?php

namespace App\Modules\Acquisition\Registration;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

final class PurposeThrottle extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 'context', $decayMinutes = 1, $prefix = '')
    {
        $purpose = $maxAttempts;
        if (! in_array($purpose, ['context', 'preferences'], true)) {
            throw new \LogicException('Invalid R1 throttle purpose.');
        }
        $prefix = config('h5.workspace', 'h5').'|acquisition.r1|'.$purpose;
        $ip = hash('sha256', $prefix.'|ip|'.$request->ip());
        $limits = $purpose === 'context' ? [$this->limit($ip, 120)] : [
            $this->limit(hash('sha256', $prefix.'|session|'.($request->hasSession() ? $request->session()->getId() : $request->ip())), 30),
            $this->limit($ip, 180),
        ];

        return $this->handleRequest($request, $next, $limits);
    }

    private function limit(string $key, int $attempts): object
    {
        return (object) ['key' => $key, 'maxAttempts' => $attempts, 'decaySeconds' => 60, 'afterCallback' => null, 'responseCallback' => null];
    }
}
