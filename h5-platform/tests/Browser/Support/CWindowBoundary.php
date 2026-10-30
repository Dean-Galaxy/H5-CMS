<?php

namespace Tests\Browser\Support;

/** Additional test-window boundary; application CSRF/permissions/media guards remain active. */
final class CWindowBoundary
{
    public static function allows(string $method, string $path): bool
    {
        if (preg_match('#^/(admin|webhooks|billing|membership|api/v1/(auth|billing|admin))(/|$)#D', $path)) {
            return false;
        }
        if (in_array($method, ['GET', 'HEAD'], true)) { return true; }
        if (in_array($method, ['POST', 'PATCH'], true)
            && in_array($path, ['/api/v2/measurement/start', '/api/v2/measurement/preferences', '/api/v2/measurement/events',
                '/api/v1/tracking/consent', '/api/v1/tracking/landing', '/api/v1/tracking/events'], true)) {
            return true;
        }
        $uuid = '[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}';
        return $method === 'POST' && (bool) preg_match('#^(/api/v1/episodes/'.$uuid.'/playback-grants|/audience/dramas/'.$uuid.'/episodes/'.$uuid.'/playback)$#D', $path);
    }
}
