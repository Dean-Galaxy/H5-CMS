<?php

namespace App\Modules\Acquisition\Sending;

final class Credentials
{
    /** Credentials are never rendered in Filament, accepted in a browser form, or written to delivery logs. */
    public function forDelivery(object $delivery): ?array
    {
        $path = config('acquisition.credentials_file');
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $real = realpath($path);
        if (! $real || str_starts_with($real, public_path().DIRECTORY_SEPARATOR) || (fileperms($real) & 0077) !== 0 || filesize($real) > 65536) {
            return null;
        }
        $map = json_decode(file_get_contents($real), true);
        $value = is_array($map) ? ($map[$delivery->pixel_id.':'.$delivery->pixel_version] ?? null) : null;
        if (! is_array($value) || ($value['environment'] ?? null) !== $delivery->environment
            || ($value['external_id'] ?? null) !== $delivery->external_id || ($value['platform'] ?? null) !== $delivery->platform
            || ! is_string($value['token'] ?? null) || $value['token'] === '' || strlen($value['token']) > 4096) {
            return null;
        }
        if ($delivery->environment === 'test' && (! is_string($value['test_event_code'] ?? null) || $value['test_event_code'] === '')) {
            return null;
        }

        return $value;
    }
}
