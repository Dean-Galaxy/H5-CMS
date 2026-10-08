<?php

use App\Data\PaymentSettled;
use App\Modules\Acquisition\Services\PaymentReceiver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getDatabaseName();
if (! $app->environment('testing') || $db !== getenv('H5_TEST_DATABASE') || ! str_ends_with($db, '_test')) {
    throw new RuntimeException('Isolated PostgreSQL testing only.');
}
$input = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
echo json_encode(['pid' => DB::selectOne('select pg_backend_pid() as pid')->pid])."\n";
flush();
app(PaymentReceiver::class)->handle(PaymentSettled::fromArray($input));
echo "received\n";
