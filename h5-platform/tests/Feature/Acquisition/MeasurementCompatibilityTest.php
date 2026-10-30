<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\BillingAnalyticsReader;
use App\Contracts\IdentityContext;
use App\Data\ViewerIdentity;
use App\Enums\BehaviorRecordingResult as Result;
use App\Enums\Role;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Measurement\CoreContext;
use App\Modules\Acquisition\Measurement\CorePolicy;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Acquisition\Services\PaymentReceiver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;

final class MeasurementCompatibilityTest extends AcquisitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['h5.measurement_c_experience' => false, 'acquisition.analytics_enabled' => true,
            'acquisition.measurement.enabled' => true, 'acquisition.measurement.synthetic_policy' => true,
            'acquisition.measurement.synthetic_until' => now()->addDay()->toIso8601String()]);
    }

    private function consent(bool $analysis = true, bool $ads = true)
    {
        return $this->postJson('/api/v1/tracking/consent', ['analytics' => $analysis, 'advertising' => $ads, 'policy_version' => 'analytics-v1']);
    }

    private function start()
    {
        return $this->postJson('/api/v2/measurement/start', ['notice_version' => CorePolicy::NOTICE, 'page_instance_id' => (string) Str::uuid()]);
    }

    private function deny(int $revision = 0)
    {
        return $this->patchJson('/api/v2/measurement/preferences', ['site_session' => 'deny', 'expected_revision' => $revision, 'notice_version' => CorePolicy::NOTICE]);
    }

    private function oldEvent()
    {
        return $this->postJson('/api/v1/tracking/events', ['events' => [['event_id' => (string) Str::uuid(), 'event_name' => 'page_view',
            'properties' => ['page_instance_id' => (string) Str::uuid(), 'path' => '/']]]]);
    }

    private function assertNoOldGraph(): void
    {
        foreach (['subjects', 'behaviors', 'analytics_orders', 'event_keys', 'play_states'] as $table) {
            $this->assertDatabaseCount('acquisition_'.$table, 0);
        }
    }

    public function test_normal_mode_keeps_legacy_behavior_and_passive_mode_switch_does_not_revoke_consent(): void
    {
        $this->consent()->assertJsonPath('consent.analytics', 'granted');
        $this->oldEvent()->assertJsonPath('accepted', 1);
        $old = DB::table('acquisition_privacy')->get()->toJson();
        config(['h5.measurement_c_experience' => true]);
        $this->oldEvent()->assertOk()->assertJsonPath('accepted', 0);
        $this->assertSame(Result::Skipped, app(BehaviorRecorder::class)->recordFact('login_failed', [], 'c-mode'));
        $this->assertSame($old, DB::table('acquisition_privacy')->get()->toJson());
        config(['h5.measurement_c_experience' => false]);
        $this->oldEvent()->assertJsonPath('accepted', 1);
        $this->assertDatabaseCount('acquisition_behaviors', 2);
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    public function test_new_c_mode_never_grants_legacy_analysis_or_binds_a_trusted_viewer(): void
    {
        config(['h5.measurement_c_experience' => true]);
        $identity = Mockery::mock(IdentityContext::class);
        $identity->shouldReceive('viewer')->andReturn(new ViewerIdentity((string) Str::uuid(), 'synthetic@example.test', true));
        $this->app->instance(IdentityContext::class, $identity);
        $this->consent(true, true)->assertOk()->assertJsonPath('consent.analytics', 'unknown')->assertJsonPath('consent.advertising', 'granted');
        $this->getJson('/api/v1/tracking/context')->assertOk();
        $this->assertNull(DB::table('acquisition_privacy')->value('viewer_id'));
        $this->assertNull(DB::table('acquisition_privacy')->value('analytics_granted_at'));
        $this->assertNull(app(TrackingContext::class)->subject());
        $this->assertSame(Result::Skipped, app(BehaviorRecorder::class)->recordFact('checkout_created', ['order_id' => (string) Str::uuid()], 'checkout'));
        $this->assertNoOldGraph();
    }

    public function test_c_active_and_stale_old_grant_capture_once_only_in_c_pipeline(): void
    {
        $this->consent()->assertOk();
        config(['h5.measurement_c_experience' => true]);
        $this->start()->assertJsonPath('site_effective', 'on');
        $this->postJson('/api/v2/measurement/events', ['events' => [['event_id' => (string) Str::uuid(), 'event_name' => 'page_view',
            'properties' => ['page_instance_id' => (string) Str::uuid(), 'page_type' => 'catalog']]]])->assertJsonPath('accepted', 1);
        $this->oldEvent()->assertJsonPath('accepted', 0);
        $this->assertSame(Result::Skipped, app(BehaviorRecorder::class)->recordFact('login_failed', [], 'same-activity'));
        $this->assertDatabaseCount('acquisition_c_events', 1);
        $this->assertNoOldGraph();
        Http::assertNothingSent();
    }

    public function test_c_off_expired_unknown_or_storage_failure_never_falls_back_or_returns_retry(): void
    {
        $this->consent()->assertOk();
        config(['h5.measurement_c_experience' => true]);
        foreach ([['acquisition.measurement.enabled' => false], ['acquisition.measurement.enabled' => true, 'acquisition.measurement.synthetic_until' => now()->toIso8601String()],
            ['acquisition.measurement.synthetic_policy' => false], ['acquisition.analytics_enabled' => false]] as $state) {
            config($state);
            $this->oldEvent()->assertOk()->assertJsonPath('accepted', 0);
            $this->assertSame(Result::Skipped, app(BehaviorRecorder::class)->recordFact('checkout_created', ['order_id' => (string) Str::uuid()], 'off'));
        }
        $this->withSession(['measurement_c.preference' => (string) Str::uuid()]);
        DB::statement('ALTER TABLE acquisition_c_preferences RENAME TO acquisition_c_preferences_fault');
        $this->getJson('/api/v2/measurement/context')->assertStatus(503)->assertJsonPath('reason', 'MEASUREMENT_UNAVAILABLE');
        $this->assertSame(Result::Skipped, app(BehaviorRecorder::class)->recordFact('payment_confirmed', ['payment_id' => (string) Str::uuid()], 'unavailable'));
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_c_preferences_fault RENAME TO acquisition_c_preferences');
        $this->assertNoOldGraph();
    }

    public function test_c_deny_blocks_refresh_mode_fallback_and_old_positive_api_without_changing_ads(): void
    {
        $this->consent()->assertOk();
        $this->oldEvent()->assertJsonPath('accepted', 1);
        $old = DB::table('acquisition_privacy')->first();
        config(['h5.measurement_c_experience' => true]);
        $this->start()->assertOk();
        $this->deny()->assertJsonPath('site_choice', 'deny');
        $proof = DB::table('acquisition_privacy')->first();
        $this->assertSame('denied', $proof->analytics);
        $this->assertNull($proof->analytics_granted_at);
        $this->assertSame($old->advertising, $proof->advertising);
        $this->assertSame($old->viewer_id, $proof->viewer_id);
        $this->assertSame($old->active_source, $proof->active_source);
        $this->assertNotNull($proof->site_analysis_denied_at);
        config(['h5.measurement_c_experience' => false]);
        $this->getJson('/api/v2/measurement/context')->assertJsonPath('reason', 'SITE_OPTOUT');
        $this->consent()->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'granted');
        $this->oldEvent()->assertJsonPath('accepted', 0);
        $this->assertSame(Result::Skipped, app(BehaviorRecorder::class)->recordFact('login_failed', [], 'fallback'));
        $this->assertDatabaseCount('acquisition_behaviors', 1);
        $this->assertDatabaseCount('acquisition_subjects', 1);
        $this->assertSame($proof->site_analysis_denial_until, DB::table('acquisition_privacy')->value('site_analysis_denial_until'));
    }

    public function test_bare_c_denial_blocks_new_legacy_api_proof_and_unknown_c_proof_fails_closed(): void
    {
        $this->deny()->assertOk();
        $this->assertDatabaseCount('acquisition_privacy', 0);
        $this->consent()->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'granted');
        $this->assertNotNull(DB::table('acquisition_privacy')->value('site_analysis_denied_at'));
        DB::statement('ALTER TABLE acquisition_c_preferences RENAME TO acquisition_c_preferences_fault');
        $this->oldEvent()->assertJsonPath('accepted', 0);
        $this->assertSame(Result::Skipped, app(BehaviorRecorder::class)->recordFact('login_failed', [], 'storage-fault'));
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_c_preferences_fault RENAME TO acquisition_c_preferences');
        $this->assertNoOldGraph();
    }

    public function test_later_ad_only_proofs_inherit_original_c_refusal_dates_without_extending_retention(): void
    {
        $this->deny()->assertOk();
        $preference = DB::table('acquisition_c_preferences')->first();
        foreach ([120, 180] as $days) {
            $this->travel($days)->days();
            $this->consent(true, true)->assertOk()->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'granted');
            $proof = DB::table('acquisition_privacy')->first();
            $this->assertSame($preference->updated_at, $proof->site_analysis_denied_at);
            $this->assertSame($preference->expires_at, $proof->site_analysis_denial_until);
            DB::table('acquisition_privacy')->where('id', $proof->id)->update(['site_analysis_denial_until' => now()->addDays(30)]);
            $earlier = DB::table('acquisition_privacy')->value('site_analysis_denial_until');
            $this->consent(true, true)->assertOk();
            $this->assertSame($earlier, DB::table('acquisition_privacy')->value('site_analysis_denial_until'));
            // A different later ad proof is still bounded by the original refusal; no cross-purpose extension.
            DB::table('acquisition_privacy')->delete();
            session()->forget('tracking');
            request()->cookies->remove(app(TrackingContext::class)->cookieName());
        }
        $this->assertDatabaseCount('acquisition_c_preferences', 1);
        $this->assertNoOldGraph();
    }

    public function test_c_refusal_keeps_both_platforms_independently_consented_local_simulation_path(): void
    {
        $this->signIn($this->staff(Role::Owner));
        $this->consent()->assertOk();
        $privacy = app(TrackingContext::class)->privacy();
        $deliveries = [];
        foreach (['meta', 'tiktok'] as $platform) {
            [$pixel, $link] = $this->assets($platform);
            $source = $this->source($pixel, $link);
            DB::table('acquisition_sources')->where('id', $source->source_id)->update(['privacy_id' => $privacy->id]);
            $deliveries[] = $this->event($source);
        }
        $sourceBefore = DB::table('acquisition_sources')->orderBy('id')->get()->toJson();
        $this->deny()->assertOk();
        config(['h5.measurement_c_experience' => true, 'acquisition.transport' => 'local_simulation']);
        foreach ($deliveries as $event) {
            app(PaymentReceiver::class)->handle($event);
            app(PaymentReceiver::class)->handle($event);
        }
        $this->assertSame(2, app(DeliveryWorker::class)->run());
        $this->assertSame(2, DB::table('acquisition_deliveries')->where('state', 'simulated')->count());
        $this->assertDatabaseCount('acquisition_receipts', 2);
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->assertDatabaseCount('acquisition_analytics_orders', 0);
        $this->assertSame($sourceBefore, DB::table('acquisition_sources')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('acquisition_privacy', ['advertising' => 'granted', 'analytics' => 'denied']);
        Http::assertNothingSent();
    }

    public function test_old_denial_cannot_be_upgraded_by_c_allow_or_old_api_in_c_mode(): void
    {
        $this->consent(false, true)->assertOk();
        config(['h5.measurement_c_experience' => true]);
        $this->patchJson('/api/v2/measurement/preferences', ['site_session' => 'allow', 'expected_revision' => 0, 'notice_version' => CorePolicy::NOTICE])
            ->assertJsonPath('site_effective', 'off')->assertJsonPath('reason', 'LEGACY_DENIED');
        $this->consent()->assertJsonPath('consent.analytics', 'denied');
        $this->start()->assertJsonPath('reason', 'LEGACY_DENIED');
        $this->assertNoOldGraph();
        $this->assertDatabaseCount('acquisition_c_subjects', 0);
    }

    public function test_old_session_only_rejection_is_not_silently_upgraded_by_c_mode_ad_consent(): void
    {
        $this->consent(false, false)->assertOk();
        $this->assertDatabaseCount('acquisition_privacy', 0);
        config(['h5.measurement_c_experience' => true]);
        $this->consent(true, true)->assertJsonPath('consent.analytics', 'denied')->assertJsonPath('consent.advertising', 'granted');
        $this->start()->assertJsonPath('reason', 'LEGACY_DENIED');
        $this->assertNoOldGraph();
        $this->assertDatabaseCount('acquisition_c_subjects', 0);
    }

    public function test_denial_bridge_failure_rolls_back_c_revision_and_old_proof_without_poisoning_outer_transaction(): void
    {
        $this->consent()->assertOk();
        $this->start()->assertOk();
        $before = DB::table('acquisition_privacy')->get()->toJson();
        DB::statement('ALTER TABLE acquisition_privacy RENAME COLUMN site_analysis_denied_at TO temporary_denial_fault');
        $this->deny()->assertStatus(503)->assertJsonPath('reason', 'MEASUREMENT_UNAVAILABLE')->assertJsonPath('site_effective', 'off');
        $this->assertDatabaseHas('acquisition_c_preferences', ['choice' => 'unset', 'revision' => 0]);
        $this->assertNull(DB::table('acquisition_c_subjects')->value('ended_at'));
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_privacy RENAME COLUMN temporary_denial_fault TO site_analysis_denied_at');
        $this->assertSame($before, DB::table('acquisition_privacy')->get()->toJson());
        $this->deny()->assertOk()->assertJsonPath('revision', 1);
    }

    public function test_stale_revision_does_not_apply_bridge_and_all_optional_denial_needs_both_apis(): void
    {
        $this->consent()->assertOk();
        $this->start()->assertOk();
        $this->deny(8)->assertStatus(409);
        $this->assertDatabaseHas('acquisition_privacy', ['analytics' => 'granted', 'advertising' => 'granted', 'site_analysis_denied_at' => null]);
        $this->deny()->assertOk();
        $this->assertDatabaseHas('acquisition_privacy', ['analytics' => 'denied', 'advertising' => 'granted']);
        $this->consent(false, false)->assertOk()->assertJsonPath('consent.advertising', 'denied');
        $this->assertDatabaseHas('acquisition_c_preferences', ['choice' => 'deny']);
    }

    public function test_c_only_success_does_not_claim_advertising_withdrawal_when_legacy_save_fails(): void
    {
        $this->consent()->assertOk();
        $this->deny()->assertOk()->assertJsonPath('site_choice', 'deny');
        DB::statement('ALTER TABLE acquisition_sources RENAME TO acquisition_sources_fault');
        $this->consent(false, false)->assertStatus(503)->assertJsonPath('error', 'TRACKING_UNAVAILABLE');
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_sources_fault RENAME TO acquisition_sources');
        $this->getJson('/api/v1/tracking/context')->assertJsonPath('consent.advertising', 'granted');
        $this->getJson('/api/v2/measurement/context')->assertJsonPath('site_choice', 'deny');
        $this->consent(false, false)->assertOk()->assertJsonPath('consent.advertising', 'denied');
    }

    public function test_authenticated_denial_uses_existing_trusted_proofs_only_and_keeps_other_viewers(): void
    {
        $this->consent()->assertOk();
        $proof = DB::table('acquisition_privacy')->first();
        $viewer = (string) Str::uuid();
        $other = (string) Str::uuid();
        foreach ([$viewer, $other] as $id) {
            $row = (array) $proof;
            $row['id'] = (string) Str::uuid();
            $row['viewer_id'] = $id;
            DB::table('acquisition_privacy')->insert($row);
        }
        $identity = Mockery::mock(IdentityContext::class);
        $identity->shouldReceive('viewer')->andReturn(new ViewerIdentity($viewer, 'synthetic@example.test', true));
        $this->app->instance(IdentityContext::class, $identity);
        $this->deny()->assertOk();
        $this->assertSame('denied', DB::table('acquisition_privacy')->where('viewer_id', $viewer)->value('analytics'));
        $this->assertSame('granted', DB::table('acquisition_privacy')->where('viewer_id', $other)->value('analytics'));
        $this->assertSame(1, DB::table('acquisition_privacy')->whereNull('viewer_id')->count());
        $this->assertDatabaseCount('acquisition_c_subjects', 0);
        $this->assertNoOldGraph();
    }

    public function test_late_background_payment_skips_before_creating_mapping_but_ad_receipt_and_snapshot_survive(): void
    {
        $this->signIn($this->staff(Role::Owner));
        $this->consent()->assertOk();
        $subject = app(TrackingContext::class)->subject();
        [$pixel, $link] = $this->assets();
        $source = $this->source($pixel, $link);
        $viewer = (string) Str::uuid();
        DB::table('acquisition_subjects')->where('id', $subject->id)->update(['viewer_id' => $viewer]);
        DB::table('acquisition_sources')->where('id', $source->source_id)->update(['subject_id' => $subject->id, 'privacy_id' => $subject->privacy_id]);
        DB::table('acquisition_privacy')->where('id', $subject->privacy_id)->update(['active_source' => $source->source_id]);
        $event = $this->event($source);
        $reader = Mockery::mock(BillingAnalyticsReader::class);
        $reader->shouldReceive('payments')->andReturn([$event->payment_id => ['viewer_id' => $viewer, 'order_id' => $event->order_id,
            'subscription_id' => $event->subscription_id, 'transaction_kind' => $event->transaction_kind->value, 'amount_minor' => 300,
            'currency' => 'USD', 'is_test' => true, 'paid_at' => now()->toIso8601String(), 'attribution_snapshot' => $source->toArray()]]);
        $this->app->instance(BillingAnalyticsReader::class, $reader);
        $before = DB::table('acquisition_sources')->get()->toJson();
        $this->deny()->assertOk();
        // Worker has no C/browser context. Only the immutable old source and proof are available.
        session()->forget(['measurement_c', 'tracking']);
        request()->cookies->remove(app(CoreContext::class)->cookie('preference'));
        config(['h5.measurement_c_experience' => false]);
        $r = app(BehaviorRecorder::class);
        $this->assertSame(Result::Skipped, $r->recordFact('payment_confirmed', ['payment_id' => $event->payment_id], 'late'));
        $this->assertDatabaseCount('acquisition_analytics_orders', 0);
        DB::table('acquisition_analytics_orders')->insert(['order_id' => $event->order_id, 'subject_id' => $subject->id,
            'privacy_id' => $subject->privacy_id, 'viewer_id' => $viewer, 'source_id' => $source->source_id,
            'subscription_id' => (string) Str::uuid(), 'created_at' => now()]);
        $mapping = DB::table('acquisition_analytics_orders')->get()->toJson();
        $this->assertSame(Result::Skipped, $r->recordFact('payment_confirmed', ['payment_id' => $event->payment_id], 'late-existing'));
        $this->assertSame($mapping, DB::table('acquisition_analytics_orders')->get()->toJson());
        app(PaymentReceiver::class)->handle($event);
        app(PaymentReceiver::class)->handle($event);
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertDatabaseCount('acquisition_deliveries', 1);
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->assertSame($before, DB::table('acquisition_sources')->get()->toJson());
        $this->assertEquals($source->toArray(), json_decode(DB::table('acquisition_receipts')->value('attribution_snapshot'), true));
        $this->assertDatabaseHas('acquisition_privacy', ['advertising' => 'granted', 'active_source' => $source->source_id]);
        Http::assertNothingSent();
    }
}
