<?php
/** CLI control for isolated browser fixture. No provider API, no viewer pre-verification or manual paid flag. */
use Tests\Browser\Support\AnalyticsRuntime as Runtime;
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Content\Services\ContentManager;
use App\Modules\Content\Services\PublicationService;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Billing\Services\BillingAdministration;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\BillingBehaviorOutbox;
use Illuminate\Support\Facades\DB;
use Tests\Integration\AnalyticsCombined\Support\ControlledPayment;
use Filament\Auth\MultiFactor\App\AppAuthentication;

$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
$app = Runtime::boot();
if (PHP_SAPI !== 'cli') { throw new LogicException('CLI ONLY'); }
umask(0077);
$action = $argv[1] ?? '';
if ($action === 'flags') {
    echo json_encode(['runtime_safe' => true, 'test_provider_stub' => true, 'external_enabled' => false, 'purchase_enabled' => false])."\n";
} elseif ($action === 'seed') {
    if (is_file(Runtime::directory().'/fixture.json')) { echo json_encode(['fixture_retained' => true])."\n"; exit; }
    foreach (['identity_viewers','identity_staff','content_dramas','billing_orders','billing_prices'] as $table) {
        if (DB::table($table)->exists()) { throw new LogicException('EMPTY OWN TEST DATABASE REQUIRED; NO DATA CLEARED'); }
    }
    $password = bin2hex(random_bytes(24)).'Aa9!';
    $staff = Staff::create(['name' => '06 Synthetic Analytics Owner', 'email' => '06-owner-'.bin2hex(random_bytes(6)).'@example.test',
        'password' => $password, 'role' => Role::Owner]);
    $secret = app(AppAuthentication::class)->generateSecret(); $staff->saveAppAuthenticationSecret($secret);
    Runtime::write('owner.json', ['email' => $staff->email, 'password' => $password, 'totp_secret' => $secret]);
    $session = app('session.store'); $session->start(); request()->setLaravelSession($session);
    auth('staff')->login($staff); $session->put('identity.version.staff', $staff->session_version); $session->put('identity.mfa_staff', $staff->id);
    $media = app(MediaStore::class); $assets = [];
    $source = $root.'/.runtime/06/analytics-media/synthetic.mp4';
    if (! is_file($source) || is_link($source)) { throw new LogicException('GENERATE OWN SYNTHETIC VIDEO FIRST'); }
    $cover = Runtime::directory().'/generated-cover.png';
    $process = new Symfony\Component\Process\Process([config('content.ffmpeg'), '-nostdin', '-v', 'error', '-n', '-f', 'lavfi', '-i', 'color=c=teal:s=180x320', '-frames:v', '1', $cover]);
    $process->mustRun();
    foreach (['video' => $source, 'cover' => $cover] as $kind => $path) {
        $asset = $media->start(['kind' => $kind, 'filename' => basename($path), 'bytes' => filesize($path)]);
        $file = fopen($path, 'rb'); $offset = 0;
        while (! feof($file)) { $bytes = fread($file, 1048576); if ($bytes === '') break; $media->append($asset->id, $offset, $bytes); $offset += strlen($bytes); }
        fclose($file); $media->finish($asset->id); $asset = $media->process($asset->id);
        if ($asset->state !== 'ready') { throw new RuntimeException('OWN SYNTHETIC MEDIA PROCESSING FAILED'); }
        $assets[$kind] = $asset->id;
    }
    $manager = app(ContentManager::class);
    $drama = $manager->saveDrama(['title_en' => '06 Synthetic Analytics Journey', 'title_zh' => '06 合成埋点完整链路',
        'synopsis_en' => 'Self-generated local test image/tone; no real provider or content.', 'synopsis_zh' => '本区自行生成画面及音频，仅技术测试。',
        'total_episodes' => 6, 'cover_asset_id' => $assets['cover'], 'regions' => ['US'], 'license_from' => now()->subDay()->toIso8601String(),
        'license_until' => now()->addMonth()->toIso8601String(), 'rights_note' => 'Self generated ffmpeg lavfi; no third party materials.',
        'recommended' => true, 'sort_order' => 0, 'category_ids' => []]);
    for ($number = 1; $number <= 6; $number++) {
        $manager->saveEpisode($drama->id, ['number' => $number, 'title_en' => 'Synthetic '.$number, 'title_zh' => '合成第'.$number.'集', 'video_asset_id' => $assets['video']]);
    }
    $publication = app(PublicationService::class); $input = ['mode' => 'completed', 'interval_days' => 1, 'batch_size' => 1];
    $preview = $publication->previewLaunchNow($drama->id, $input);
    $publication->launchNow($drama->id, $input + ['plan_version' => $preview['plan_version'], 'confirmation' => $preview['confirmation']]);
    app(BillingAdministration::class)->savePrice(['version' => 0, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'is_test' => true]);
    $links = [];
    foreach (['meta','tiktok'] as $platform) {
        $assetManager = app(AssetManager::class);
        $pixel = $assetManager->savePixel(null, ['name' => '06 Synthetic TEST '.$platform, 'platform' => $platform,
            'external_id' => $platform === 'meta' ? '1060000001' : 'TEST_LOCAL_SYNTHETIC_06', 'environment' => 'test', 'active' => true, 'version' => 0]);
        $links[$platform] = $assetManager->saveLink(null, ['name' => '06 Test Journey '.$platform, 'platform' => $platform,
            'drama_id' => $drama->id, 'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0]);
    }
    Runtime::write('gateway.json', []);
    Runtime::write('fixture.json', ['candidate' => getenv('H5_ANALYTICS_BROWSER_CANDIDATE'), 'synthetic_only' => true, 'drama_id' => $drama->id,
        'episodes' => $drama->fresh('episodes')->episodes->pluck('id')->all(), 'links' => $links,
        'viewer' => ['name' => '06 Synthetic Viewer', 'email' => '06-viewer-'.bin2hex(random_bytes(6)).'@example.test', 'password' => bin2hex(random_bytes(24)).'Aa9!']]);
    echo json_encode(['seeded' => true, 'published_episodes' => 6, 'viewer_created' => false, 'provider_fixture_only' => true])."\n";
} elseif ($action === 'settle') {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! Illuminate\Support\Str::isUuid($input['order_id'] ?? null)) { throw new LogicException('SYNTHETIC ORDER REQUIRED'); }
    [$gateway, $lock] = Runtime::gateway();
    try { ControlledPayment::settle($input['order_id'], $gateway); } finally { Runtime::saveGateway($gateway, $lock); }
    echo json_encode(['settled_existing_test_order' => true, 'official_payment' => false])."\n";
} elseif ($action === 'dispatch') {
    app(OutboxService::class)->dispatchPending(); app(BillingBehaviorOutbox::class)->dispatchPending();
    echo json_encode(['local_outboxes_dispatched' => true, 'official_payment' => false])."\n";
} elseif ($action === 'state') {
    $fixture = Runtime::document('fixture.json'); $linkIds = array_column($fixture['links'], 'id');
    $events = DB::table('acquisition_behaviors')->whereIn('link_id', $linkIds)->get();
    $payment = DB::table('billing_payments')->first();
    $verified = DB::table('identity_viewers')->whereNotNull('email_verified_at')->count();
    $required = ['landing_view','registration_succeeded','email_verified','checkout_created','payment_confirmed'];
    $stagesPresent = collect($required)->every(fn ($name) => $events->contains('event_name', $name));
    $owner = Staff::where('role', Role::Owner)->sole();
    $session = app('session.store'); $session->start(); request()->setLaravelSession($session);
    auth('staff')->login($owner); $session->put('identity.version.staff', $owner->session_version); $session->put('identity.mfa_staff', $owner->id);
    $report = app(App\Modules\Acquisition\Analytics\AnalyticsReport::class)->read(['test' => 'test']);
    $sources = DB::table('acquisition_sources')->whereIn('link_id', $linkIds)->get();
    $matching = $sources->map(function ($source) use ($fixture) {
        $context = json_decode(Illuminate\Support\Facades\Crypt::decryptString($source->context_encrypted), true);
        return ['platform' => collect($fixture['links'])->firstWhere('id', $source->link_id)['platform'] ?? 'unknown', 'synthetic_click_present' => str_contains(json_encode($context), 'SYNTHETIC06'),
            'encrypted_at_rest' => ! str_contains($source->context_encrypted, 'SYNTHETIC06'),
            'page_query_absent' => ! str_contains($context['page_url'] ?? '', '?')];
    })->all();
    echo json_encode(['candidate' => getenv('H5_ANALYTICS_BROWSER_CANDIDATE'), 'events' => $events->groupBy('event_name')->map->count()->all(),
        'payment_count' => DB::table('billing_payments')->count(), 'entitlement_count' => DB::table('billing_entitlements')->count(),
        'verified_viewers' => $verified, 'payment_is_test' => $payment ? (bool) $payment->is_test : null,
        'gross_minor' => (int) DB::table('billing_payments')->sum('amount_minor'), 'refund_minor' => (int) DB::table('billing_payments')->sum('refunded_amount_minor'),
        'migrations' => DB::table('migrations')->count(), 'matching' => $matching,
        'report' => array_intersect_key($report, array_flip(['finance','funnel','registration_funnel','observing_people','watched_seconds','errors'])),
        'one_subject_for_full_chain' => $stagesPresent && $events->whereIn('event_name', $required)->pluck('subject_id')->unique()->count() === 1,
        'provider_off' => config('billing.paypal.enabled') === false, 'external_disabled' => config('acquisition.transport') === 'disabled' && config('acquisition.external_enabled') === false], JSON_THROW_ON_ERROR)."\n";
} else { throw new LogicException('EXPLICIT FIXTURE ACTION REQUIRED'); }
