<?php

namespace App\Modules\Acquisition\Measurement;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/** Separate optional traffic from necessary preference writes; no measurement identity is created. */
final class CoreThrottle extends ThrottleRequests
{
    private const PER_MINUTE = ['context' => 120, 'start' => 60, 'events' => 120, 'preferences' => 30];

    public function handle($request, Closure $next, $maxAttempts = 'context', $decayMinutes = 1, $prefix = '')
    {
        $purpose = $maxAttempts;
        if (! isset(self::PER_MINUTE[$purpose])) {
            throw new \LogicException('Unknown C throttle purpose.');
        }
        $prefix = config('h5.workspace', 'h5').'|acquisition.c|'.$purpose;
        $ipKey = hash('sha256', $prefix.'|ip|'.$request->ip());
        if ($purpose === 'preferences') {
            // StartSession/EncryptCookies supply this server session. Never use a payload, header, or C proof ID.
            $session = $request->hasSession() ? $request->session()->getId() : null;
            $sessionKey = hash('sha256', $prefix.($session ? '|session|'.$session : '|no-session|'.$request->ip()));
            $limits = [$this->limit($sessionKey, 30), $this->limit($ipKey, 180)];
        } else {
            $limits = [$this->limit($ipKey, self::PER_MINUTE[$purpose])];
        }

        // Keep Laravel's existing 429/Retry-After response and shared error renderer. No permission fallback.
        return $this->handleRequest($request, $next, $limits);
    }

    private function limit(string $key, int $attempts): object
    {
        return (object) ['key' => $key, 'maxAttempts' => $attempts, 'decaySeconds' => 60,
            'afterCallback' => null, 'responseCallback' => null];
    }
}
