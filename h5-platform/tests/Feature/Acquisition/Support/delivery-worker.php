<?php

use App\Modules\Acquisition\Sending\DeliveryResult;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Sending\PlatformAdapter;
use App\Modules\Acquisition\Sending\TikTokAdapter;
use App\Modules\Acquisition\Services\DeliveryWorker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getDatabaseName();
[$script, $id, $mode, $directory, $credentials] = $argv;
$directory = realpath($directory);
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || $db !== getenv('H5_TEST_DATABASE') || ! str_ends_with($db, '_test')
    || ! $directory || preg_match('~^'.preg_quote(realpath(sys_get_temp_dir()), '~').'/h5-acq-worker-[a-f0-9-]{36}/[a-f0-9-]{36}$~D', $directory) !== 1
    || ! in_array($mode, ['pause_payload', 'crash_io', 'complete'], true)) {
    throw new RuntimeException('Isolated Acquisition testing only.');
}
umask(0077);
config(['acquisition.transport' => 'http', 'acquisition.external_enabled' => true, 'acquisition.platform_reviewed' => true,
    'acquisition.max_attempts' => 6, 'acquisition.meta_api_version' => 'v26.0', 'acquisition.credentials_file' => $credentials,
    'app.url' => 'https://localhost']);
Http::preventStrayRequests();
$pid = DB::selectOne('select pg_backend_pid() as pid')->pid;
$write = static function (string $path, array $data): void {
    file_put_contents($path.'.writing', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    rename($path.'.writing', $path);
};
$waitFor = static function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }
};
$delivery = DB::table('acquisition_deliveries')->where('id', $id)->first();
$class = $delivery->platform === 'meta' ? MetaAdapter::class : TikTokAdapter::class;
if ($mode === 'pause_payload') {
    app()->instance($class, new class(new $class, $directory, $waitFor, $write, $pid) implements PlatformAdapter
    {
        public function __construct(private PlatformAdapter $inner, private string $directory, private Closure $waitFor, private Closure $write, private int $pid) {}

        public function payload(object $delivery, object $receipt, array $context, ?string $testCode): array
        {
            $body = $this->inner->payload($delivery, $receipt, $context, $testCode);
            ($this->write)($this->directory.'/payload-ready.json', ['pid' => $this->pid, 'transaction_level' => DB::transactionLevel()]);
            ($this->waitFor)($this->directory.'/resume');

            return $body;
        }

        public function send(object $delivery, array $payload, string $token): DeliveryResult
        {
            return $this->inner->send($delivery, $payload, $token);
        }
    });
}
Http::fake(function ($request) use ($id, $mode, $directory, $waitFor, $write, $pid, $delivery) {
    $stored = DB::table('acquisition_deliveries')->where('id', $id)->first();
    $write($directory.'/io.json', [
        'pid' => $pid, 'transaction_level' => DB::transactionLevel(), 'attempts' => $stored->attempts,
        'first_attempted_at' => $stored->first_attempted_at, 'event_key' => $stored->event_key,
        'request_sha256' => hash('sha256', $request->body()),
        'frozen_sha256' => $stored->payload_encrypted ? hash('sha256', Crypt::decryptString($stored->payload_encrypted)) : null,
    ]);
    if ($mode === 'crash_io') {
        // Parent will SIGKILL this process after independently reading the committed row.
        $waitFor($directory.'/release-io');
    }

    return Http::response($delivery->platform === 'meta' ? ['events_received' => 1] : ['code' => 0], 200);
});
$handled = app(DeliveryWorker::class)->deliver($id);
$write($directory.'/completed.json', ['handled' => $handled, 'pid' => $pid]);
echo "completed\n";
