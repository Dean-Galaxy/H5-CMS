<?php

namespace App\Modules\Acquisition\Http;

use App\Contracts\AuditRecorder;
use App\Enums\Role;
use App\Modules\Acquisition\Sending\CredentialFileException;
use App\Modules\Acquisition\Sending\PrivateCredentialFile;
use App\Modules\Acquisition\Services\AssetAccess;
use App\Support\ApiProblem;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Sensitive JSON only. Never validate via exceptions/redirects or return old input. */
final class PixelCredentialsController
{
    public function __invoke(Request $r)
    {
        $reply = static fn (string $code, int $status) => response()->json(['code' => $code], $status)->header('Cache-Control', 'no-store, private');
        try {
            $actor = app(AssetAccess::class)->actor();
            if ($actor->role !== Role::Owner) {
                return $reply('CREDENTIAL_ACCESS_DENIED', 403);
            }
            if (! app()->environment('local', 'testing')) {
                app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
            }
            $origin = rtrim(config('app.url'), '/');
            if (! config('h5.modules.acquisition.ready') || ! $r->isSecure() || $r->getSchemeAndHttpHost() !== $origin
                || $r->header('Origin') !== $origin || ! in_array($r->header('Sec-Fetch-Site'), [null, 'same-origin'], true)) {
                return $reply('CREDENTIAL_ORIGIN_REQUIRED', 403);
            }
            if (! $r->isJson() || strlen($r->getContent()) > 8192) {
                return $reply('CREDENTIAL_INPUT_INVALID', 422);
            }
            $v = json_decode($r->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($v) || array_diff(array_keys($v), ['pixel_id', 'version', 'token', 'test_event_code', 'reuse_previous'])
                || ! is_string($v['pixel_id'] ?? null) || ! Str::isUuid($v['pixel_id']) || ! is_int($v['version'] ?? null) || $v['version'] < 1
                || ! is_string($v['token'] ?? null) || ! is_string($v['test_event_code'] ?? null) || ! is_bool($v['reuse_previous'] ?? null)
                || ($v['token'] !== '' && ! PrivateCredentialFile::secretValid($v['token'], 4096))
                || ($v['test_event_code'] !== '' && ! PrivateCredentialFile::secretValid($v['test_event_code'], 256))) {
                return $reply('CREDENTIAL_INPUT_INVALID', 422);
            }
            $code = DB::transaction(function () use ($v) {
                $pixel = app(AssetAccess::class)->get('pixel', $v['pixel_id'], true);
                if ((int) $pixel->version !== $v['version']) {
                    return 'CREDENTIAL_VERSION_CONFLICT';
                }
                $version = DB::table('acquisition_pixel_versions')->where('pixel_id', $pixel->id)->where('version', $pixel->version)->first();
                if (! $version || $version->platform !== $pixel->platform || $version->external_id !== $pixel->external_id || $version->environment !== $pixel->environment) {
                    return 'CREDENTIAL_VERSION_CONFLICT';
                }
                if ($pixel->environment !== 'test' && $v['test_event_code'] !== '') {
                    return 'CREDENTIAL_INPUT_INVALID';
                }
                $store = app(PrivateCredentialFile::class);
                $store->preflight();
                // Missing file is valid for a first write. All existing files still go through the original safe reader.
                try {
                    $map = $store->read();
                } catch (CredentialFileException $e) {
                    if ($e->reason !== 'FILE_MISSING') {
                        throw $e;
                    }
                    $map = [];
                }
                $match = static fn ($x) => is_array($x) && ($x['platform'] ?? null) === $pixel->platform
                    && ($x['external_id'] ?? null) === $pixel->external_id && ($x['environment'] ?? null) === $pixel->environment;
                $old = $map[$pixel->id.':'.$pixel->version] ?? null;
                if ($old !== null && ! $match($old)) {
                    return 'CREDENTIAL_VERSION_CONFLICT';
                }
                if ($old === null && $v['reuse_previous']) {
                    // Explicit Owner reauthorization only, never an automatic version fallback in the sender.
                    $versions = DB::table('acquisition_pixel_versions')->where('pixel_id', $pixel->id)->where('version', '<', $pixel->version)
                        ->where('platform', $pixel->platform)->where('external_id', $pixel->external_id)->where('environment', $pixel->environment)
                        ->orderByDesc('version')->pluck('version');
                    foreach ($versions as $previous) {
                        $candidate = $map[$pixel->id.':'.$previous] ?? null;
                        if ($match($candidate)) {
                            $old = $candidate;
                            break;
                        }
                    }
                }
                $token = $v['token'] !== '' ? $v['token'] : ($old['token'] ?? null);
                $testCode = $pixel->environment === 'test' ? ($v['test_event_code'] !== '' ? $v['test_event_code'] : ($old['test_event_code'] ?? null)) : null;
                if (! PrivateCredentialFile::secretValid($token, 4096) || ($pixel->environment === 'test' && ! PrivateCredentialFile::secretValid($testCode, 256))) {
                    return 'CREDENTIAL_VALUE_REQUIRED';
                }
                $store->save((object) ['pixel_id' => $pixel->id, 'pixel_version' => (int) $pixel->version,
                    'platform' => $pixel->platform, 'external_id' => $pixel->external_id, 'environment' => $pixel->environment], $token, $testCode);
                app(AuditRecorder::class)->record('acquisition.credentials.saved', 'acquisition_pixel', $pixel->id, 'success', ['version' => (int) $pixel->version]);

                return 'CREDENTIAL_SAVED';
            });

            return $reply($code, match ($code) {
                'CREDENTIAL_SAVED' => 200, 'CREDENTIAL_VERSION_CONFLICT' => 409, default => 422
            });
        } catch (ApiProblem $e) {
            return $reply('CREDENTIAL_ACCESS_DENIED', $e->status === 401 ? 401 : 403);
        } catch (\JsonException) {
            return $reply('CREDENTIAL_INPUT_INVALID', 422);
        } catch (Throwable) {
            // No report(), raw exception, request, path or credentials in any response/log/session.
            return $reply('CREDENTIAL_SAVE_UNCONFIRMED', 503);
        }
    }
}
