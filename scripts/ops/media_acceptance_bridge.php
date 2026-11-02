<?php

// Thin operational adapter only. No Laravel bootstrap without the approved supervisor.
namespace H5MediaAcceptance;

const SOURCE = '57a658ef0c8f29842047aca94572bec784bfae37';
const RELEASE = '/srv/h5/releases/'.SOURCE;
const APP = RELEASE.'/h5-platform';
const OPS = '/srv/h5/ops/media-acceptance';
const STATE = '/srv/h5/shared/ops/media-acceptance';
const BINDING = '/etc/h5/media-acceptance-window.json';

function ensure(bool $ok, string $code): void
{
    if (! $ok) {
        throw new \LogicException($code);
    }
}

function instant(mixed $value): int
{
    ensure(is_string($value) && preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D', $value) === 1, 'WINDOW_TIME_INVALID');
    $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
    ensure($time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value, 'WINDOW_TIME_INVALID');
    return $time->getTimestamp();
}

function bindingPolicy(array $b, int $now): void
{
    // This check is deliberately before autoload/Kernel, including the default false.
    ensure(($b['schema'] ?? null) === 'h5-media-acceptance-v1' && ($b['approved'] ?? null) === true
        && is_string($b['approval_reference'] ?? null) && strlen($b['approval_reference']) >= 1, 'DIRECT_APPROVAL_REQUIRED');
    $start = instant($b['not_before'] ?? null);
    $end = instant($b['expires_at'] ?? null);
    ensure($start <= $now && $now < $end && $end - $start > 0 && $end - $start <= 2700
        && ($b['max_assets'] ?? null) === 6, 'WINDOW_EXPIRED_OR_INVALID');
    foreach (['owner_id', 'window_id'] as $key) {
        ensure(is_string($b[$key] ?? null) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $b[$key]) === 1, 'IDENTITY_BINDING_INVALID');
    }
    ensure(($b['source_sha'] ?? null) === SOURCE && ($b['release_root'] ?? null) === RELEASE
        && ($b['workspace'] ?? null) === 'h5_cloud_staging' && ($b['database'] ?? null) === 'h5_staging'
        && ($b['media_root'] ?? null) === '/srv/h5/shared/media', 'STAGING_BINDING_INVALID');
    ensure(is_array($b['fixtures'] ?? null) && count($b['fixtures']) === 3, 'FIXTURE_BINDING_INVALID');
    $kinds = [];
    foreach ($b['fixtures'] as $f) {
        ensure(is_array($f) && in_array($f['kind'] ?? null, ['video', 'subtitle', 'cover'], true)
            && is_string($f['extension'] ?? null) && is_string($f['filename'] ?? null)
            && preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/D', $f['filename']) === 1
            && str_ends_with($f['filename'], '.'.$f['extension'])
            && is_int($f['bytes'] ?? null) && $f['bytes'] > 0 && $f['bytes'] <= 16777216
            && is_string($f['sha256'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/D', $f['sha256']) === 1, 'FIXTURE_BINDING_INVALID');
        ensure(in_array($f['extension'], match ($f['kind']) {
            'video' => ['mp4'], 'subtitle' => ['srt'], 'cover' => ['png', 'jpg', 'jpeg'],
        }, true), 'FIXTURE_BINDING_INVALID');
        $kinds[] = $f['kind'];
    }
    sort($kinds);
    ensure($kinds === ['cover', 'subtitle', 'video'], 'FIXTURE_BINDING_INVALID');
}

function assetPolicy(array $b, array $a, int $now, bool $forProcess = false, bool $verifyFile = true): void
{
    bindingPolicy($b, $now);
    ensure(is_string($a['id'] ?? null) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $a['id']) === 1, 'ASSET_ID_INVALID');
    $created = instant($a['created_at'] ?? null);
    ensure(instant($b['not_before']) < $created && $created <= $now && $created < instant($b['expires_at']), 'OLD_OR_OUTSIDE_WINDOW_ASSET');
    ensure(($a['created_by'] ?? null) === $b['owner_id'], 'OTHER_OWNER_ASSET');
    $matches = array_values(array_filter($b['fixtures'], fn ($f) => [$f['kind'], $f['extension'], $f['filename'], $f['bytes']]
        === [$a['kind'] ?? null, $a['extension'] ?? null, $a['filename'] ?? null, $a['expected_bytes'] ?? null]));
    ensure(count($matches) === 1, 'UNKNOWN_FILE_SCOPE');
    $f = $matches[0];
    ensure(($a['source_key'] ?? null) === 'assets/'.$a['id'].'/source.'.$f['extension'], 'ASSET_PATH_INVALID');
    if (($a['state'] ?? null) === 'uploading' && ! $forProcess) {
        ensure(($a['attempts'] ?? null) === 0 && is_int($a['received_bytes'] ?? null)
            && $a['received_bytes'] >= 0 && $a['received_bytes'] <= $f['bytes'], 'UPLOAD_STATE_INVALID');
        return;
    }
    ensure(in_array($a['state'] ?? null, $forProcess ? ['pending'] : ['pending', 'ready'], true), 'ASSET_STATE_INVALID');
    ensure(! $forProcess || ($a['attempts'] ?? null) === 0, 'ASSET_ALREADY_ATTEMPTED');
    if (! $verifyFile) {
        return;
    }
    ensure(($a['received_bytes'] ?? null) === $f['bytes'] && ($a['observed_bytes'] ?? null) === $f['bytes']
        && ($a['sha256'] ?? null) === $f['sha256'] && ($a['observed_sha256'] ?? null) === $f['sha256'], 'UNKNOWN_FILE_HASH_OR_SIZE');
}

function ownerPolicy(array $b, ?array $owner): void
{
    ensure($owner !== null && ($owner['id'] ?? null) === $b['owner_id']
        && ($owner['active'] ?? null) === true && ($owner['role'] ?? null) === 'owner'
        && ($owner['has_mfa'] ?? null) === true, 'OWNER_OR_MFA_NOT_READY');
}

function privateState(string $path): array
{
    clearstatcache(true, $path);
    ensure(! is_link($path) && realpath($path) === $path && is_file($path)
        && fileowner($path) === posix_geteuid() && (fileperms($path) & 0777) === 0600, 'PRIVATE_LEDGER_INVALID');
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function supervisor(array $b, string $raw, string $action, mixed $id): void
{
    $path = STATE.'/'.$b['window_id'].'.json';
    $s = privateState($path);
    ensure(($s['window_id'] ?? null) === $b['window_id'] && ($s['binding_sha256'] ?? null) === hash('sha256', $raw)
        && ($s['supervisor_pid'] ?? null) === posix_getppid() && ($s['status'] ?? null) === 'running'
        && is_array($s['claimed'] ?? null) && count($s['claimed']) <= 6
        && count(array_unique($s['claimed'])) === count($s['claimed']), 'SUPERVISOR_OR_QUOTA_INVALID');
    ensure(! is_link(STATE.'/window.lock') && is_file(STATE.'/window.lock'), 'SUPERVISOR_LOCK_REQUIRED');
    $lock = fopen(STATE.'/window.lock', 'r+');
    ensure($lock !== false, 'SUPERVISOR_LOCK_REQUIRED');
    if (flock($lock, LOCK_EX | LOCK_NB)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new \LogicException('SUPERVISOR_LOCK_REQUIRED');
    }
    fclose($lock);
    ensure(! file_exists(STATE.'/STOP'), 'STOP_REQUESTED');
    if ($action === 'process') {
        ensure(is_string($id) && ($s['in_flight'] ?? null) === $id && in_array($id, $s['claimed'], true), 'PRECONSUMED_SLOT_REQUIRED');
    } else {
        ensure(($s['in_flight'] ?? null) === null && count($s['claimed']) < 6, 'INSPECTION_STATE_INVALID');
    }
}

function snapshot(array $b, object $asset, object $media): array
{
    $a = ['id' => $asset->id, 'created_by' => $asset->created_by, 'kind' => $asset->kind,
        'extension' => $asset->extension, 'filename' => ($asset->metadata ?? [])['original_filename'] ?? null,
        'created_at' => $asset->created_at->utc()->format('Y-m-d\TH:i:s\Z'), 'state' => $asset->state,
        'expected_bytes' => (int) $asset->expected_bytes, 'received_bytes' => (int) $asset->received_bytes,
        'attempts' => (int) $asset->attempts, 'source_key' => $asset->source_key, 'sha256' => $asset->sha256];
    assetPolicy($b, $a, time(), false, false); // Scope before reading any source bytes.
    // Never follow a source symlink. Existing MediaStore::path also checks ancestors.
    if (in_array($asset->state, ['pending', 'ready'], true)) {
        $path = $media->path((string) $asset->source_key);
        clearstatcache(true, $path);
        ensure(is_file($path) && ! is_link($path) && filesize($path) <= 16777216, 'SOURCE_FILE_INVALID');
        $a['observed_bytes'] = filesize($path);
        $a['observed_sha256'] = hash_file('sha256', $path);
    }
    return $a;
}

function main(array $argv): int
{
    if ($argv !== [__FILE__, '--execute']) {
        echo json_encode(['started' => false, 'reason' => 'DEFAULT_DISABLED']), "\n";
        return 0;
    }
    try {
        $request = json_decode(stream_get_contents(STDIN, 16385), true, flags: JSON_THROW_ON_ERROR);
        ensure(is_array($request) && is_array($request['binding'] ?? null), 'BINDING_REQUIRED');
        $b = $request['binding'];
        bindingPolicy($b, time()); // No autoload, DB or media access before direct approval.
        ensure(PHP_OS_FAMILY === 'Linux' && posix_getpwuid(posix_geteuid())['name'] === 'h5', 'H5_LINUX_USER_REQUIRED');
        clearstatcache(true, BINDING);
        ensure(! is_link(BINDING) && realpath(BINDING) === BINDING && fileowner(BINDING) === 0
            && filegroup(BINDING) === posix_getegid() && (fileperms(BINDING) & 0777) === 0640
            && filesize(BINDING) <= 16384, 'BINDING_PERMISSIONS_INVALID');
        $raw = file_get_contents(BINDING);
        ensure(json_decode($raw, true, flags: JSON_THROW_ON_ERROR) === $b, 'BINDING_CHANGED');
        $action = $request['action'] ?? null;
        ensure(in_array($action, ['inspect', 'process'], true), 'ACTION_INVALID');
        $id = $request['asset_id'] ?? null;
        supervisor($b, $raw, $action, $id);
        $frozen = ['env' => '/srv/h5/shared/.env', 'config' => APP.'/bootstrap/cache/config.php',
            'lock' => APP.'/composer.lock', 'media_store' => APP.'/app/Modules/Content/Services/MediaStore.php',
            'runner' => OPS.'/media_acceptance_window.py', 'bridge' => OPS.'/media_acceptance_bridge.php'];
        ensure(is_array($b['frozen_sha256'] ?? null) && count($b['frozen_sha256']) === 6, 'FROZEN_HASHES_REQUIRED');
        foreach ($frozen as $key => $file) {
            ensure(! is_link($file) && realpath($file) === $file && is_file($file)
                && hash_file('sha256', $file) === ($b['frozen_sha256'][$key] ?? null), 'FROZEN_FILE_CHANGED');
        }
        ensure(getcwd() === APP && realpath(APP) === APP && __FILE__ === OPS.'/media_acceptance_bridge.php', 'WORKING_DIRECTORY_OR_BRIDGE_INVALID');
        require APP.'/vendor/autoload.php';
        $app = require APP.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        app(\App\Support\RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        ensure(app()->environment('staging') && \Illuminate\Support\Env::get('APP_ENV') === 'staging'
            && app()->configurationIsCached() && config('h5.controlled_staging') === true
            && config('h5.workspace') === 'h5_cloud_staging' && config('h5.media_root') === $b['media_root']
            && config('database.default') === 'pgsql', 'RUNTIME_BINDING_INVALID');
        $db = config('database.connections.pgsql');
        ensure(($db['host'] ?? null) === '127.0.0.1' && (string) ($db['port'] ?? '') === '5432'
            && ($db['database'] ?? null) === 'h5_staging' && ($db['username'] ?? null) === 'h5_staging'
            && ($db['url'] ?? null) === null, 'DATABASE_BINDING_INVALID');
        ensure(config('billing.driver') === 'unconfigured' && config('billing.new_purchase_provider') === 'unconfigured'
            && config('billing.paypal.enabled') === false && config('h5.acquisition_staging_test') === false
            && config('h5.measurement_c_experience') === false && config('h5.measurement_c_registration') === false,
            'CLOSED_FEATURES_CHANGED');
        $owner = \App\Modules\Identity\Models\Staff::find($b['owner_id']);
        ownerPolicy($b, $owner ? ['id' => $owner->id, 'active' => $owner->active,
            'role' => $owner->role->value, 'has_mfa' => filled($owner->getAppAuthenticationSecret())] : null);
        $media = app(\App\Modules\Content\Services\MediaStore::class);
        // Never processNext(): old pending rows are not candidates. Seven is a stop sentinel.
        $assets = \App\Modules\Content\Models\Asset::where('created_at', '>', new \DateTimeImmutable($b['not_before']))
            ->orderBy('created_at')->orderBy('id')->limit(7)->get();
        ensure($assets->count() <= 6, 'NEW_UPLOAD_QUOTA_EXCEEDED');
        $rows = [];
        foreach ($assets as $asset) {
            $a = snapshot($b, $asset, $media);
            assetPolicy($b, $a, time());
            $rows[] = $a;
        }
        if ($action === 'inspect') {
            echo json_encode(['ok' => true, 'owner_ok' => true, 'assets' => $rows], JSON_THROW_ON_ERROR), "\n";
            return 0;
        }
        ensure($assets->contains('id', $id), 'OLD_OR_UNKNOWN_ASSET');
        // Fresh read + real file hash immediately before the ID-specific existing call.
        $asset = \App\Modules\Content\Models\Asset::findOrFail($id);
        $a = snapshot($b, $asset, $media);
        assetPolicy($b, $a, time(), true);
        supervisor($b, $raw, $action, $id);
        ensure(file_get_contents(BINDING) === $raw, 'BINDING_CHANGED');
        $result = $media->process($id);
        ensure($result->state === 'ready' && (int) $result->attempts === 1, 'MEDIA_NOT_READY_REVIEW_REQUIRED');
        ensure($result->kind !== 'video' || $media->playable($result), 'MEDIA_OUTPUT_INVALID');
        echo json_encode(['ok' => true, 'asset_id' => $id, 'state' => $result->state, 'attempts' => (int) $result->attempts], JSON_THROW_ON_ERROR), "\n";
        return 0;
    } catch (\Throwable $error) {
        $code = preg_match('/\A[A-Z0-9_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'MEDIA_WINDOW_REJECTED';
        echo json_encode(['ok' => false, 'reason' => $code]), "\n";
        return 42;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main($argv));
}
