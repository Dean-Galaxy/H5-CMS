<?php

// Explicit synthetic test fixture only; never loaded by web requests or normal seeders.
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Models\EpisodeRecord;
use App\Modules\Content\Models\Plan;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\Verification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(base_path('..'));
$r1 = getenv('H5_C_UI_R1') === '1';
$scope = $r1 ? 'r1-audience' : 'c-ui-1-audience';
$owned = json_decode(file_get_contents($root.'/.runtime/'.$scope.'-owned.json'), true, flags: JSON_THROW_ON_ERROR);
if (! app()->environment('testing') || $owned['root'] !== $root || $owned['database'] !== DB::connection()->getDatabaseName()
    || ! str_ends_with($owned['database'], $r1 ? '_r1_audience_http_test' : '_cui1_audience_http_test') || ! str_starts_with(config('h5.media_root'), $root.'/.runtime/'.$scope.'/')) {
    throw new LogicException('Refuse non-owned synthetic C UI database or media.');
}
$target = $root.'/.runtime/'.$scope.'/fixture.json';
if ($r1 && getenv('H5_C_UI_VERIFY') === '1') {
    $email = getenv('H5_C_UI_VERIFY_EMAIL');
    if (! is_string($email) || ! preg_match('/^[a-f0-9-]{36}@example\.test$/D', $email)) {
        throw new LogicException('Refuse non-synthetic verification fixture.');
    }
    echo app(Verification::class)->url(Viewer::where('email', $email)->firstOrFail());
    exit;
}
if (is_file($target)) {
    $fixture = json_decode(file_get_contents($target), true);
    if (DramaRecord::find($fixture['drama'])) {
        exit;
    }
}
if (DramaRecord::count() !== 0) {
    throw new LogicException('Unexpected content in synthetic DB; refusing to replace.');
}
DB::transaction(function () use ($target, $r1) {
    $owner = (string) Str::uuid(); // Synthetic author reference only; no usable staff login.
    $coverBytes = filesize(config('h5.media_root').'/cover/image.png');
    $cover = Asset::create(['created_by' => $owner, 'kind' => 'cover', 'state' => 'ready', 'extension' => 'png',
        'expected_bytes' => $coverBytes, 'received_bytes' => $coverBytes, 'output_key' => 'cover', 'metadata' => ['width' => 160, 'height' => 240]]);
    $drama = DramaRecord::create(['created_by' => $owner, 'title_en' => 'Synthetic preview — C test', 'title_zh' => '合成试看 · C测试',
        'synopsis_en' => 'Six generated eight-second clips. First five are free.', 'synopsis_zh' => '自行生成的六集八秒样片，前五集免费。',
        'cover_asset_id' => $cover->id, 'total_episodes' => 6, 'free_count' => 5, 'regions' => ['US'],
        'license_from' => now()->subDay(), 'license_until' => now()->addDays(2), 'rights_note' => 'Synthetic test only',
        'state' => 'published', 'recommended' => true]);
    $episodes = [];
    for ($number = 1; $number <= 6; $number++) {
        $videoBytes = filesize(config('h5.media_root').'/episode-'.$number.'/media.mp4');
        $video = Asset::create(['created_by' => $owner, 'kind' => 'video', 'state' => 'ready', 'extension' => 'mp4',
            'expected_bytes' => $videoBytes, 'received_bytes' => $videoBytes, 'output_key' => 'episode-'.$number,
            'metadata' => ['duration_seconds' => 8, 'duration' => 8, 'width' => 160, 'height' => 240]]);
        $episodes[] = EpisodeRecord::create(['drama_id' => $drama->id, 'number' => $number, 'video_asset_id' => $video->id, 'published_at' => now()])->id;
    }
    Plan::create(['drama_id' => $drama->id, 'mode' => 'completed', 'launch_at' => now(), 'state' => 'active']);
    $accounts = [];
    if ($r1) {
        foreach (range(1, 2) as $number) {
            $email = Str::uuid().'@example.test';
            $password = Str::random(24).'T1!';
            Viewer::create(['name' => 'Synthetic R1 '.$number, 'email' => $email, 'password' => $password, 'email_verified_at' => now()]);
            $accounts[] = ['email' => $email, 'password' => $password];
        }
    }
    file_put_contents($target, json_encode(['drama' => $drama->id, 'episodes' => $episodes, ...($r1 ? ['accounts' => $accounts] : [])]));
    chmod($target, 0600);
});
