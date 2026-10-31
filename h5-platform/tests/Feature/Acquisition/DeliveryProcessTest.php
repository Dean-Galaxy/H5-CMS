<?php

namespace Tests\Feature\Acquisition;

use App\Data\AttributionSnapshot;
use App\Data\PaymentSettled;
use App\Enums\TransactionKind;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\PaymentReceiver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class DeliveryProcessTest extends TestCase
{
    use DatabaseMigrations;

    private array $processes = [];

    private string $directory;

    private array $proof = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->directory = realpath(sys_get_temp_dir()).'/h5-acq-worker-'.AcquisitionStore::id();
        mkdir($this->directory, 0700, true);
        File::ensureDirectoryExists(base_path('../.runtime/f07'), 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.1, 9);
            }
        }
        if ($this->proof !== []) {
            file_put_contents(base_path('../.runtime/f07/'.$this->name().'.json'), json_encode($this->proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function fixture(string $platform): array
    {
        // Only this module's tables; explicit testing source, not a public attribution writer.
        $pixel = AcquisitionStore::id();
        $link = AcquisitionStore::id();
        $source = AcquisitionStore::id();
        $external = $platform === 'meta' ? (string) random_int(10000000, 99999999) : 'TEST'.bin2hex(random_bytes(5));
        $time = now()->startOfSecond();
        DB::table('acquisition_pixels')->insert(['id' => $pixel, 'owner_id' => AcquisitionStore::id(), 'name' => 'F07 test only',
            'platform' => $platform, 'external_id' => $external, 'environment' => 'test', 'created_at' => $time, 'updated_at' => $time]);
        DB::table('acquisition_pixel_versions')->insert(['pixel_id' => $pixel, 'version' => 1, 'platform' => $platform,
            'external_id' => $external, 'environment' => 'test', 'created_at' => $time]);
        DB::table('acquisition_links')->insert(['id' => $link, 'owner_id' => AcquisitionStore::id(), 'name' => 'F07 test link',
            'code' => bin2hex(random_bytes(12)), 'drama_id' => AcquisitionStore::id(), 'drama_title' => 'Explicit fixture',
            'platform' => $platform, 'pixel_id' => $pixel, 'created_at' => $time, 'updated_at' => $time]);
        DB::table('acquisition_sources')->insert(['id' => $source, 'link_id' => $link, 'pixel_id' => $pixel, 'pixel_version' => 1,
            'channel' => $platform, 'consent' => 'granted', 'policy_version' => 'EXPLICIT_F07_TEST_NOT_D05',
            'captured_at' => $time, 'expires_at' => $time->copy()->addDay(), 'is_simulation' => false,
            'context_encrypted' => Crypt::encryptString(json_encode(['page_url' => 'https://localhost/test', 'ip' => '8.8.8.8', 'user_agent' => 'F07 explicit HTTP fake']))]);
        $privacy = AcquisitionStore::id();
        $context = AcquisitionStore::id();
        DB::table('acquisition_privacy')->insert(['id' => $privacy, 'analytics' => 'denied', 'advertising' => 'granted',
            'policy_version' => 'F07_TEST_ONLY', 'created_at' => $time, 'updated_at' => $time]);
        DB::table('acquisition_sources')->where('id', $source)->update(['privacy_id' => $privacy]);
        DB::table('acquisition_checkout_contexts')->insert(['id' => $context, 'source_id' => $source, 'privacy_id' => $privacy,
            'pixel_id' => $pixel, 'pixel_version' => 1, 'platform' => $platform,
            'context_encrypted' => Crypt::encryptString(json_encode(['page_url' => 'https://localhost/membership',
                'ip' => '8.8.8.8', 'user_agent' => 'F07 explicit HTTP fake'])),
            'rendered_at' => $time, 'captured_at' => $time, 'expires_at' => $time->copy()->addDays(2)]);
        $event = new PaymentSettled(AcquisitionStore::id(), 'PaymentSettled.v1', AcquisitionStore::id(), AcquisitionStore::id(),
            AcquisitionStore::id(), TransactionKind::FirstSubscription, true, 300, 'USD', $time->toIso8601String(), true,
            new AttributionSnapshot($source, $link, $platform, 1, $time->toIso8601String(), 'granted', $context));
        app(PaymentReceiver::class)->handle($event);
        $delivery = DB::table('acquisition_deliveries')->where('receipt_id', $event->event_id)->first();
        $credentials = $this->directory.'/'.$pixel.'.json';
        file_put_contents($credentials, json_encode([$pixel.':1' => ['platform' => $platform, 'external_id' => $external,
            'environment' => 'test', 'token' => 'EXPLICIT_F07_FAKE_TOKEN', 'test_event_code' => 'LOCAL_ONLY']]));
        chmod($credentials, 0600);

        return [$delivery, $credentials];
    }

    private function worker(object $delivery, string $credentials, string $mode): array
    {
        $directory = $this->directory.'/'.AcquisitionStore::id();
        mkdir($directory, 0700);
        $process = new Process([PHP_BINARY, __DIR__.'/Support/delivery-worker.php', $delivery->id, $mode, $directory, $credentials],
            base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'H5_LOCAL_MODULES' => '']);
        $process->setTimeout(25);
        $process->start();
        $this->processes[] = $process;

        return [$process, $directory];
    }

    private function observed(Process $process, string $file): array
    {
        $deadline = microtime(true) + 15;
        while (! is_file($file)) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                $this->fail('Child did not reach test barrier: '.$process->getErrorOutput().$process->getOutput());
            }
            usleep(10000);
        }

        return json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_real_process_takeover_leaves_resumed_old_preparer_without_transport_or_writes(): void
    {
        foreach (['meta', 'tiktok'] as $platform) {
            [$delivery, $credentials] = $this->fixture($platform);
            [$old, $oldDir] = $this->worker($delivery, $credentials, 'pause_payload');
            $paused = $this->observed($old, $oldDir.'/payload-ready.json');
            $this->assertSame(0, $paused['transaction_level']);
            // Controlled expiry; the second PHP process must actually acquire and finish the same row.
            DB::table('acquisition_deliveries')->where('id', $delivery->id)->update(['lease_until' => now()->subSecond()]);
            [$new, $newDir] = $this->worker($delivery, $credentials, 'complete');
            $io = $this->observed($new, $newDir.'/io.json');
            $this->assertSame(0, $new->wait(), $new->getErrorOutput());
            $this->assertNotSame($paused['pid'], $io['pid']);
            $this->assertSame(0, $io['transaction_level']);
            $expected = (array) DB::table('acquisition_deliveries')->where('id', $delivery->id)->first();
            $this->assertSame('api_accepted', $expected['state']);
            touch($oldDir.'/resume');
            $this->assertSame(0, $old->wait(), $old->getErrorOutput());
            $this->assertFileDoesNotExist($oldDir.'/io.json');
            $this->assertSame($expected, (array) DB::table('acquisition_deliveries')->where('id', $delivery->id)->first());
            $this->assertSame(1, $expected['attempts']);
            $this->proof[$platform] = ['paused' => $paused, 'new_holder_io' => $io, 'old_transport_calls' => 0, 'old_changed_new_state' => false];
        }
    }

    public function test_six_sigkills_inside_http_do_not_lose_reservations_or_allow_a_seventh_call(): void
    {
        foreach (['meta', 'tiktok'] as $platform) {
            [$delivery, $credentials] = $this->fixture($platform);
            $first = null;
            $frozen = null;
            for ($attempt = 1; $attempt <= 6; $attempt++) {
                [$worker, $directory] = $this->worker($delivery, $credentials, 'crash_io');
                $io = $this->observed($worker, $directory.'/io.json');
                $stored = DB::table('acquisition_deliveries')->where('id', $delivery->id)->first();
                $first ??= $stored->first_attempted_at;
                $frozen ??= $stored->payload_encrypted;
                $this->assertSame($attempt, $io['attempts']);
                $this->assertSame($attempt, $stored->attempts);
                $this->assertSame(0, $io['transaction_level']);
                $this->assertSame($io['request_sha256'], $io['frozen_sha256']);
                $this->assertSame($first, $stored->first_attempted_at);
                $this->assertSame($frozen, $stored->payload_encrypted);
                $this->assertSame($delivery->event_key, $stored->event_key);
                $worker->signal(9);
                $worker->wait();
                $this->assertTrue($worker->hasBeenSignaled());
                $this->assertSame(9, $worker->getTermSignal());
                DB::table('acquisition_deliveries')->where('id', $delivery->id)->update(['lease_until' => now()->subSecond()]);
                $this->proof[$platform]['killed_attempts'][] = $io + ['signal' => 9];
            }
            [$last, $lastDir] = $this->worker($delivery, $credentials, 'complete');
            $this->assertSame(0, $last->wait(), $last->getErrorOutput());
            $this->assertFileDoesNotExist($lastDir.'/io.json');
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'attempts' => 6,
                'state' => 'failed', 'last_error' => 'RETRY_EXHAUSTED', 'first_attempted_at' => $first, 'payload_encrypted' => $frozen]);
            $this->proof[$platform]['seventh_transport_calls'] = 0;
        }
    }
}
