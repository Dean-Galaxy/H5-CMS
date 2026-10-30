<?php
/** New C-only CLI control: synthetic media seed and aggregate state. No payment or advertising assets. */
use Tests\Browser\Support\AnalyticsRuntime as Runtime;
use Tests\Browser\Support\CWindowRuntime;
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Content\Services\ContentManager;
use App\Modules\Content\Services\PublicationService;
use Illuminate\Support\Facades\DB;
use Filament\Auth\MultiFactor\App\AppAuthentication;
$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
if (PHP_SAPI !== 'cli') { throw new LogicException('CLI ONLY'); }
CWindowRuntime::boot(); umask(0077);
$action = $argv[1] ?? '';
if ($action === 'seed') {
    if (is_file(Runtime::directory().'/fixture.json')) { throw new LogicException('NO RESEED'); }
    foreach (['identity_viewers','identity_staff','content_dramas','billing_orders','billing_prices','acquisition_subjects','acquisition_c_subjects'] as $table) {
        if (DB::table($table)->exists()) { throw new LogicException('EMPTY OWN C DATABASE REQUIRED; NOTHING CLEARED'); }
    }
    $password = bin2hex(random_bytes(24)).'Aa9!';
    $staff = Staff::create(['name' => '06 Synthetic C Owner', 'email' => '06-owner-'.bin2hex(random_bytes(6)).'@example.test',
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
    $drama = $manager->saveDrama(['title_en' => '06 Synthetic C Window', 'title_zh' => '06 合成C窗口',
        'synopsis_en' => 'Self-generated local test image/tone; no real provider or content.', 'synopsis_zh' => '本区自行生成画面及音频，仅技术测试。',
        'total_episodes' => 6, 'cover_asset_id' => $assets['cover'], 'regions' => ['US'], 'license_from' => now()->subDay()->toIso8601String(),
        'license_until' => now()->addMonth()->toIso8601String(), 'rights_note' => 'Self generated ffmpeg lavfi; no third party materials.',
        'recommended' => true, 'sort_order' => 0, 'category_ids' => []]);
    if ((int) $drama->free_count !== 5) { throw new LogicException('EXACTLY FIVE FREE EPISODES REQUIRED'); }
    for ($number = 1; $number <= 6; $number++) {
        $manager->saveEpisode($drama->id, ['number' => $number, 'title_en' => 'Synthetic '.$number, 'title_zh' => '合成第'.$number.'集', 'video_asset_id' => $assets['video']]);
    }
    $publication = app(PublicationService::class); $input = ['mode' => 'completed', 'interval_days' => 1, 'batch_size' => 1];
    $preview = $publication->previewLaunchNow($drama->id, $input);
    $publication->launchNow($drama->id, $input + ['plan_version' => $preview['plan_version'], 'confirmation' => $preview['confirmation']]);
    Runtime::write('fixture.json', ['candidate' => getenv('H5_ANALYTICS_BROWSER_CANDIDATE'), 'synthetic_only' => true,
        'drama_id' => $drama->id, 'episodes' => $drama->episodes()->orderBy('number')->pluck('id')->all(), 'free_count' => 5]);
    echo json_encode(['seeded' => true, 'published_episodes' => 6, 'free_count' => 5, 'viewers_created' => 0,
        'prices_created' => 0, 'advertising_assets_created' => 0, 'payment_fixture_installed' => false])."\n";
} elseif ($action === 'state') {
    $counts = [];
    foreach (['identity_viewers','billing_prices','billing_orders','billing_payments','billing_entitlements','billing_subscriptions',
        'billing_refunds','billing_inbox','billing_outbox','billing_behavior_outbox',
        'acquisition_subjects','acquisition_sources','acquisition_behaviors','acquisition_event_keys',
        'acquisition_c_preferences','acquisition_c_subjects','acquisition_c_sessions','acquisition_c_events','acquisition_c_event_keys','acquisition_c_plays'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    $fixture = Runtime::document('fixture.json');
    if ($fixture['candidate'] !== getenv('H5_ANALYTICS_BROWSER_CANDIDATE')) { throw new LogicException('FIXTURE CANDIDATE MISMATCH'); }
    echo json_encode(['candidate' => getenv('H5_ANALYTICS_BROWSER_CANDIDATE'), 'synthetic_only' => true,
        'gateway_class' => get_class(app(App\Modules\Billing\Gateway\PaymentGateway::class)),
        'payment_fixture_installed' => false, 'paypal_api_enabled' => config('billing.paypal.enabled'),
        'new_purchase_provider' => config('billing.new_purchase_provider'), 'advertising_external_enabled' => config('acquisition.external_enabled'),
        'advertising_transport' => config('acquisition.transport'), 'mail' => config('mail.default'), 'debug' => config('app.debug'),
        'experience' => config('h5.measurement_c_experience'), 'synthetic_until' => config('acquisition.measurement.synthetic_until'),
        'policy_window_current' => now()->lt(Carbon\CarbonImmutable::parse(config('acquisition.measurement.synthetic_until'))),
        'counts' => $counts, 'migrations' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
        'c_event_high_watermark' => DB::table('acquisition_c_events')->max('sequence'),
        'c_events' => DB::table('acquisition_c_events')->select('event_name')->selectRaw('COUNT(*) AS count')->groupBy('event_name')->get(),
        'v1_events' => DB::table('acquisition_behaviors')->select('event_name')->selectRaw('COUNT(*) AS count')->groupBy('event_name')->get(),
        'free_episode_path' => '/dramas/'.$fixture['drama_id'].'/episodes/'.$fixture['episodes'][0],
        'locked_episode_path' => '/dramas/'.$fixture['drama_id'].'/episodes/'.$fixture['episodes'][5]], JSON_THROW_ON_ERROR)."\n";
} elseif ($action === 'fingerprint') {
    // All rows of business tables, not just counts. Exclude necessary generic session/cache/runtime tables.
    $tables = [];
    foreach (DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
        $name = $table->tablename;
        if ($name !== 'migrations' && ! preg_match('/^(identity|content|billing|acquisition)_[a-z0-9_]+$/D', $name)) { continue; }
        $rows = [];
        foreach (DB::table($name)->get() as $record) {
            $row = (array) $record; ksort($row);
            $rows[] = json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        sort($rows, SORT_STRING);
        $tables[$name] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    echo json_encode(['candidate' => getenv('H5_ANALYTICS_BROWSER_CANDIDATE'), 'tables' => $tables,
        'sha256' => hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR)),
        'scope' => 'business_rows_and_migrations_not_generic_session_cache_runtime'], JSON_THROW_ON_ERROR)."\n";
} else { throw new LogicException('ONLY SEED AGGREGATE STATE OR BUSINESS FINGERPRINT'); }
