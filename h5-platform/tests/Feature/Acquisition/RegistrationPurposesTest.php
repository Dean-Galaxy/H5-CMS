<?php

namespace Tests\Feature\Acquisition;

use App\Enums\Role;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Measurement\CorePolicy;
use App\Modules\Acquisition\Registration\PurposePolicy;
use App\Modules\Acquisition\Registration\PurposeProof;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Acquisition\Services\PaymentReceiver;
use App\Modules\Identity\Models\Viewer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class RegistrationPurposesTest extends AcquisitionTestCase
{
    private const URL = '/api/v3/measurement/purposes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['h5.measurement_c_experience' => true, 'h5.measurement_c_registration' => true,
            'acquisition.registration.synthetic_policy' => true, 'acquisition.registration.synthetic_from' => now()->toIso8601String(),
            'acquisition.registration.synthetic_until' => now()->addDay()->toIso8601String()]);
        $s = app('session.store');
        $s->setName(config('h5.workspace').'_viewer');
        $this->withCredentials()->withCookie($s->getName(), $s->getId());
    }

    private function viewer(): Viewer
    {
        $v = Viewer::create(['name' => 'R1 synthetic', 'email' => Str::uuid().'@example.test', 'password' => 'SyntheticPass1234']);
        $this->actingAs($v, 'web')->withSession(['identity.version.web' => $v->session_version]);

        return $v;
    }

    private function body(string $purpose = 'account_link', string $choice = 'deny', int $revision = 0): array
    {
        return ['purpose' => $purpose, 'choice' => $choice, 'notice_version' => PurposePolicy::NOTICE,
            'expected_revision' => $revision, 'operation_id' => (string) Str::uuid()];
    }

    private function send(array $body)
    {
        return $this->patchJson(self::URL.'/preferences', $body);
    }

    private function legacy(bool $analytics, bool $advertising)
    {
        config(['acquisition.analytics_enabled' => true]);

        return $this->postJson('/api/v1/tracking/consent', ['analytics' => $analytics, 'advertising' => $advertising, 'policy_version' => 'analytics-v1']);
    }

    public function test_context_disabled_or_eligible_never_creates_measurement_or_preference_proof(): void
    {
        foreach ([false, true] as $flag) {
            config(['h5.measurement_c_registration' => $flag]);
            $r = $this->getJson(self::URL.'/context')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $r->assertJsonPath('schema_version', 3)->assertJsonPath('policy_version', PurposePolicy::VERSION)
                ->assertJsonPath('notice_version', PurposePolicy::NOTICE)->assertJsonPath('is_test', true)
                ->assertJsonPath('purposes.account_link.choice', 'unknown')->assertJsonPath('purposes.account_link.effective', false)
                ->assertJsonPath('purposes.account_link.capability', $flag ? 'choice_only' : 'unavailable')
                ->assertJsonPath('legacy_advertising.choice', 'unknown')->assertCookieMissing(app(PurposeProof::class)->cookie());
        }
        foreach (['r1_proofs', 'r1_choices', 'r1_operations', 'c_preferences', 'c_subjects', 'c_events', 'privacy', 'subjects', 'analytics_orders'] as $table) {
            $this->assertDatabaseCount('acquisition_'.$table, 0);
        }
    }

    public function test_policy_must_be_independent_explicit_and_at_most_24_hours(): void
    {
        $this->viewer();
        foreach ([['acquisition.registration.synthetic_policy' => false],
            ['acquisition.registration.synthetic_policy' => true, 'acquisition.registration.synthetic_until' => now()->addDays(2)->toIso8601String()],
            ['acquisition.registration.synthetic_until' => now()->toIso8601String()],
            ['acquisition.registration.synthetic_until' => 'invalid'],
            ['h5.measurement_c_registration' => false]] as $values) {
            config($values + ['h5.local_us_test_region' => true]);
            $this->send($this->body(choice: 'allow'))->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
        }
        $this->assertDatabaseCount('acquisition_r1_proofs', 0);
        $this->send($this->body())->assertOk()->assertJsonPath('state.choice', 'deny');
    }

    public function test_allow_requires_real_web_identity_staff_and_forged_identity_are_not_enough(): void
    {
        $this->send($this->body(choice: 'allow'))->assertUnauthorized();
        $this->signIn($this->staff(Role::Owner));
        $this->send($this->body(choice: 'allow'))->assertUnauthorized();
        $this->send($this->body() + ['viewer_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('acquisition_r1_proofs', 0);
    }

    public function test_two_purposes_save_separately_without_running_or_writing_legacy_grants(): void
    {
        $v = $this->viewer();
        foreach (PurposePolicy::PURPOSES as $purpose) {
            $this->send($this->body($purpose, 'allow'))->assertOk()->assertJsonPath('status', 'saved')
                ->assertJsonPath('state.choice', 'allow')->assertJsonPath('state.effective', false)
                ->assertJsonPath('state.capability', 'choice_only')->assertJsonPath('state.revision', 1);
        }
        $this->assertDatabaseCount('acquisition_r1_proofs', 1);
        $this->assertDatabaseCount('acquisition_r1_choices', 2);
        $this->assertDatabaseHas('acquisition_r1_choices', ['purpose' => 'account_link', 'owner_id' => $v->id]);
        foreach (['privacy', 'subjects', 'c_subjects', 'c_events', 'sources', 'analytics_orders', 'deliveries'] as $table) {
            $this->assertDatabaseCount('acquisition_'.$table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_choice_only_allow_is_hidden_from_another_account_or_anonymous_but_deny_persists(): void
    {
        $this->viewer();
        $this->send($this->body(choice: 'allow'))->assertOk();
        $this->viewer();
        $this->getJson(self::URL.'/context')->assertJsonPath('purposes.account_link.choice', 'unknown')->assertJsonPath('purposes.account_link.revision', 1);
        $this->send($this->body(revision: 1))->assertOk()->assertJsonPath('state.choice', 'deny');
        Auth::guard('web')->logout();
        $this->getJson(self::URL.'/context')->assertJsonPath('purposes.account_link.choice', 'deny');
    }

    public function test_legacy_grant_is_read_only_separate_and_not_a_new_notice_grant(): void
    {
        $this->viewer();
        $this->legacy(false, true)->assertOk();
        $before = DB::table('acquisition_privacy')->get()->toJson();
        $this->getJson(self::URL.'/context')->assertJsonPath('legacy_advertising.choice', 'allow')->assertJsonPath('purposes.advertising.choice', 'unknown');
        $this->send($this->body('advertising', 'allow'))->assertOk();
        $this->assertSame($before, DB::table('acquisition_privacy')->get()->toJson());
    }

    public function test_site_denial_survives_purpose_grants_and_the_v2_contract_remains_strict(): void
    {
        $this->viewer();
        $this->patchJson('/api/v2/measurement/preferences', ['site_session' => 'deny', 'notice_version' => CorePolicy::NOTICE, 'expected_revision' => 0])->assertOk();
        $before = DB::table('acquisition_c_preferences')->get()->toJson();
        $this->send($this->body(choice: 'allow'))->assertOk()->assertJsonPath('state.reason', 'SITE_OPTOUT');
        $this->assertSame($before, DB::table('acquisition_c_preferences')->get()->toJson());
        $this->patchJson('/api/v2/measurement/preferences', $this->body())->assertUnprocessable();
    }

    public function test_cas_notice_conflicts_and_replayed_allow_return_current_deny_without_resurrection(): void
    {
        $this->viewer();
        $allow = $this->body(choice: 'allow');
        $this->send($allow)->assertOk();
        $this->send($this->body(revision: 0))->assertStatus(409)->assertJsonPath('state.revision', 1);
        $wrong = $this->body(revision: 1);
        $wrong['notice_version'] = 'stale';
        $this->send($wrong)->assertStatus(409)->assertJsonPath('error.code', 'NOTICE_CONFLICT');
        $this->send($this->body(revision: 1))->assertOk()->assertJsonPath('state.revision', 2);
        config(['h5.measurement_c_registration' => false]);
        $this->send($allow)->assertOk()->assertJsonPath('state.choice', 'deny')->assertJsonPath('state.revision', 2);
        config(['h5.measurement_c_registration' => true]);
        $changed = $allow;
        $changed['choice'] = 'deny';
        $this->send($changed)->assertStatus(409)->assertJsonPath('error.code', 'OPERATION_CONFLICT');
        $this->viewer();
        $this->send($allow)->assertStatus(409)->assertJsonPath('state.choice', 'deny');
        $this->assertDatabaseCount('acquisition_r1_operations', 2);
    }

    public function test_replay_unchanged_and_reads_do_not_extend_proof_choice_or_receipt(): void
    {
        $b = $this->body();
        $this->send($b)->assertOk();
        $proof = DB::table('acquisition_r1_proofs')->first();
        $row = DB::table('acquisition_r1_choices')->first();
        $operation = DB::table('acquisition_r1_operations')->first();
        $this->travel(2)->hours();
        // The in-process test cookie queue survives requests; a real HTTP worker's queue does not.
        Cookie::unqueue(app(PurposeProof::class)->cookie());
        $this->getJson(self::URL.'/context')->assertOk()->assertCookieMissing(app(PurposeProof::class)->cookie());
        $this->send($b)->assertOk()->assertCookieMissing(app(PurposeProof::class)->cookie());
        $this->send($this->body(revision: 1))->assertOk()->assertJsonPath('status', 'unchanged')->assertJsonPath('state.revision', 1);
        $this->assertEquals($proof, DB::table('acquisition_r1_proofs')->first());
        $this->assertEquals($row, DB::table('acquisition_r1_choices')->first());
        $this->assertSame($operation->expires_at, DB::table('acquisition_r1_operations')->where('id', $operation->id)->value('expires_at'));
    }

    public function test_advertising_denial_stops_recognizable_legacy_delivery_and_preserves_snapshots_and_accounting(): void
    {
        $staff = $this->staff(Role::Owner);
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => $staff->session_version, 'identity.mfa_staff' => $staff->id]);
        $this->viewer();
        $this->legacy(false, true)->assertOk();
        $privacy = app(TrackingContext::class)->privacy();
        $events = [];
        foreach (['meta', 'tiktok'] as $platform) {
            [$pixel, $link] = $this->assets($platform);
            $source = $this->source($pixel, $link);
            DB::table('acquisition_sources')->where('id', $source->source_id)->update(['privacy_id' => $privacy->id]);
            $events[] = $this->event($source);
        }
        $analytics = DB::table('acquisition_privacy')->value('analytics');
        $this->send($this->body('advertising'))->assertOk();
        $this->assertSame($analytics, DB::table('acquisition_privacy')->value('analytics'));
        config(['h5.measurement_c_registration' => false, 'h5.measurement_c_experience' => false, 'acquisition.transport' => 'local_simulation']);
        $this->legacy(false, true)->assertJsonPath('consent.advertising', 'denied');
        // This fixture covers the independent ad receiver, not a synthetic old BillingAnalyticsReader.
        config(['acquisition.analytics_enabled' => false]);
        foreach ($events as $event) {
            app(PaymentReceiver::class)->handle($event);
            app(PaymentReceiver::class)->handle($event);
        }
        app(DeliveryWorker::class)->run();
        $this->assertDatabaseCount('acquisition_receipts', 2);
        $this->assertSame(2, DB::table('acquisition_deliveries')->where('state', 'blocked')->where('attempts', 0)->count());
        foreach ($events as $event) {
            $this->assertEquals($event->attribution_snapshot->toArray(), json_decode(DB::table('acquisition_receipts')->where('payment_id', $event->payment_id)->value('attribution_snapshot'), true));
        }
        Http::assertNothingSent();
    }

    public function test_new_choice_only_allow_does_not_clear_prior_denial_even_without_old_proof(): void
    {
        $this->viewer();
        $this->send($this->body('advertising'))->assertOk();
        $until = DB::table('acquisition_r1_choices')->value('legacy_denial_until');
        $this->send($this->body('advertising', 'allow', 1))->assertOk()->assertJsonPath('state.choice', 'allow');
        config(['h5.measurement_c_registration' => false, 'h5.measurement_c_experience' => false]);
        $this->legacy(true, true)->assertJsonPath('consent.advertising', 'denied');
        $this->assertSame($until, DB::table('acquisition_privacy')->value('r1_advertising_denial_until'));
    }

    public function test_old_denial_advances_corresponding_r1_version_and_blocks_stale_cas_even_when_r1_off(): void
    {
        $this->viewer();
        $this->send($this->body('advertising', 'allow'))->assertOk();
        config(['h5.measurement_c_registration' => false]);
        $this->legacy(false, false)->assertOk();
        $this->assertDatabaseHas('acquisition_r1_choices', ['purpose' => 'advertising', 'choice' => 'deny', 'revision' => 2, 'notice_version' => 'analytics-v1']);
        $this->send($this->body('advertising', 'deny', 1))->assertStatus(409)->assertJsonPath('state.revision', 2);
        $this->legacy(false, true)->assertJsonPath('consent.advertising', 'denied');
    }

    public function test_atomic_ad_denial_failure_rolls_back_both_namespaces_and_does_not_issue_proof(): void
    {
        $this->legacy(false, true)->assertOk();
        $before = DB::table('acquisition_privacy')->get()->toJson();
        DB::statement('ALTER TABLE acquisition_sources RENAME TO acquisition_sources_r1_fault');
        $this->send($this->body('advertising'))->assertStatus(503)->assertJsonPath('error.code', 'PURPOSE_UNAVAILABLE')->assertCookieMissing(app(PurposeProof::class)->cookie());
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_sources_r1_fault RENAME TO acquisition_sources');
        $this->assertSame($before, DB::table('acquisition_privacy')->get()->toJson());
        $this->assertDatabaseCount('acquisition_r1_proofs', 0);
        $this->assertDatabaseCount('acquisition_r1_operations', 0);
    }

    public function test_storage_failure_never_rolls_back_a_real_necessary_created_account(): void
    {
        $v = app(CreatesNewUsers::class)->create(['name' => 'R1 isolated', 'email' => Str::uuid().'@example.test',
            'password' => 'SyntheticPass1234', 'password_confirmation' => 'SyntheticPass1234']);
        DB::statement('ALTER TABLE acquisition_r1_operations RENAME TO acquisition_r1_operations_fault');
        $this->send($this->body())->assertStatus(503);
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_r1_operations_fault RENAME TO acquisition_r1_operations');
        $this->assertDatabaseHas('identity_viewers', ['id' => $v->id]);
        $this->assertDatabaseCount('acquisition_r1_choices', 0);
        $this->assertDatabaseCount('acquisition_r1_proofs', 0);
    }

    public function test_partial_failure_preserves_other_purpose_and_necessary_account(): void
    {
        $viewer = $this->viewer();
        $this->legacy(false, true)->assertOk();
        $this->send($this->body(choice: 'allow'))->assertOk();
        DB::statement('ALTER TABLE acquisition_sources RENAME TO acquisition_sources_r1_partial');
        $this->send($this->body('advertising'))->assertStatus(503);
        DB::statement('ALTER TABLE acquisition_sources_r1_partial RENAME TO acquisition_sources');
        $this->getJson(self::URL.'/context')->assertJsonPath('purposes.account_link.choice', 'allow')
            ->assertJsonPath('purposes.advertising.choice', 'unknown')->assertJsonPath('legacy_advertising.choice', 'allow');
        $this->assertDatabaseHas('identity_viewers', ['id' => $viewer->id]);
        $this->assertDatabaseCount('acquisition_r1_operations', 1);
    }

    public function test_provider_rejects_r1_synthetic_policy_outside_local_testing(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->expectException(\LogicException::class);
            (new AcquisitionServiceProvider($this->app))->boot();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_input_size_unknown_fields_and_cross_origin_are_rejected_without_writes(): void
    {
        $this->send($this->body() + ['padding' => str_repeat('a', 4100)])->assertStatus(413);
        $this->send($this->body() + ['policy' => 'eligible'])->assertUnprocessable();
        $bad = $this->body();
        $bad['expected_revision'] = '0';
        $this->send($bad)->assertUnprocessable();
        $this->withHeader('Origin', 'https://attacker.invalid')->send($this->body())->assertForbidden();
        $this->assertDatabaseCount('acquisition_r1_proofs', 0);
    }

    public function test_operation_24_hours_and_necessary_proof_24_months_have_bounded_cleanup(): void
    {
        $this->send($this->body())->assertOk();
        $proof = DB::table('acquisition_r1_proofs')->first();
        $this->travel(24)->hours();
        $this->artisan('acquisition:r1-prune')->assertExitCode(0);
        $this->assertDatabaseCount('acquisition_r1_operations', 0);
        $this->assertDatabaseCount('acquisition_r1_proofs', 1);
        $this->travelTo(CarbonImmutable::parse($proof->expires_at));
        $this->artisan('acquisition:r1-prune')->assertExitCode(0);
        $this->assertDatabaseCount('acquisition_r1_proofs', 0);
        $this->assertDatabaseCount('acquisition_r1_choices', 0);
    }

    public function test_context_pressure_does_not_consume_preference_or_v2_denial_budget(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson(self::URL.'/context')->assertOk();
        }
        $this->getJson(self::URL.'/context')->assertStatus(429);
        for ($i = 0; $i < 30; $i++) {
            $this->send($this->body(revision: $i === 0 ? 0 : 1))->assertOk();
        }
        $this->send($this->body(revision: 1))->assertStatus(429)->assertHeader('Retry-After');
        $this->patchJson('/api/v2/measurement/preferences', ['site_session' => 'deny', 'notice_version' => CorePolicy::NOTICE, 'expected_revision' => 0])->assertOk();
        $this->assertDatabaseCount('acquisition_r1_operations', 30);
    }
}
