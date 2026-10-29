<?php
// 06-only explicit MFA fixture, never an enrollment acceptance result.
require dirname(__DIR__,2).'/h5-platform/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/h5-platform/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$root=realpath(dirname(__DIR__,2));$ws=json_decode(file_get_contents($root.'/.runtime/workspace.json'),true,flags:JSON_THROW_ON_ERROR);
if(!$app->environment('local')||config('mail.default')!=='preview'||!config('h5.modules.acquisition.ready')||config('acquisition.transport')!=='disabled'||config('acquisition.external_enabled')||DB::connection()->getDatabaseName()!==$ws['db'])throw new RuntimeException('Isolated local disabled-send fixture only.');
if(realpath($ws['root'])!==$root||parse_url(config('app.url'),PHP_URL_HOST)!=='127.0.0.1')throw new RuntimeException('Own loopback workspace required.');
$file=$root.'/.runtime/acquisition-operator-preview-account.json';$a=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);if((fileperms($file)&0077)!==0||!str_starts_with($a['email'],'h5-06-acq-')||!str_ends_with($a['email'],'@example.test'))throw new RuntimeException('Private 06 synthetic account required.');
if(!in_array('--rotate-synthetic-mfa',$argv,true))throw new RuntimeException('Explicit --rotate-synthetic-mfa required; overwrites only this fixture MFA.');
$staff=App\Modules\Identity\Models\Staff::where('email',$a['email'])->firstOrFail();if(!$staff->active||$staff->role!==App\Enums\Role::Acquisition)throw new RuntimeException('Expected the UI-created active acquisition test staff.');
$mfa=Filament\Auth\MultiFactor\App\AppAuthentication::make()->recoverable();$secret=$mfa->generateSecret();$codes=$mfa->generateRecoveryCodes();Illuminate\Support\Facades\DB::transaction(function()use($staff,$mfa,$secret,$codes){$mfa->saveSecret($staff,$secret);$mfa->saveRecoveryCodes($staff,$codes);});$a['totp_secret']=$secret;$a['recovery_codes']=$codes;$a['mfa_source']='private local fixture after UI-created staff; standard enrollment remains unverified';file_put_contents($file,json_encode($a,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($file,0600);echo "Private MFA fixture installed for UI-created staff; credentials not printed.\n";
