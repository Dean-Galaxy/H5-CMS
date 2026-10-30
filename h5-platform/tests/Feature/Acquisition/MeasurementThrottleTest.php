<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Measurement\CorePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MeasurementThrottleTest extends AcquisitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['h5.measurement_c_experience' => true, 'acquisition.measurement.enabled' => true,
            'acquisition.measurement.synthetic_policy' => true, 'acquisition.measurement.synthetic_until' => now()->addDay()->toIso8601String()]);
        $session = app('session.store');
        $session->setName(config('h5.workspace').'_viewer');
        // Laravel's JSON test client omits all cookies unless credentials are explicit.
        $this->withCredentials()->withCookie($session->getName(), $session->getId());
    }

    private function start()
    {
        return $this->postJson('/api/v2/measurement/start', ['notice_version' => CorePolicy::NOTICE, 'page_instance_id' => (string) Str::uuid()]);
    }

    private function deny(int $revision)
    {
        return $this->patchJson('/api/v2/measurement/preferences', ['notice_version' => CorePolicy::NOTICE,
            'site_session' => 'deny', 'expected_revision' => $revision]);
    }

    private function revision(): int
    {
        return (int) DB::table('acquisition_c_preferences')->value('revision');
    }

    public function test_exhausting_context_start_and_events_still_allows_necessary_denial(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v2/measurement/context')->assertOk();
        }
        $this->getJson('/api/v2/measurement/context')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        for ($i = 0; $i < 60; $i++) {
            $this->start()->assertOk()->assertJsonPath('site_effective', 'on');
        }
        $this->start()->assertStatus(429);
        $event = ['event_id' => (string) Str::uuid(), 'event_name' => 'page_view',
            'properties' => ['page_instance_id' => (string) Str::uuid(), 'page_type' => 'catalog']];
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/api/v2/measurement/events', ['events' => [$event]])->assertOk()->assertJsonPath('accepted', 1);
        }
        $this->postJson('/api/v2/measurement/events', ['events' => [$event]])->assertStatus(429);
        $this->assertDatabaseCount('acquisition_c_events', 1);
        $this->deny(0)->assertOk()->assertJsonPath('site_choice', 'deny')->assertJsonPath('site_effective', 'off');
        $this->assertSame(1, $this->revision());
        $this->assertNotNull(DB::table('acquisition_c_subjects')->value('ended_at'));
        $this->assertDatabaseCount('acquisition_subjects', 0);
    }

    public function test_preference_budget_rejects_without_writing_or_being_reset_by_other_endpoints_and_headers(): void
    {
        for ($revision = 0; $revision < 30; $revision++) {
            $this->deny($revision)->assertOk();
        }
        $before = DB::table('acquisition_c_preferences')->get()->toJson();
        $this->deny(30)->assertStatus(429)->assertHeader('X-RateLimit-Remaining', '0')->assertHeader('Retry-After');
        $this->getJson('/api/v2/measurement/context')->assertOk()->assertJsonPath('site_choice', 'deny');
        $this->start()->assertOk()->assertJsonPath('site_effective', 'off');
        $this->withHeaders(['X-Viewer-Id' => (string) Str::uuid(), 'X-Forwarded-For' => '203.0.113.111',
            'X-Session-Id' => (string) Str::uuid()])->deny(30)->assertStatus(429);
        $this->assertSame($before, DB::table('acquisition_c_preferences')->get()->toJson());
        $this->travel(61)->seconds();
        $this->deny(30)->assertOk()->assertJsonPath('revision', 31);
    }

    public function test_genuine_sessions_have_own_preference_budget_but_share_bounded_ip_abuse_protection(): void
    {
        $session = app('session.store');
        $first = $session->getId();
        for ($browser = 0; $browser < 6; $browser++) {
            if ($browser > 0) {
                $session->invalidate();
                $this->assertNotSame($first, $session->getId());
                $this->withCookie(config('h5.workspace').'_viewer', $session->getId());
            }
            for ($revision = 0; $revision < 30; $revision++) {
                $this->deny($revision)->assertOk();
            }
            $this->deny(30)->assertStatus(429);
        }
        $this->assertDatabaseCount('acquisition_c_preferences', 6);
        $before = DB::table('acquisition_c_preferences')->get()->toJson();
        $session->invalidate();
        $this->withCookie(config('h5.workspace').'_viewer', $session->getId());
        $this->deny(0)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame($before, DB::table('acquisition_c_preferences')->get()->toJson());
        $this->getJson('/api/v2/measurement/context')->assertOk();
        $this->assertDatabaseCount('acquisition_c_subjects', 0);
    }

    public function test_v1_traffic_does_not_consume_c_preference_budget_and_query_identity_is_never_a_key(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/tracking/context')->assertOk();
        }
        $this->getJson('/api/v1/tracking/context')->assertStatus(429);
        $this->patchJson('/api/v2/measurement/preferences?viewer_id='.(string) Str::uuid(), [
            'site_session' => 'deny', 'expected_revision' => 0, 'notice_version' => CorePolicy::NOTICE])->assertUnprocessable();
        $this->assertDatabaseCount('acquisition_c_preferences', 0);
        $this->deny(0)->assertOk();
        $this->getJson('/api/v1/tracking/context')->assertStatus(429);
    }
}
