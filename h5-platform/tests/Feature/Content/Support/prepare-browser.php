<?php

// Explicit local browser fixture provisioning. Never a web route or production seed.
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use Illuminate\Contracts\Console\Kernel;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(base_path('..'));
$config = json_decode(file_get_contents($root.'/.runtime/workspace.json'), true, flags: JSON_THROW_ON_ERROR);
if (! app()->environment('local') || config('mail.default') !== 'preview'
    || realpath($config['root']) !== $root || $config['workspace'] !== config('h5.workspace')
    || $config['db'] !== config('database.connections.pgsql.database')
    || ! in_array(parse_url(config('app.url'), PHP_URL_HOST), ['localhost', '127.0.0.1'], true)
    || (int) parse_url(config('app.url'), PHP_URL_PORT) !== (int) $config['port']) {
    throw new RuntimeException('Only the dev.py-bound isolated local preview workspace is allowed.');
}
umask(0077);
$directory = $root.'/.runtime/content';
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$credentials = $directory.'/preview-account.json';
if (! file_exists($credentials)) {
    $password = bin2hex(random_bytes(20)).'Aa1';
    $email = 'content-browser-'.bin2hex(random_bytes(8)).'@example.test';
    $secret = (new Google2FA)->generateSecretKey();
    $staff = Staff::create(['name' => 'Content local browser fixture', 'email' => $email, 'password' => $password, 'role' => Role::Owner]);
    $staff->saveAppAuthenticationSecret($secret);
    file_put_contents($credentials, json_encode(['staff_id' => $staff->id, 'email' => $email, 'password' => $password, 'totp_secret' => $secret], JSON_THROW_ON_ERROR));
    chmod($credentials, 0600);
}
$ffmpeg = config('content.ffmpeg');
foreach ([
    ['-f', 'lavfi', '-i', 'color=c=blue:s=96x128:r=10', '-t', '2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $directory.'/ui-test.mp4'],
    ['-f', 'lavfi', '-i', 'color=c=blue:s=96x128', '-frames:v', '1', $directory.'/ui-cover.png'],
    ['-f', 'lavfi', '-i', 'color=c=blue:s=192x108', '-frames:v', '1', $directory.'/ui-banner.png'],
] as $args) {
    $process = new Process([$ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', ...$args]);
    $process->setTimeout(60);
    $process->mustRun();
}
echo "Prepared local-only staff/MFA and generated media in this workspace's private .runtime/content directory. No credentials printed.\n";
