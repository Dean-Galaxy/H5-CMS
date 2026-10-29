<?php

// Local-only synthetic Acquisition assets for the bounded search browser check.
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Identity\Models\Staff;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(base_path('..'));
$c = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if (! app()->environment('local') || $c['root'] !== $root || $c['port'] !== 8205 || $c['db_port'] !== 5605
    || DB::connection()->getDatabaseName() !== $c['db'] || config('acquisition.transport') !== 'disabled' || config('acquisition.external_enabled')) {
    throw new RuntimeException('Only isolated 05 local with external advertising disabled.');
}
$path = $root.'/.runtime/analytics-link-search-fixture.json';
if (is_file($path)) {
    echo "Existing synthetic link search fixture retained.\n";
    exit;
}
$account = json_decode(file_get_contents($root.'/.runtime/acquisition-preview-account.json'), true, flags: JSON_THROW_ON_ERROR);
$fixture = json_decode(file_get_contents($root.'/.runtime/analytics-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = Staff::where('email', $account['email'])->firstOrFail();
auth('staff')->login($owner);
$s = app('session.store');
$s->start();
$s->put('identity.mfa_staff', $owner->id);
$s->put('identity.version.staff', $owner->session_version);
request()->setLaravelSession($s);
$manager = app(AssetManager::class);
$names = ['05搜索验收 ZZZ旧广告'];
for ($i = 0; $i < 60; $i++) {
    $names[] = sprintf('05搜索验收 Campaign %03d', $i);
}
$created = [];
foreach ($names as $name) {
    $created[] = $manager->saveLink(null, ['name' => $name, 'platform' => 'meta', 'drama_id' => $fixture['drama_id'],
        'pixel_id' => $fixture['links']['meta']['pixel_id'], 'active' => true, 'version' => 0]);
}
// Make the target demonstrably older than the main list's first fifty records.
DB::table('acquisition_links')->where('id', $created[0]['id'])->update(['created_at' => now()->subDays(2)]);
umask(0077);
file_put_contents($path, json_encode(['target_id' => $created[0]['id'], 'target_name' => $created[0]['name'], 'count' => count($created), 'synthetic_only' => true], JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "61 own synthetic Acquisition links prepared; no Billing writes or external events.\n";
