<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\RegistrationTerms;
use App\Modules\Identity\Services\Verification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RegistrationTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.measurement_c_registration' => true, 'h5.measurement_c_experience' => true]);
        Notification::fake();
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['name' => 'Local viewer', 'email' => 'local@example.test',
            'password' => 'LocalPassword123', 'password_confirmation' => 'LocalPassword123',
            'terms_accepted' => true, 'terms_version' => RegistrationTerms::TERMS,
            'privacy_notice_version' => RegistrationTerms::PRIVACY], $overrides);
    }

    public function test_required_versions_and_strict_json_acceptance_are_enforced_before_any_write(): void
    {
        foreach ([false, 1, '1', 'true', null] as $value) {
            $this->postJson('/api/v1/auth/register', $this->input(['terms_accepted' => $value]))->assertUnprocessable();
        }
        $this->travel(61)->seconds();
        foreach (['terms_version', 'privacy_notice_version'] as $field) {
            $this->postJson('/api/v1/auth/register', $this->input([$field => 'old-or-forged']))->assertUnprocessable();
        }
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->assertDatabaseCount('identity_registration_receipts', 0);
    }

    public function test_account_and_minimal_test_receipt_commit_together_without_optional_choices(): void
    {
        $this->postJson('/api/v1/auth/register?lang=en', $this->input(['account_link' => false, 'advertising' => false, 'return_to' => '/membership']))
            ->assertOk()->assertJsonPath('redirect_to', '/identity/account')
            ->assertJsonPath('identity.viewer.email_verified', false)->assertJsonPath('identity.staff', null);
        $viewer = Viewer::sole();
        $row = DB::table('identity_registration_receipts')->sole();
        $this->assertSame($viewer->id, $row->viewer_id);
        $this->assertSame(RegistrationTerms::TERMS, $row->terms_version);
        $this->assertSame(RegistrationTerms::PRIVACY, $row->privacy_notice_version);
        $this->assertSame('en', $row->locale);
        $this->assertTrue($row->is_test);
        $this->assertSame(['viewer_id', 'terms_version', 'privacy_notice_version', 'locale', 'accepted_at', 'is_test'], array_keys((array) $row));
        $this->assertSame('/membership', session('identity.return_to'));
        $this->assertSame($viewer->id, Auth::guard('web')->id());
        $this->assertSame(0, Staff::count());
        $this->get('/identity/account?lang=en')->assertOk()->assertSee('Your local test account was created.')->assertSee('Optional choices');
        $viewer->delete();
        $this->assertDatabaseCount('identity_registration_receipts', 0);
    }

    public function test_receipt_storage_failure_rolls_back_the_account_too(): void
    {
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'insert into "identity_registration_receipts"')) {
                $fail = false;
                throw new RuntimeException('Synthetic receipt write failure');
            }
        });
        $this->postJson('/api/v1/auth/register', $this->input())->assertStatus(500);
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->assertDatabaseCount('identity_registration_receipts', 0);
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_html_non_javascript_registration_uses_explicit_checkbox_value_and_can_continue(): void
    {
        $this->post('/api/v1/auth/register', $this->input(['terms_accepted' => '1']))->assertRedirect('/identity/account');
        $this->get('/identity/account')->assertOk();
        $this->assertDatabaseCount('identity_registration_receipts', 1);
    }

    public function test_observer_or_mail_failure_keeps_the_account_and_shows_separate_notice(): void
    {
        Event::listen(Registered::class, fn () => throw new RuntimeException('Synthetic notification unavailable'));
        $this->postJson('/api/v1/auth/register', $this->input())->assertOk()
            ->assertJsonPath('identity.viewer.email_verified', false)->assertJsonPath('redirect_to', '/identity/account');
        $this->assertDatabaseCount('identity_viewers', 1);
        $this->assertDatabaseCount('identity_registration_receipts', 1);
        $this->get('/identity/account?lang=en')->assertOk()->assertSee('verification mail preview could not be confirmed');
        $this->postJson('/api/v1/auth/register', $this->input())->assertRedirect();
        $this->assertDatabaseCount('identity_viewers', 1);
        $this->assertDatabaseCount('identity_registration_receipts', 1);
    }

    public function test_r1_never_sends_through_a_real_transport_and_normal_mode_remains_unchanged(): void
    {
        config(['mail.default' => 'smtp']);
        $this->postJson('/api/v1/auth/register', $this->input())->assertOk();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('identity_registration_receipts', 1);
        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
    }

    public function test_real_private_preview_verifies_without_upgrading_optional_purposes(): void
    {
        Notification::swap(new ChannelManager($this->app));
        $directory = storage_path('framework/testing/r1-mail-'.Str::uuid());
        config(['mail.default' => 'preview', 'h5.mail_preview_root' => $directory]);
        $this->withSession(['identity-test-deny' => 'deny']);
        try {
            $this->postJson('/api/v1/auth/register', $this->input())->assertOk()->assertSessionHas('identity-test-deny', 'deny');
            $this->assertCount(1, glob($directory.'/*.eml'));
            $this->assertCount(1, glob($directory.'/*.html'));
            $viewer = Viewer::sole();
            $url = app(Verification::class)->url($viewer->fresh());
            $this->getJson($url)->assertOk()->assertSessionHas('identity-test-deny', 'deny');
            $this->assertTrue($viewer->fresh()->hasVerifiedEmail());
            $this->getJson($url)->assertStatus(409);
            $this->assertDatabaseCount('identity_registration_receipts', 1);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_csrf_rejection_cannot_create_an_account_or_receipt(): void
    {
        $this->app['env'] = 'local'; // Enable the real CSRF middleware in this isolated test only.
        $this->postJson('/api/v1/auth/register', $this->input())->assertStatus(419);
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->assertDatabaseCount('identity_registration_receipts', 0);
    }

    public function test_viewer_password_rules_and_staff_isolation_are_not_relaxed(): void
    {
        $this->postJson('/api/v1/auth/register', $this->input(['password' => 'Abcdef12345', 'password_confirmation' => 'Abcdef12345']))->assertUnprocessable();
        $this->postJson('/api/v1/auth/register', $this->input(['role' => 'owner', 'email_verified_at' => now(), 'account_link' => true, 'advertising' => true]))
            ->assertOk()->assertJsonPath('identity.staff', null)->assertJsonPath('identity.viewer.email_verified', false);
        $this->assertSame(0, Staff::count());
        $this->getJson('/api/v1/admin/staff')->assertUnauthorized();
        $this->get('/identity/account?lang=en')->assertSee('Optional choices');
    }

    public function test_false_flag_does_not_require_new_fields_or_backfill_existing_accounts(): void
    {
        config(['h5.measurement_c_registration' => false]);
        $input = $this->input();
        unset($input['terms_accepted'], $input['terms_version'], $input['privacy_notice_version']);
        $this->postJson('/api/v1/auth/register', $input)->assertOk();
        $this->assertDatabaseCount('identity_registration_receipts', 0);
        $this->get('/identity/register')->assertOk()->assertDontSee('data-registration-r1', false);
        $this->get('/identity/test-terms')->assertNotFound();
        $this->get('/identity/test-privacy')->assertNotFound();
    }

    public function test_receipt_migration_upgrade_and_rollback_preserve_existing_accounts_without_backfill(): void
    {
        $existing = Viewer::create(['name' => 'Pre-existing', 'email' => 'existing@example.test', 'password' => 'ExistingPassword123']);
        $migration = require database_path('migrations/2026_10_31_010001_create_identity_registration_receipts.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('identity_registration_receipts'));
        $this->assertTrue($existing->fresh()->exists);
        $migration->up();
        $this->assertDatabaseCount('identity_registration_receipts', 0);
        $this->postJson('/api/v1/auth/register', $this->input())->assertOk();
        $this->assertDatabaseCount('identity_viewers', 2);
        $this->assertDatabaseCount('identity_registration_receipts', 1);
        $this->assertDatabaseMissing('identity_registration_receipts', ['viewer_id' => $existing->id]);
        $migration->down();
        $this->assertDatabaseCount('identity_viewers', 2);
        $migration->up();
        $this->assertDatabaseCount('identity_registration_receipts', 0);
    }

    public function test_test_documents_are_readable_in_both_languages_and_choices_are_not_preselected(): void
    {
        foreach (['zh_CN', 'en'] as $locale) {
            $response = $this->get('/identity/register?lang='.$locale)->assertOk()->assertSee('data-registration-purposes', false)->assertSee('terms_accepted', false);
            $this->assertStringNotContainsString(' checked', $response->getContent());
            $response->assertSee('target="_blank" rel="noopener"', false)->assertSee('data-purpose-detail', false)->assertSee('data-purpose-latest', false);
            $response->assertSee($locale === 'en' ? 'Local Registration Test Terms' : '本机注册测试条款');
            $this->get('/identity/test-terms?lang='.$locale)->assertOk()->assertSee(RegistrationTerms::TERMS);
            $this->get('/identity/test-privacy?lang='.$locale)->assertOk()->assertSee(RegistrationTerms::PRIVACY);
        }
        $this->assertDatabaseCount('identity_viewers', 0);
        $this->assertDatabaseCount('identity_registration_receipts', 0);
    }
}
