<?php

namespace Tests\Feature\Acquisition;

use App\Enums\Role;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Probe\PageTestProbe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PageTestProbeTest extends AcquisitionTestCase
{
    private array $originalEnvironment;

    // Sending must commit its single-use reservation, so do not wrap this suite in an outer test transaction.
    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $this->freezeTime();
        $this->signIn($this->staff(Role::Owner));
    }

    protected function tearDown(): void
    {
        [$server, $env, $process] = $this->originalEnvironment;
        if ($server === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $server;
        }
        if ($env === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $env;
        }
        putenv($process === false ? 'APP_ENV' : 'APP_ENV='.$process);
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    private function staging(): void
    {
        $this->app->instance('env', 'staging');
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');
        config(['app.env' => 'staging', 'app.debug' => false, 'app.url' => 'https://staging.example.test',
            'h5.acquisition_staging_test' => true, 'h5.controlled_staging' => true, 'h5.simulation' => false,
            'h5.local_us_test_region' => false, 'h5.local_modules' => '',
            'h5.modules.acquisition' => ['provider' => AcquisitionServiceProvider::class, 'ready' => true],
            'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
            'acquisition.analytics_enabled' => false, 'acquisition.measurement.enabled' => false,
            'acquisition.measurement.synthetic_policy' => false, 'acquisition.registration.synthetic_policy' => false,
            'acquisition.registration.observations_enabled' => false, 'session.secure' => true,
            'session.http_only' => true, 'session.same_site' => 'lax', 'trustedproxy.proxies' => null,
            'mail.default' => 'smtp', 'mail.driver' => null, 'mail.from.address' => 'noreply@example.test',
            'mail.mailers' => ['smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
                'host' => 'smtp.example.test', 'port' => 587, 'timeout' => 10, 'username' => 'FAKE_USER',
                'password' => 'FAKE_NOT_SENT', 'require_tls' => true, 'verify_peer' => true]],
        ]);
    }

    private function request(string $method, string $path = PageTestProbe::PATH, array $server = []): void
    {
        $request = Request::create('https://staging.example.test'.$path, $method, server: array_replace([
            'REMOTE_ADDR' => '8.8.8.8', 'HTTP_USER_AGENT' => 'Explicit fake page tester',
            'HTTP_ORIGIN' => 'https://staging.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], $server));
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
    }

    private function grant(): void
    {
        // Exercise the existing explicit consent mechanism, not a new probe consent provider.
        app(TrackingContext::class)->consent(false, true);
    }

    private function intent(array $pixel): string
    {
        $this->request('GET');
        $this->grant();
        app(PageTestProbe::class)->pageProof();
        $this->request('POST');

        return app(PageTestProbe::class)->create($pixel['id'], true);
    }

    private function credentials(array $pixel): void
    {
        $this->httpCredentials((object) ['pixel_id' => $pixel['id'], 'pixel_version' => $pixel['version'],
            'platform' => $pixel['platform'], 'external_id' => $pixel['external_id'], 'environment' => $pixel['environment']]);
        config(['app.url' => 'https://staging.example.test']);
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected refusal');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function businessEmpty(): void
    {
        foreach (['acquisition_receipts', 'acquisition_deliveries', 'acquisition_sources', 'acquisition_subjects',
            'acquisition_behaviors', 'acquisition_analytics_orders', 'acquisition_c_subjects', 'acquisition_c_events',
            'acquisition_r1_proofs', 'acquisition_r1_choices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('acquisition_privacy')->whereNotNull('viewer_id')->count());
    }

    public function test_explicit_real_page_context_meta_only_one_send_and_no_business_or_identity_capture(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        $this->request('GET', PageTestProbe::PATH.'?email=not-stored@example.test');
        app(PageTestProbe::class)->pageProof();
        $this->assertDatabaseCount('acquisition_page_tests', 0);
        $this->assertDatabaseCount('acquisition_privacy', 0);
        $this->grant();
        $privacy = (array) app(TrackingContext::class)->privacy();
        $this->request('POST');
        $id = app(PageTestProbe::class)->create($pixel['id'], true);
        $row = DB::table('acquisition_page_tests')->sole();
        $context = json_decode(Crypt::decryptString($row->context_encrypted), true);
        $this->assertSame('https://staging.example.test'.PageTestProbe::PATH, $context['page_url']);
        $this->assertSame('8.8.8.8', $context['ip']);
        $this->assertSame('Explicit fake page tester', $context['user_agent']);
        $this->assertStringNotContainsString('8.8.8.8', $row->context_encrypted);
        $this->assertSame($privacy, (array) app(TrackingContext::class)->privacy());
        $this->credentials($pixel);
        Http::fake(function ($request) use ($id) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('attempted', DB::table('acquisition_page_tests')->where('id', $id)->value('state'));
            $this->assertSame('PROBE_ALREADY_CONSUMED_OR_MISSING', app(PageTestProbe::class)->send($id)['reason']);
            $body = $request->data();
            $this->assertSame(['data', 'test_event_code'], array_keys($body));
            $this->assertSame(['event_name', 'event_time', 'event_id', 'action_source', 'event_source_url', 'user_data'], array_keys($body['data'][0]));
            $this->assertSame('PageView', $body['data'][0]['event_name']);
            $this->assertSame('h5_probe_'.$id, $body['data'][0]['event_id']);
            $this->assertSame(now()->timestamp, $body['data'][0]['event_time']);
            $this->assertSame('website', $body['data'][0]['action_source']);
            $this->assertSame(['client_ip_address', 'client_user_agent'], array_keys($body['data'][0]['user_data']));
            $this->assertSame('UNIT_TEST', $body['test_event_code']);
            $this->assertStringNotContainsString('not-stored', $request->body());
            $this->assertStringNotContainsString('Purchase', $request->body());

            return Http::response(['events_received' => 1], 200);
        });
        $this->artisan('acquisition:page-test', ['intent' => $id])->expectsOutput('Meta PageView: api_accepted; PLATFORM_CONFIRMATION_PENDING')->assertSuccessful();
        $this->artisan('acquisition:page-test', ['intent' => $id])->assertFailed();
        Http::assertSentCount(1);
        $row = DB::table('acquisition_page_tests')->sole();
        foreach (['context_encrypted', 'privacy_id', 'pixel_id', 'pixel_version'] as $key) {
            $this->assertNull($row->$key);
        }
        $this->businessEmpty();
    }

    public function test_missing_grant_unchecked_expired_or_replayed_page_never_creates_intent(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        $probe = app(PageTestProbe::class);
        $this->request('POST');
        $this->refused(fn () => $probe->create($pixel['id'], true));
        $this->request('GET');
        $probe->pageProof();
        $this->request('POST');
        $this->refused(fn () => $probe->create($pixel['id'], false));
        $this->refused(fn () => $probe->create($pixel['id'], true)); // missing grant
        $this->request('GET');
        $this->grant();
        $probe->pageProof();
        $this->travel(10)->minutes();
        $this->request('POST');
        $this->refused(fn () => $probe->create($pixel['id'], true));
        $this->assertDatabaseCount('acquisition_page_tests', 0);
        $this->intent($pixel);
        $this->refused(fn () => $probe->create($pixel['id'], true)); // same rendered proof pulled once
        Http::assertNothingSent();
    }

    public function test_wrong_asset_or_version_and_no_test_code_or_disabled_transport_never_send(): void
    {
        [$pixel] = $this->assets();
        [$production] = $this->assets(environment: 'production');
        [$tiktok] = $this->assets('tiktok');
        $this->staging();
        foreach ([$production, $tiktok] as $bad) {
            $this->request('GET');
            $this->grant();
            app(PageTestProbe::class)->pageProof();
            $this->request('POST');
            $this->refused(fn () => app(PageTestProbe::class)->create($bad['id'], true));
        }
        $id = $this->intent($pixel);
        $this->assertSame('PROBE_TEST_CREDENTIALS_REQUIRED', app(PageTestProbe::class)->send($id)['reason']);
        $id = $this->intent($pixel);
        $this->credentials($pixel);
        $path = config('acquisition.credentials_file');
        $map = json_decode(file_get_contents($path), true);
        unset($map[$pixel['id'].':'.$pixel['version']]['test_event_code']);
        file_put_contents($path, json_encode($map));
        $this->assertSame('PROBE_TEST_CREDENTIALS_REQUIRED', app(PageTestProbe::class)->send($id)['reason']);
        $id = $this->intent($pixel);
        $this->credentials($pixel);
        DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['version' => $pixel['version'] + 1]);
        $this->assertSame('PROBE_TEST_ASSET_REQUIRED', app(PageTestProbe::class)->send($id)['reason']);
        DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['version' => $pixel['version']]);
        $id = $this->intent($pixel);
        config(['acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false]);
        $this->assertSame('EXTERNAL_DISABLED', app(PageTestProbe::class)->send($id)['reason']);
        Http::assertNothingSent();
    }

    public function test_withdrawal_and_regrant_or_expiry_blocks_prepared_intent_without_send(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        $id = $this->intent($pixel);
        $this->credentials($pixel);
        $this->travel(1)->seconds();
        app(PageTestProbe::class)->withdraw();
        $this->assertDatabaseHas('acquisition_page_tests', ['id' => $id, 'state' => 'blocked', 'reason' => 'CONSENT_REQUIRED_OR_REVOKED']);
        $this->assertSame('PROBE_ALREADY_CONSUMED_OR_MISSING', app(PageTestProbe::class)->send($id)['reason']);
        $id = $this->intent($pixel);
        $this->travel(1)->seconds();
        app(TrackingContext::class)->consent(false, false);
        $this->travel(1)->seconds();
        $this->grant();
        $this->assertDatabaseHas('acquisition_page_tests', ['id' => $id, 'state' => 'blocked', 'reason' => 'CONSENT_REQUIRED_OR_REVOKED']);
        $this->assertSame('PROBE_ALREADY_CONSUMED_OR_MISSING', app(PageTestProbe::class)->send($id)['reason']);
        $id = $this->intent($pixel);
        $this->travel(10)->minutes();
        $this->assertSame('PROBE_EXPIRED', app(PageTestProbe::class)->send($id)['reason']);
        Http::assertNothingSent();
        $this->businessEmpty();
    }

    public function test_timeout_and_http_failure_consume_intent_with_no_automatic_retry(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        $id = $this->intent($pixel);
        $this->credentials($pixel);
        Http::fake(['*' => Http::failedConnection()]);
        $this->assertSame('PROBE_RESULT_UNKNOWN_NO_RETRY', app(PageTestProbe::class)->send($id)['reason']);
        $this->assertSame('PROBE_ALREADY_CONSUMED_OR_MISSING', app(PageTestProbe::class)->send($id)['reason']);
        $this->assertDatabaseHas('acquisition_page_tests', ['id' => $id, 'state' => 'unconfirmed', 'context_encrypted' => null]);
        $id = $this->intent($pixel);
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertSame('unconfirmed', app(PageTestProbe::class)->send($id)['state']);
        $this->assertSame('PROBE_ALREADY_CONSUMED_OR_MISSING', app(PageTestProbe::class)->send($id)['reason']);
        $this->assertSame('unconfirmed', DB::table('acquisition_page_tests')->where('id', $id)->value('state'));
    }

    public function test_production_local_default_false_and_changed_guard_refuse_before_any_io(): void
    {
        [$pixel] = $this->assets();
        $this->artisan('acquisition:page-test', ['intent' => (string) Str::uuid()])->assertFailed();
        $this->staging();
        $id = $this->intent($pixel);
        $this->credentials($pixel);
        config(['h5.acquisition_staging_test' => false]);
        $this->artisan('acquisition:page-test', ['intent' => $id])->assertFailed();
        config(['h5.acquisition_staging_test' => true, 'app.env' => 'production']);
        $this->app->instance('env', 'production');
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');
        $this->artisan('acquisition:page-test', ['intent' => $id])->assertFailed();
        $this->assertDatabaseHas('acquisition_page_tests', ['id' => $id, 'state' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_bad_origin_ip_and_self_reported_context_fail_before_capture(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        foreach ([['HTTP_ORIGIN' => 'https://evil.example'], ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
            ['HTTP_X_FORWARDED_FOR' => '8.8.4.4'], ['REMOTE_ADDR' => '127.0.0.1'], ['HTTP_USER_AGENT' => '']] as $bad) {
            $this->request('GET');
            $this->grant();
            app(PageTestProbe::class)->pageProof();
            $this->request('POST', server: $bad);
            $this->refused(fn () => app(PageTestProbe::class)->create($pixel['id'], true));
        }
        $this->assertDatabaseCount('acquisition_page_tests', 0);
        Http::assertNothingSent();
    }

    public function test_real_protected_http_page_and_post_render_and_refuse_self_reported_fields_and_missing_mfa(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeaders(['User-Agent' => 'Explicit HTTP fake tester',
            'Origin' => 'https://staging.example.test', 'Sec-Fetch-Site' => 'same-origin']);
        $url = 'https://staging.example.test'.PageTestProbe::PATH;
        $this->get($url.'?token=never-capture-this')->assertOk()->assertSee('Meta 单次页面测试')->assertSee('尚无广告同意')
            ->assertDontSee('never-capture-this')->assertHeader('Cache-Control', 'no-store, private');
        $this->post($url, ['pixel_id' => $pixel['id'], 'agree' => '1', 'ip' => '8.8.8.8'])->assertStatus(422);
        $this->request('GET');
        $this->grant();
        $this->get($url)->assertOk();
        $this->post($url, ['pixel_id' => $pixel['id'], 'agree' => '1'])->assertRedirect(PageTestProbe::PATH);
        $this->assertDatabaseCount('acquisition_page_tests', 1);
        $this->get($url)->assertSee(DB::table('acquisition_page_tests')->sole()->id);
        $this->post($url.'/withdraw')->assertRedirect(PageTestProbe::PATH);
        $this->assertSame('denied', app(TrackingContext::class)->privacy()->advertising);
        request()->session()->forget('identity.mfa_staff');
        request()->session()->save();
        $this->get($url)->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_owner_page_reuses_actual_privacy_partial_with_csrf_and_does_not_grant_on_get(): void
    {
        $this->staging();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeader('User-Agent', 'Explicit inline privacy fixture');
        $response = $this->get('https://staging.example.test'.PageTestProbe::PATH);
        $response->assertOk()->assertSee('data-audience-tracking', false)->assertSee('data-buffer-scope', false)
            ->assertSee('data-privacy-open', false)->assertSee('data-privacy-dialog', false)->assertSee('data-privacy-form', false)
            ->assertSee('data-privacy-advertising-test', false)->assertSee('data-privacy-reject', false)->assertSee('data-privacy-withdraw', false)
            ->assertSee('name="csrf-token"', false)->assertSee(csrf_token())->assertSee('保存成功后刷新本页')
            ->assertDontSee('href="/membership"', false);
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-audience-tracking'));
        $this->assertSame(1, substr_count($html, 'href="/audience/analytics.css"'));
        $this->assertSame(1, substr_count($html, 'src="/audience/analytics.js" defer'));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//form[@data-privacy-form]')->length);
        $this->assertSame(0, $xpath->query('//form[@data-privacy-form]/ancestor::form')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.PageTestProbe::PATH.'"]//button[@disabled]')->length);
        $this->assertDatabaseCount('acquisition_privacy', 0);
        $this->assertDatabaseCount('acquisition_page_tests', 0);
        Http::assertNothingSent();
    }

    public function test_inline_privacy_uses_existing_browser_api_cookie_scope_and_withdrawal_without_sending(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeaders([
            'User-Agent' => 'Explicit inline privacy fixture', 'Origin' => 'https://staging.example.test', 'Sec-Fetch-Site' => 'same-origin',
        ]);
        // Preserve encrypted response cookies exactly as a browser does across separate staff/viewer session scopes.
        $keepCookies = function ($response): void {
            foreach ($response->headers->getCookies() as $cookie) {
                $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            }
        };
        $url = 'https://staging.example.test'.PageTestProbe::PATH;
        $response = $this->get($url)->assertOk();
        $keepCookies($response);
        preg_match('/name="csrf-token" content="([^"]+)"/', $response->getContent(), $meta);
        $this->assertNotEmpty($meta[1] ?? null);
        $this->withHeader('X-CSRF-TOKEN', $meta[1]);
        $context = $this->getJson('https://staging.example.test/api/v1/tracking/context')->assertOk()
            ->assertJsonPath('advertising_test_only', true)->assertJsonPath('analytics_available', false)
            ->assertJsonPath('consent.advertising', 'unknown');
        $keepCookies($context);
        $this->assertDatabaseCount('acquisition_privacy', 0);
        $choice = ['analytics' => false, 'advertising' => true, 'policy_version' => 'analytics-v1'];
        $saved = $this->postJson('https://staging.example.test/api/v1/tracking/consent', $choice)->assertOk()
            ->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'granted');
        $keepCookies($saved);
        $page = $this->get($url)->assertOk()->assertDontSee('尚无广告同意');
        $keepCookies($page);
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $this->assertSame(0, (new \DOMXPath($dom))->query('//form[@action="'.PageTestProbe::PATH.'"]//button[@disabled]')->length);
        $this->post($url, ['pixel_id' => $pixel['id'], 'agree' => '1'])->assertRedirect(PageTestProbe::PATH);
        $this->assertDatabaseCount('acquisition_page_tests', 1);
        $withdrawn = $this->postJson('https://staging.example.test/api/v1/tracking/consent', array_replace($choice, ['advertising' => false]))
            ->assertOk()->assertJsonPath('consent.advertising', 'denied');
        $keepCookies($withdrawn);
        $this->get($url)->assertOk()->assertSee('尚无广告同意');
        $this->assertDatabaseHas('acquisition_page_tests', ['state' => 'blocked', 'reason' => 'CONSENT_REQUIRED_OR_REVOKED']);
        // Existing legacy refusal marker remains authoritative; embedding the component cannot override it.
        DB::table('acquisition_privacy')->update(['r1_advertising_denied_at' => now(), 'r1_advertising_denial_until' => now()->addDay()]);
        $this->postJson('https://staging.example.test/api/v1/tracking/consent', $choice)->assertOk()->assertJsonPath('consent.advertising', 'denied');
        $this->get($url)->assertOk()->assertSee('尚无广告同意');
        $this->assertDatabaseCount('acquisition_page_tests', 1);
        $this->businessEmpty();
        Http::assertNothingSent();
    }

    public function test_protected_post_rejects_cross_origin_and_missing_csrf_without_fetch_metadata(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        $url = 'https://staging.example.test'.PageTestProbe::PATH;
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeader('User-Agent', 'Explicit fake tester');
        $this->get($url)->assertOk();
        $this->withHeader('Origin', 'https://evil.example')->post($url, ['pixel_id' => $pixel['id'], 'agree' => '1'])->assertStatus(419);
        $this->withHeader('Origin', 'https://staging.example.test')->post($url, ['pixel_id' => $pixel['id'], 'agree' => '1'])->assertStatus(419);
        $this->assertDatabaseCount('acquisition_page_tests', 0);
        Http::assertNothingSent();
    }

    public function test_new_table_upgrade_keeps_existing_assets_and_old_receipts_unchanged(): void
    {
        [, , , , $delivery] = $this->queueEvent();
        $tables = ['acquisition_pixels', 'acquisition_links', 'acquisition_sources', 'acquisition_receipts', 'acquisition_deliveries'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $migration = require database_path('migrations/2026_11_12_050010_create_acquisition_page_tests.php');
        $migration->down();
        $migration->up();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson());
        }
        $this->assertDatabaseCount('acquisition_page_tests', 0);
        Http::assertNothingSent();
    }

    public function test_guest_is_sent_to_existing_staff_login_without_a_new_auth_system(): void
    {
        $this->staging();
        auth('staff')->logout();
        $this->get('https://staging.example.test'.PageTestProbe::PATH)->assertRedirect('/admin/login');
        Http::assertNothingSent();
    }

    public function test_nonowner_has_no_protected_page_access_or_intent_creation(): void
    {
        $this->signIn();
        $this->staging();
        $this->request('GET');
        $this->refused(fn () => app(PageTestProbe::class)->pageProof());
        $this->get('https://staging.example.test'.PageTestProbe::PATH)->assertForbidden();
        $this->assertDatabaseCount('acquisition_page_tests', 0);
        Http::assertNothingSent();
    }

    public function test_prune_erases_expired_private_context_and_never_makes_crashed_attempt_retryable(): void
    {
        [$pixel] = $this->assets();
        $this->staging();
        $id = $this->intent($pixel);
        DB::table('acquisition_page_tests')->where('id', $id)->update(['state' => 'attempted', 'attempted_at' => now()]);
        $this->travel(10)->minutes();
        $this->artisan('acquisition:analytics-prune')->assertSuccessful();
        $this->assertDatabaseHas('acquisition_page_tests', ['id' => $id, 'state' => 'attempted', 'context_encrypted' => null, 'pixel_id' => null]);
        $this->assertSame('PROBE_ALREADY_CONSUMED_OR_MISSING', app(PageTestProbe::class)->send($id)['reason']);
        $this->travel(1)->days();
        $this->artisan('acquisition:analytics-prune')->assertSuccessful();
        $this->assertDatabaseCount('acquisition_page_tests', 0);
        $this->businessEmpty();
        Http::assertNothingSent();
    }
}
