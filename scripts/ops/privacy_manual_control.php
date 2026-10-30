<?php
/** Private CLI snapshots and synthetic links for the new AN-M01 workspace. No payment mutation actions. */
use Tests\Browser\Support\AnalyticsRuntime as Runtime;
use Illuminate\Support\Facades\DB;
use App\Modules\Identity\Models\Staff;
use App\Enums\Role;
use App\Modules\Acquisition\Services\AssetManager;

$root = dirname(__DIR__, 2);
require $root.'/h5-platform/vendor/autoload.php';
Runtime::boot();
if (PHP_SAPI !== 'cli' || getenv('H5_ANALYTICS_PRIVACY_ONLY') !== '1'
    || basename(dirname($root)) !== 'h5-06-privacy-manual') {
    throw new LogicException('NEW PRIVATE AN-M01 WORKSPACE REQUIRED');
}
umask(0077);
$action = $argv[1] ?? '';
if ($action === 'seed_cases') {
    if (is_file(Runtime::directory().'/privacy-cases.json')) { throw new LogicException('CASES ALREADY SEEDED'); }
    $fixture = Runtime::document('fixture.json');
    $owner = Staff::where('role', Role::Owner)->sole();
    $session = app('session.store'); $session->start(); request()->setLaravelSession($session);
    auth('staff')->login($owner);
    $session->put('identity.version.staff', $owner->session_version);
    $session->put('identity.mfa_staff', $owner->id);
    $labels = ['unknown' => '不作选择', 'analytics' => '仅站内分析', 'advertising' => '仅广告衡量', 'both' => '两项同意', 'denied' => '两项拒绝后试看'];
    $groups = [];
    foreach (['automatic', 'manual'] as $group) {
        foreach ($labels as $key => $label) {
            $platform = $key === 'advertising' ? 'tiktok' : 'meta';
            $original = $fixture['links'][$platform];
            $link = app(AssetManager::class)->saveLink(null, ['name' => 'AN-M01 '.$group.' '.$label,
                'platform' => $platform, 'drama_id' => $fixture['drama_id'], 'pixel_id' => $original['pixel_id'], 'active' => true, 'version' => 0]);
            $groups[$group][$key] = ['label' => $label, 'id' => $link['id'], 'url' => $link['url']];
        }
    }
    Runtime::write('privacy-cases.json', ['application_base' => '4add9e40e4820bbc0dfa38c3ea5796b527943c38',
        'seed_candidate' => getenv('H5_ANALYTICS_BROWSER_CANDIDATE'), 'groups' => $groups]);
    echo json_encode(['cases_created' => 10, 'viewers_created' => 0, 'financial_facts_created' => 0])."\n";
} elseif ($action === 'state') {
    $fixture = Runtime::document('fixture.json');
    $cases = Runtime::document('privacy-cases.json');
    $counts = [];
    foreach (['identity_viewers','billing_orders','billing_payments','billing_entitlements','billing_subscriptions','billing_refunds',
        'acquisition_subjects','acquisition_sources','acquisition_behaviors','acquisition_event_keys'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    $perCase = [];
    foreach ($cases['groups'] as $group => $rows) {
        foreach ($rows as $key => $row) {
            $perCase[$group][$key] = ['sources' => DB::table('acquisition_sources')->where('link_id', $row['id'])->count(),
                'behaviors' => DB::table('acquisition_behaviors')->where('link_id', $row['id'])->count()];
        }
    }
    echo json_encode(['application_base' => $cases['application_base'], 'candidate' => getenv('H5_ANALYTICS_BROWSER_CANDIDATE'),
        'new_purchase_provider' => config('billing.new_purchase_provider'), 'paypal_api_enabled' => config('billing.paypal.enabled'),
        'advertising_transport' => config('acquisition.transport'), 'advertising_external_enabled' => config('acquisition.external_enabled'),
        'debug' => config('app.debug'), 'mail' => config('mail.default'), 'payment_fixture_installed' => false,
        'counts' => $counts, 'case_counts' => $perCase, 'migrations' => DB::table('migrations')->count(),
        'privacy_states' => DB::table('acquisition_privacy')->select('analytics','advertising')->selectRaw('COUNT(*) AS count')->groupBy('analytics','advertising')->get(),
        'events' => DB::table('acquisition_behaviors')->select('event_name')->selectRaw('COUNT(*) AS count')->groupBy('event_name')->get(),
        'free_episode_path' => '/dramas/'.$fixture['drama_id'].'/episodes/'.$fixture['episodes'][0],
        'manual_entries' => $cases['groups']['manual']], JSON_THROW_ON_ERROR)."\n";
} else { throw new LogicException('READ ONLY STATE OR SYNTHETIC LINK SEED REQUIRED'); }
