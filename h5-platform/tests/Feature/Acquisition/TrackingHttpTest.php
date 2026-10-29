<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\CatalogReader;
use App\Enums\Role;
use App\Modules\Acquisition\Analytics\AnalyticsReport;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrackingHttpTest extends AcquisitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['acquisition.analytics_enabled' => true]);
    }

    private function item(array $p = []): array
    {
        return ['event_id' => (string) Str::uuid(), 'event_name' => 'page_view', 'properties' => $p + ['page_instance_id' => (string) Str::uuid(), 'path' => '/']];
    }

    public function test_default_unknown_and_disabled_context_is_private_and_records_no_subject(): void
    {
        $this->getJson('/api/v1/tracking/context')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('consent.analytics', 'unknown');
        $this->postJson('/api/v1/tracking/events', ['events' => [$this->item()]])->assertOk()->assertJson(['accepted' => 0, 'rejected' => 1]);
        $this->assertDatabaseCount('acquisition_subjects', 0);
        config(['acquisition.analytics_enabled' => false]);
        $this->getJson('/api/v1/tracking/context')->assertOk()->assertJsonPath('enabled', false);
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertJsonPath('enabled', false);
        $this->assertDatabaseCount('acquisition_privacy', 0);
    }

    public function test_consent_http_batch_retries_and_unknown_fields_do_not_leak_identifiers(): void
    {
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk()->assertJsonPath('consent.analytics', 'granted')->assertJsonMissingPath('subject_id');
        $event = $this->item();
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertOk()->assertJson(['accepted' => 1, 'rejected' => 0]);
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertOk()->assertJson(['accepted' => 1, 'rejected' => 0]);
        $event['properties']['path'] = '/catalog';
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertOk()->assertJson(['accepted' => 0, 'rejected' => 1]);
        $this->postJson('/api/v1/tracking/events', ['events' => [$this->item(['amount_minor' => 999]), $this->item(['viewer_id' => (string) Str::uuid()])]])->assertOk()->assertJson(['accepted' => 0, 'rejected' => 2]);
        $this->assertDatabaseCount('acquisition_behaviors', 1);
        $this->assertDatabaseCount('acquisition_subjects', 1);
        $this->postJson('/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->postJson('/api/v1/tracking/events', ['events' => [$this->item()]])->assertOk()->assertJson(['accepted' => 0, 'rejected' => 1]);
        $this->assertDatabaseCount('acquisition_behaviors', 1);
    }

    public function test_payload_size_count_origin_and_boolean_strictness_are_enforced(): void
    {
        $this->postJson('/api/v1/tracking/events', ['events' => array_fill(0, 21, $this->item())])->assertUnprocessable();
        $this->postJson('/api/v1/tracking/events', ['events' => [$this->item(['stack' => str_repeat('x', 33000)])]])->assertStatus(413);
        $this->postJson('/api/v1/tracking/events', ['events' => [$this->item()], 'source_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/v1/tracking/consent', ['analytics' => 'true', 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertUnprocessable();
        $this->withHeader('Origin', 'https://attacker.invalid')->postJson('/api/v1/tracking/events', ['events' => [$this->item()]])->assertForbidden();
        $this->assertDatabaseCount('acquisition_subjects', 0);
    }

    public function test_transient_storage_503_allows_same_id_replay_after_rollback(): void
    {
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk();
        $event = $this->item();
        DB::statement('ALTER TABLE acquisition_behaviors RENAME TO acquisition_behaviors_temporary_unavailable');
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertStatus(503)->assertJson(['error' => 'TRACKING_UNAVAILABLE']);
        DB::statement('ALTER TABLE acquisition_behaviors_temporary_unavailable RENAME TO acquisition_behaviors');
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertOk()->assertJsonPath('accepted', 1);
        $this->assertDatabaseCount('acquisition_event_keys', 1);
        $this->assertDatabaseCount('acquisition_behaviors', 1);
    }

    public function test_diagnostics_separate_no_consent_schema_conflict_and_temporary_catalog_failure_without_payloads(): void
    {
        $this->postJson('/api/v1/tracking/events', ['events' => [$this->item()]])->assertJsonPath('rejected', 1);
        $this->postJson('/api/v1/tracking/events', ['events' => []])->assertUnprocessable();
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk();
        $event = $this->item();
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertJsonPath('accepted', 1);
        $event['properties']['path'] = '/catalog';
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertJsonPath('rejected', 1);
        $catalog = \Mockery::mock(CatalogReader::class);
        $catalog->shouldReceive('drama')->andThrow(new ApiProblem('DEPENDENCY_NOT_READY', 503, 'Unavailable fixture'));
        $this->app->instance(CatalogReader::class, $catalog);
        $this->postJson('/api/v1/tracking/events', ['events' => [$this->item(['drama_id' => (string) Str::uuid()])]])->assertStatus(503);
        foreach (['consent_skipped', 'http_schema_rejected', 'event_id_conflict', 'catalog_unavailable'] as $reason) {
            $this->assertDatabaseHas('acquisition_capture_diagnostics', ['reason' => $reason, 'count' => 1]);
        }
        $this->signIn($this->staff(Role::Owner));
        $report = app(AnalyticsReport::class)->read();
        $this->assertSame(1, $report['diagnostics']['consent_skipped']);
        $reasons = array_column($report['diagnostics']['capture_failures'], 'reason');
        $this->assertNotContains('consent_skipped', $reasons);
        $this->assertContains('catalog_unavailable', $reasons);
        $this->assertStringNotContainsString($event['event_id'], json_encode($report['diagnostics']));
    }

    public function test_http_rate_limit_has_safe_persistent_diagnostic(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/tracking/context')->assertOk();
        }
        $this->getJson('/api/v1/tracking/context')->assertStatus(429);
        $this->assertDatabaseHas('acquisition_capture_diagnostics', ['reason' => 'http_rate_limited', 'count' => 1]);
    }
}
