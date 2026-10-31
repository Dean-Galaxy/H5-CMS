<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class MailFailureRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private SyntheticMailTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.measurement_c_registration' => false, 'mail.default' => 'identity_test',
            'mail.mailers.identity_test' => ['transport' => 'identity_test']]);
        $this->transport = new SyntheticMailTransport;
        Mail::extend('identity_test', fn () => $this->transport);
    }

    private function payload(): array
    {
        return ['name' => 'Synthetic', 'email' => 'synthetic@example.test', 'password' => 'SyntheticPassword123',
            'password_confirmation' => 'SyntheticPassword123', 'return_to' => '/membership'];
    }

    public function test_committed_ordinary_registration_transport_failure_continues_login_and_other_listeners(): void
    {
        $listened = false;
        Event::listen(Registered::class, function () use (&$listened): void {
            $listened = true;
        });
        Log::spy();
        $response = $this->postJson('/api/v1/auth/register?lang=en', $this->payload())->assertOk()
            ->assertJsonPath('redirect_to', '/identity/account')->assertJsonPath('identity.viewer.email_verified', false)
            ->assertJsonPath('identity.staff', null);
        $this->assertEqualsCanonicalizing(['identity', 'redirect_to', 'request_id'], array_keys($response->json()));
        $this->assertTrue($listened);
        $this->assertDatabaseCount('identity_viewers', 1);
        $this->assertDatabaseCount('identity_registration_receipts', 0);
        $this->assertSame(0, Staff::count());
        $viewer = Viewer::sole();
        $this->assertSame($viewer->id, Auth::guard('web')->id());
        $this->assertNull($viewer->verification_nonce);
        $this->assertSame('/membership', session('identity.return_to'));
        $this->get('/identity/account?lang=en')->assertOk()->assertSee('Your account was created, but verification email delivery is unconfirmed.');
        $this->postJson('/api/v1/auth/register', $this->payload())->assertRedirect();
        $this->assertDatabaseCount('identity_viewers', 1);
        Log::shouldHaveReceived('warning')->once()->with('identity.mail_delivery_unconfirmed', \Mockery::on(fn ($fields) => array_keys($fields) === ['failure_kind', 'request_id'] && $fields['failure_kind'] === 'transport' && is_string($fields['request_id'])));
        $this->transport->fail = false;
        $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        $this->assertNotNull($viewer->fresh()->verification_nonce);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $viewer->email, 'password' => 'SyntheticPassword123'])->assertOk();
    }

    public function test_html_mail_failure_has_chinese_account_notice_and_normal_success_keeps_safe_return(): void
    {
        $this->post('/api/v1/auth/register?lang=zh_CN', $this->payload())->assertRedirect('/identity/account');
        $this->get('/identity/account?lang=zh_CN')->assertSee('账号已创建，验证邮件发送未确认；可稍后重发，不必重新注册。');
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->transport->fail = false;
        $payload = $this->payload();
        $payload['email'] = 'success@example.test';
        $this->postJson('/api/v1/auth/register', $payload)->assertOk()->assertJsonPath('redirect_to', '/membership');
    }

    public function test_only_mail_send_transport_is_recoverable_not_creation_database_or_other_event_errors(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->assertSame(0, $this->transport->attempts);
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "identity_viewers"')) {
                throw new RuntimeException('synthetic database write failure');
            }
        });
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->assertNull(Auth::guard('web')->user());
        $this->assertSame(0, $this->transport->attempts);
    }

    public function test_registered_listener_error_including_transport_shaped_error_is_not_swallowed(): void
    {
        $this->transport->fail = false;
        Event::listen(Registered::class, fn () => throw new TransportException('synthetic unrelated listener'));
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('identity_viewers', 1);
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_non_transport_notification_error_is_not_mail_recovery(): void
    {
        $this->transport->otherFailure = true;
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('identity_viewers', 1);
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_resend_transport_failure_is_safe_503_rolls_back_nonce_and_does_not_auto_retry(): void
    {
        $viewer = Viewer::create(['name' => 'Synthetic', 'email' => 'synthetic@example.test', 'password' => 'SyntheticPassword123']);
        $this->transport->fail = false;
        $viewer->sendEmailVerificationNotification();
        $oldNonce = $viewer->fresh()->verification_nonce;
        $this->transport->fail = true;
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => 1]);
        $this->postJson('/api/v1/auth/email/verification-notification?lang=en')->assertStatus(503)
            ->assertJsonPath('error.code', 'MAIL_DELIVERY_UNCONFIRMED')->assertJsonPath('error.retryable', true)
            ->assertJsonPath('error.message', 'Email delivery could not be confirmed. Wait before retrying; the message may already have been submitted.')
            ->assertDontSee('synthetic-sensitive-smtp-diagnostic');
        $this->assertSame($oldNonce, $viewer->fresh()->verification_nonce);
        $this->assertSame(2, $this->transport->attempts);
        $this->assertDatabaseCount('identity_audit_events', 1); // Only the successful send audit.
        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(2, $this->transport->attempts);
    }

    public function test_forgot_success_missing_transport_failure_and_broker_throttle_share_neutral_response(): void
    {
        Viewer::create(['name' => 'Synthetic', 'email' => 'synthetic@example.test', 'password' => 'SyntheticPassword123']);
        $responses = [];
        $responses[] = $this->postJson('/api/v1/auth/forgot-password?lang=en', ['email' => 'synthetic@example.test'])->assertOk()->json();
        $this->assertSame(1, $this->transport->attempts);
        $firstToken = DB::table('identity_password_reset_tokens')->sole()->token;
        // Isolate the broker's own 60-second limit without clearing any shared cache/data.
        $this->withoutMiddleware(ThrottleRequests::class);
        $responses[] = $this->postJson('/api/v1/auth/forgot-password?lang=en', ['email' => 'synthetic@example.test'])->assertOk()->json();
        $this->assertSame(1, $this->transport->attempts);
        $this->assertSame($firstToken, DB::table('identity_password_reset_tokens')->sole()->token);
        $this->withMiddleware(ThrottleRequests::class);
        $responses[] = $this->postJson('/api/v1/auth/forgot-password?lang=en', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->travel(61)->seconds();
        $this->transport->fail = false;
        $responses[] = $this->postJson('/api/v1/auth/forgot-password?lang=en', ['email' => 'synthetic@example.test'])->assertOk()->json();
        $this->assertSame(2, $this->transport->attempts);
        foreach ($responses as &$response) {
            unset($response['request_id']);
        }
        $this->assertSame(array_fill(0, 4, $responses[0]), $responses);
        $this->assertStringContainsString('If this account is eligible', $responses[0]['message']);
        $this->postJson('/api/v1/auth/forgot-password?lang=zh_CN', ['email' => 'another@example.test'])->assertOk()
            ->assertJsonPath('message', '如果该账号适用，请查看密码重设邮件；未收到可稍后重试。本机测试邮件仅在本地预览。');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'another@example.test'])->assertStatus(429);
    }

    public function test_forgot_non_transport_failure_is_not_hidden(): void
    {
        Viewer::create(['name' => 'Synthetic', 'email' => 'synthetic@example.test', 'password' => 'SyntheticPassword123']);
        $this->transport->otherFailure = true;
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'synthetic@example.test'])->assertStatus(500);
    }
}

class SyntheticMailTransport extends AbstractTransport
{
    public bool $fail = true;

    public bool $otherFailure = false;

    public int $attempts = 0;

    protected function doSend(SentMessage $message): void
    {
        $this->attempts++;
        if ($this->otherFailure) {
            throw new RuntimeException('synthetic non-transport error');
        }
        if ($this->fail) {
            throw new TransportException('synthetic-sensitive-smtp-diagnostic');
        }
    }

    public function __toString(): string
    {
        return 'synthetic-identity-mail';
    }
}
