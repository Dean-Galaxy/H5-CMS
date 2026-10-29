<?php

use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Explicit isolated visual-test data only. Does not create Billing facts or dispatch events.
require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(base_path('..'));
$workspace = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('local') || $workspace['root'] !== $root
    || DB::connection()->getDatabaseName() !== $workspace['db']
    || config('acquisition.transport') !== 'disabled' || config('acquisition.external_enabled')
    || ! config('h5.modules.acquisition.ready') || config('mail.default') !== 'preview'
    || ! in_array(parse_url(config('app.url'), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Only explicit isolated local Acquisition with external sending disabled.');
}
$path = $root.'/.runtime/acquisition-f09-fixture.json';
if (is_file($path)) {
    echo "Existing F09 local visual fixture retained.\n";
    exit;
}
$account = json_decode(file_get_contents($root.'/.runtime/acquisition-preview-account.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = Staff::where('email', $account['email'])->firstOrFail();
if ($owner->role !== Role::Owner || ! $owner->active) {
    throw new RuntimeException('Own local preview Owner required.');
}
$data = DB::transaction(function () use ($owner) {
    $id = fn () => (string) Str::uuid();
    $pixel = $id();
    $link = $id();
    $number = (string) random_int(100000000000, 999999999999);
    DB::table('acquisition_pixels')->insert(['id' => $pixel, 'owner_id' => $owner->id,
        'name' => 'F09 本地测试像素', 'platform' => 'meta', 'external_id' => $number, 'environment' => 'test',
        'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('acquisition_pixel_versions')->insert(['pixel_id' => $pixel, 'version' => 1,
        'platform' => 'meta', 'external_id' => $number, 'environment' => 'test', 'created_at' => now()]);
    // A disabled historical link exercises editing without publishing fake Content or assuming D05.
    DB::table('acquisition_links')->insert(['id' => $link, 'owner_id' => $owner->id,
        'name' => 'F09 本地测试链接', 'platform' => 'meta', 'pixel_id' => $pixel, 'drama_id' => $id(),
        'drama_title' => 'F09 合成旧记录（非已发布剧）', 'code' => 'F09LOCAL'.bin2hex(random_bytes(12)),
        'active' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['first_subscription', 300], ['first_subscription', 300], ['renewal', 1200]] as [$kind, $amount]) {
        $event = $id();
        DB::table('acquisition_receipts')->insert(['event_id' => $event, 'payment_id' => $id(),
            'fingerprint' => hash('sha256', 'F09_SYNTHETIC_'.$event), 'link_id' => $link, 'source_id' => null,
            'transaction_kind' => $kind, 'discount_applied' => false, 'amount_minor' => $amount, 'currency' => 'USD',
            'is_test' => true, 'occurred_at' => now(), 'attribution_snapshot' => null, 'source_status' => 'unattributed', 'received_at' => now()]);
    }

    return ['pixel_id' => $pixel, 'link_id' => $link, 'kind' => 'SYNTHETIC_LOCAL_VISUAL_TEST_NOT_BILLING_FACTS'];
});
umask(0077);
file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "F09 synthetic local visual fixture ready; no Billing writes or external events.\n";
