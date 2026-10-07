<?php

use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Models\Plan;
use App\Modules\Identity\Models\Staff;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Support\PaymentScenario;

$root = dirname(__DIR__, 4);
require $root.'/h5-platform/vendor/autoload.php';
$app = require $root.'/h5-platform/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = config('database.connections.pgsql.database');
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || $db !== getenv('H5_TEST_DATABASE') || ! preg_match('/^h5_[a-f0-9]{10}_recovery_[a-f0-9]{8}_test$/D', $db)) {
    throw new LogicException('Only a newly created isolated recovery fixture database is accepted.');
}
app()->bind(RegionResolver::class, fn () => new class implements RegionResolver
{
    public function resolve(): ResolvedRegion
    {
        return new ResolvedRegion('US', 'local_us_test');
    }
});
if (($argv[1] ?? '') === 'seed') {
    if (DB::table('billing_payments')->exists()) {
        throw new LogicException('Refuse existing payment data.');
    }
    $p = PaymentScenario::paid();
    $p['gateway']->refunds[$p['charge']] = [['id' => 're_restore06', 'amount_minor' => 100, 'status' => 'succeeded', 'currency' => 'USD']];
    app(SettlementService::class)->syncRefunds($p['charge']);
    $d = DramaRecord::create(['created_by' => Staff::firstOrFail()->id, 'title_en' => '06 overdue recovery fixture', 'title_zh' => '', 'synopsis_en' => '', 'synopsis_zh' => '', 'regions' => ['US'], 'rights_note' => 'Testing only; never publish automatically', 'state' => 'draft']);
    Plan::create(['drama_id' => $d->id, 'state' => 'active', 'mode' => 'updating', 'version' => 1, 'launch_at' => now()->subMinutes(5)->startOfMinute(), 'next_at' => now()->subMinutes(5)->startOfMinute()]);
}
$account = DB::table('billing_accounts')->first();
$membership = $account ? app(BillingEngine::class)->membership($account->viewer_id, now()->toDateTimeImmutable()) : null;
$summary = ['orders' => DB::table('billing_orders')->count(), 'payments' => DB::table('billing_payments')->count(), 'entitlements' => DB::table('billing_entitlements')->count(), 'refunds' => DB::table('billing_refunds')->count(), 'outbox' => DB::table('billing_outbox')->count(), 'access_active' => $membership?->access_active, 'renewal_enabled' => $membership?->renewal_enabled, 'public_episodes' => DB::table('content_episodes')->whereNotNull('published_at')->count(), 'publications' => DB::table('content_publications')->count(), 'plan_states' => DB::table('content_plans')->orderBy('id')->pluck('state')->all(), 'mfa_ciphertext_decrypts' => filled(Staff::firstOrFail()->getAppAuthenticationSecret())];
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
