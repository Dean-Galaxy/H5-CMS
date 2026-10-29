<?php
/** Explicit new 06 restart-test database only. Produces a fact through real local SettlementService. */
$root=dirname(__DIR__,2);require $root.'/h5-platform/vendor/autoload.php';umask(0077);
use Illuminate\Support\Facades\DB;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Billing\Services\OutboxService;
use Tests\Integration\AnalyticsCombined\Support\ControlledPayment;
function bound():void {
    $c=json_decode(file_get_contents(dirname(__DIR__,2).'/.runtime/workspace.json'),true);
    $db=config('database.connections.pgsql.database');
    if(!app()->environment('testing') || getenv('H5_ANALYTICS_RESTART_FIXTURE')!=='1'
        || $db!==getenv('H5_TEST_DATABASE') || !preg_match('/^'.preg_quote($c['db'],'/').'_analytics_restart_[a-f0-9]{6}_test$/D',$db)
        || (int)config('database.connections.pgsql.port')!==$c['db_port'] || config('billing.paypal.enabled')!==false
        || config('acquisition.external_enabled')!==false || config('acquisition.transport')!=='disabled') throw new LogicException('OWN NEW RESTART TEST DATABASE REQUIRED');
}
$action=$argv[1]??'';
if($action==='seed') {
    $fixture=new class('prepare') extends Tests\Integration\AnalyticsCombined\Support\CombinedTestCase {
        public function refreshDatabase():void { bound();$this->artisan('migrate:fresh',['--force'=>true])->assertExitCode(0); }
        public function prepare():void {
            $this->setUp();bound();$this->assertSame(0,DB::transactionLevel());
            $this->consent();$this->landing();$this->registerAndVerify();$order=$this->checkout();
            $payment=ControlledPayment::settle($order->id,$this->gateway);app(OutboxService::class)->dispatchPending();
            $this->gateway->refunds[$payment['charge']]=[['id'=>'re_06_restart','amount_minor'=>100,'currency'=>'USD','status'=>'succeeded']];
            app(SettlementService::class)->syncRefunds($payment['charge']);
            $this->assertSame('pending',DB::table('billing_behavior_outbox')->where('event_name','refund_confirmed')->sole()->state);
            $this->assertSame(0,DB::table('acquisition_behaviors')->where('event_name','refund_confirmed')->count());
            echo json_encode(['seeded_committed_fact'=>true,'transaction_level'=>DB::transactionLevel()])."\n";
        }
    };$fixture->prepare();
} elseif($action==='inspect') {
    $app=require $root.'/h5-platform/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();bound();
    $fact=DB::table('billing_behavior_outbox')->where('event_name','refund_confirmed')->sole();$hashes=[];
    foreach(['billing_orders','billing_payments','billing_entitlements','billing_subscriptions','billing_refunds'] as $table) {
        $rows=DB::table($table)->get()->map(fn($r)=>json_encode($r))->sort()->values()->all();$hashes[$table]=hash('sha256',implode("\n",$rows));
    }
    echo json_encode(['fact_key_sha256'=>hash('sha256','billing:'.$fact->id),'state'=>$fact->state,'attempts'=>$fact->attempts,
        'next_attempt_at'=>$fact->next_attempt_at,'refund_behaviors'=>DB::table('acquisition_behaviors')->where('event_name','refund_confirmed')->count(),
        'ledger_hashes'=>$hashes,'payments'=>DB::table('billing_payments')->count(),'entitlements'=>DB::table('billing_entitlements')->count()])."\n";
} else throw new LogicException('EXPLICIT FIXTURE ACTION REQUIRED');
