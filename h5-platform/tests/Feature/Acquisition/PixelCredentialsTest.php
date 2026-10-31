<?php

namespace Tests\Feature\Acquisition;

use App\Enums\Role;
use App\Modules\Acquisition\Console\CredentialPrompter;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use App\Modules\Acquisition\Sending\CredentialFileException;
use App\Modules\Acquisition\Sending\Credentials;
use App\Modules\Acquisition\Sending\PrivateCredentialFile;
use App\Modules\Acquisition\Services\AssetManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class PixelCredentialsTest extends AcquisitionTestCase
{
    private string $directory;

    private array $otherDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()).'/h5-acq-file-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['acquisition.credentials_file' => $this->directory.'/credentials.json']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        foreach ($this->otherDirectories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    private function asset(string $platform = 'meta'): object
    {
        return (object) ['pixel_id' => (string) Str::uuid(), 'pixel_version' => 1, 'platform' => $platform,
            'external_id' => $platform === 'meta' ? '123456789' : 'TESTPIXEL1234', 'environment' => 'test'];
    }

    private function save(object $asset): void
    {
        app(PrivateCredentialFile::class)->save($asset, 'EXPLICIT_FAKE_TOKEN_ONLY', 'TEST_CODE_ONLY');
    }

    private function unsafe(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe configuration was accepted');
        } catch (CredentialFileException $e) {
            $this->assertStringNotContainsString($this->directory, $e->getMessage());
            $this->assertStringNotContainsString('EXPLICIT_FAKE', $e->getMessage());
        }
    }

    public function test_both_platforms_preserve_other_records_and_bind_exact_asset_and_version(): void
    {
        $store = app(PrivateCredentialFile::class);
        $reader = app(Credentials::class);
        foreach (['meta', 'tiktok'] as $platform) {
            $a = $this->asset($platform);
            $this->assertSame('CONFIG_MISSING', $reader->readiness($a)['code']);
            $this->save($a);
            $this->assertSame('EXPLICIT_FAKE_TOKEN_ONLY', $reader->forDelivery($a)['token']);
            $safe = $reader->readiness($a);
            $this->assertSame('CONFIGURED_UNVERIFIED', $safe['code']);
            $this->assertSame('pending', $safe['platform_confirmation']);
            $this->assertStringNotContainsString('EXPLICIT_FAKE', json_encode($safe));
            $this->assertStringNotContainsString('TEST_CODE_ONLY', json_encode($safe));
            $a->pixel_version++;
            $this->assertSame('VERSION_MISMATCH', $reader->readiness($a)['code']);
            $this->assertNull($reader->forDelivery($a));
            $a->pixel_version--;
            $a->environment = 'production';
            $this->assertSame('ASSET_MISMATCH', $reader->readiness($a)['code']);
            $this->assertNull($reader->forDelivery($a));
        }
        $this->assertCount(2, $store->read());
        $this->assertSame(0600, fileperms(config('acquisition.credentials_file')) & 07777);
        $this->assertSame(0600, fileperms(config('acquisition.credentials_file').'.lock') & 07777);
        $this->assertSame([], glob($this->directory.'/.credential-*'));
        Http::assertNothingSent();
    }

    public function test_missing_test_code_invalid_token_or_corrupt_json_is_unready_and_not_overwritten(): void
    {
        $a = $this->asset();
        $this->save($a);
        $path = config('acquisition.credentials_file');
        $map = app(PrivateCredentialFile::class)->read();
        unset($map[$a->pixel_id.':1']['test_event_code']);
        file_put_contents($path, json_encode($map));
        $this->assertSame('TEST_CODE_MISSING', app(Credentials::class)->readiness($a)['code']);
        $this->assertNull(app(Credentials::class)->forDelivery($a));
        $map[$a->pixel_id.':1']['token'] = "FAKE\nINVALID";
        file_put_contents($path, json_encode($map));
        $this->assertSame('TOKEN_MISSING', app(Credentials::class)->readiness($a)['code']);
        file_put_contents($path, '{invalid TEST_CONTENT_NOT_FOR_OUTPUT');
        $before = file_get_contents($path);
        $this->assertSame('FILE_INVALID', app(Credentials::class)->readiness($a)['code']);
        $this->unsafe(fn () => $this->save($a));
        $this->assertSame($before, file_get_contents($path));
        $this->unsafe(fn () => app(PrivateCredentialFile::class)->save($a, 'VALID_FAKE', null));
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_git_public_symlink_and_writable_parent_locations_are_rejected_without_writes(): void
    {
        $a = $this->asset();
        $git = base_path('../.runtime/credential-boundary-'.bin2hex(random_bytes(8)));
        mkdir($git, 0700);
        $this->otherDirectories[] = $git;
        foreach ([$git.'/secret.json', public_path('credential-not-created.json'), 'php://memory', $this->directory.'/../secret.json'] as $path) {
            config(['acquisition.credentials_file' => $path]);
            $this->unsafe(fn () => $this->save($a));
            if (str_starts_with($path, '/')) {
                $this->assertFileDoesNotExist($path);
            }
        }
        $nested = $this->directory.'/repo';
        mkdir($nested, 0700);
        file_put_contents($nested.'/.git', 'gitdir: nowhere');
        config(['acquisition.credentials_file' => $nested.'/secret.json']);
        $this->unsafe(fn () => $this->save($a));
        $this->assertFileDoesNotExist($nested.'/secret.json');
        $link = $this->directory.'/linked-parent';
        symlink($git, $link);
        config(['acquisition.credentials_file' => $link.'/secret.json']);
        $this->unsafe(fn () => $this->save($a));
        unlink($link);
        config(['acquisition.credentials_file' => $this->directory.'/credentials.json']);
        chmod($this->directory, 0755);
        $this->unsafe(fn () => $this->save($a));
        chmod($this->directory, 0700);
        $parent = $this->directory.'/unsafe';
        mkdir($parent, 0777);
        chmod($parent, 0777);
        mkdir($parent.'/private', 0700);
        config(['acquisition.credentials_file' => $parent.'/private/secret.json']);
        $this->unsafe(fn () => $this->save($a));
        $this->assertFileDoesNotExist($parent.'/private/secret.json');
    }

    public function test_file_and_lock_symlinks_hardlinks_and_insecure_permissions_never_replace_a_target(): void
    {
        $a = $this->asset();
        $this->save($a);
        $path = config('acquisition.credentials_file');
        $real = $this->directory.'/original.json';
        rename($path, $real);
        $before = file_get_contents($real);
        symlink($real, $path);
        $this->unsafe(fn () => $this->save($a));
        $this->assertNull(app(Credentials::class)->forDelivery($a));
        $this->assertSame('FILE_UNSAFE', app(Credentials::class)->readiness($a)['code']);
        $this->assertSame($before, file_get_contents($real));
        unlink($path);
        link($real, $path);
        $this->unsafe(fn () => $this->save($a));
        unlink($path);
        rename($real, $path);
        chmod($path, 0644);
        $this->unsafe(fn () => $this->save($a));
        $this->assertSame($before, file_get_contents($path));
        chmod($path, 0600);
        unlink($path.'.lock');
        symlink($path, $path.'.lock');
        $this->unsafe(fn () => $this->save($a));
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_concurrent_process_writers_wait_on_stable_lock_and_merge_without_lost_records(): void
    {
        $path = config('acquisition.credentials_file');
        touch($path.'.lock');
        chmod($path.'.lock', 0600);
        $lock = fopen($path.'.lock', 'r+b');
        flock($lock, LOCK_EX);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, __DIR__.'/Support/credential-file-worker.php', $path, (string) Str::uuid()], base_path(), ['APP_ENV' => 'testing', 'H5_LOCAL_MODULES' => 'acquisition']);
                $process->setTimeout(10)->start();
                $workers[] = $process;
            }
            usleep(250000);
            $this->assertFileDoesNotExist($path);
            $this->assertTrue($workers[0]->isRunning());
            $this->assertTrue($workers[1]->isRunning());
            flock($lock, LOCK_UN);
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), 'Fake-only file worker failed');
                $this->assertSame('saved-fake-only', trim($worker->getOutput()));
                $this->assertSame('', $worker->getErrorOutput());
            }
            $this->assertCount(2, app(PrivateCredentialFile::class)->read());
            $this->assertSame([], glob($this->directory.'/.credential-*'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_cli_fake_hidden_prompt_saves_current_version_only_and_never_outputs_secret(): void
    {
        $this->signIn($this->staff(Role::Owner));
        [$pixel] = $this->assets();
        $fake = new class extends CredentialPrompter
        {
            public int $calls = 0;

            public ?\Closure $beforeReturn = null;

            public function ask(InputInterface $input, OutputInterface $output, bool $test): array
            {
                $this->calls++;
                if ($this->beforeReturn) {
                    ($this->beforeReturn)();
                }

                return ['token' => 'EXPLICIT_CLI_FAKE_SECRET', 'test_event_code' => 'EXPLICIT_TEST_CODE'];
            }
        };
        app()->instance(CredentialPrompter::class, $fake);
        $this->artisan('acquisition:credentials:set', ['pixel' => $pixel['id'], 'version' => $pixel['version']])
            ->expectsOutput('私密配置已保存；未联网、未启外发，待官方联调。')->doesntExpectOutput('EXPLICIT_CLI_FAKE_SECRET')->assertExitCode(0);
        $this->assertSame(1, $fake->calls);
        $before = file_get_contents(config('acquisition.credentials_file'));
        $this->artisan('acquisition:credentials:set', ['pixel' => $pixel['id'], 'version' => $pixel['version'] + 1])->assertExitCode(1);
        $this->assertSame(1, $fake->calls);
        $this->assertSame($before, file_get_contents(config('acquisition.credentials_file')));
        $fake->beforeReturn = function () use ($pixel): void {
            $input = array_intersect_key($pixel, $this->pixelInput());
            $input['name'] = 'Changed during fake hidden prompt';
            app(AssetManager::class)->savePixel($pixel['id'], $input);
        };
        $this->artisan('acquisition:credentials:set', ['pixel' => $pixel['id'], 'version' => $pixel['version']])->assertExitCode(1);
        $this->assertSame(2, $fake->calls);
        $this->assertSame($before, file_get_contents(config('acquisition.credentials_file')));
        $this->assertSame('VERSION_MISMATCH', app(AssetManager::class)->pixel($pixel['id'])['credential_readiness']['code']);
        Http::assertNothingSent();
    }

    public function test_noninteractive_prompt_refuses_fallback_and_cli_does_not_write(): void
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $this->unsafe(fn () => app(CredentialPrompter::class)->ask($input, new BufferedOutput, true));
        $this->signIn($this->staff(Role::Owner));
        [$pixel] = $this->assets();
        $this->artisan('acquisition:credentials:set', ['pixel' => $pixel['id'], 'version' => $pixel['version'], '--no-interaction' => true])->assertExitCode(1);
        $this->assertFileDoesNotExist(config('acquisition.credentials_file'));
    }

    public function test_readiness_on_real_pixel_page_obeys_object_scope_and_never_renders_secrets(): void
    {
        $this->signIn();
        [$pixel] = $this->assets();
        $a = (object) ['pixel_id' => $pixel['id'], 'pixel_version' => (int) $pixel['version'], 'platform' => $pixel['platform'],
            'external_id' => $pixel['external_id'], 'environment' => $pixel['environment']];
        $this->save($a);
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'pixels')->assertSee('配置可读取；待官方联调')->assertSee('不代表平台接收')
            ->assertDontSee('EXPLICIT_FAKE_TOKEN_ONLY')->assertDontSee('TEST_CODE_ONLY')->assertDontSee($this->directory);
        $this->signIn();
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'pixels')->assertDontSee($pixel['external_id'])->assertDontSee('配置可读取；待官方联调');
        $this->problem('NOT_FOUND', fn () => app(AssetManager::class)->pixel($pixel['id']));
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        Http::assertNothingSent();
    }
}
