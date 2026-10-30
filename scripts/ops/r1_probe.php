<?php
/** 06 probes: owned new upgrade/lifecycle DBs only, no HTTP fixture endpoint. */
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use App\Modules\Acquisition\Registration\PurposeStore;
use App\Modules\Acquisition\Registration\PurposePolicy;
use App\Modules\Acquisition\Registration\PurposeProof;
require dirname(__DIR__,2).'/h5-platform/vendor/autoload.php';
require __DIR__.'/r1_window_runtime.php';
if(PHP_SAPI!=='cli'){throw new LogicException('CLI ONLY');}
umask(0077);$role=getenv('H5_R1_DB_ROLE');$action=$argv[1]??'';$phase='bootstrap';$assertion=null;$booted=false;$exitCode=0;
function requireTrue(bool $ok,string $reason):void{$GLOBALS['assertion']=strtolower(trim(preg_replace('/[^A-Za-z0-9]+/','_',$reason),'_'));if(!$ok){throw new RuntimeException($reason);}}
function snapshot(?array $columns=null):array{
    $tables=[];
    foreach(DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public' ORDER BY tablename") as $table){
        $name=$table->tablename;if($name==='migrations'||($columns!==null&&!isset($columns[$name]))){continue;}
        $cols=$columns[$name]??Illuminate\Support\Facades\Schema::getColumnListing($name);$rows=[];
        foreach(DB::table($name)->select($cols)->get() as $value){$row=(array)$value;ksort($row);$rows[]=json_encode($row,JSON_THROW_ON_ERROR);}
        sort($rows,SORT_STRING);$tables[$name]=['columns'=>$cols,'rows'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
    }return $tables;
}
try {
H5R1Runtime::boot();$booted=true;$phase='dispatch';
if($role==='upgrade'&&str_starts_with($action,'upgrade-')){
    $file=H5R1Runtime::directory().'/upgrade-before.json';
    if($action==='upgrade-seed'){$phase='upgrade_seed';
        requireTrue(!is_file($file),'NO RESEED');
        $viewer=app(Laravel\Fortify\Contracts\CreatesNewUsers::class)->create(['name'=>'06 upgrade synthetic','email'=>'06-upgrade-'.Str::uuid().'@example.test','password'=>'SyntheticUpgradePass123!','password_confirmation'=>'SyntheticUpgradePass123!','terms_accepted'=>'1','terms_version'=>'local-c-terms-r1-v1','privacy_notice_version'=>'local-c-privacy-r1-v1']);
        DB::table('acquisition_privacy')->insert(['id'=>(string)Str::uuid(),'analytics'=>'denied','advertising'=>'denied','policy_version'=>'analytics-v1','created_at'=>now(),'updated_at'=>now(),'site_analysis_denied_at'=>now(),'site_analysis_denial_until'=>now()->addMonthsNoOverflow(24)]);
        $before=snapshot();H5R1Runtime::write('upgrade-before.json',$before);
        echo json_encode(['old_tables'=>count($before),'synthetic_viewers'=>1,'necessary_receipts'=>1,'legacy_denial_rows'=>1,'other_tables_may_be_empty'=>true]);
    }else{
        $phase='upgrade_old_rows';$before=H5R1Runtime::document($file);$cols=array_map(fn($t)=>$t['columns'],$before);$after=snapshot($cols);
        requireTrue($after===$before,'OLD ROW/COLUMN DIGEST CHANGED');
        if($action==='upgrade-check'){
            foreach(['proofs','choices','operations'] as $name){requireTrue(DB::table('acquisition_r1_'.$name)->count()===0,'NO BACKFILL');}
            requireTrue(DB::table('acquisition_privacy')->whereNotNull('r1_advertising_denied_at')->count()===0,'NO SYNTHETIC R1 CONSENT BACKFILL');
        }
        echo json_encode(['original_tables_compared'=>count($before),'all_original_columns_and_rows_equal'=>true,'no_allow_backfill'=>true]);
    }
}elseif(in_array($role,['lifecycle','lifecycle_retry1'],true)&&$action==='lifecycle'){
    $phase='lifecycle_fresh';requireTrue(DB::table('acquisition_r1_proofs')->count()===0,'FRESH LIFECYCLE REQUIRED');
    $session=app('session.store');$session->start();request()->setLaravelSession($session);
    $store=app(PurposeStore::class);$op=(string)Str::uuid();$purpose='account_link';
    $phase='create_first_choice';$first=$store->change($purpose,'deny',PurposePolicy::NOTICE,0,$op);
    $proof=DB::table('acquisition_r1_proofs')->first();$created=CarbonImmutable::parse($proof->created_at);$expires=$proof->expires_at;
    $ops=DB::table('acquisition_r1_operations')->first();$operationExpiry=$ops->expires_at;
    $before=snapshot();foreach(['acquisition_r1_proofs','acquisition_r1_choices','acquisition_r1_operations'] as $name){unset($before[$name]);}
    $phase='replay_unchanged';CarbonImmutable::setTestNow($created->addHour());
    $replay=$store->change($purpose,'deny',PurposePolicy::NOTICE,0,$op);
    $unchanged=$store->change($purpose,'deny',PurposePolicy::NOTICE,1,(string)Str::uuid());
    requireTrue($first['state']['revision']===1&&$replay['state']['revision']===1&&$unchanged['status']==='unchanged','REPLAY OR UNCHANGED');
    requireTrue(DB::table('acquisition_r1_proofs')->first()->expires_at===$expires&&DB::table('acquisition_r1_operations')->where('id',$op)->value('expires_at')===$operationExpiry,'NO RETENTION EXTENSION');
    $phase='before_operation_expiry';CarbonImmutable::setTestNow(CarbonImmutable::parse($operationExpiry)->subMicrosecond());Artisan::call('acquisition:r1-prune',['--limit'=>1]);
    requireTrue(DB::table('acquisition_r1_operations')->where('id',$op)->exists(),'BEFORE EXACT OP BOUNDARY');
    $phase='at_operation_expiry';CarbonImmutable::setTestNow(CarbonImmutable::parse($operationExpiry));Artisan::call('acquisition:r1-prune',['--limit'=>1]);
    requireTrue(!DB::table('acquisition_r1_operations')->where('id',$op)->exists()&&DB::table('acquisition_r1_proofs')->count()===1,'EXACT OP BOUNDARY');
    $phase='before_proof_expiry';CarbonImmutable::setTestNow(CarbonImmutable::parse($expires)->subMicrosecond());Artisan::call('acquisition:r1-prune',['--limit'=>1000]);requireTrue(DB::table('acquisition_r1_proofs')->count()===1,'BEFORE PROOF BOUNDARY');
    $phase='at_proof_expiry';CarbonImmutable::setTestNow(CarbonImmutable::parse($expires));Artisan::call('acquisition:r1-prune',['--limit'=>1000]);
    requireTrue(DB::table('acquisition_r1_proofs')->count()===0&&DB::table('acquisition_r1_choices')->count()===0&&DB::table('acquisition_r1_operations')->count()===0,'EXACT PROOF CASCADE');
    $phase='other_rows_unchanged';$after=snapshot(array_map(fn($t)=>$t['columns'],$before));requireTrue($before===$after,'NON R1 ROWS MUST STAY');
    CarbonImmutable::setTestNow();
    echo json_encode(['role'=>$role,'clock_frozen_only_in_this_cli'=>true,'replay_unchanged_no_extension'=>true,'operation_24h_boundary'=>true,'proof_24months_boundary'=>true,'cascade_choices_operations'=>true,'other_tables_unchanged'=>count($before)]);
}else{throw new LogicException('OWN ROLE/ACTION REQUIRED');}

} catch (Throwable $exception) {
    $exitCode=1;
    $safe=['passed'=>false,'candidate'=>(string)getenv('H5_R1_CANDIDATE'),'role'=>$role,'action'=>$action,'phase'=>$phase,'assertion'=>$assertion,'exception_type'=>get_class($exception)];
    if($booted){
        try{H5R1Runtime::write('probe-diagnostics/failure-'.getmypid().'-'.bin2hex(random_bytes(6)).'.json',$safe+['message_private'=>$exception->getMessage(),'stack_private'=>$exception->getTraceAsString()]);}
        catch(Throwable){fwrite(STDERR,"Private diagnostic persistence failed; captured process output retained.\n");}
    }
    echo json_encode($safe,JSON_THROW_ON_ERROR)."\n";
} finally {
    CarbonImmutable::setTestNow();
}
exit($exitCode);
