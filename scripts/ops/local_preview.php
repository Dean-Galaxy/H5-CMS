<?php

// Explicit 06 local fixture provisioning; no fake payments or rights in the preview.
use App\Enums\Role;
use App\Modules\Billing\Services\BillingAdministration;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Services\ContentManager;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Content\Services\PublicationService;
use App\Modules\Identity\Models\Staff;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
$app = require $root.'/h5-platform/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$c = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || ! $app->environment('local') || $c['root'] !== $root ||
    config('database.connections.pgsql.database') !== $c['db'] ||
    (int) config('database.connections.pgsql.port') !== $c['db_port'] ||
    config('mail.default') !== 'preview' || config('billing.driver') !== 'unconfigured') {
    throw new LogicException('Only the checkout-bound local unconfigured preview is allowed.');
}
$run = $root.'/.runtime/06';
if (! is_dir($run)) {
    mkdir($run, 0700, true);
}
$record = $run.'/preview.json';
if (file_exists($record)) {
    $existing = json_decode(file_get_contents($record), true);
    if (in_array($argv[1] ?? '', ['withdraw', 'relist'], true)) {
        $staff = Staff::findOrFail($existing['owner_id']);
    } else {
        echo "Preview already created; data preserved.\n";
        exit;
    }
} else {
    if (DB::table('content_dramas')->exists() || DB::table('identity_staff')->exists() || DB::table('billing_prices')->exists()) {
        throw new LogicException('Refuse to provision over existing preview data.');
    }
    $password = bin2hex(random_bytes(24)).'Aa9!';
    $staff = Staff::create(['name' => '06 disposable fixture owner', 'email' => '06-'.bin2hex(random_bytes(6)).'@example.test', 'password' => $password, 'role' => Role::Owner]);
    $staff->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
    file_put_contents($run.'/owner.json', json_encode(['email' => $staff->email, 'password' => $password]));
    chmod($run.'/owner.json', 0600);
}
$session = app('session.store');
$session->start();
request()->setLaravelSession($session);
Auth::guard('staff')->login($staff);
$session->put('identity.mfa_staff', $staff->id);
$publication = app(PublicationService::class);
if (($argv[1] ?? '') === 'withdraw') {
    $d = DramaRecord::findOrFail($existing['drama_id']);
    $publication->withdraw($d->id, $d->version);
    echo "06 fixture drama withdrawn using Content service.\n";
    exit;
}
if (($argv[1] ?? '') === 'relist') {
    $d = DramaRecord::findOrFail($existing['drama_id']);
    $publication->relist($d->id, ['version' => $d->version, 'choice' => 'reset']);
    echo "06 fixture relisted using Content service; no scheduled catch-up.\n";
    exit;
}
$media = app(MediaStore::class);
$assets = [];
foreach (['video' => 'sample.mp4', 'cover' => 'cover.png', 'subtitle' => 'sample.srt'] as $kind => $name) {
    $path = $run.'/'.$name;
    $a = $media->start(['kind' => $kind, 'filename' => $name, 'bytes' => filesize($path)]);
    $f = fopen($path, 'rb');
    $offset = 0;
    while (! feof($f)) {
        $bytes = fread($f, 1048576);
        if ($bytes === '') {
            break;
        }
        $media->append($a->id, $offset, $bytes);
        $offset += strlen($bytes);
    }
    fclose($f);
    $media->finish($a->id);
    $a = $media->process($a->id);
    if ($a->state !== 'ready') {
        throw new RuntimeException('06 media processing failed: '.$a->error_code);
    }
    $assets[$kind] = $a->id;
}
$manager = app(ContentManager::class);
$d = $manager->saveDrama(['title_en' => '06 Synthetic HLS Acceptance', 'title_zh' => '06 合成视频验收',
    'synopsis_en' => 'Generated test pattern, six entries share one synthetic clip. No purchased content or real payment.',
    'synopsis_zh' => '自行生成的测试图案，六个分集引用同一合成片段；用于解码和权限验收。',
    'total_episodes' => 6, 'cover_asset_id' => $assets['cover'], 'regions' => ['US'],
    'license_from' => now()->subDay()->format('Y-m-d\TH:i:sP'), 'license_until' => now()->addDays(30)->format('Y-m-d\TH:i:sP'),
    'rights_note' => 'Self-generated 06 technical test fixture only.', 'recommended' => true, 'sort_order' => 0, 'category_ids' => []]);
for ($i = 1; $i <= 6; $i++) {
    $manager->saveEpisode($d->id, ['number' => $i, 'title_en' => 'Synthetic '.$i, 'title_zh' => '测试 '.$i,
        'video_asset_id' => $assets['video'], 'subtitle_asset_id' => $assets['subtitle'], 'subtitle_language' => 'en']);
}
$p = $publication->preview($d->id, 6);
$publication->manual($d->id, ['version' => $p['drama_version'], 'through' => 6, 'confirmation' => $p['confirmation']]);
app(BillingAdministration::class)->savePrice(['version' => 0, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'is_test' => true]);
$d = $d->fresh('episodes');
file_put_contents($record, json_encode(['owner_id' => $staff->id, 'drama_id' => $d->id, 'episodes' => $d->episodes->pluck('id')->all(), 'assets' => $assets,
    'media_source' => 'self-generated ffmpeg testsrc2; all six episodes share one clip', 'payment' => 'unconfigured; no fake membership',
    'code_commit' => trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'))], JSON_PRETTY_PRINT));
chmod($record, 0600);
echo "06 synthetic preview uploaded, processed and published via Content services; no paid access.\n";
