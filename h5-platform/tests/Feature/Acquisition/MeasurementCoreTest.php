<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\CatalogReader;
use App\Data\Drama;
use App\Data\Episode;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Measurement\CoreContext;
use App\Modules\Acquisition\Measurement\CorePolicy;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;

final class MeasurementCoreTest extends AcquisitionTestCase
{
    private string $drama;

    private string $episode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['acquisition.measurement.enabled' => true, 'acquisition.measurement.synthetic_policy' => true,
            'acquisition.measurement.synthetic_until' => now()->addDays(60)->toIso8601String()]);
        $this->drama = (string) Str::uuid();
        $this->episode = (string) Str::uuid();
        $catalog = Mockery::mock(CatalogReader::class);
        $catalog->shouldReceive('drama')->andReturnUsing(fn ($id) => $id === $this->drama
            ? new Drama($id, 'SYNTHETIC ONLY', '', null, 1, 1, 1, 'published', [new Episode($this->episode, 1, 'SYNTHETIC ONLY', 60, 'free', 'ready')])
            : throw new ApiProblem('NOT_FOUND', 404, 'Synthetic missing'));
        $this->app->instance(CatalogReader::class, $catalog);
    }

    private function startCore(): TestResponse
    {
        return $this->postJson('/api/v2/measurement/start', ['notice_version' => CorePolicy::NOTICE, 'page_instance_id' => (string) Str::uuid()]);
    }

    private function preference(string $choice, int $revision = 0): TestResponse
    {
        return $this->patchJson('/api/v2/measurement/preferences', ['site_session' => $choice, 'notice_version' => CorePolicy::NOTICE, 'expected_revision' => $revision]);
    }

    private function cEvent(string $name = 'page_view', array $properties = []): array
    {
        return ['event_id' => (string) Str::uuid(), 'event_name' => $name,
            'properties' => $properties + ['page_instance_id' => (string) Str::uuid(), 'page_type' => 'catalog']];
    }

    private function send(array $event): TestResponse
    {
        return $this->postJson('/api/v2/measurement/events', ['events' => [$event]]);
    }

    private function assertNoSubjects(): void
    {
        foreach (['subjects', 'sessions', 'events', 'plays', 'event_keys'] as $table) {
            $this->assertDatabaseCount('acquisition_c_'.$table, 0);
        }
    }

    public function test_context_matches_frozen_disabled_and_before_start_without_measurement_writes(): void
    {
        foreach ([false => 'disabled', true => 'before-start'] as $enabled => $example) {
            config(['acquisition.measurement.enabled' => (bool) $enabled]);
            $response = $this->getJson('/api/v2/measurement/context')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $expected = json_decode(file_get_contents(base_path('../docs/contracts/examples/measurement-c-'.$example.'.json')), true);
            $response->assertExactJson($expected);
            foreach (['preference', 'subject', 'session'] as $kind) {
                $response->assertCookieMissing(app(CoreContext::class)->cookie($kind));
            }
        }
        $this->assertNoSubjects();
        $this->assertDatabaseCount('acquisition_c_preferences', 0);
    }

    public function test_secure_request_sets_secure_http_only_measurement_cookies(): void
    {
        $response = $this->postJson('https://localhost/api/v2/measurement/start', [
            'notice_version' => CorePolicy::NOTICE, 'page_instance_id' => (string) Str::uuid(),
        ])->assertOk();
        $cookies = collect($response->headers->getCookies())->filter(fn ($c) => str_contains($c->getName(), '_c_'));
        $this->assertCount(3, $cookies);
        foreach ($cookies as $cookie) {
            $this->assertTrue($cookie->isSecure());
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
        }
    }

    public function test_missing_module_never_saves_preferences_or_creates_measurement_data(): void
    {
        config(['h5.modules.acquisition.ready' => false]);
        $this->getJson('/api/v2/measurement/context')->assertJsonPath('enabled', false);
        $this->preference('deny')->assertStatus(503)->assertJsonPath('reason', 'MEASUREMENT_UNAVAILABLE');
        $this->assertDatabaseCount('acquisition_c_preferences', 0);
        $this->assertNoSubjects();
    }

    public function test_synthetic_profile_is_explicit_expiring_and_not_derived_from_country_or_headers(): void
    {
        config(['acquisition.measurement.synthetic_policy' => false, 'h5.local_us_test_enabled' => true]);
        $this->withHeaders(['X-Country' => 'US', 'CF-IPCountry' => 'US', 'X-State' => 'CA'])->getJson('/api/v2/measurement/context')
            ->assertJsonPath('eligibility', 'unknown')->assertJsonPath('reason', 'POLICY_UNRESOLVED');
        $this->startCore()->assertOk()->assertJsonPath('site_effective', 'off');
        config(['acquisition.measurement.synthetic_policy' => true, 'acquisition.measurement.synthetic_until' => now()->toIso8601String()]);
        $this->startCore()->assertJsonPath('eligibility', 'unknown');
        config(['acquisition.measurement.synthetic_until' => 'not a date']);
        $this->startCore()->assertJsonPath('eligibility', 'unknown');
        $this->assertNoSubjects();
    }

    public function test_notice_version_and_start_shape_are_strict_and_start_does_not_grant_legacy_consent(): void
    {
        $this->postJson('/api/v2/measurement/start', ['notice_version' => 'unreviewed', 'page_instance_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/v2/measurement/start', ['notice_version' => CorePolicy::NOTICE, 'page_instance_id' => (string) Str::uuid(), 'country' => 'US'])->assertUnprocessable();
        $this->startCore()->assertOk()->assertExactJson(json_decode(file_get_contents(base_path('../docs/contracts/examples/measurement-c-synthetic-active.json')), true));
        $this->startCore()->assertJsonPath('site_effective', 'on');
        $this->assertDatabaseCount('acquisition_c_subjects', 1);
        $this->assertDatabaseCount('acquisition_c_sessions', 1);
        $this->assertDatabaseCount('acquisition_privacy', 0);
        $this->assertNull(session('tracking.consent'));
        $this->assertDatabaseHas('acquisition_c_subjects', ['is_test' => true]);
    }

    public function test_deny_is_necessary_even_while_disabled_and_allow_requires_another_start(): void
    {
        config(['acquisition.measurement.enabled' => false]);
        $this->preference('deny')->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('site_choice', 'deny');
        $this->assertNoSubjects();
        config(['acquisition.measurement.enabled' => true]);
        $this->startCore()->assertJsonPath('reason', 'SITE_OPTOUT');
        $this->preference('allow', 1)->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('reason', 'NOTICE_NOT_READY');
        $this->assertNoSubjects();
        $this->startCore()->assertJsonPath('site_effective', 'on');
    }

    public function test_revision_conflict_cannot_overwrite_newer_deny(): void
    {
        $this->startCore()->assertOk();
        $this->preference('deny')->assertExactJson(json_decode(file_get_contents(base_path('../docs/contracts/examples/measurement-c-denied.json')), true));
        $this->preference('allow')->assertStatus(409)->assertJsonPath('site_choice', 'deny')->assertJsonPath('revision', 1);
        $this->send($this->cEvent())->assertJsonPath('accepted', 0);
        $this->assertDatabaseCount('acquisition_c_events', 0);
        $this->assertNotNull(DB::table('acquisition_c_subjects')->value('ended_at'));
    }

    public function test_legacy_denial_blocks_c_and_c_denial_bridges_only_site_analysis(): void
    {
        config(['acquisition.analytics_enabled' => true]);
        $this->postJson('/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->startCore()->assertJsonPath('reason', 'LEGACY_DENIED');
        $this->preference('allow')->assertJsonPath('reason', 'LEGACY_DENIED');
        $this->assertNoSubjects();
        $this->assertSame('denied', session('tracking.consent.analytics'));
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertOk();
        $before = DB::table('acquisition_privacy')->first();
        $this->startCore()->assertJsonPath('site_effective', 'on');
        $this->withHeader('Sec-GPC', '1')->getJson('/api/v2/measurement/context')->assertJsonPath('advertising.choice', 'granted')
            ->assertJsonPath('advertising.effective', false)->assertJsonPath('advertising.opt_out_signal', true)->assertJsonPath('site_effective', 'on');
        $this->preference('deny', 1)->assertOk();
        $after = DB::table('acquisition_privacy')->first();
        $this->assertSame('denied', $after->analytics);
        $this->assertSame($before->advertising, $after->advertising);
        $this->assertNotNull($after->site_analysis_denied_at);
        $this->assertDatabaseCount('acquisition_sources', 0);
    }

    public function test_unsupported_purposes_never_save_and_context_exposes_no_internal_identifiers(): void
    {
        foreach (['account_link', 'conversion_link', 'advertising', 'utm_source'] as $field) {
            $this->patchJson('/api/v2/measurement/preferences', ['site_session' => 'allow', 'notice_version' => CorePolicy::NOTICE, 'expected_revision' => 0, $field => true])->assertUnprocessable();
        }
        $this->assertDatabaseCount('acquisition_c_preferences', 0);
        $this->startCore()->assertOk();
        $body = $this->getJson('/api/v2/measurement/context')->assertJsonPath('account_link.reason', 'DEPENDENCY_NOT_READY')->json();
        $this->assertSame(['mode' => 'off', 'effective' => false], $body['conversion_link']);
        foreach (['subjects', 'sessions', 'preferences'] as $table) {
            $this->assertStringNotContainsString(DB::table('acquisition_c_'.$table)->value('id'), json_encode($body));
        }
    }

    public function test_forbidden_identity_url_and_financial_properties_or_success_events_are_rejected(): void
    {
        $this->startCore()->assertOk();
        foreach (['viewer_id', 'order_id', 'payment_id', 'source_id', 'country', 'ip', 'user_agent', 'path', 'url', 'query', 'referrer', 'utm_source', 'ttclid', 'email', 'amount_minor', 'stack'] as $field) {
            $this->send($this->cEvent('page_view', [$field => 'PRIVATE_SENTINEL']))->assertUnprocessable();
        }
        foreach (['registration_succeeded', 'payment_confirmed', 'refund_confirmed', 'login_succeeded', 'landing_view'] as $name) {
            $this->send($this->cEvent($name))->assertUnprocessable();
        }
        $this->assertDatabaseCount('acquisition_c_events', 0);
        $this->assertDatabaseCount('acquisition_c_event_keys', 0);
    }

    public function test_event_replays_changed_payload_and_cross_subject_reuse_are_distinct(): void
    {
        $this->send($this->cEvent())->assertJsonPath('accepted', 0);
        $this->assertNoSubjects();
        $this->startCore()->assertOk();
        $e = $this->cEvent();
        $this->send($e)->assertJson(['accepted' => 1, 'rejected' => 0]);
        $this->send($e)->assertJsonPath('accepted', 1);
        $alias = $e;
        $alias['event_id'] = (string) Str::uuid();
        $this->send($alias)->assertJsonPath('accepted', 1);
        $alias['properties']['page_type'] = 'home';
        $this->send($alias)->assertJsonPath('rejected', 1);
        $e['properties']['page_type'] = 'home';
        $this->send($e)->assertJsonPath('rejected', 1);
        $this->assertDatabaseCount('acquisition_c_events', 1);
        $this->assertDatabaseCount('acquisition_c_event_keys', 2);
        app(CoreContext::class)->end();
        $this->startCore()->assertOk();
        $e['properties']['page_type'] = 'catalog';
        $this->send($e)->assertJsonPath('rejected', 1);
    }

    public function test_catalog_relation_and_unavailability_do_not_accept_fake_content(): void
    {
        $this->startCore()->assertOk();
        $this->send($this->cEvent('drama_view', ['drama_id' => (string) Str::uuid()]))->assertUnprocessable();
        $this->send($this->cEvent('drama_view', ['drama_id' => $this->drama, 'episode_id' => (string) Str::uuid()]))->assertUnprocessable();
        $this->send($this->cEvent('drama_view', ['drama_id' => $this->drama, 'episode_id' => $this->episode]))->assertJsonPath('accepted', 1);
        $catalog = Mockery::mock(CatalogReader::class);
        $catalog->shouldReceive('drama')->andThrow(new ApiProblem('DEPENDENCY_NOT_READY', 503, 'PRIVATE_SENTINEL'));
        $this->app->instance(CatalogReader::class, $catalog);
        $this->send($this->cEvent('drama_view', ['drama_id' => $this->drama]))->assertStatus(503)->assertDontSee('PRIVATE_SENTINEL');
        $this->assertDatabaseCount('acquisition_c_events', 1);
    }

    public function test_play_progress_limits_seek_and_uses_catalog_duration_for_completion(): void
    {
        $this->startCore()->assertOk();
        $play = ['drama_id' => $this->drama, 'episode_id' => $this->episode, 'play_instance_id' => (string) Str::uuid()];
        $this->send($this->cEvent('play_started', $play + ['position_seconds' => 0]))->assertUnprocessable();
        $this->send($this->cEvent('watch_progress', $play + ['watched_seconds' => 50]))->assertJsonPath('rejected', 1);
        $this->send($this->cEvent('play_started', $play + ['position_seconds' => 1]))->assertJsonPath('accepted', 1);
        $this->travel(10)->seconds();
        $this->send($this->cEvent('watch_progress', $play + ['position_seconds' => 59, 'watched_seconds' => 10]))->assertJsonPath('accepted', 1);
        $this->assertEquals(10, DB::table('acquisition_c_plays')->value('watched_seconds'));
        $this->send($this->cEvent('episode_completed', $play + ['duration_seconds' => 1, 'watched_seconds' => 60]))->assertJsonPath('rejected', 1);
        $this->travel(45)->seconds();
        $this->send($this->cEvent('episode_completed', $play + ['watched_seconds' => 55]))->assertJsonPath('accepted', 1);
        $row = json_decode(DB::table('acquisition_c_events')->where('event_name', 'watch_progress')->value('properties'), true);
        $this->assertSame(10, $row['watched_delta']);
    }

    public function test_expiry_is_absolute_context_does_not_keep_session_alive_and_no_late_cache_is_accepted(): void
    {
        $this->startCore()->assertOk();
        $subject = DB::table('acquisition_c_subjects')->first();
        $session = DB::table('acquisition_c_sessions')->first();
        $this->travel(30)->minutes();
        $this->getJson('/api/v2/measurement/context')->assertJsonPath('reason', 'NOTICE_NOT_READY');
        $this->assertSame($session->last_seen_at, DB::table('acquisition_c_sessions')->value('last_seen_at'));
        $this->startCore()->assertJsonPath('site_effective', 'on');
        $this->assertDatabaseCount('acquisition_c_subjects', 1);
        $this->assertDatabaseCount('acquisition_c_sessions', 2);
        $this->assertSame($subject->expires_at, DB::table('acquisition_c_subjects')->value('expires_at'));
        $this->travelTo(CarbonImmutable::parse($subject->expires_at));
        $this->send($this->cEvent())->assertJsonPath('accepted', 0);
        $this->startCore()->assertOk();
        $this->assertDatabaseCount('acquisition_c_subjects', 2);
    }

    public function test_session_absolute_cap_and_policy_loss_revalidate_each_event(): void
    {
        $this->startCore()->assertOk();
        $first = DB::table('acquisition_c_sessions')->first();
        for ($i = 0; $i < 47; $i++) {
            $this->travel(29)->minutes();
            $this->send($this->cEvent())->assertJsonPath('accepted', 1);
        }
        $this->travelTo(CarbonImmutable::parse($first->expires_at));
        $this->send($this->cEvent())->assertJsonPath('accepted', 0);
        $this->startCore()->assertOk();
        config(['acquisition.measurement.synthetic_policy' => false]);
        $this->send($this->cEvent())->assertJsonPath('accepted', 0);
        $this->getJson('/api/v2/measurement/context')->assertJsonPath('reason', 'POLICY_UNRESOLVED');
    }

    public function test_schema_size_origin_and_safe_errors(): void
    {
        $this->startCore()->assertOk();
        $this->postJson('/api/v2/measurement/events', ['events' => array_fill(0, 21, $this->cEvent())])->assertUnprocessable();
        $this->send($this->cEvent('page_view', ['url' => str_repeat('s', 33000)]))->assertStatus(413);
        $this->postJson('/api/v2/measurement/events?viewer_id=PRIVATE_SENTINEL', ['events' => [$this->cEvent()]])->assertUnprocessable()->assertDontSee('PRIVATE_SENTINEL');
        $this->withHeader('Origin', 'https://attacker.invalid')->send($this->cEvent())->assertForbidden();
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_storage_failure_is_retryable_without_partial_batch_and_legacy_still_operates(): void
    {
        $this->startCore()->assertOk();
        $e = $this->cEvent();
        DB::statement('ALTER TABLE acquisition_c_events RENAME TO acquisition_c_events_unavailable');
        $this->send($e)->assertStatus(503)->assertJsonPath('site_effective', 'off')->assertJsonPath('reason', 'MEASUREMENT_UNAVAILABLE');
        $this->assertDatabaseCount('acquisition_c_event_keys', 0);
        $this->getJson('/api/v1/tracking/context')->assertOk();
        DB::statement('ALTER TABLE acquisition_c_events_unavailable RENAME TO acquisition_c_events');
        $this->send($e)->assertJsonPath('accepted', 1);
        $this->assertDatabaseCount('acquisition_c_events', 1);
        Http::assertNothingSent();
    }

    public function test_late_batch_failure_rolls_back_prior_events_and_does_not_poison_other_writes(): void
    {
        $this->startCore()->assertOk();
        DB::unprepared("CREATE FUNCTION c_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_name = 'drama_view' THEN RAISE EXCEPTION 'synthetic measurement failure'; END IF; RETURN NEW; END $$");
        DB::unprepared('CREATE TRIGGER c_test_fail BEFORE INSERT ON acquisition_c_events FOR EACH ROW EXECUTE FUNCTION c_test_fail()');
        $events = [$this->cEvent(), $this->cEvent('drama_view', ['drama_id' => $this->drama])];
        $this->postJson('/api/v2/measurement/events', ['events' => $events])->assertStatus(503)->assertDontSee('synthetic measurement failure');
        $this->assertDatabaseCount('acquisition_c_events', 0);
        $this->assertDatabaseCount('acquisition_c_event_keys', 0);
        DB::table('cache')->insert(['key' => 'c-core-other-purpose-sentinel', 'value' => 'still-usable', 'expiration' => 2000000000]);
        $this->assertDatabaseHas('cache', ['key' => 'c-core-other-purpose-sentinel', 'value' => 'still-usable']);
        DB::unprepared('DROP TRIGGER c_test_fail ON acquisition_c_events');
        DB::unprepared('DROP FUNCTION c_test_fail()');
        $this->postJson('/api/v2/measurement/events', ['events' => $events])->assertJson(['accepted' => 2, 'rejected' => 0]);
    }

    public function test_logout_c_storage_failure_still_clears_browser_capabilities_and_old_logout_context(): void
    {
        $this->startCore()->assertOk();
        DB::statement('ALTER TABLE acquisition_c_preferences RENAME TO acquisition_c_preferences_unavailable');
        app(BehaviorRecorder::class)->clearBrowserContext();
        DB::statement('ALTER TABLE acquisition_c_preferences_unavailable RENAME TO acquisition_c_preferences');
        $this->assertNull(session('measurement_c.subject'));
        $this->assertNull(session('measurement_c.session'));
        $this->getJson('/api/v1/tracking/context')->assertOk();
        $this->getJson('/api/v2/measurement/context')->assertJsonPath('reason', 'NOTICE_NOT_READY');
    }

    public function test_preference_storage_failure_does_not_claim_saved_or_change_old_choices(): void
    {
        config(['acquisition.analytics_enabled' => true]);
        app(TrackingContext::class)->consent(true, false);
        $old = DB::table('acquisition_privacy')->get()->toJson();
        DB::statement('ALTER TABLE acquisition_c_preferences RENAME TO acquisition_c_preferences_unavailable');
        $this->preference('deny')->assertStatus(503)->assertJsonPath('reason', 'MEASUREMENT_UNAVAILABLE');
        DB::statement('ALTER TABLE acquisition_c_preferences_unavailable RENAME TO acquisition_c_preferences');
        $this->assertSame($old, DB::table('acquisition_privacy')->get()->toJson());
        $this->assertDatabaseCount('acquisition_c_preferences', 0);
    }

    public function test_logout_ends_c_but_keeps_denial_and_legacy_proofs(): void
    {
        $this->startCore()->assertOk();
        $this->preference('deny')->assertOk();
        app(BehaviorRecorder::class)->clearBrowserContext();
        $this->getJson('/api/v2/measurement/context')->assertJsonPath('site_choice', 'deny');
        $this->startCore()->assertJsonPath('reason', 'SITE_OPTOUT');
        $this->assertDatabaseCount('acquisition_c_subjects', 1);
    }

    public function test_retention_removes_c_data_at_boundaries_without_touching_v1(): void
    {
        config(['acquisition.analytics_enabled' => true]);
        app(TrackingContext::class)->consent(true, false);
        $this->startCore()->assertOk();
        $this->send($this->cEvent())->assertJsonPath('accepted', 1);
        $this->preference('deny')->assertOk();
        $old = DB::table('acquisition_privacy')->get()->toJson();
        $this->travel(30)->days();
        $this->artisan('acquisition:c-prune')->assertExitCode(0);
        $this->assertNoSubjects();
        $this->assertDatabaseCount('acquisition_c_preferences', 1);
        $this->assertSame($old, DB::table('acquisition_privacy')->get()->toJson());
        $this->travel(25)->months();
        $this->artisan('acquisition:c-prune')->assertExitCode(0);
        $this->assertDatabaseCount('acquisition_c_preferences', 0);
    }

    public function test_subject_metadata_has_a_hard_37_day_cap_and_contains_no_forbidden_columns(): void
    {
        $this->startCore()->assertOk();
        $this->travel(37)->days();
        $this->artisan('acquisition:c-prune')->assertExitCode(0);
        $this->assertNoSubjects();
        foreach (['subjects', 'sessions', 'events', 'preferences', 'event_keys', 'plays'] as $name) {
            $columns = Schema::getColumnListing('acquisition_c_'.$name);
            $this->assertEmpty(array_intersect($columns, ['viewer_id', 'order_id', 'payment_id', 'source_id', 'ip', 'user_agent', 'url', 'path', 'referrer', 'channel']));
        }
    }

    public function test_c_core_cannot_be_enabled_outside_local_testing_even_when_provider_is_registered_directly(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->assertFalse(app(CorePolicy::class)->resolve()['enabled']);
            try {
                app(CoreContext::class)->choose('deny', 0);
                $this->fail('Non-local C preference write accepted');
            } catch (\LogicException) {
                $this->assertDatabaseCount('acquisition_c_preferences', 0);
            }
            $this->expectException(\LogicException::class);
            (new AcquisitionServiceProvider($this->app))->boot();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
