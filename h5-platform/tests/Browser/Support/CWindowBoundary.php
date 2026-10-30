<?php

namespace Tests\Browser\Support;

/** Additional test-window boundary; application CSRF/permissions/media guards remain active. */
final class CWindowBoundary
{
    public static function normalizeServer(array $server, string $public): array
    {
        $directory = realpath($public);
        if (! $directory || ! is_file($directory.'/index.php')) { throw new \LogicException('PUBLIC INDEX REQUIRED'); }
        // Preserve URI/query, cookies, headers, method and body; only identify the actual front controller.
        $server['SCRIPT_NAME'] = '/index.php';
        $server['PHP_SELF'] = '/index.php';
        $server['SCRIPT_FILENAME'] = $directory.'/index.php';
        return $server;
    }

    public static function staticTarget(string $public, string $requestUri): ?string
    {
        // cli-server may set SCRIPT_NAME to an existing asset; Symfony then reports pathInfo "/".
        $directory = realpath($public);
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (! $directory || ! is_string($path) || ! str_starts_with($path, '/')) { return null; }
        $target = realpath($directory.$path);
        if (! $target || ! str_starts_with($target, $directory.'/') || ! is_file($target)
            || ! in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), ['js','css','png','jpg','jpeg','svg','ico','woff','woff2','ttf'], true)) {
            return null;
        }
        return $target;
    }

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
