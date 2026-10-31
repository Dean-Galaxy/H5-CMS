<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Sending\PrivateCredentialFile;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Trusted host operator only, not a browser/staff impersonation endpoint. No send or switch changes. */
final class ConfigurePixelCredentials extends Command
{
    protected $signature = 'acquisition:credentials:set {pixel : Existing pixel UUID} {version : Current immutable version}';

    protected $description = '本机运维TTY私密录入；不联网、不启外发、不输出秘密';

    public function handle(CredentialPrompter $prompter, PrivateCredentialFile $store): int
    {
        try {
            if (! app()->environment('local', 'testing') || config('h5.acquisition_staging_test') !== false) {
                app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
            }
            if (! Str::isUuid($this->argument('pixel'))
                || ! ctype_digit((string) $this->argument('version')) || (int) $this->argument('version') < 1) {
                throw new \LogicException;
            }
            $target = $this->target();
            if (! app()->environment('local', 'testing') && $target->environment !== 'test') {
                throw new \LogicException;
            }
            $store->preflight();
            $secret = $prompter->ask($this->input, $this->output, $target->environment === 'test');
            DB::transaction(function () use ($target, $secret, $store) {
                if (! app()->environment('local', 'testing') || config('h5.acquisition_staging_test') !== false) {
                    app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
                }
                $current = $this->target(true);
                if ((array) $current !== (array) $target) {
                    throw new \LogicException;
                }
                $store->save($current, $secret['token'], $secret['test_event_code']);
            });
            $this->info('私密配置已保存；未联网、未启外发，待官方联调。');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('配置未确认保存。需受信本机交互TTY、当前资产版本及Git/public外安全私密目录；检查后重试。未输出秘密。');

            return self::FAILURE;
        }
    }

    private function target(bool $lock = false): object
    {
        $q = DB::table('acquisition_pixels')->where('id', $this->argument('pixel'))->where('version', (int) $this->argument('version'));
        $pixel = ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new \LogicException;
        $version = DB::table('acquisition_pixel_versions')->where('pixel_id', $pixel->id)->where('version', $pixel->version)->first();
        if (! $version || $version->platform !== $pixel->platform || $version->external_id !== $pixel->external_id || $version->environment !== $pixel->environment) {
            throw new \LogicException;
        }

        return (object) ['pixel_id' => $pixel->id, 'pixel_version' => (int) $pixel->version, 'platform' => $pixel->platform,
            'external_id' => $pixel->external_id, 'environment' => $pixel->environment];
    }
}
