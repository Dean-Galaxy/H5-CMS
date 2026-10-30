<?php
namespace Tests\Browser\Support;

/** 06 test-router-only storage failure. Not loaded by an application provider or normal entry point. */
final class CWindowStorageFault
{
    public static function matches(array $record, string $candidate, string $token, int $now): bool
    {
        return preg_match('/^[a-f0-9]{40}$/D', $candidate) === 1
            && ($record['candidate'] ?? null) === $candidate
            && preg_match('/^[a-f0-9]{64}$/D', $token) === 1
            && is_string($record['nonce'] ?? null) && hash_equals($record['nonce'], $token)
            && is_int($record['expires_at'] ?? null) && $record['expires_at'] > $now && $record['expires_at'] <= $now + 180;
    }

    public static function install(\Illuminate\Http\Request $request): void
    {
        if ($request->method() !== 'PATCH' || $request->getPathInfo() !== '/api/v2/measurement/preferences'
            || getenv('H5_C_WINDOW_ONLY') !== '1') { return; }
        $file = AnalyticsRuntime::directory().'/c-store-fault.json';
        if (! file_exists($file)) { return; }
        $record = AnalyticsRuntime::document('c-store-fault.json');
        $candidate = (string) getenv('H5_ANALYTICS_BROWSER_CANDIDATE');
        $token = (string) $request->header('X-06-C-Fault', '');
        if (! self::matches($record, $candidate, $token, time())) { return; }
        $fired = false;
        \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $sql) use (&$fired, $candidate, $token): void {
            if ($fired || ! preg_match('/^\s*(insert\s+into|update)\s+"?acquisition_c_preferences"?\b/i', $sql)) { return; }
            $fired = true;
            try {
                // Real PostgreSQL error inside the preference write transaction; no DDL/table renaming or data rewriting.
                \Illuminate\Support\Facades\DB::statement('SELECT 1 / 0');
            } catch (\Illuminate\Database\QueryException $e) {
                AnalyticsRuntime::write('c-store-fault-receipt.json', ['candidate' => $candidate,
                    'nonce_sha256' => hash('sha256', $token), 'sqlstate' => $e->errorInfo[0] ?? null,
                    'fault' => '06-controlled-postgres-write-path-failure']);
                throw $e; // The unmodified CoreController must roll back and return its real 503.
            }
        });
    }
}
