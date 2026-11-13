<?php

namespace Tests\Feature\Identity;

use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\StagingTestRecipients;
use App\Modules\Identity\Services\Verification;
use App\Support\ApiProblem;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use LogicException;
use Tests\TestCase;

class StagingTestRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private array $environmentBefore;

    private ArrayTransport $transport;

    private array $trustedHostsBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trustedHostsBefore = Request::getTrustedHosts();
        $this->environmentBefore = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $this->environment('staging');
        config([
            'app.debug' => false, 'app.url' => 'https://staging.example.test',
            'h5.controlled_staging' => true, 'h5.acquisition_staging_test' => true,
            'h5.simulation' => false, 'h5.local_us_test_region' => false, 'h5.local_modules' => '',
            'h5.measurement_c_experience' => false, 'h5.measurement_c_registration' => false,
            'h5.viewer_email_pages_enabled' => true,
            'h5.modules.acquisition' => ['provider' => AcquisitionServiceProvider::class, 'ready' => true],
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'trustedproxy.proxies' => null, 'mail.default' => 'smtp', 'mail.driver' => null,
            'mail.from.address' => 'sender@example.test',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
                'host' => 'smtp.example.test', 'port' => 587, 'timeout' => 10,
                'username' => 'synthetic', 'password' => 'synthetic', 'require_tls' => true],
            'identity.staging_test_recipients' => [' Allowed@Example.Test '],
            'acquisition.analytics_enabled' => false, 'acquisition.measurement.enabled' => false,
            'acquisition.measurement.synthetic_policy' => false, 'acquisition.registration.synthetic_policy' => false,
            'acquisition.registration.observations_enabled' => false, 'acquisition.transport' => 'disabled',
            'acquisition.external_enabled' => false, 'acquisition.platform_reviewed' => false,
        ]);
        // Exercise real Laravel notifications and MessageSending without a network transport.
        $this->transport = new ArrayTransport;
        Mail::extend('smtp', fn () => $this->transport);
        Mail::purge('smtp');
        $this->withoutMiddleware(PreventRequestForgery::class);
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme('https');
        $this->withServerVariables(['HTTPS' => 'on', 'HTTP_HOST' => 'staging.example.test']);
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
        Request::setTrustedHosts(array_map(fn ($pattern) => substr($pattern, 1, -2), $this->trustedHostsBefore));
        parent::tearDown();
    }

    private function environment(string $name): void
    {
        $this->app->instance('env', $name);
        config(['app.env' => $name]);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $name;
        putenv('APP_ENV='.$name);
    }

    private function payload(string $email): array
    {
        return ['name' => 'Synthetic', 'email' => $email, 'password' => 'SyntheticPassword123',
            'password_confirmation' => 'SyntheticPassword123'];
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a fixed refusal.');
        } catch (ApiProblem|LogicException $error) {
            $this->assertStringNotContainsString('@', $error->getMessage());
        }
    }

    public function test_allowed_registration_and_signed_verification_reuse_the_normal_path(): void
    {
        app(StagingTestRecipients::class)->assertConfigured();
        $this->postJson('/api/v1/auth/register', $this->payload('ALLOWED@example.test'))->assertOk();
        $viewer = Viewer::sole();
        $this->assertSame('allowed@example.test', $viewer->email);
        $this->assertFalse($viewer->hasVerifiedEmail());
        $this->assertCount(1, $this->transport->messages());
        $this->getJson(app(Verification::class)->url($viewer))->assertOk();
        $this->assertTrue($viewer->fresh()->hasVerifiedEmail());
    }

    public function test_outside_or_empty_allowlist_cannot_create_an_account(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload('other@example.test'))
            ->assertForbidden()->assertJsonPath('error.code', 'NOT_AUTHORIZED');
        config(['identity.staging_test_recipients' => []]);
        $this->postJson('/api/v1/auth/register', $this->payload('allowed@example.test'))->assertForbidden();
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->assertCount(0, $this->transport->messages());
    }

    public function test_email_correction_checks_before_mutation_and_reuses_normal_notification(): void
    {
        $viewer = Viewer::create($this->payload('old@example.test'));
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version]);
        $this->patchJson('/api/v1/auth/email', ['email' => 'other@example.test', 'password' => 'SyntheticPassword123'])->assertForbidden();
        $this->assertSame('old@example.test', $viewer->fresh()->email);
        $this->assertNull($viewer->fresh()->verification_nonce);
        $this->assertCount(0, $this->transport->messages());
        $this->travel(61)->seconds();
        $this->patchJson('/api/v1/auth/email', ['email' => 'ALLOWED@example.test', 'password' => 'SyntheticPassword123'])->assertOk();
        $this->assertSame('allowed@example.test', $viewer->fresh()->email);
        $this->assertNotNull($viewer->fresh()->verification_nonce);
        $this->assertCount(1, $this->transport->messages());
    }

    public function test_forbidden_resend_and_forgot_never_reach_transport(): void
    {
        $viewer = Viewer::create($this->payload('other@example.test'));
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $viewer->email])->assertForbidden();
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version]);
        $this->postJson('/api/v1/auth/email/verification-notification')->assertForbidden();
        $this->assertNull($viewer->fresh()->verification_nonce);
        $this->assertDatabaseCount('identity_password_reset_tokens', 0);
        $this->assertCount(0, $this->transport->messages());
    }

    public function test_allowed_forgot_and_resend_use_the_same_recipient_boundary(): void
    {
        $viewer = Viewer::create($this->payload('allowed@example.test'));
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $viewer->email])->assertOk();
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version]);
        $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        $this->assertCount(2, $this->transport->messages());
    }

    public function test_direct_mail_checks_all_to_cc_bcc_and_does_not_rewrite_addresses(): void
    {
        foreach ([
            ['to' => ['other@example.test']], ['cc' => ['other@example.test']],
            ['bcc' => ['other@example.test']], ['to' => ['allowed@example.test', 'other@example.test']],
            ['to' => ['allowed@example.test'], 'bcc' => ['allowed@example.test']],
        ] as $fields) {
            $this->denied(fn () => Mail::raw('Synthetic', function ($message) use ($fields): void {
                foreach ($fields as $field => $addresses) {
                    $message->{$field}($addresses);
                }
            }));
        }
        $this->assertCount(0, $this->transport->messages());
        Mail::raw('Synthetic', fn ($message) => $message->to('ALLOWED@example.test'));
        $sent = $this->transport->messages()->sole()->getOriginalMessage();
        $this->assertSame('ALLOWED@example.test', $sent->getTo()[0]->getAddress());
    }

    public function test_explicit_configuration_check_rejects_wrong_lane_and_invalid_configuration(): void
    {
        $guard = app(StagingTestRecipients::class);
        foreach ([
            ['identity.staging_test_recipients' => []], ['identity.staging_test_recipients' => 'allowed@example.test'],
            ['identity.staging_test_recipients' => ['allowed@example.test', 1]],
            ['identity.staging_test_recipients' => ['invalid']],
            ['mail.mailers.smtp.require_tls' => false], ['mail.mailers.smtp.transport' => 'array'],
            ['mail.default' => 'array'], ['h5.controlled_staging' => false],
            ['h5.acquisition_staging_test' => false], ['acquisition.analytics_enabled' => true],
        ] as $changes) {
            $before = config()->all();
            config($changes);
            $this->denied(fn () => $guard->assertConfigured());
            config()->set($before);
        }
        $this->environment('production');
        $this->denied(fn () => $guard->assertConfigured());
    }

    public function test_production_and_array_normal_paths_ignore_the_staging_recipient_list(): void
    {
        config(['identity.staging_test_recipients' => []]);
        $this->environment('production');
        Mail::raw('Synthetic', fn ($message) => $message->to('other@example.test'));
        $this->assertCount(1, $this->transport->messages());
        $this->environment('staging');
        config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
        $this->postJson('/api/v1/auth/register', $this->payload('other@example.test'))->assertOk();
        $this->assertSame('other@example.test', Viewer::sole()->email);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }
}
