<?php

namespace App\Modules\Acquisition\Sending;

use Illuminate\Support\Str;

final class Credentials
{
    /** Only the worker receives a secret. Neither readiness nor a CLI response includes one. */
    public function forDelivery(object $delivery): ?array
    {
        return $this->resolve($delivery)['value'];
    }

    public function readiness(object $asset): array
    {
        $code = $this->resolve($asset)['code'];

        return ['code' => $code, 'label' => match ($code) {
            'CONFIGURED_UNVERIFIED' => '配置可读取；待官方联调', 'VERSION_MISMATCH' => '配置版本不匹配',
            'ASSET_MISMATCH' => '平台、编号或环境不匹配', 'TOKEN_MISSING' => '凭据配置无效', 'TEST_CODE_MISSING' => '缺测试码',
            'FILE_UNSAFE' => '私密文件位置或权限不安全', 'FILE_INVALID' => '私密配置不可读取', default => '配置缺失',
        }, 'platform_confirmation' => 'pending'];
    }

    private function resolve(object $asset): array
    {
        $no = static fn ($code) => ['code' => $code, 'value' => null];
        if (config('acquisition.credentials_file', '') === '') {
            return $no('CONFIG_MISSING');
        }
        try {
            $map = app(PrivateCredentialFile::class)->read();
        } catch (CredentialFileException $e) {
            return $no(match ($e->reason) {
                'FILE_MISSING' => 'CONFIG_MISSING', 'PATH_UNSAFE', 'FILE_UNSAFE', 'PATH_CHANGED' => 'FILE_UNSAFE', default => 'FILE_INVALID',
            });
        } catch (\Throwable) {
            return $no('FILE_INVALID');
        }
        $id = $asset->pixel_id ?? $asset->id ?? '';
        $version = $asset->pixel_version ?? $asset->version ?? 0;
        if (! Str::isUuid($id) || ! is_numeric($version) || (int) $version < 1) {
            return $no('ASSET_MISMATCH');
        }
        $value = $map[$id.':'.$version] ?? null;
        if ($value === null) {
            foreach (array_keys($map) as $key) {
                if (str_starts_with((string) $key, $id.':')) {
                    return $no('VERSION_MISMATCH');
                }
            }

            return $no('CONFIG_MISSING');
        }
        if (! is_array($value) || ($value['environment'] ?? null) !== $asset->environment
            || ($value['external_id'] ?? null) !== $asset->external_id || ($value['platform'] ?? null) !== $asset->platform) {
            return $no('ASSET_MISMATCH');
        }
        if (! PrivateCredentialFile::secretValid($value['token'] ?? null, 4096)) {
            return $no('TOKEN_MISSING');
        }
        if ($asset->environment === 'test' && ! PrivateCredentialFile::secretValid($value['test_event_code'] ?? null, 256)) {
            return $no('TEST_CODE_MISSING');
        }

        return ['code' => 'CONFIGURED_UNVERIFIED', 'value' => $value];
    }
}
