<?php
/** Precise 06 window restriction, additional to unchanged application CSRF/auth/permissions. */
final class H5R1Boundary
{
    public static function blocked(string $path): bool
    {
        return (bool) preg_match('#^/(admin|membership|billing|webhooks|api/v1/(admin|billing))(/|$)#D', $path);
    }

    public static function normalize(array $server, string $public): array
    {
        $directory = realpath($public);
        if (! $directory || ! is_file($directory.'/index.php')) { throw new LogicException('OWN INDEX REQUIRED'); }
        $server['SCRIPT_NAME'] = '/index.php'; $server['PHP_SELF'] = '/index.php'; $server['SCRIPT_FILENAME'] = $directory.'/index.php';
        return $server;
    }

    public static function staticTarget(string $public, string $uri): ?string
    {
        $directory = realpath($public); $path = parse_url($uri, PHP_URL_PATH);
        if (! $directory || ! is_string($path) || ! str_starts_with($path, '/') || self::blocked($path)) { return null; }
        $target = realpath($directory.$path);
        return $target && str_starts_with($target, $directory.'/') && is_file($target)
            && ! self::blocked(substr($target, strlen($directory)))
            && in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), ['js','css','png','jpg','jpeg','svg','ico','woff','woff2','ttf'], true) ? $target : null;
    }

    public static function allows(string $method, string $path): bool
    {
        $read = in_array($method, ['GET','HEAD'], true);
        if (self::blocked($path)) { return false; }
        $uuid = '[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}';
        if ($read && in_array($path, ['/', '/health', '/catalog','/identity/register','/identity/login','/identity/account','/identity/test-terms','/identity/test-privacy','/identity/forgot-password','/identity/reset-password','/api/v1/auth/csrf','/api/v1/auth/me','/api/v2/measurement/context','/api/v3/measurement/purposes/context'], true)) { return true; }
        if ($read && preg_match('#^(/dramas/'.$uuid.'(/episodes/'.$uuid.')?|/api/v1/content/images/'.$uuid.'|/api/v1/content/playback/'.$uuid.'/(master\.m3u8|index\.m3u8|captions\.m3u8|segment-[0-9]{5}\.ts|media\.mp4|subtitles\.vtt)|/api/v1/auth/email/verify/'.$uuid.'/[a-f0-9]{40})$#D', $path)) { return true; }
        if ($method === 'POST' && in_array($path, ['/api/v1/auth/register','/api/v1/auth/login','/api/v1/auth/logout','/api/v1/auth/email/verification-notification','/api/v1/auth/forgot-password','/api/v1/auth/reset-password','/api/v2/measurement/start','/api/v2/measurement/events'], true)) { return true; }
        if ($method === 'PATCH' && in_array($path, ['/api/v2/measurement/preferences','/api/v3/measurement/purposes/preferences'], true)) { return true; }
        return $method === 'POST' && (bool) preg_match('#^(/api/v1/episodes/'.$uuid.'/playback-grants|/audience/dramas/'.$uuid.'/episodes/'.$uuid.'/playback)$#D', $path);
    }
}
