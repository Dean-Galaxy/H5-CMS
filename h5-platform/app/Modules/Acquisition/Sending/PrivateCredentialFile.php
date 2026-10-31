<?php

namespace App\Modules\Acquisition\Sending;

use Illuminate\Support\Str;

/** Local POSIX private store. Cooperating writers lock a stable sidecar across atomic renames. */
final class PrivateCredentialFile
{
    private const MAX_BYTES = 65536;

    private function fail(string $reason): never
    {
        throw new CredentialFileException($reason);
    }

    /** Existing private directory only: no mkdir, Git traversal, wrapper, symlink or foreign-writable parent. */
    private function path(): array
    {
        $path = config('acquisition.credentials_file');
        if (! is_string($path) || ! str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f]/', $path)
            || array_intersect(explode('/', $path), ['.', '..']) || str_contains($path, '//') || str_ends_with($path, '/')) {
            $this->fail('PATH_UNSAFE');
        }
        $public = rtrim(public_path(), '/');
        if ($path === $public || str_starts_with($path, $public.'/')) {
            $this->fail('PATH_UNSAFE');
        }
        $dirs = [];
        for ($dir = dirname($path); ; $dir = dirname($dir)) {
            clearstatcache(true, $dir);
            $st = @lstat($dir);
            if (! $st || ($st['mode'] & 0170000) !== 0040000 || ! in_array($st['uid'], [0, posix_geteuid()], true)
                || @lstat($dir.'/.git') !== false || basename($dir) === '.git'
                || (is_file($dir.'/HEAD') && is_dir($dir.'/objects') && is_dir($dir.'/refs'))) {
                $this->fail('PATH_UNSAFE');
            }
            // A root-owned sticky shared ancestor (e.g. /private/tmp) is safe only above a private 0700 leaf.
            $stickyRoot = $st['uid'] === 0 && ($st['mode'] & 01000) !== 0;
            if (($st['mode'] & 0022) !== 0 && ! $stickyRoot) {
                $this->fail('PATH_UNSAFE');
            }
            if ($dir === dirname($path) && (($st['mode'] & 07777) !== 0700 || $st['uid'] !== posix_geteuid())) {
                $this->fail('PATH_UNSAFE');
            }
            $dirs[$dir] = [$st['dev'], $st['ino'], $st['mode'], $st['uid']];
            if ($dir === '/') {
                break;
            }
        }

