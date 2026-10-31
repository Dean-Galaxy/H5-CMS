<?php

namespace App\Support;

use MaxMind\Db\Reader;
use Throwable;

/** Local country-only lookup. No IP logging, remote fallback or forwarding-header input. */
final class DbIpCountryLookup
{
    private const MAX_BYTES = 64 * 1024 * 1024;

    public function countryFor(mixed $peer): ?string
    {
        // Do not stat/open anything when disabled or when the direct peer is not public.
        if (config('geoip.driver') !== 'dbip_lite' || ! is_string($peer)
            || filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false) {
            return null;
        }
        $path = config('geoip.database');
        $days = config('geoip.max_age_days');
        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || ! (is_int($days) || (is_string($days) && preg_match('/\A[1-9][0-9]?\z/D', $days) === 1))
            || (int) $days < 1 || (int) $days > 45) {
            return null;
        }
        $reader = null;
        try {
            clearstatcache(true, $path);
            $real = @realpath($path);
            $public = realpath(public_path());
            if ($real !== $path || $public === false || $path === $public || str_starts_with($path, $public.'/')) {
                return null;
            }
            $before = @lstat($path);
            if (! is_array($before) || ($before['mode'] & 0170000) !== 0100000
                || ($before['mode'] & 0222) !== 0 || $before['size'] < 1 || $before['size'] > self::MAX_BYTES || ! is_readable($path)) {
                return null;
            }
            $reader = new Reader($path);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (! is_array($after) || array_intersect_key($before, array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']))
                !== array_intersect_key($after, array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']))) {
                return null;
            }
            $metadata = $reader->metadata();
            $now = now()->getTimestamp();
            if ($metadata->binaryFormatMajorVersion !== 2 || ! in_array($metadata->ipVersion, [4, 6], true)
                || ! is_int($metadata->buildEpoch) || $metadata->buildEpoch > $now
                || $metadata->buildEpoch < $now - $days * 86400
                || ! is_string($metadata->databaseType)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._-]{0,79}\z/D', $metadata->databaseType) !== 1
                || preg_match('/(?:\A|[^A-Za-z0-9])Country(?:\z|[^A-Za-z0-9])/i', $metadata->databaseType) !== 1
                || preg_match('/City|ASN/i', $metadata->databaseType) === 1) {
                return null;
            }
            $record = $reader->get($peer);
            $country = is_array($record) ? ($record['country']['iso_code'] ?? null) : null;

            return is_string($country) && preg_match('/\A[A-Z]{2}\z/D', $country) === 1 && $country !== 'ZZ' ? $country : null;
        } catch (Throwable) {
            return null; // No paths, raw exceptions or visitor IPs escape into logs/responses.
        } finally {
            if ($reader !== null) {
                try {
                    $reader->close();
                } catch (Throwable) {
                    // A failing reader never authorizes a country or triggers an external fallback.
                }
            }
        }
    }
}
