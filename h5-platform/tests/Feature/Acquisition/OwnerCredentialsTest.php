<?php

namespace Tests\Feature\Acquisition;

use App\Enums\Role;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use App\Modules\Acquisition\Sending\Credentials;
use App\Modules\Acquisition\Sending\PrivateCredentialFile;
use App\Modules\Acquisition\Services\AssetManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

final class OwnerCredentialsTest extends AcquisitionTestCase
{
    private string $directory;

    private const URL = 'https://localhost/api/v1/admin/acquisition/pixel-credentials';

    private const TOKEN = 'EXPLICIT_OWNER_FAKE_TOKEN_20261113';

    private const CODE = 'EXPLICIT_OWNER_FAKE_CODE_20261113';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn($this->staff(Role::Owner));
        $this->directory = realpath(sys_get_temp_dir()).'/h5-owner-secret-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['app.url' => 'https://localhost', 'acquisition.credentials_file' => $this->directory.'/credentials.json']);
        $this->withHeaders(['Origin' => 'https://localhost', 'Sec-Fetch-Site' => 'same-origin']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function input(array $pixel, array $replace = []): array
    {
        return array_replace(['pixel_id' => $pixel['id'], 'version' => $pixel['version'], 'token' => self::TOKEN,
            'test_event_code' => $pixel['environment'] === 'test' ? self::CODE : '', 'reuse_previous' => false], $replace);
    }

    private function noLeaks(string $response = ''): void
    {
        foreach ([self::TOKEN, self::CODE] as $secret) {
            $this->assertStringNotContainsString($secret, $response);
            $this->assertStringNotContainsString($secret, json_encode(session()->all()));
            $this->assertStringNotContainsString($secret, DB::table('identity_audit_events')->get()->toJson());
        }
        $this->assertFalse(session()->has('_old_input'));
        Http::assertNothingSent();
    }

    public function test_owner_saves_both_platforms_without_secret_readback_or_sending_or_switch_changes(): void
    {
        foreach (['meta', 'tiktok'] as $platform) {
            [$pixel] = $this->assets($platform);
            $response = $this->postJson(self::URL, $this->input($pixel))->assertOk()->assertExactJson(['code' => 'CREDENTIAL_SAVED'])
                ->assertHeader('Cache-Control', 'no-store, private');
            $private = app(Credentials::class)->forDelivery((object) $pixel);
            $this->assertSame(self::TOKEN, $private['token']);
            $this->assertSame(self::CODE, $private['test_event_code']);
            $this->noLeaks($response->getContent().json_encode(app(AssetManager::class)->pixel($pixel['id'])));
        }
        $this->assertSame('disabled', config('acquisition.transport'));
        $this->assertFalse(config('acquisition.external_enabled'));
        $this->assertFalse(config('acquisition.platform_reviewed'));
        $this->assertSame(0600, fileperms(config('acquisition.credentials_file')) & 0777);
    }

    public function test_updating_test_code_keeps_current_token_and_production_does_not_accept_test_code(): void
    {
        [$pixel] = $this->assets();
        $this->postJson(self::URL, $this->input($pixel))->assertOk();
        $this->postJson(self::URL, $this->input($pixel, ['token' => '', 'test_event_code' => 'NEW_FAKE_CODE']))->assertOk();
        $value = app(Credentials::class)->forDelivery((object) $pixel);
        $this->assertSame(self::TOKEN, $value['token']);
        $this->assertSame('NEW_FAKE_CODE', $value['test_event_code']);
        [$production] = $this->assets(environment: 'production');
        $this->postJson(self::URL, $this->input($production, ['test_event_code' => self::CODE]))->assertStatus(422);
        $this->postJson(self::URL, $this->input($production))->assertOk();
        $this->assertArrayNotHasKey('test_event_code', app(Credentials::class)->forDelivery((object) $production));
        $this->noLeaks();
    }

    public function test_rename_version_requires_explicit_owner_reauthorization_and_preserves_old_binding(): void
    {
        [$pixel] = $this->assets();
        $this->postJson(self::URL, $this->input($pixel))->assertOk();
        $input = array_intersect_key($pixel, $this->pixelInput());
        $input['name'] = 'Owner renamed only';
        $new = app(AssetManager::class)->savePixel($pixel['id'], $input);
        $this->postJson(self::URL, $this->input($pixel))->assertStatus(409)->assertJsonPath('code', 'CREDENTIAL_VERSION_CONFLICT');
        $this->postJson(self::URL, $this->input($new, ['token' => '', 'test_event_code' => '']))->assertStatus(422);
        $this->assertNull(app(Credentials::class)->forDelivery((object) $new));
        $this->postJson(self::URL, $this->input($new, ['token' => '', 'test_event_code' => '', 'reuse_previous' => true]))->assertOk();
        $map = app(PrivateCredentialFile::class)->read();
        $this->assertSame($map[$pixel['id'].':1'], $map[$pixel['id'].':2']);
        $this->assertCount(2, $map);
        $this->noLeaks();
    }

    public function test_nonowner_and_missing_mfa_are_rejected_even_for_own_or_assigned_assets(): void
    {
        [$pixel] = $this->assets();
        $this->signIn();
        $this->postJson(self::URL, $this->input($pixel))->assertForbidden();
        $this->signIn($this->staff(Role::Owner));
        request()->session()->forget('identity.mfa_staff');
        request()->session()->save();
        $this->postJson(self::URL, $this->input($pixel))->assertForbidden();
        $this->assertFileDoesNotExist(config('acquisition.credentials_file'));
        $this->noLeaks();
    }

    public function test_origin_http_raw_whitespace_and_unknown_fields_fail_without_flash_or_logs(): void
    {
        [$pixel] = $this->assets();
        Log::spy();
        $this->withHeader('Origin', 'https://evil.example')->postJson(self::URL, $this->input($pixel))->assertForbidden();
        $this->withHeader('Origin', 'https://localhost');
        $this->postJson(str_replace('https:', 'http:', self::URL), $this->input($pixel))->assertForbidden();
        foreach ([['token' => self::TOKEN.' '], ['token' => ['array']], ['extra' => self::TOKEN], ['version' => '1']] as $bad) {
            $response = $this->postJson(self::URL, $this->input($pixel, $bad))->assertStatus(422);
            $this->noLeaks($response->getContent());
        }
        Log::shouldNotHaveReceived('warning');
        $this->assertFileDoesNotExist(config('acquisition.credentials_file'));
    }

    public function test_csrf_middleware_rejects_missing_token_when_fetch_metadata_absent(): void
    {
        // Exercise actual CSRF middleware with testing bypass disabled, without changing shared configuration.
        $request = Request::create(self::URL, 'POST');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('env', 'staging');
        try {
            app(PreventRequestForgery::class)->handle($request, fn () => throw new \RuntimeException('Must not enter endpoint'));
            $this->fail('Expected CSRF refusal');
        } catch (TokenMismatchException) {
            $this->assertTrue(true);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->noLeaks();
    }

    public function test_storage_failure_keeps_existing_bytes_and_has_fixed_safe_response(): void
    {
        [$pixel] = $this->assets();
        $this->postJson(self::URL, $this->input($pixel))->assertOk();
        $path = config('acquisition.credentials_file');
        $before = file_get_contents($path);
        chmod($path, 0644);
        Log::spy();
        $response = $this->postJson(self::URL, $this->input($pixel, ['token' => 'NEW_FAKE_TOKEN']))->assertStatus(503)
            ->assertExactJson(['code' => 'CREDENTIAL_SAVE_UNCONFIRMED']);
        $this->assertSame($before, file_get_contents($path));
        $this->assertStringNotContainsString($path, $response->getContent());
        $this->noLeaks($response->getContent());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_sensitive_dialog_has_no_livewire_binding_and_is_owner_only_and_secrets_never_serialize(): void
    {
        [$pixel] = $this->assets();
        $this->postJson(self::URL, $this->input($pixel))->assertOk();
        $page = Livewire::test(AcquisitionDesk::class)->call('selectTab', 'pixels')->assertSee('授权配置')->assertSee('仅负责人');
        $html = $page->html();
        $this->noLeaks($html.json_encode($page->snapshot));
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//dialog[@id="acquisition-credentials"]//input[@type="password"]')->length);
        $this->assertSame(0, $xpath->query('//dialog[@id="acquisition-credentials"]//input[@value or @name]')->length);
        $this->assertSame(0, $xpath->query('//dialog[@id="acquisition-credentials"]//input/@*[starts-with(name(),"wire:")]')->length);
        $this->signIn();
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'pixels')->assertDontSee('像素授权配置（仅负责人）');
    }
}
