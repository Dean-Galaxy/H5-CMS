<?php
/** 06 CLI-only private R1 seed/observations. No payment, advertising, or identity-state overrides. */
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Content\Services\ContentManager;
use App\Modules\Content\Services\PublicationService;
use Illuminate\Support\Facades\DB;
use Filament\Auth\MultiFactor\App\AppAuthentication;
$root=dirname(__DIR__,2);
require $root.'/h5-platform/vendor/autoload.php';
require __DIR__.'/r1_window_runtime.php';
if (PHP_SAPI!=='cli') { throw new LogicException('CLI ONLY'); }
H5R1Runtime::boot();umask(0077);
$action=$argv[1]??'';$label=$argv[2]??'';
if ($action==='seed') {
    if (getenv('H5_R1_DB_ROLE')!=='manual' || is_file(H5R1Runtime::directory().'/fixtures/content.json')) { throw new LogicException('FRESH MANUAL SEED ONLY'); }
    foreach (['identity_viewers','identity_staff','content_dramas','billing_prices','billing_orders','acquisition_subjects','acquisition_c_subjects','acquisition_r1_proofs'] as $table) {
        if (DB::table($table)->exists()) { throw new LogicException('EMPTY OWN DATA REQUIRED; NO RESET'); }
    }
    $password = bin2hex(random_bytes(24)).'Aa9!';
    $staff = Staff::create(['name' => '06 R1 Synthetic Owner', 'email' => '06-owner-'.bin2hex(random_bytes(6)).'@example.test',
        'password' => $password, 'role' => Role::Owner]);
    $secret = app(AppAuthentication::class)->generateSecret(); $staff->saveAppAuthenticationSecret($secret);
    H5R1Runtime::write('fixtures/owner.json', ['email' => $staff->email, 'password' => $password, 'totp_secret' => $secret]);
    $session = app('session.store'); $session->start(); request()->setLaravelSession($session);
    auth('staff')->login($staff); $session->put('identity.version.staff', $staff->session_version); $session->put('identity.mfa_staff', $staff->id);
    $media = app(MediaStore::class); $assets = [];
    $source = H5R1Runtime::directory().'/generated/synthetic.mp4';
    if (! is_file($source) || is_link($source)) { throw new LogicException('GENERATE OWN SYNTHETIC VIDEO FIRST'); }
    $cover = H5R1Runtime::directory().'/generated-cover.png';
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
    $drama = $manager->saveDrama(['title_en' => '06 R1 Synthetic Journey', 'title_zh' => '06 R1 自行生成测试剧',
        'synopsis_en' => 'Self-generated local test image/tone; no real provider or content.', 'synopsis_zh' => '本区自行生成画面及音频，仅技术测试。',
        'total_episodes' => 6, 'cover_asset_id' => $assets['cover'], 'regions' => ['US'], 'license_from' => now()->subDay()->toIso8601String(),
        'license_until' => now()->addMonth()->toIso8601String(), 'rights_note' => 'Self generated ffmpeg lavfi; no third party materials.',
        'recommended' => true, 'sort_order' => 0, 'category_ids' => []]);
    if ((int) $drama->free_count !== 5) { throw new LogicException('FIVE FREE EPISODES REQUIRED'); }
    for ($number = 1; $number <= 6; $number++) {
        $manager->saveEpisode($drama->id, ['number' => $number, 'title_en' => 'Synthetic '.$number, 'title_zh' => '合成第'.$number.'集', 'video_asset_id' => $assets['video']]);
    }
    $publication = app(PublicationService::class); $input = ['mode' => 'completed', 'interval_days' => 1, 'batch_size' => 1];
    $preview = $publication->previewLaunchNow($drama->id, $input);
    $publication->launchNow($drama->id, $input + ['plan_version' => $preview['plan_version'], 'confirmation' => $preview['confirmation']]);
    H5R1Runtime::write('fixtures/content.json',['seed_candidate'=>getenv('H5_R1_CANDIDATE'),'synthetic_only'=>true,'drama_id'=>$drama->id,
        'episodes'=>$drama->episodes()->orderBy('number')->pluck('id')->all(),'free_count'=>5]);
    echo json_encode(['seeded'=>true,'episodes'=>6,'free_count'=>5,'viewers_created'=>0,'prices_or_payments_created'=>0])."\n";
} elseif ($action==='actor' || $action==='actor-status') {
    if (!preg_match('/^[a-z0-9-]{1,48}$/D',$label) || getenv('H5_R1_DB_ROLE')!=='manual') { throw new LogicException('OWN MANUAL ACTOR LABEL REQUIRED'); }
    $path=H5R1Runtime::directory().'/fixtures/actor-'.$label.'.json';
    if ($action==='actor') {
        if (file_exists($path)) { throw new LogicException('ACTOR EXISTS; NO OVERWRITE'); }
        $kind=str_starts_with($label,'manual-')?'manual-unregistered':'automation-material';
        $email='r1-'.substr($label,0,16).'-'.bin2hex(random_bytes(8)).'@example.test';
        if (DB::table('identity_viewers')->where('email',$email)->exists()) { throw new LogicException('UNIQUE UNUSED MATERIAL REQUIRED'); }
        H5R1Runtime::write('fixtures/actor-'.$label.'.json',['kind'=>$kind,'name'=>'R1 Synthetic '.$label,'email'=>$email,'password'=>bin2hex(random_bytes(20)).'Aa7!', 'registered_by_generator'=>false]);
        echo json_encode(['private_actor_file'=>$path,'registered'=>false,'kind'=>$kind])."\n";
    } else {
        $actor=H5R1Runtime::document($path);$viewer=DB::table('identity_viewers')->where('email',$actor['email'])->first();
        $receipt=$viewer?DB::table('identity_registration_receipts')->where('viewer_id',$viewer->id)->first():null;
        echo json_encode(['exists'=>$viewer!==null,'verified'=>$viewer?->email_verified_at!==null,'receipt_exists'=>$receipt!==null,
            'receipt_versions_match'=>$receipt && $receipt->terms_version==='local-c-terms-r1-v1' && $receipt->privacy_notice_version==='local-c-privacy-r1-v1',
            'manual_material_still_unregistered'=>$actor['kind']==='manual-unregistered' && $viewer===null])."\n";
    }
} elseif ($action==='fingerprint') {
    $tables=[];
    foreach(DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public' ORDER BY tablename") as $table){
        $name=$table->tablename;$rows=[];
        foreach(DB::table($name)->get() as $record){$row=(array)$record;ksort($row);$rows[]=json_encode($row,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);}
        sort($rows,SORT_STRING);$tables[$name]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
    }
    echo json_encode(['tables'=>$tables,'sha256'=>hash('sha256',json_encode($tables,JSON_THROW_ON_ERROR)),'scope'=>'all_public_table_rows_including_sessions_cache_jobs'],JSON_THROW_ON_ERROR)."\n";
} elseif ($action==='state') {
    $counts=[];
    foreach(DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public' ORDER BY tablename") as $table){$counts[$table->tablename]=DB::table($table->tablename)->count();}
    $fixture=is_file(H5R1Runtime::directory().'/fixtures/content.json')?H5R1Runtime::document(H5R1Runtime::directory().'/fixtures/content.json'):null;
    echo json_encode(['candidate'=>getenv('H5_R1_CANDIDATE'),'role'=>getenv('H5_R1_DB_ROLE'),'migrations'=>DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
        'counts'=>$counts,'gateway'=>get_class(app(App\Modules\Billing\Gateway\PaymentGateway::class)),
        'paypal_api'=>config('billing.paypal.enabled'),'purchase'=>config('billing.new_purchase_provider'),'advertising_external'=>config('acquisition.external_enabled'),'transport'=>config('acquisition.transport'),'mail'=>config('mail.default'),'debug'=>config('app.debug'),
        'registration_provider'=>get_class(app(App\Contracts\RegistrationMeasurement::class)), 'result_observations'=>config('acquisition.registration.observations_enabled'),
        'policy_from'=>config('acquisition.registration.synthetic_from'),'policy_until'=>config('acquisition.registration.synthetic_until'),
        'c_events'=>DB::table('acquisition_c_events')->select('event_name')->selectRaw('COUNT(*) AS count')->groupBy('event_name')->get(),
        'r1_choice_counts'=>Illuminate\Support\Facades\Schema::hasTable('acquisition_r1_choices') ? DB::table('acquisition_r1_choices')->select('purpose','choice')->selectRaw('COUNT(*) AS count')->groupBy('purpose','choice')->get() : [],
        'free_episode_path'=>$fixture?'/dramas/'.$fixture['drama_id'].'/episodes/'.$fixture['episodes'][0]:null],JSON_THROW_ON_ERROR)."\n";
} else { throw new LogicException('KNOWN READ OR SYNTHETIC SEED ACTION REQUIRED'); }
