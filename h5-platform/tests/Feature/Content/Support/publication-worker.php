<?php

use App\Modules\Content\ContentServiceProvider;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Services\PublicationService;
use App\Modules\Identity\Models\Staff;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

// Never reachable through HTTP; invoked only by the isolated database race tests.
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = config('database.connections.pgsql.database');
if (! app()->environment('testing') || ! str_ends_with($db, '_test') || $db !== getenv('H5_TEST_DATABASE')) {
    throw new RuntimeException('Child test database safety guard: environment='.app()->environment().'; test database match='.(int) ($db === getenv('H5_TEST_DATABASE')));
}
$app->register(ContentServiceProvider::class);
$d = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
config(['h5.media_root' => $d['media_root'], 'session.driver' => 'array']);
Date::setTestNow(CarbonImmutable::parse($d['at']));
$staff = Staff::findOrFail($d['owner']);
auth('staff')->setUser($staff);
$session = app('session.store');
$session->start();
$session->put('identity.mfa_staff', $staff->id);
request()->setLaravelSession($session);
file_put_contents($d['ready'], 'ready');
$s = app(PublicationService::class);
try {
    $result = match ($d['action']) {
        'scheduled' => $s->execute($d['plan'], $d['plan_version'], CarbonImmutable::parse($d['at'])),
        'manual' => $s->manual($d['drama'], ['version' => $d['version'], 'through' => 3, 'confirmation' => $d['confirmation']]),
        'withdraw' => $s->withdraw($d['drama'], $d['version']),
        'edit' => $s->savePlan($d['drama'], ['version' => $d['plan_version'], 'mode' => 'updating', 'interval_days' => 2, 'batch_size' => 5,
            'start_number' => $s->nextNumber(DramaRecord::findOrFail($d['drama'])), 'launch_at' => now()->addDay()->toIso8601String()]),
    };
    echo json_encode(['result' => is_string($result) ? $result : 'ok']);
} catch (ApiProblem $e) {
    echo json_encode(['result' => $e->errorCode]);
} catch (ValidationException $e) {
    echo json_encode(['result' => 'RANGE_CHANGED']);
}