        return [$path, $dirs];
    }

    private function sameDirectories(array $expected): void
    {
        [, $actual] = $this->path();
        if ($actual !== $expected) {
            $this->fail('PATH_CHANGED');
        }
    }

    private function regular(string $path, bool $missing = false): ?array
    {
        clearstatcache(true, $path);
        $s = @lstat($path);
        if ($s === false && $missing) {
            return null;
        }
        if (! $s || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 07777) !== 0600
            || $s['uid'] !== posix_geteuid() || $s['nlink'] !== 1 || $s['size'] > self::MAX_BYTES) {
            $this->fail($s === false ? 'FILE_MISSING' : 'FILE_UNSAFE');
        }

        return $s;
    }

    private function sameFile(array $a, array|false $b): void
    {
        if (! $b || array_intersect_key($a, array_flip(['dev', 'ino', 'mode', 'uid', 'nlink'])) !== array_intersect_key($b, array_flip(['dev', 'ino', 'mode', 'uid', 'nlink']))) {
            $this->fail('PATH_CHANGED');
        }
    }

    public function read(): array
    {
        [$path, $dirs] = $this->path();
        $st = $this->regular($path);
        $h = @fopen($path, 'rb');
        if (! $h) {
            $this->fail('FILE_UNREADABLE');
        }
        try {
            // Check the opened inode before reading bytes, then verify its path has not been replaced.
            $this->sameFile($st, fstat($h));
            $this->sameDirectories($dirs);
            $this->sameFile($st, $this->regular($path));
            $raw = stream_get_contents($h, self::MAX_BYTES + 1);
            $this->sameFile($st, $this->regular($path));
            $this->sameDirectories($dirs);
        } finally {
            fclose($h);
        }
        try {
            $map = is_string($raw) && strlen($raw) <= self::MAX_BYTES ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $map = null;
        }
        if (! is_array($map) || ($map !== [] && array_is_list($map))) {
            $this->fail('FILE_INVALID');
        }

        return $map;
    }

    public function preflight(): void
    {
        [$path] = $this->path();
        if (! is_writable(dirname($path))) {
            $this->fail('PATH_UNSAFE');
        }
        if ($this->regular($path, true)) {
            $this->read();
        }
        $this->regular($path.'.lock', true);
    }

    public function save(object $asset, string $token, ?string $testCode): void
    {
        if (! Str::isUuid($asset->pixel_id ?? '') || ! is_int($asset->pixel_version ?? null) || $asset->pixel_version < 1
            || ! in_array($asset->platform ?? '', ['meta', 'tiktok'], true) || ! in_array($asset->environment ?? '', ['test', 'production'], true)
            || ! is_string($asset->external_id ?? null)
            || ! preg_match($asset->platform === 'meta' ? '/^[0-9]{5,30}$/D' : '/^[A-Za-z0-9_-]{5,64}$/D', $asset->external_id)
            || ! self::secretValid($token, 4096) || ($asset->environment === 'test' && ! self::secretValid($testCode, 256))
            || ($testCode !== null && ! self::secretValid($testCode, 256))) {
            $this->fail('VALUE_INVALID');
        }
        [$path, $dirs] = $this->path();
        $this->regular($path, true);
        $lockPath = $path.'.lock';
        $lock = $this->openLock($lockPath);
        $tmp = null;
        try {
            $deadline = microtime(true) + 5;
            while (! @flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    $this->fail('FILE_BUSY');
                }
                usleep(25000);
            }
            $this->sameDirectories($dirs);
            $this->sameFile(fstat($lock), $this->regular($lockPath));
            $map = $this->regular($path, true) ? $this->read() : [];
            $map[$asset->pixel_id.':'.$asset->pixel_version] = ['platform' => $asset->platform, 'external_id' => $asset->external_id,
                'environment' => $asset->environment, 'token' => $token] + ($testCode === null ? [] : ['test_event_code' => $testCode]);
            $json = json_encode((object) $map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > self::MAX_BYTES) {
                $this->fail('FILE_TOO_LARGE');
            }
            $tmp = dirname($path).'/.credential-'.bin2hex(random_bytes(16));
            $mask = umask(0077);
            try {
                $h = @fopen($tmp, 'x+b');
            } finally {
                umask($mask);
            }
            if (! $h) {
                $this->fail('WRITE_FAILED');
            }
            try {
                for ($offset = 0; $offset < strlen($json); $offset += $n) {
                    $n = @fwrite($h, substr($json, $offset));
                    if (! is_int($n) || $n < 1) {
                        $this->fail('WRITE_FAILED');
                    }
                }
                if (! @fflush($h) || ! @fsync($h)) {
                    $this->fail('WRITE_FAILED');
                }
                $written = fstat($h);
                $this->sameFile($written, $this->regular($tmp));
                $this->sameDirectories($dirs);
                $this->regular($path, true);
                if (! @rename($tmp, $path)) {
                    $this->fail('WRITE_FAILED');
                }
                $tmp = null;
                $this->sameFile($written, $this->regular($path));
            } finally {
                fclose($h);
            }
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function openLock(string $path)
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $st = $this->regular($path, true);
            $mask = umask(0077);
            try {
                $h = @fopen($path, $st ? 'r+b' : 'x+b');
            } finally {
                umask($mask);
            }
            if ($h) {
                try {
                    $this->sameFile($this->regular($path), fstat($h));

                    return $h;
                } catch (\Throwable $e) {
                    fclose($h);
                    throw $e;
                }
            }
        }
        $this->fail('FILE_BUSY');
    }

    public static function secretValid(mixed $value, int $limit): bool
    {
        return is_string($value) && strlen($value) >= 1 && strlen($value) <= $limit && ! preg_match('/[^\x21-\x7e]/', $value);
    }
}
