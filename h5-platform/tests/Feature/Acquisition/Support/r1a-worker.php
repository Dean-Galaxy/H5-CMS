<?php

// CLI-only test of the real R1 service on this checkout's explicitly marked synthetic HTTP DB.
use App\Modules\Acquisition\Registration\PurposeConflict;
use App\Modules\Acquisition\Registration\PurposePolicy;
use App\Modules\Acquisition\Registration\PurposeProof;
use App\Modules\Acquisition\Registration\PurposeStore;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(base_path('..'));
$owned = json_decode(file_get_contents($root.'/.runtime/r1-a-owned.json'), true);
$database = config('database.connections.pgsql.database');
if (! app()->environment('testing') || ! str_ends_with($database, '_r1a_http_test')
    || ($owned['root'] ?? '') !== $root || ! in_array($database, $owned['databases'] ?? [], true)
    || realpath(dirname($argv[1] ?? '')) !== $root.'/.runtime') {
    throw new RuntimeException('Refuse unmarked R1 test worker input.');
}
$input = json_decode(file_get_contents($argv[1]), true);
$r = Request::create('http://localhost/api/v3/measurement/purposes/preferences', 'PATCH');
$session = app('session.store');
$session->start();
$r->setLaravelSession($session);
$app->instance('request', $r);
$r->cookies->set(app(PurposeProof::class)->cookie(), $input['proof']);
if ($input['actor'] ?? null) {
    Auth::guard('web')->login(Viewer::findOrFail($input['actor']));
}
try {
    $result = app(PurposeStore::class)->change($input['purpose'], $input['choice'], PurposePolicy::NOTICE, $input['revision'], $input['operation']);
    echo json_encode(['status' => 200, 'receipt' => $result]);
} catch (PurposeConflict $e) {
    echo json_encode(['status' => 409, 'code' => $e->problemCode, 'state' => $e->state]);
} catch (Throwable) {
    echo json_encode(['status' => 500, 'code' => 'TEST_WORKER_FAILED']);
    exit(1);
}
