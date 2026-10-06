<?php

namespace App\Modules\Identity\Services;

final class SafeReturn
{
    public function path(?string $input): string
    {
        $fallback = '/identity/account';
        if (! $input || strlen($input) > 1024) {
            return $fallback;
        }
        $decoded = $input;
        for ($i = 0; $i < 4; $i++) {
            $decoded = rawurldecode($decoded);
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) || str_contains($decoded, '%') || str_starts_with($decoded, '//')) {
            return $fallback;
        }
        $parts = parse_url($decoded);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme']) || isset($parts['user'])) {
            return $fallback;
        }
        $path = $parts['path'] ?? '';
        if (! preg_match('#^/(?:$|identity/account$|dramas/[a-zA-Z0-9-]+(?:/episodes/[a-zA-Z0-9-]+)?$|watch/[a-zA-Z0-9-]+$|membership$|billing/return$)#', $path)) {
            return $fallback;
        }

        return $decoded;
    }

    public function remember(?string $path): void
    {
        if ($path !== null) {
            request()->session()->put('identity.return_to', $this->path($path));
        }
    }

    public function current(): string
    {
        return $this->path(request()->session()->get('identity.return_to'));
    }
}
