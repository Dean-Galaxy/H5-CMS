<?php

use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

// Explicit developer fixture, never loaded by the application or a public route.
require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(base_path('..'));
$workspace = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('local') || config('mail.default') !== 'preview' || $workspace['root'] !== $root
    || DB::connection()->getDatabaseName() !== $workspace['db']
    || ! in_array(parse_url(config('app.url'), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Isolated local preview only.');
}
$path = $root.'/.runtime/acquisition-preview-account.json';
if (is_file($path)) {
    echo "Existing local preview account preserved.\n";
    exit;
}
$password = bin2hex(random_bytes(20)).'A9';
$secret = app(Google2FA::class)->generateSecretKey();
$staff = Staff::create(['name' => '05 本地验收负责人', 'email' => 'acquisition-preview-'.bin2hex(random_bytes(5)).'@example.test',
    'password' => $password, 'role' => Role::Owner]);
$staff->saveAppAuthenticationSecret($secret);
file_put_contents($path, json_encode(['id' => $staff->id, 'email' => $staff->email, 'password' => $password, 'totp_secret' => $secret,
    'purpose' => 'Only 05 isolated local preview; never a production account'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Local preview account saved privately; no email sent.\n";
