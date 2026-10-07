<?php

use App\Contracts\CatalogReader;
use App\Contracts\PlaybackAuthorizer;
use App\Data\Drama;
use App\Data\Episode;
use App\Data\ProgressInput;
use App\Data\ViewerIdentity;
use App\Modules\Audience\Progress\ProgressRecorder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection()->getDatabaseName();
    if (! app()->environment('testing') || $db !== getenv('H5_TEST_DATABASE') || ! str_ends_with($db, '_test')) {
        throw new RuntimeException('Progress worker refused a non-isolated test database.');
    }
    if (realpath((new ReflectionClass(ProgressRecorder::class))->getFileName()) !== realpath(base_path('app/Modules/Audience/Progress/ProgressRecorder.php'))) {
        throw new RuntimeException('Progress worker autoloaded a different worktree.');
    }
    $input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
    // Explicit testing contract sample: the race exercises PostgreSQL writes, not media decoding.
    $catalog = Mockery::mock(CatalogReader::class);
    $catalog->shouldReceive('drama')->with($input['drama_id'])->andReturn(new Drama($input['drama_id'], 'Race sample', '', null, 1, 1, 1, 'completed', [
        new Episode($input['episode_id'], 1, 'Race episode', 100, 'free', 'ready'),
    ]));
    $recorder = new ProgressRecorder($catalog, Mockery::mock(PlaybackAuthorizer::class));
    echo json_encode(['phase' => 'ready', 'pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid])."\n";
    flush();
    $result = $recorder->saveProgress(new ViewerIdentity($input['viewer_id'], 'race@example.test', true), $input['drama_id'],
        new ProgressInput($input['episode_id'], $input['position'], $input['session'], $input['sequence']));
    echo json_encode(['phase' => 'result', 'progress' => $result->toArray()])."\n";
} catch (Throwable $error) {
    echo json_encode(['phase' => 'error', 'message' => $error->getMessage()])."\n";
    exit(1);
}
