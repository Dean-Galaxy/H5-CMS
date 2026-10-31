<?php

use App\Modules\Acquisition\Sending\PrivateCredentialFile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

// Explicit fake-only file concurrency fixture, no database or platform requests.
require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || count($argv) !== 3 || ! preg_match('~/h5-acq-file-test-[a-f0-9]+/credentials.json$~', $argv[1])
    || ! Str::isUuid($argv[2])) {
    exit(2);
}
config(['acquisition.credentials_file' => $argv[1]]);
app(PrivateCredentialFile::class)->save((object) ['pixel_id' => $argv[2], 'pixel_version' => 1,
    'platform' => 'meta', 'external_id' => '12345678', 'environment' => 'test'], 'EXPLICIT_FAKE_CONCURRENCY_TOKEN', 'TEST_ONLY');
echo "saved-fake-only\n";
