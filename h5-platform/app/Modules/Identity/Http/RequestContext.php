<?php

namespace App\Modules\Identity\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RequestContext
{
    public function handle(Request $request, Closure $next)
    {
        // Server-generated IDs prevent forged correlation and accidental PII in logs.
        $request->attributes->set('request_id', (string) Str::uuid7());
        Log::withContext(['request_id' => $request->attributes->get('request_id')]);
        $staff = $request->is('admin', 'admin/*', 'api/v1/admin/*', 'livewire/*');
        $selected = $request->query('lang');
        $preferred = str_starts_with(strtolower($request->getLanguages()[0] ?? 'en'), 'zh') ? 'zh_CN' : 'en';
        $locale = in_array($selected, ['zh_CN', 'en'], true) ? $selected : $request->cookie(config('h5.workspace').'_locale', $preferred);
        app()->setLocale($staff ? 'zh_CN' : (in_array($locale, ['zh_CN', 'en'], true) ? $locale : 'en'));
        $request->attributes->set('identity_guard', $staff ? 'staff' : 'web');
        config(['session.cookie' => config('h5.workspace').($staff ? '_admin' : '_viewer'),
            'session.lifetime' => $staff ? 30 : 120,
            'session.secure' => app()->environment('production') || config('session.secure'),
            'session.http_only' => true, 'session.same_site' => 'lax', 'session.encrypt' => true]);
        $response = $next($request);
        if (in_array($selected, ['zh_CN', 'en'], true)) {
            $response->headers->setCookie(cookie(config('h5.workspace').'_locale', $selected, 525600, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        $response->headers->set('X-Request-ID', $request->attributes->get('request_id'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        if ($request->is('api/*', 'identity/*', 'admin*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
