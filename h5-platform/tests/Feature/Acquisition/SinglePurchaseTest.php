<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\Labels;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Acquisition\Services\PaymentReceiver;
use App\Modules\Acquisition\Services\SinglePurchaseTestSender;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

final class SinglePurchaseTest extends AcquisitionTestCase
{
    private array $originalEnvironment;

    private array $processes = [];

    private ?string $directory = null;

    public function refreshDatabase(): void
    {
        $this->app->register(AcquisitionServiceProvider::class);
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $this->freezeTime();
        $this->signIn();
        config(['acquisition.analytics_enabled' => false, 'app.url' => 'https://localhost']);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.1, 9);
            }
        }
        if ($this->directory) {
            File::deleteDirectory($this->directory);
        }
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

    private function fixture(string $platform = 'meta'): array
    {
        $this->environment('testing');
        config(['app.url' => 'https://localhost', 'h5.acquisition_staging_test' => false, 'h5.controlled_staging' => false]);
        $data = $this->queueEvent($platform, simulation: false); // Explicit settled fact + checkout fixture, NOT official Sandbox evidence.
        $this->httpCredentials($data[4]);
        $this->staging();
        $contextId = $data[3]->attribution_snapshot->checkout_context_id;
        $row = DB::table('acquisition_checkout_contexts')->where('id', $contextId)->sole();
        $context = json_decode(Crypt::decryptString($row->context_encrypted), true);
        $context['page_url'] = 'https://staging.example.test/membership';
        DB::table('acquisition_checkout_contexts')->where('id', $contextId)->update(['context_encrypted' => Crypt::encryptString(json_encode($context))]);

        return $data;
    }

    public function test_exact_cli_uses_each_real_adapter_once_preserves_peer_and_facts_and_cannot_resend(): void
    {
        foreach (['meta', 'tiktok'] as $platform) {
            [, , , $event, $row] = $this->fixture($platform);
            $peerEvent = $this->event($event->attribution_snapshot);
            app(PaymentReceiver::class)->handle($peerEvent);
            $peer = DB::table('acquisition_deliveries')->where('receipt_id', $peerEvent->event_id)->sole();
            $before = (array) DB::table('acquisition_deliveries')->where('id', $peer->id)->sole();
            $receipt = (array) DB::table('acquisition_receipts')->where('event_id', $event->event_id)->sole();
            Http::swap(new Factory(app('events')));
            Http::preventStrayRequests();
            Http::fake(function ($request) use ($row, $platform) {
                $this->assertSame(0, DB::transactionLevel());
                $stored = DB::table('acquisition_deliveries')->where('id', $row->id)->sole();
                $this->assertSame('failed', $stored->state);
                $this->assertSame('PURCHASE_TEST_RESERVED_NO_RETRY', $stored->last_error);
                $this->assertSame(1, $stored->attempts);
                $body = $request->data();
                $data = $body['data'][0];
                $this->assertSame($row->event_key, $data['event_id']);
                $this->assertSame('Purchase', $data[$platform === 'meta' ? 'event_name' : 'event']);
                $this->assertSame(['currency' => 'USD', 'value' => 3], $data[$platform === 'meta' ? 'custom_data' : 'properties']);
                $this->assertSame('UNIT_TEST', $body['test_event_code']);
                $this->assertStringNotContainsString('UNIT-TEST-NOT-A-REAL-SECRET', $request->url());

                return Http::response($platform === 'meta' ? ['events_received' => 1] : ['code' => 0], 200);
            });
            $this->artisan('acquisition:purchase-test', ['delivery' => $row->id])->assertSuccessful();
            $this->artisan('acquisition:purchase-test', ['delivery' => $row->id])->assertFailed();
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $row->id, 'state' => 'api_accepted', 'attempts' => 1]);
            $this->assertSame($before, (array) DB::table('acquisition_deliveries')->where('id', $peer->id)->sole());
            $this->assertSame($receipt, (array) DB::table('acquisition_receipts')->where('event_id', $event->event_id)->sole());
            Http::assertSentCount(1);
        }
        foreach (['billing_orders', 'billing_payments', 'billing_outbox', 'acquisition_subjects', 'acquisition_behaviors', 'acquisition_c_events', 'acquisition_r1_choices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_503_disconnect_malformed_success_and_explicit_rejection_remain_terminal_and_ui_is_unambiguous(): void
    {
        foreach (['503', 'disconnect', 'malformed', 'rejected', 'tiktok_rejected'] as $case) {
            [, $link, , , $row] = $this->fixture($case === 'tiktok_rejected' ? 'tiktok' : 'meta');
            Http::swap(new Factory(app('events')));
            Http::preventStrayRequests();
            Http::fake(['*' => match ($case) {
                '503' => Http::response([], 503), 'disconnect' => Http::failedConnection(),
                'tiktok_rejected' => Http::response(['code' => 40001, 'message' => 'explicit fake rejection'], 200),
                'rejected' => Http::response(['error' => ['code' => 100, 'message' => 'explicit fake rejection']], 400),
                default => Http::response(['unexpected' => true], 200),
            }]);
            $result = app(SinglePurchaseTestSender::class)->send($row->id);
            $this->assertSame(in_array($case, ['rejected', 'tiktok_rejected'], true) ? 'rejected' : 'unconfirmed', $result['state'], json_encode($result));
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $row->id, 'state' => 'failed', 'attempts' => 1, 'last_error' => $result['reason']]);
            $stored = (array) DB::table('acquisition_deliveries')->where('id', $row->id)->sole();
            $this->assertSame('PURCHASE_TEST_NOT_FRESH', app(SinglePurchaseTestSender::class)->send($row->id)['reason']);
            $this->assertFalse(app(DeliveryWorker::class)->deliver($row->id));
            $this->assertSame(0, app(AssetManager::class)->retry($link['id']));
            $this->assertSame($stored, (array) DB::table('acquisition_deliveries')->where('id', $row->id)->sole());
            if ($case !== 'disconnect') {
                Http::assertSentCount(1);
            }
        }
        $this->assertStringContainsString('结果未知', Labels::error('PURCHASE_TEST_UNKNOWN_NO_RETRY'));
        $this->assertStringContainsString('未确认', Labels::error('PURCHASE_TEST_RESERVED_NO_RETRY'));
        $this->assertStringContainsString('明确拒收', Labels::error('PURCHASE_TEST_REJECTED_NO_RETRY'));
        $this->assertStringNotContainsString('失败', Labels::state('failed'));
    }

    public function test_invalid_scope_or_nonfresh_state_never_reserves_or_sends(): void
    {
        foreach (['real', 'renewal', 'simulation', 'production_asset', 'version', 'lease', 'payload', 'attempt', 'retry', 'consent', 'runtime', 'disabled'] as $case) {
            [$pixel, , $source, $event, $row] = $this->fixture();
            match ($case) {
                'real' => DB::table('acquisition_receipts')->where('event_id', $event->event_id)->update(['is_test' => false]),
                'renewal' => DB::table('acquisition_receipts')->where('event_id', $event->event_id)->update(['transaction_kind' => 'renewal']),
                'simulation' => DB::table('acquisition_sources')->where('id', $source->source_id)->update(['is_simulation' => true]),
                'production_asset' => DB::table('acquisition_deliveries')->where('id', $row->id)->update(['environment' => 'production']),
                'version' => DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['version' => 2]),
                'lease' => DB::table('acquisition_deliveries')->where('id', $row->id)->update(['lease_token' => (string) Str::uuid()]),
                'payload' => DB::table('acquisition_deliveries')->where('id', $row->id)->update(['payload_encrypted' => 'old opaque bytes']),
                'attempt' => DB::table('acquisition_deliveries')->where('id', $row->id)->update(['attempts' => 1]),
                'retry' => DB::table('acquisition_deliveries')->where('id', $row->id)->update(['state' => 'retry']),
                'consent' => DB::table('acquisition_privacy')->update(['advertising' => 'denied']),
                'runtime' => $this->environment('production'),
                'disabled' => config(['acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false]),
            };
            $before = (array) DB::table('acquisition_deliveries')->where('id', $row->id)->sole();
            $this->assertSame('blocked', app(SinglePurchaseTestSender::class)->send($row->id)['state'], $case);
            $this->assertSame($before, (array) DB::table('acquisition_deliveries')->where('id', $row->id)->sole());
            Http::assertNothingSent();
        }
    }

    public function test_consent_and_version_changes_after_commit_consume_budget_but_do_not_send(): void
    {
        foreach (['consent', 'version'] as $case) {
            [$pixel, , , , $row] = $this->fixture();
            $armed = true;
            DB::listen(function (QueryExecuted $query) use (&$armed, $case, $pixel) {
                if ($armed && str_starts_with($query->sql, 'update "acquisition_deliveries"') && in_array('PURCHASE_TEST_RESERVED_NO_RETRY', $query->bindings, true)) {
                    $armed = false;
                    DB::afterCommit(function () use ($case, $pixel) {
                        if ($case === 'consent') {
                            DB::table('acquisition_privacy')->update(['advertising' => 'denied']);
                        } else {
                            DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['version' => 2]);
                        }
                    });
                }
            });
            $result = app(SinglePurchaseTestSender::class)->send($row->id);
            $this->assertFalse($armed);
            $this->assertSame('blocked', $result['state']);
            $this->assertStringStartsWith('PURCHASE_TEST_STOP:', $result['reason']);
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $row->id, 'state' => 'failed', 'attempts' => 1]);
            Http::assertNothingSent();
        }
    }

    private function child(object $row, string $mode): array
    {
        $this->directory ??= realpath(sys_get_temp_dir()).'/h5-purchase-once-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory, 0700);
        $dir = $this->directory.'/'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $keys = ['app.url', 'app.debug', 'h5.acquisition_staging_test', 'h5.controlled_staging', 'h5.simulation', 'h5.local_us_test_region', 'h5.local_modules', 'h5.modules.acquisition', 'h5.measurement_c_experience', 'h5.measurement_c_registration', 'acquisition.analytics_enabled', 'acquisition.measurement', 'acquisition.registration', 'acquisition.transport', 'acquisition.external_enabled', 'acquisition.platform_reviewed', 'acquisition.credentials_file', 'acquisition.meta_api_version', 'session.secure', 'session.http_only', 'session.same_site', 'trustedproxy.proxies', 'mail.default', 'mail.driver', 'mail.from.address', 'mail.mailers'];
        $cfg = [];
        foreach ($keys as $key) {
            $cfg[$key] = config($key);
        }
        file_put_contents($dir.'/config.json', json_encode(['config' => $cfg, 'now' => now()->toIso8601String()]));
        chmod($dir.'/config.json', 0600);
        $process = new Process([PHP_BINARY, __DIR__.'/Support/purchase-test-worker.php', $row->id, $mode, $dir], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'H5_LOCAL_MODULES' => '']);
        $process->setTimeout(15);
        $process->start();
        $this->processes[] = $process;

        return [$process, $dir];
    }

    private function barrier(Process $process, string $file): void
    {
        $until = microtime(true) + 8;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $until) {
            usleep(10000);
        }
        $this->assertFileExists($file, $process->getErrorOutput().$process->getOutput());
    }

    public function test_real_process_competition_and_death_after_reservation_never_reclaim_or_retry(): void
    {
        foreach (['meta', 'tiktok'] as $platform) {
            [, $link, , , $row] = $this->fixture($platform);
            [$first, $dir] = $this->child($row, 'hold');
            $this->barrier($first, $dir.'/reserved');
            $stored = (array) DB::table('acquisition_deliveries')->where('id', $row->id)->sole();
            $this->assertSame('failed', $stored['state']);
            $this->assertSame(1, $stored['attempts']);
            [$other, $otherDir] = $this->child($row, 'complete');
            $this->assertSame(1, $other->wait(), $other->getErrorOutput());
            $this->assertFileDoesNotExist($otherDir.'/io');
            if ($platform === 'meta') {
                touch($dir.'/release');
                $this->assertSame(0, $first->wait(), $first->getErrorOutput());
                $this->assertSame('api_accepted', DB::table('acquisition_deliveries')->where('id', $row->id)->value('state'));
                $this->assertSame('1', file_get_contents($dir.'/io'));
            } else {
                $first->signal(9);
                $first->wait();
                $this->assertSame(9, $first->getTermSignal());
                $this->assertFileDoesNotExist($dir.'/io');
                $this->assertSame($stored, (array) DB::table('acquisition_deliveries')->where('id', $row->id)->sole());
                $this->assertFalse(app(DeliveryWorker::class)->deliver($row->id));
                $this->assertSame(0, app(AssetManager::class)->retry($link['id']));
                $this->assertSame('PURCHASE_TEST_NOT_FRESH', app(SinglePurchaseTestSender::class)->send($row->id)['reason']);
                $this->assertSame($stored, (array) DB::table('acquisition_deliveries')->where('id', $row->id)->sole());
            }
        }
    }
}
