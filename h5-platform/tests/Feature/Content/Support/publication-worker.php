<?php

use App\Modules\Content\ContentServiceProvider;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Services\PublicationService;
use App\Modules\Identity\Models\Staff;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// No HTTP route. Structured progress begins BEFORE autoload/bootstrap so a stalled
// startup is distinguishable from a blocked publication or a worker that exited.
$started = hrtime(true);
$emit = static function (string $phase, array $data = []) use ($started): void {
    echo json_encode(['phase' => $phase, 'elapsed_seconds' => (hrtime(true) - $started) / 1e9, ...$data], JSON_UNESCAPED_SLASHES)."\n";
    flush();
};
$emit('entered', ['pid' => getmypid()]);
try {
    require __DIR__.'/../../../../vendor/autoload.php';
    $emit('autoloaded');
    $app = require __DIR__.'/../../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $emit('bootstrapped');
    $db = config('database.connections.pgsql.database');
    if (! app()->environment('testing') || ! str_ends_with($db, '_test') || $db !== getenv('H5_TEST_DATABASE')) {
        throw new RuntimeException('Child test database safety guard: environment='.app()->environment().'; test database match='.(int) ($db === getenv('H5_TEST_DATABASE')));
    }
    $providerPath = (new ReflectionClass(ContentServiceProvider::class))->getFileName();
    if (realpath($providerPath) !== realpath(base_path('app/Modules/Content/ContentServiceProvider.php'))) {
        throw new RuntimeException('Worker autoload resolved Content from a different worktree: '.$providerPath);
    }
    $emit('guarded', ['app_base' => base_path(), 'provider_path' => $providerPath]);
    $d = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    // Controlled fault injection is only available after the isolated testing guard.
    if ($d['fail_startup'] ?? false) {
        throw new RuntimeException('Injected startup failure');
    }
    if ($delay = (int) ($d['startup_delay_ms'] ?? 0)) {
        $emit('startup_delay', ['milliseconds' => $delay]);
        usleep(min(15000, max(0, $delay)) * 1000);
    }
    $app->register(ContentServiceProvider::class);
    config(['h5.media_root' => $d['media_root'], 'session.driver' => 'array']);
    Date::setTestNow(CarbonImmutable::parse($d['at']));
    $staff = Staff::findOrFail($d['owner']);
    auth('staff')->setUser($staff);
    $session = app('session.store');
    $session->start();
    $session->put('identity.mfa_staff', $staff->id);
    request()->setLaravelSession($session);
    $emit('ready', ['pg_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid]);
    $s = app(PublicationService::class);
    try {
        $result = match ($d['action']) {
            'scheduled' => $s->execute($d['plan'], $d['plan_version'], CarbonImmutable::parse($d['at'])),
            'launch_now' => $s->launchNow($d['drama'], $d['launch_payload']),
            'manual' => $s->manual($d['drama'], ['version' => $d['version'], 'through' => 3, 'confirmation' => $d['confirmation']]),
            'withdraw' => $s->withdraw($d['drama'], $d['version']),
            'edit' => $s->savePlan($d['drama'], ['version' => $d['plan_version'], 'mode' => 'updating', 'interval_days' => 2, 'batch_size' => 5,
                'start_number' => $s->nextNumber(DramaRecord::findOrFail($d['drama'])), 'launch_at' => now()->addDay()->toIso8601String()]),
        };
        $emit('result', ['result' => is_string($result) ? $result : 'ok']);
    } catch (ApiProblem $e) {
        $emit('result', ['result' => $e->errorCode]);
    } catch (ValidationException $e) {
        $emit('result', ['result' => 'RANGE_CHANGED']);
    }
} catch (Throwable $e) {
    $emit('error', ['class' => $e::class, 'message' => $e->getMessage()]);
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n");
    exit(1);
}
