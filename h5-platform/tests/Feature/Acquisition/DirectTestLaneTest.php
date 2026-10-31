<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\CatalogReader;
use App\Contracts\CheckoutAttribution;
use App\Contracts\IdentityContext;
use App\Data\AttributionSnapshot;
use App\Data\CheckoutPageContext;
use App\Data\Drama;
use App\Enums\BehaviorRecordingResult;
use App\Enums\TransactionKind;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Console\CredentialPrompter;
use App\Modules\Acquisition\Measurement\CoreContext;
use App\Modules\Acquisition\Sending\DeliveryGate;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Sending\TikTokAdapter;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Acquisition\Services\PaymentReceiver;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class DirectTestLaneTest extends AcquisitionTestCase
{
    private array $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $this->freezeTime();
        $this->signIn();
        config(['app.url' => 'https://localhost', 'acquisition.analytics_enabled' => true]);
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
        parent::tearDown();
    }

    private function environment(string $environment): void
    {
        $this->app->instance('env', $environment);
        config(['app.env' => $environment]);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $environment;
        putenv('APP_ENV='.$environment);
    }

    private function staging(): void
    {
        $this->environment('staging');
        config(['app.debug' => false, 'app.url' => 'https://staging.example.test',
            'h5.acquisition_staging_test' => true, 'h5.controlled_staging' => true,
            'h5.simulation' => false, 'h5.local_us_test_region' => false, 'h5.local_modules' => '',
            'h5.modules.acquisition' => ['provider' => AcquisitionServiceProvider::class, 'ready' => true],
            'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
            'acquisition.analytics_enabled' => false, 'acquisition.measurement.enabled' => false,
            'acquisition.measurement.synthetic_policy' => false, 'acquisition.registration.synthetic_policy' => false,
            'acquisition.registration.observations_enabled' => false,
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'trustedproxy.proxies' => null,
            'mail.default' => 'smtp', 'mail.driver' => null, 'mail.from.address' => 'noreply@example.test',
            'mail.mailers' => ['smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
                'host' => 'smtp.example.test', 'port' => 587, 'timeout' => 10, 'username' => 'FAKE_USER',
                'password' => 'FAKE_NOT_SENT', 'require_tls' => true, 'verify_peer' => true]],
        ]);
    }

    private function purchaseRequest(array $server = []): void
    {
        $request = Request::create(rtrim(config('app.url'), '/').'/membership/checkout', 'POST', server: array_replace([
            'REMOTE_ADDR' => '8.8.4.4', 'HTTP_USER_AGENT' => 'Explicit checkout PHPUnit fake browser',
            'HTTP_ORIGIN' => rtrim(config('app.url'), '/'), 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], $server));
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
    }

    private function trustedSource(string $platform = 'meta'): array
    {
        [$pixel, $link] = $this->assets($platform);
        $source = $this->source($pixel, $link, false);
        $this->purchaseRequest();
        app(TrackingContext::class)->consent(false, true);
        $privacy = app(TrackingContext::class)->privacy();
        DB::table('acquisition_sources')->where('id', $source->source_id)->update(['privacy_id' => $privacy->id]);
        session()->put('tracking.source', $source->source_id);
        $old = DB::table('acquisition_sources')->where('id', $source->source_id)->first();
        $context = json_decode(Crypt::decryptString($old->context_encrypted), true);
        $context += ['fbc' => 'fb.1.1700000000000.FAKE_CLICK', 'fbp' => 'fb.1.1700000000000.1234',
            'ttclid' => 'E.C.P.FAKE_CLICK', 'ttp' => 'FAKE_TTP'];
        DB::table('acquisition_sources')->where('id', $old->id)->update(['context_encrypted' => Crypt::encryptString(json_encode($context))]);

        return [$pixel, $link, $source];
    }

    private function capture(AttributionSnapshot $source, ?CheckoutPageContext $page = null): ?AttributionSnapshot
    {
        return app(CheckoutAttribution::class)->capture($source, $page ?? new CheckoutPageContext('/membership', now()->subMinute()->toIso8601String()));
    }

    public function test_capture_matches_six_fields_and_encrypts_current_purchase_evidence_without_rewriting_source(): void
    {
        foreach (['meta', 'tiktok'] as $platform) {
            app(TrackingContext::class)->clear();
            [$pixel, , $source] = $this->trustedSource($platform);
            $before = (array) DB::table('acquisition_sources')->where('id', $source->source_id)->first();
            $captured = $this->capture($source, new CheckoutPageContext('/membership', now()->subMinutes(30)->format('Y-m-d\TH:i:s.u\Z')));
            $this->assertNotNull($captured);
            $this->assertSame($source->toArray(), array_diff_key($captured->toArray(), ['checkout_context_id' => 1]));
            $row = DB::table('acquisition_checkout_contexts')->where('id', $captured->checkout_context_id)->sole();
            $this->assertSame($pixel['id'], $row->pixel_id);
            $this->assertStringNotContainsString('8.8.4.4', $row->context_encrypted);
            $data = json_decode(Crypt::decryptString($row->context_encrypted), true);
            $this->assertSame('https://localhost/membership', $data['page_url']);
            $this->assertSame('8.8.4.4', $data['ip']);
            $this->assertSame(request()->userAgent(), $data['user_agent']);
            $this->assertSame($platform === 'meta' ? ['page_url', 'ip', 'user_agent', 'fbc', 'fbp'] : ['page_url', 'ip', 'user_agent', 'ttclid', 'ttp'], array_keys($data));
            $this->assertLessThanOrEqual(now()->addDay()->timestamp, strtotime($row->expires_at));
            $this->assertLessThanOrEqual(strtotime($before['expires_at']), strtotime($row->expires_at));
            $this->assertSame($before, (array) DB::table('acquisition_sources')->where('id', $source->source_id)->first());
            $this->assertNull($this->capture($captured)); // no implicit recapture from a fixed attempt reference
        }
        $this->assertDatabaseCount('acquisition_subjects', 0);
        $this->assertDatabaseCount('acquisition_analytics_orders', 0);
        Http::assertNothingSent();
    }

    public function test_untrusted_page_request_and_each_mismatched_source_field_capture_nothing(): void
    {
        [, , $source] = $this->trustedSource();
        foreach ([['/other', now()->toIso8601String()], ['/membership', now()->addSecond()->toIso8601String()],
            ['/membership', now()->subMinutes(30)->subMicrosecond()->format('Y-m-d\TH:i:s.u\Z')], ['/membership', 'yesterday']] as [$path, $time]) {
            $this->assertNull($this->capture($source, new CheckoutPageContext($path, $time)));
        }
        foreach (['source_id' => AcquisitionStore::id(), 'link_id' => AcquisitionStore::id(), 'channel' => 'tiktok',
            'pixel_config_version' => 999, 'captured_at' => now()->toIso8601String(), 'consent' => 'unknown'] as $key => $value) {
            $data = array_replace($source->toArray(), [$key => $value]);
            $this->assertNull($this->capture(new AttributionSnapshot(...$data)), $key);
        }
        foreach ([['HTTP_ORIGIN' => 'https://evil.example'], ['HTTP_ORIGIN' => ''], ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
            ['HTTP_X_FORWARDED_FOR' => '8.8.8.8'], ['REMOTE_ADDR' => '127.0.0.1'], ['HTTP_USER_AGENT' => ''],
            ['HTTP_PURPOSE' => 'prefetch']] as $server) {
            $this->purchaseRequest($server);
            $this->assertNull($this->capture($source));
        }
        $this->assertDatabaseCount('acquisition_checkout_contexts', 0);
    }

    public function test_absent_consent_expired_source_disabled_pixel_and_unavailable_module_do_not_capture(): void
    {
        [$pixel, , $source] = $this->trustedSource();
        config(['h5.modules.acquisition.ready' => false]);
        $this->assertNull($this->capture($source));
        config(['h5.modules.acquisition.ready' => true]);
        DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['active' => false]);
        $this->assertNull($this->capture($source));
        DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['active' => true]);
        DB::table('acquisition_sources')->where('id', $source->source_id)->update(['expires_at' => now()]);
        $this->assertNull($this->capture($source));
        DB::table('acquisition_sources')->where('id', $source->source_id)->update(['expires_at' => now()->addHour()]);
        app(TrackingContext::class)->consent(false, false);
        $this->assertNull($this->capture($source));
        $this->assertDatabaseCount('acquisition_checkout_contexts', 0);
        Http::assertNothingSent();
    }

    public function test_ad_only_real_guard_landing_and_choices_create_no_identity_behavior_c_or_r1_records(): void
    {
        [$pixel, $link] = $this->assets();
        $this->staging();
        (new AcquisitionServiceProvider($this->app))->boot();
        $this->purchaseRequest();
        $identity = Mockery::mock(IdentityContext::class);
        $identity->shouldNotReceive('viewer');
        $this->app->instance(IdentityContext::class, $identity);
        $catalog = Mockery::mock(CatalogReader::class);
        $catalog->shouldReceive('drama')->andReturn(new Drama($link['drama_id'], 'Fixture', '', null, 0, 0, 5, 'published', []));
        $this->app->instance(CatalogReader::class, $catalog);
        $c = app(TrackingContext::class);
        $c->consent(true, true); // crafted analysis=true cannot become a grant
        $public = $c->publicContext();
        $this->assertTrue($public['enabled']);
        $this->assertTrue($public['advertising_test_only']);
        $this->assertFalse($public['analytics_available']);
        $this->assertSame(['analytics' => 'denied', 'advertising' => 'granted'], $public['consent']);
        app(AttributionRecorder::class)->prepare(basename($link['url']));
        $this->assertTrue(app(AttributionRecorder::class)->confirm($link['drama_id'], AcquisitionStore::id(), ['fbclid' => 'FAKE_TEST_CLICK']));
        $c->syncViewer(AcquisitionStore::id());
        $this->assertNull($c->subject(AcquisitionStore::id()));
        $recorder = app(BehaviorRecorder::class);
        $this->assertSame(BehaviorRecordingResult::Skipped, $recorder->recordFact('page_view', [], 'fake'));
        $this->assertSame(BehaviorRecordingResult::Skipped, $recorder->browser(AcquisitionStore::id(), 'page_view', []));
        $snapshot = app(AttributionRecorder::class)->snapshot(null);
        $capture = $this->capture($snapshot);
        $this->assertNotNull($capture);
        app(PaymentReceiver::class)->handle($this->event($capture));
        $c->consent(true, false);
        $this->assertSame('denied', $c->publicContext()['consent']['advertising']);
        $coreResolved = false;
        $this->app->bind(CoreContext::class, function () use (&$coreResolved) {
            $coreResolved = true;
            throw new \RuntimeException('C must stay off in ad-only context clearing');
        });
        $recorder->clearBrowserContext();
        $this->assertFalse($coreResolved);

        foreach (['acquisition_subjects', 'acquisition_behaviors', 'acquisition_analytics_orders',
            'acquisition_c_subjects', 'acquisition_c_sessions', 'acquisition_c_events', 'acquisition_r1_proofs', 'acquisition_r1_choices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('acquisition_privacy')->whereNotNull('viewer_id')->count());
        $this->assertDatabaseHas('acquisition_sources', ['id' => $snapshot->source_id, 'subject_id' => null]);
        Http::assertNothingSent();
    }

    public function test_staging_both_platforms_send_minimal_fake_http_once_and_retry_exact_bytes_with_frozen_context(): void
    {
        foreach (['meta', 'tiktok'] as $platform) {
            $this->environment('testing');
            config(['h5.acquisition_staging_test' => false, 'h5.controlled_staging' => false, 'app.url' => 'https://localhost']);
            app(TrackingContext::class)->clear();
            [, , $source] = $this->trustedSource($platform);
            $this->staging();
            $this->purchaseRequest();
            $capture = $this->capture($source);
            $this->assertNotNull($capture);
            $event = $this->event($capture, TransactionKind::Resubscription);
            app(PaymentReceiver::class)->handle($event);
            app(PaymentReceiver::class)->handle($event);
            $delivery = DB::table('acquisition_deliveries')->where('receipt_id', $event->event_id)->sole();
            $this->httpCredentials($delivery);
            config(['app.url' => 'https://staging.example.test']);
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::sequence()->push([], 503)->push($platform === 'meta' ? ['events_received' => 1] : ['code' => 0], 200)]);
            app(DeliveryWorker::class)->deliver($delivery->id);
            $frozen = DB::table('acquisition_deliveries')->where('id', $delivery->id)->sole();
            $this->assertSame('retry', $frozen->state);
            // Later landing and pixel edits never mutate this order's purchase evidence/payload.
            DB::table('acquisition_sources')->where('id', $source->source_id)->update(['context_encrypted' => Crypt::encryptString('{"page_url":"https://wrong.example/"}')]);
            $this->purchaseRequest(['HTTP_USER_AGENT' => 'Later browser must not replace frozen checkout UA']);
            $this->travel(61)->seconds();
            app(DeliveryWorker::class)->deliver($delivery->id);
            $row = DB::table('acquisition_deliveries')->where('id', $delivery->id)->sole();
            $this->assertSame('api_accepted', $row->state);
            $this->assertSame(2, $row->attempts);
            $this->assertSame($frozen->payload_encrypted, $row->payload_encrypted);
            $requests = Http::recorded();
            $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
            $body = $requests[0][0]->data();
            $data = $body['data'][0];
            $this->assertSame('https://staging.example.test/membership', $platform === 'meta' ? $data['event_source_url'] : $data['page']['url']);
            $this->assertSame(['currency' => 'USD', 'value' => 12], $data[$platform === 'meta' ? 'custom_data' : 'properties']);
            $this->assertSame('Explicit checkout PHPUnit fake browser', ((array) $data[$platform === 'meta' ? 'user_data' : 'user'])[$platform === 'meta' ? 'client_user_agent' : 'user_agent']);
            foreach ([$source->source_id, $source->link_id, $event->order_id, $capture->checkout_context_id, 'discount_applied', 'transaction_kind', 'email'] as $private) {
                $this->assertStringNotContainsString($private, json_encode($body));
            }
            $this->assertFalse(app(DeliveryWorker::class)->deliver($delivery->id));
            Http::assertSentCount(2);
        }
        $this->assertDatabaseCount('acquisition_subjects', 0);
    }

    public function test_missing_wrong_expired_context_and_old_frozen_extra_fields_block_without_rewriting_json(): void
    {
        config(['acquisition.analytics_enabled' => false]);
        foreach (['missing', 'wrong', 'expired', 'old-payload'] as $case) {
            [, , , , $delivery] = $this->queueEvent(simulation: false);
            $this->httpCredentials($delivery);
            $receipt = DB::table('acquisition_receipts')->where('event_id', $delivery->receipt_id)->sole();
            $snapshot = json_decode($receipt->attribution_snapshot, true);
            $id = $snapshot['checkout_context_id'];
            if ($case === 'missing') {
                unset($snapshot['checkout_context_id']);
                DB::table('acquisition_receipts')->where('event_id', $receipt->event_id)->update(['attribution_snapshot' => json_encode($snapshot)]);
            } elseif ($case === 'wrong') {
                DB::table('acquisition_checkout_contexts')->where('id', $id)->update(['pixel_version' => 999]);
            } elseif ($case === 'expired') {
                DB::table('acquisition_checkout_contexts')->where('id', $id)->update(['expires_at' => now()]);
            } else {
                DB::table('acquisition_deliveries')->where('id', $delivery->id)->update(['payload_encrypted' => Crypt::encryptString('{"transaction_kind":"first_subscription","email":"PRIVATE"}')]);
            }
            $snapshotBefore = DB::table('acquisition_receipts')->where('event_id', $receipt->event_id)->value('attribution_snapshot');
            $payloadBefore = DB::table('acquisition_deliveries')->where('id', $delivery->id)->value('payload_encrypted');
            app(DeliveryWorker::class)->deliver($delivery->id);
            $reason = ['missing' => 'CHECKOUT_CONTEXT_REQUIRED', 'wrong' => 'CHECKOUT_CONTEXT_INVALID', 'expired' => 'CHECKOUT_CONTEXT_EXPIRED', 'old-payload' => 'FROZEN_PAYLOAD_NOT_ALLOWED'][$case];
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'last_error' => $reason, 'attempts' => 0]);
            $this->assertSame($snapshotBefore, DB::table('acquisition_receipts')->where('event_id', $receipt->event_id)->value('attribution_snapshot'));
            $this->assertSame($payloadBefore, DB::table('acquisition_deliveries')->where('id', $delivery->id)->value('payload_encrypted'));
        }
        Http::assertNothingSent();
    }

    public function test_staging_withdrawal_and_environment_changes_before_send_are_rechecked(): void
    {
        [, , $source] = $this->trustedSource();
        $this->staging();
        $this->purchaseRequest();
        $captured = $this->capture($source);
        $event = $this->event($captured);
        app(PaymentReceiver::class)->handle($event);
        $delivery = DB::table('acquisition_deliveries')->sole();
        $this->httpCredentials($delivery);
        config(['app.url' => 'https://staging.example.test']);
        app(TrackingContext::class)->consent(false, false);
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertDatabaseHas('acquisition_deliveries', ['last_error' => 'CONSENT_REQUIRED_OR_REVOKED', 'attempts' => 0]);
        $this->assertEquals($captured->toArray(), json_decode(DB::table('acquisition_receipts')->sole()->attribution_snapshot, true));
        config(['h5.acquisition_staging_test' => false]);
        $this->assertSame('STAGING_TEST_RUNTIME_INVALID', DeliveryGate::runtimeReason());
        $this->environment('production');
        $this->assertSame('STAGING_TEST_RUNTIME_INVALID', DeliveryGate::runtimeReason());
        Http::assertNothingSent();
    }

    public function test_staging_rejects_renewal_simulation_real_payment_and_production_asset_but_local_renewal_remains(): void
    {
        config(['acquisition.analytics_enabled' => false]);
        [, , , $event, $delivery] = $this->queueEvent(simulation: false);
        $this->staging();
        $receipt = DB::table('acquisition_receipts')->sole();
        $source = DB::table('acquisition_sources')->sole();
        $pixel = DB::table('acquisition_pixels')->sole();
        $gate = app(DeliveryGate::class);
        config(['acquisition.transport' => 'http', 'acquisition.external_enabled' => true, 'acquisition.platform_reviewed' => true]);
        $source->is_simulation = true;
        $this->assertSame('SIMULATED_SOURCE_EXTERNAL_FORBIDDEN', $gate->reason($delivery, $receipt, $source, $pixel, []));
        $source->is_simulation = false;
        $receipt->transaction_kind = 'renewal';
        $this->assertSame('STAGING_RENEWAL_NOT_RELEASED', $gate->reason($delivery, $receipt, $source, $pixel, []));
        $receipt->transaction_kind = 'first_subscription';
        $receipt->is_test = false;
        $delivery->environment = 'production';
        $this->assertSame('STAGING_TEST_ASSET_REQUIRED', $gate->reason($delivery, $receipt, $source, $pixel, []));
        config(['acquisition.transport' => 'local_simulation']);
        $this->assertSame('STAGING_TEST_RUNTIME_INVALID', $gate->reason($delivery, $receipt, $source, $pixel, []));
        $this->environment('testing');
        config(['h5.acquisition_staging_test' => false, 'h5.controlled_staging' => false]);
        $source->is_simulation = true;
        $receipt->transaction_kind = 'renewal';
        $receipt->is_test = true;
        $delivery->environment = 'test';
        $this->assertNull($gate->reason($delivery, $receipt, $source, $pixel, []));
        Http::assertNothingSent();
    }

    public function test_nonlocal_cli_uses_real_guard_and_only_test_assets_without_enabling_outbound(): void
    {
        [$pixel] = $this->assets();
        [$production] = $this->assets(environment: 'production');
        $directory = realpath(sys_get_temp_dir()).'/h5-acq-secret-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/credentials.json';
        config(['acquisition.credentials_file' => $path]);
        $this->privateFiles[] = $path;
        $this->privateFiles[] = $path.'.lock';
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

                return ['token' => 'FAKE_TEST_ONLY_TOKEN', 'test_event_code' => 'FAKE_TEST_CODE'];
            }
        };
        $this->app->instance(CredentialPrompter::class, $fake);
        $this->staging();
        $this->artisan('acquisition:credentials:set', ['pixel' => $pixel['id'], 'version' => 1])
            ->doesntExpectOutput('FAKE_TEST_ONLY_TOKEN')->assertExitCode(0);
        $before = file_get_contents($path);
        $this->assertSame(1, $fake->calls);
        $this->assertSame('disabled', config('acquisition.transport'));
        $this->assertFalse(config('acquisition.external_enabled'));
        $this->artisan('acquisition:credentials:set', ['pixel' => $production['id'], 'version' => 1])->assertExitCode(1);
        $this->assertSame(1, $fake->calls);
        $fake->beforeReturn = fn () => config(['h5.acquisition_staging_test' => false]);
        $this->artisan('acquisition:credentials:set', ['pixel' => $pixel['id'], 'version' => 1])->assertExitCode(1);
        $this->assertSame(2, $fake->calls);
        $this->assertSame($before, file_get_contents($path));
        $this->environment('production');
        $this->artisan('acquisition:credentials:set', ['pixel' => $pixel['id'], 'version' => 1])->assertExitCode(1);
        $this->assertSame(2, $fake->calls);
        try {
            (new AcquisitionServiceProvider($this->app))->boot();
            $this->fail('Production must refuse module boot');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        Http::assertNothingSent();
    }

    public function test_external_config_keeps_invalid_raw_values_for_real_guard_rejection(): void
    {
        $script = 'require "vendor/autoload.php"; $c = require "app/Modules/Acquisition/config.php"; echo json_encode([$c["external_enabled"], $c["platform_reviewed"]]);';
        $cases = [
            ['false', 'false', [false, false], false], ['true', 'true', [true, true], true],
            ['junk', 'true', ['junk', true], false], ['true', 'junk', [true, 'junk'], false],
            ['1', 'true', ['1', true], false], ['true', '0', [true, '0'], false],
        ];
        foreach ($cases as [$external, $reviewed, $expected, $allowed]) {
            $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
                'ACQUISITION_EXTERNAL_ENABLED' => $external, 'ACQUISITION_PLATFORM_REVIEWED' => $reviewed,
            ]);
            $process->setTimeout(10)->run();
            $this->assertSame(0, $process->getExitCode());
            $this->assertSame($expected, json_decode($process->getOutput(), true));
            $this->staging();
            config(['acquisition.transport' => 'http', 'acquisition.external_enabled' => $expected[0], 'acquisition.platform_reviewed' => $expected[1]]);
            $this->assertSame($allowed ? null : 'STAGING_TEST_RUNTIME_INVALID', DeliveryGate::runtimeReason());
        }
        Http::assertNothingSent();
    }

    public function test_each_adapter_refuses_default_closed_or_changed_nonlocal_guard_before_http(): void
    {
        $delivery = (object) ['external_id' => '123456789'];
        foreach ([new MetaAdapter, new TikTokAdapter] as $adapter) {
            $this->assertSame('EXTERNAL_REVIEW_REQUIRED', $adapter->send($delivery, [], 'FAKE_NOT_SENT')->error);
            $this->staging();
            config(['acquisition.analytics_enabled' => true]);
            $this->assertSame('STAGING_TEST_RUNTIME_INVALID', $adapter->send($delivery, [], 'FAKE_NOT_SENT')->error);
            $this->environment('testing');
            config(['h5.acquisition_staging_test' => false, 'h5.controlled_staging' => false]);
        }
        Http::assertNothingSent();
    }

    public function test_new_migration_preserves_legacy_rows_and_capture_storage_failure_returns_null(): void
    {
        [, , $source] = $this->trustedSource();
        config(['acquisition.analytics_enabled' => false]);
        app(PaymentReceiver::class)->handle($this->event($source));
        $tables = ['acquisition_sources', 'acquisition_receipts', 'acquisition_deliveries', 'acquisition_pixels'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $migration = require database_path('migrations/2026_11_01_050009_create_acquisition_checkout_contexts.php');
        $migration->down();
        config(['acquisition.analytics_enabled' => true]);
        $this->assertNull($this->capture($source));
        $this->assertSame(1, DB::table('acquisition_receipts')->count()); // savepoint left outer transaction usable
        $migration->up();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson());
        }
        $snapshot = json_decode(DB::table('acquisition_receipts')->sole()->attribution_snapshot, true);
        $this->assertCount(6, $snapshot);
        $this->assertArrayNotHasKey('checkout_context_id', $snapshot);
        $this->assertDatabaseCount('acquisition_checkout_contexts', 0);
    }

    public function test_prune_removes_only_expired_purchase_contexts_and_keeps_receipt_and_source(): void
    {
        [, , $source] = $this->trustedSource();
        $captured = $this->capture($source);
        config(['acquisition.analytics_enabled' => false]);
        app(PaymentReceiver::class)->handle($this->event($captured));
        $before = DB::table('acquisition_receipts')->sole()->attribution_snapshot;
        DB::table('acquisition_checkout_contexts')->where('id', $captured->checkout_context_id)->update(['expires_at' => now()]);
        $this->artisan('acquisition:analytics-prune')->assertSuccessful();
        $this->assertDatabaseCount('acquisition_checkout_contexts', 0);
        $this->assertDatabaseCount('acquisition_sources', 1);
        $this->assertSame($before, DB::table('acquisition_receipts')->sole()->attribution_snapshot);
        $this->assertDatabaseCount('acquisition_deliveries', 1);
    }
}
