<?php
// Read only synthetic MFA state; never return secret, hashes, recovery values or user identity.
$root = dirname(__DIR__, 2);
$ws = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
$file = $root.'/.runtime/06-mfa/account.json';
if (realpath($ws['root']) !== $root || in_array($ws['port'], [8206,8303,8377,8378], true) || is_link($file) || (fileperms($file) & 0077)) { exit(42); }
$account = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
if (!preg_match('/\Ah5-06-mfa-[a-f0-9]+@example\.test\z/', $account['email'])) { exit(42); }
require $root.'/h5-platform/vendor/autoload.php';
$app = require $root.'/h5-platform/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local') || config('database.connections.pgsql.database') !== $ws['db'] || (int)config('database.connections.pgsql.port') !== $ws['db_port']) { exit(42); }
Illuminate\Support\Facades\DB::beginTransaction();
Illuminate\Support\Facades\DB::statement('SET TRANSACTION READ ONLY');
$staff = App\Modules\Identity\Models\Staff::where('email', $account['email'])->sole();
$rateKey = 'livewire-rate-limiter:'.sha1(App\Modules\Identity\Filament\StaffLogin::class.'|authenticate|127.0.0.1');
$cacheStore = Illuminate\Support\Facades\Cache::store()->getStore();
$decode = Closure::bind(function ($value) { return $this->unserialize($value); }, $cacheStore, get_class($cacheStore));
$readCounter = function ($key) use ($cacheStore, $decode): int {
    $row = Illuminate\Support\Facades\DB::table(config('cache.stores.database.table'))->where('key', $cacheStore->getPrefix().$key)->first();
    if (!$row || $row->expiration <= time()) { return 0; }
    $value = $decode($row->value);
    return is_int($value) ? $value : 0;
};
$rate = ['attempts'=>$readCounter($rateKey), 'wait_seconds'=>max(0, $readCounter($rateKey.':timer') - time()), 'maximum'=>5];
echo json_encode(['login_rate_limit'=>$rate, 'imagick'=>extension_loaded('imagick'), 'secret_set'=>filled($staff->getAppAuthenticationSecret()), 'recovery_count'=>count($staff->getAppAuthenticationRecoveryCodes() ?? []), 'staff_count'=>App\Modules\Identity\Models\Staff::count()], JSON_THROW_ON_ERROR).PHP_EOL;
Illuminate\Support\Facades\DB::rollBack();
