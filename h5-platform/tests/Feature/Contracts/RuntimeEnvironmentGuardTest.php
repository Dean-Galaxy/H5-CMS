<?php

namespace Tests\Feature\Contracts;

use App\Support\DirectConnectionContext;
use App\Support\RuntimeEnvironmentGuard;
use App\Support\ServerRegionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RuntimeEnvironmentGuardTest extends TestCase
{
    private array $environmentBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->environmentBefore = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
    }

    protected function tearDown(): void
    {
        [$server, $env, $process] = $this->environmentBefore;
        if ($server === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $server;
        }
        if ($env === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $env;
        }
        putenv($process === false ? 'APP_ENV' : 'APP_ENV='.$process);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    private function environment(string $environment): void
    {
        $this->app->instance('env', $environment);
        config(['app.env' => $environment]);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $environment;
        putenv('APP_ENV='.$environment);
    }

    private function safeStaging(array $changes = []): void
    {
        $this->environment('staging');
        config([
            'app.debug' => false, 'app.url' => 'https://staging.example.test',
            'h5.controlled_staging' => false, 'h5.simulation' => false, 'h5.local_us_test_region' => false,
            'h5.local_modules' => '', 'h5.modules.acquisition' => ['provider' => null, 'ready' => false],
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'trustedproxy.proxies' => null, 'billing.driver' => 'unconfigured',
            'mail.default' => 'smtp', 'mail.driver' => null,
            'mail.from.address' => 'noreply@example.test',
            'mail.mailers' => ['smtp' => [
                'transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
                'host' => 'smtp.example.test', 'port' => '587', 'timeout' => 10,
                'username' => 'SYNTHETIC_USER', 'password' => 'SYNTHETIC_SECRET_NOT_SENT',
                'require_tls' => true, 'verify_peer' => true, 'local_domain' => 'staging.example.test',
            ]],
        ]);
        config($changes);
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Unsafe configuration must fail before any operation.');
        } catch (LogicException $error) {
            $this->assertMatchesRegularExpression('/\A[A-Z0-9_]+\z/', $error->getMessage());
            $this->assertStringNotContainsString('SYNTHETIC_SECRET', $error->getMessage());
        }
    }

    public function test_default_off_does_not_release_an_origin_and_every_call_rechecks(): void
    {
        $this->safeStaging();
        $guard = app(RuntimeEnvironmentGuard::class);
        $guard->assertSafeRuntime();
        $this->rejects(fn () => $guard->controlledStagingOrigin());
        config(['h5.controlled_staging' => true, 'app.url' => 'https://STAGING.example.test:443/']);
        $this->assertSame('https://staging.example.test', $guard->controlledStagingOrigin());
        config(['app.debug' => true]);
        $this->rejects(fn () => $guard->controlledStagingOrigin());
        config(['app.debug' => false, 'mail.mailers.smtp.verify_peer' => false]);
        $this->rejects(fn () => $guard->controlledStagingOrigin());
    }

    public function test_boolean_environment_and_nonlocal_overrides_fail_closed(): void
    {
        foreach (['app.debug', 'h5.controlled_staging', 'h5.simulation', 'h5.local_us_test_region'] as $key) {
            foreach (['false', 'junk', 0, 1, null, []] as $value) {
                $this->safeStaging([$key => $value]);
                $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
            }
        }
        foreach ([
            ['app.debug' => true], ['h5.simulation' => true], ['h5.local_us_test_region' => true],
            ['h5.local_modules' => 'acquisition'], ['h5.local_modules' => []],
            ['session.secure' => false], ['session.secure' => 'true'],
            ['session.http_only' => false], ['session.same_site' => 'none'],
            ['trustedproxy.proxies' => '*'], ['trustedproxy.proxies' => ['127.0.0.1']],
            ['h5.modules.acquisition.ready' => true],
        ] as $changes) {
            $this->safeStaging($changes);
            $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
        }
        foreach (['stage', 'STAGING', 'unknown'] as $name) {
            $this->safeStaging();
            $this->environment($name);
            $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
        }
        $this->safeStaging(['app.env' => 'production']);
        $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
    }

    public function test_opt_in_never_enables_production_or_local_and_local_mail_remains_available(): void
    {
        foreach (['local', 'testing', 'production'] as $environment) {
            $this->safeStaging(['h5.controlled_staging' => true]);
            $this->environment($environment);
            $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->controlledStagingOrigin());
        }
        $this->environment('local');
        config(['h5.controlled_staging' => false, 'mail.default' => 'preview', 'session.secure' => false]);
        app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        $this->assertSame('preview', config('mail.default'));
        $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->controlledStagingOrigin());
    }

    public function test_origin_cannot_contain_unsafe_components_or_non_domain_hosts(): void
    {
        foreach ([
            'http://staging.example.test', 'https://user:secret@staging.example.test',
            'https://staging.example.test:444', 'https://staging.example.test:0443',
            'https://staging.example.test/path', 'https://staging.example.test/?x=1',
            'https://staging.example.test/#fragment', "https://staging.example.test\n",
            'https://staging.example.test.', 'https://localhost', 'https://a.localhost',
            'https://127.0.0.1', 'https://[::1]', 'https://bad_host.example.test',
            'https://staging.example.test\\@evil.test', null, [], 123,
        ] as $url) {
            $this->safeStaging(['app.url' => $url]);
            $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
        }
    }

    public function test_mail_checks_follow_real_nested_paths_and_refuse_downgrade_or_url_overrides(): void
    {
        foreach (['preview', 'log', 'array', 'sendmail', 'unreviewed'] as $transport) {
            $this->safeStaging([
                'mail.default' => 'fallback',
                'mail.mailers.fallback' => ['transport' => 'failover', 'mailers' => ['smtp', 'nested']],
                'mail.mailers.nested' => ['transport' => 'roundrobin', 'mailers' => ['bad']],
                'mail.mailers.bad' => ['transport' => $transport],
            ]);
            $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
        }
        foreach ([[], ['smtp', 'missing'], ['smtp', 'loop'], ['smtp', ['transport' => 'log']]] as $children) {
            $this->safeStaging(['mail.default' => 'loop',
                'mail.mailers.loop' => ['transport' => 'failover', 'mailers' => $children]]);
            $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
        }
        $this->safeStaging(['mail.default' => 'outer',
            'mail.mailers.outer' => ['transport' => 'failover', 'mailers' => array_fill(0, 8, 'inner')],
            'mail.mailers.inner' => ['transport' => 'roundrobin', 'mailers' => array_fill(0, 8, 'smtp')]]);
        $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
        $this->safeStaging(['mail.default' => 'fallback',
            'mail.mailers.fallback' => ['transport' => 'failover', 'mailers' => ['smtp'], 'retry_after' => 'junk']]);
        $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
        foreach ([
            ['mail.driver' => 'log'], ['mail.mailers.smtp.url' => 'smtp://example.test?verify_peer=0'],
            ['mail.mailers.smtp.scheme' => null], ['mail.mailers.smtp.scheme' => 'sendmail'],
            ['mail.mailers.smtp.require_tls' => false], ['mail.mailers.smtp.require_tls' => 'true'],
            ['mail.mailers.smtp.auto_tls' => false], ['mail.mailers.smtp.verify_peer' => false],
            ['mail.mailers.smtp.stream' => ['ssl' => ['allow_self_signed' => true]]],
            ['mail.mailers.smtp.host' => '127.0.0.1'], ['mail.mailers.smtp.port' => '587junk'],
            ['mail.mailers.smtp.timeout' => null], ['mail.mailers.smtp.password' => ''],
            ['mail.mailers.smtp.username' => "bad\r\nuser"], ['mail.from.address' => 'not-an-email'],
        ] as $changes) {
            $this->safeStaging($changes);
            $this->rejects(fn () => app(RuntimeEnvironmentGuard::class)->assertSafeRuntime());
        }
    }

    public function test_approved_smtp_configuration_builds_real_tls_transport_without_connecting(): void
    {
        foreach (['smtp', 'smtps'] as $scheme) {
            $this->safeStaging(['mail.mailers.smtp.scheme' => $scheme]);
            app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
            Mail::purge('smtp');
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $this->assertTrue($transport->isTlsRequired());
            $this->assertTrue($transport->isAutoTls());
            $this->assertSame($scheme === 'smtps', $transport->getStream()->isTLS());
            $this->assertSame([], $transport->getStream()->getStreamOptions());
        }
        $this->safeStaging(['mail.default' => 'fallback',
            'mail.mailers.fallback' => ['transport' => 'failover', 'mailers' => ['smtp']]]);
        app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
        $this->assertSame('fallback', config('mail.default'));
    }

    public function test_direct_connection_discards_previous_proxy_trust_and_hosting_auto_detection(): void
    {
        foreach (['site.on-forge.com', 'site.on-vapor.com', 'staging.example.test'] as $host) {
            Request::setTrustedProxies(['0.0.0.0/0'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
            $request = Request::create('http://'.$host.'/', server: [
                'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
                'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'evil.test',
                'HTTP_FORWARDED' => 'for=8.8.8.8;proto=https;host=evil.test', 'HTTP_CF_IPCOUNTRY' => 'US',
            ]);
            (new DirectConnectionContext)->handle($request, function (Request $request) use ($host) {
                $this->assertSame('203.0.113.9', $request->ip());
                $this->assertFalse($request->isSecure());
                $this->assertSame($host, $request->getHost());
                $this->assertSame([], Request::getTrustedProxies());

                return response('ok');
            });
            $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'],
                (new ServerRegionResolver($request, 'staging', false, false))->resolve()->toArray());
        }
    }

    public function test_nonlocal_http_requires_direct_tls_canonical_host_and_default_port(): void
    {
        $this->safeStaging();
        foreach (['http://staging.example.test/', 'https://staging.example.test:444/', 'https://evil.test/'] as $url) {
            $request = Request::create($url, server: ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'staging.example.test']);
            try {
                (new DirectConnectionContext)->handle($request, fn () => $this->fail('Unsafe ingress reached the next handler.'));
                $this->fail('Unsafe ingress was accepted.');
            } catch (BadRequestHttpException $error) {
                $this->assertSame(400, $error->getStatusCode());
            }
        }
        $result = (new DirectConnectionContext)->handle(Request::create('https://staging.example.test/'), fn () => response('ok'));
        $this->assertSame('ok', $result->getContent());
    }

    public function test_real_staging_boot_and_cached_configuration_are_checked_without_network(): void
    {
        $this->safeStaging(['h5.controlled_staging' => true]);
        $safe = config()->all();
        $safe['app']['key'] = 'base64:'.base64_encode(str_repeat('x', 32));
        $cases = [
            ['staging', [], true, 'https://staging.example.test'],
            ['staging', ['app.debug' => true], false, null],
            ['staging', ['app.debug' => 'false'], false, null],
            ['staging', ['h5.controlled_staging' => false], true, null],
            ['staging', ['app.env' => 'production', 'h5.controlled_staging' => false], false, null],
            ['production', ['app.env' => 'production'], false, null],
            ['stage', ['app.env' => 'stage', 'h5.controlled_staging' => false], false, null],
            ['staging', ['mail.mailers.smtp.require_tls' => false], false, null],
            ['staging', ['mail.default' => 'log', 'mail.mailers.log' => ['transport' => 'log']], false, null],
            ['staging', ['h5.local_us_test_region' => true], false, null],
            ['staging', ['session.secure' => false], false, null],
        ];
        $directory = base_path('../.runtime/runtime-guard-probes/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory, 0700);
        try {
            foreach ($cases as $i => [$environment, $changes, $expected, $origin]) {
                $configuration = $safe;
                foreach ($changes as $key => $value) {
                    data_set($configuration, $key, $value);
                }
                $cache = $directory.'/config.php';
                file_put_contents($cache, '<?php return '.var_export($configuration, true).';');
                chmod($cache, 0600);
                $process = new Process([PHP_BINARY, __DIR__.'/Support/runtime-guard-probe.php', $origin ? 'origin' : 'boot'],
                    base_path(), ['APP_ENV' => $environment, 'APP_CONFIG_CACHE' => $cache,
                        'H5_RUNTIME_GUARD_PROBE' => '1', 'H5_LOCAL_MODULES' => '']);
                $process->setTimeout(25);
                $process->run();
                $this->assertSame(0, $process->getExitCode(), 'Probe '.$i.' '.$process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($expected, $result['ok'], 'Probe '.$i.' '.json_encode($result));
                if ($expected) {
                    $this->assertSame('staging', $result['environment']);
                    $this->assertSame($origin, $result['origin']);
                    $this->assertSame('ZZ', $result['region']['country_code']);
                    $this->assertFalse($result['acquisition_ready']);
                } else {
                    $this->assertSame(null, $result['origin']);
                }
                $this->assertStringNotContainsString('SYNTHETIC_SECRET', $process->getOutput());
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_real_uncached_environment_loading_preserves_invalid_booleans(): void
    {
        $directory = base_path('../.runtime/runtime-guard-probes/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory, 0700);
        $safe = [
            'APP_ENV' => 'staging', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://staging.example.test',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'APP_CONFIG_CACHE' => $directory.'/uncached.php', 'H5_RUNTIME_GUARD_PROBE' => '1',
            'H5_RUNTIME_GUARD_CACHE_MODE' => 'uncached', 'H5_CONTROLLED_STAGING' => 'true',
            'H5_LOCAL_MODULES' => '', 'H5_SIMULATION' => 'false', 'H5_LOCAL_US_TEST_REGION' => 'false',
            'SESSION_SECURE_COOKIE' => 'true', 'SESSION_HTTP_ONLY' => 'true', 'SESSION_SAME_SITE' => 'lax',
            'H5_BILLING_DRIVER' => 'unconfigured', 'MAIL_MAILER' => 'smtp', 'MAIL_SCHEME' => 'smtp',
            'MAIL_URL' => 'null', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587', 'MAIL_TIMEOUT' => '10',
            'MAIL_USERNAME' => 'SYNTHETIC_USER', 'MAIL_PASSWORD' => 'SYNTHETIC_SECRET_NOT_SENT',
            'MAIL_REQUIRE_TLS' => 'true', 'MAIL_EHLO_DOMAIN' => 'staging.example.test',
            'MAIL_FROM_ADDRESS' => 'noreply@example.test',
        ];
        $cases = [[[], true]];
        foreach (['APP_DEBUG', 'H5_CONTROLLED_STAGING', 'H5_SIMULATION', 'H5_LOCAL_US_TEST_REGION'] as $name) {
            foreach (['junk', '0', '1'] as $value) {
                $cases[] = [[$name => $value], false];
            }
        }
        $cases[] = [['APP_DEBUG' => 'true'], false];
        $cases[] = [['MAIL_REQUIRE_TLS' => 'false'], false];
        $cases[] = [['MAIL_MAILER' => 'failover'], false];
        try {
            foreach ($cases as $i => [$changes, $expected]) {
                $process = new Process([PHP_BINARY, __DIR__.'/Support/runtime-guard-probe.php', 'origin'],
                    base_path(), array_replace($safe, $changes));
                $process->setTimeout(25);
                $process->run();
                $this->assertSame(0, $process->getExitCode(), 'Raw probe '.$i.' '.$process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($expected, $result['ok'], 'Raw probe '.$i.' '.json_encode($result));
                if ($expected) {
                    $this->assertSame('staging', $result['environment']);
                    $this->assertSame('https://staging.example.test', $result['origin']);
                    $this->assertSame('ZZ', $result['region']['country_code']);
                    $this->assertFalse($result['acquisition_ready']);
                } else {
                    $this->assertSame(null, $result['origin']);
                }
                $this->assertStringNotContainsString('SYNTHETIC_SECRET', $process->getOutput());
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
