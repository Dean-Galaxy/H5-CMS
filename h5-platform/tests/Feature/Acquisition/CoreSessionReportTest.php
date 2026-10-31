<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\IdentityContext;
use App\Enums\Role;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Measurement\CoreSessionReport;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

final class CoreSessionReportTest extends AcquisitionTestCase
{
    private CarbonImmutable $base;

    private string $dramaA;

    private string $dramaB;

    private array $secretIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->base = CarbonImmutable::parse(DB::selectOne('SELECT statement_timestamp() AS t')->t)->subHours(3);
        $this->dramaA = (string) Str::uuid();
        $this->dramaB = (string) Str::uuid();
        $this->signIn($this->staff(Role::Owner));
        // Viewing historical synthetic aggregates must work while every capture/result/send flag is off.
        config(['acquisition.measurement.enabled' => false, 'acquisition.registration.observations_enabled' => false,
            'acquisition.registration.synthetic_policy' => false, 'h5.measurement_c_registration' => false]);
    }

    private function window(): array
    {
        return ['from' => $this->base->addHour()->toIso8601ZuluString('microsecond'),
            'until' => $this->base->addHours(2)->toIso8601ZuluString('microsecond')];
    }

    private function fixtureSession(array $subject = [], array $session = []): array
    {
        $uid = (string) Str::uuid();
        $sid = (string) Str::uuid();
        $pref = (string) Str::uuid();
        $this->secretIds = [...$this->secretIds, $uid, $sid, $pref];
        DB::table('acquisition_c_preferences')->insert(['id' => $pref, 'created_at' => Clock::stamp($this->base), 'updated_at' => Clock::stamp($this->base), 'expires_at' => Clock::stamp($this->base->addDays(7))]);
        $u = $subject + ['id' => $uid, 'preference_id' => $pref, 'policy_version' => 'c-core-1-synthetic-v1', 'notice_version' => 'c-notice-c-core-1', 'is_test' => true,
            'created_at' => Clock::stamp($this->base), 'expires_at' => Clock::stamp($this->base->addDay())];
        DB::table('acquisition_c_subjects')->insert($u);
        DB::table('acquisition_c_sessions')->insert($session + ['id' => $sid, 'subject_id' => $uid, 'created_at' => Clock::stamp($this->base),
            'last_seen_at' => Clock::stamp($this->base->addHours(3)), 'expires_at' => Clock::stamp($this->base->addDay())]);

        return [$uid, $sid, $u];
    }

    private function fixtureEvent(array $s, string $name, int $seconds, array $p = [], array $overrides = []): void
    {
        $id = (string) Str::uuid();
        DB::table('acquisition_c_events')->insert($overrides + ['id' => $id, 'dedup_key' => hash('sha256', $id), 'fingerprint' => hash('sha256', $name.json_encode($p)),
            'subject_id' => $s[0], 'measurement_session_id' => $s[1], 'event_name' => $name, 'schema_version' => 2,
            'policy_version' => $s[2]['policy_version'], 'notice_version' => $s[2]['notice_version'], 'is_test' => $s[2]['is_test'],
            'received_at' => Clock::stamp($this->base->addSeconds($seconds)), 'properties' => json_encode($p, JSON_THROW_ON_ERROR)]);
    }

    private function report(array $extra = []): array
    {
        return app(CoreSessionReport::class)->read($extra + $this->window());
    }

    public function test_ordered_direct_entry_half_open_window_and_independent_results(): void
    {
        $s = $this->fixtureSession();
        $this->fixtureEvent($s, 'page_view', 3600, ['page_type' => 'home']); // exactly from
        $this->fixtureEvent($s, 'drama_view', 3660, ['drama_id' => $this->dramaA]);
        $this->fixtureEvent($s, 'play_requested', 3720, ['drama_id' => $this->dramaA]);
        $this->fixtureEvent($s, 'play_started', 3780, ['drama_id' => $this->dramaA, 'position_seconds' => .25]);
        $this->fixtureEvent($s, 'play_started', 3781, ['drama_id' => $this->dramaA, 'position_seconds' => .5]);
        $this->fixtureEvent($s, 'registration_succeeded', 3840, [], ['policy_version' => 'registration-purpose-r1', 'notice_version' => 'c-purpose-choice-only-r1-v1']);
        $this->fixtureEvent($s, 'registration_succeeded', 3900, [], ['policy_version' => 'registration-purpose-r1', 'notice_version' => 'c-purpose-choice-only-r1-v1']);
        $direct = $this->fixtureSession();
        $this->fixtureEvent($direct, 'page_view', 7190, ['page_type' => 'player']);
        $this->fixtureEvent($direct, 'drama_view', 7195, ['drama_id' => $this->dramaB]);
        $this->fixtureEvent($direct, 'play_started', 7260, ['drama_id' => $this->dramaB, 'position_seconds' => .5]); // tail beyond until
        $atUntil = $this->fixtureSession();
        $this->fixtureEvent($atUntil, 'page_view', 7200);
        $r = $this->report();
        $g = $r['groups'][0];
        $this->assertSame([2, 2, 2], [$g['E'], $g['D'], $g['P']]);
        $this->assertSame(1, $g['direct_drama_or_player']);
        $this->assertSame('100.00%', $g['observed_ratios']['P/D']['display']);
        $this->assertSame(2, $r['coverage']['eligible_entries']);
        $this->assertFalse($r['coverage']['limited']);
        $this->assertSame('recorded', $r['trusted_registration_results']['status']);
        $this->assertSame(1, $r['trusted_registration_results']['groups'][0]['succeeded_sessions']);
        $this->assertSame('registration-purpose-r1', $r['trusted_registration_results']['groups'][0]['policy_version']);
        $this->assertSame('NOT_APPLICABLE', $r['unavailable_stages']['payment']);
        $this->assertSame('NOT_INTEGRATED', $r['unavailable_stages']['registration_funnel']);
        $filtered = $this->report(['drama_id' => strtoupper($this->dramaB)]);
        $this->assertSame(1, $filtered['groups'][0]['E']);
        $this->assertSame(1, $filtered['trusted_registration_results']['groups'][0]['succeeded_sessions']);
        $this->assertFalse($filtered['trusted_registration_results']['drama_filter_applied']);
        $this->assertSame(0, $g['coverage_unknown']);
    }

    public function test_replay_refresh_out_of_order_and_target_filter_do_not_rewrite_prefix(): void
    {
        $s = $this->fixtureSession();
        $this->fixtureEvent($s, 'drama_view', 3601, ['drama_id' => $this->dramaA]);
        $this->fixtureEvent($s, 'page_view', 3602, ['page_type' => 'home']);
        $this->fixtureEvent($s, 'play_started', 3603, ['drama_id' => $this->dramaA, 'position_seconds' => .3]);
        $this->fixtureEvent($s, 'page_view', 3604); // fresh page UUID/refresh is still one session
        $this->fixtureEvent($s, 'drama_view', 3605, ['drama_id' => $this->dramaA]);
        $this->fixtureEvent($s, 'drama_view', 3606, ['drama_id' => $this->dramaB]);
        $this->fixtureEvent($s, 'play_started', 3607, ['drama_id' => $this->dramaB, 'position_seconds' => .5]);
        $zero = $this->fixtureSession();
        $this->fixtureEvent($zero, 'page_view', 3700);
        $g = $this->report()['groups'][0];
        $this->assertSame([2, 1, 0], [$g['E'], $g['D'], $g['P']]);
        $this->assertSame(1, $g['play_without_ordered_drama']);
        $this->assertSame('0.00%', $g['observed_ratios']['P/D']['display']);
        $this->assertSame([], $this->report(['drama_id' => $this->dramaB])['groups']);
        // Same stored event replay is already deduplicated by CoreEvents (existing accepted tests reused).
        $this->assertSame($g, $this->report()['groups'][0]);
        DB::table('acquisition_c_events')->where('measurement_session_id', $s[1])->delete();
        $ratio = $this->report()['groups'][0]['observed_ratios']['P/D'];
        $this->assertSame(['numerator' => 0, 'denominator' => 0, 'value' => null, 'display' => '—', 'kind' => 'observed_only'], $ratio);
    }

    public function test_preserved_entry_before_window_and_fixed_target_before_entry_are_not_refreshed(): void
    {
        $before = $this->fixtureSession();
        $this->fixtureEvent($before, 'page_view', 3599);
        $this->fixtureEvent($before, 'page_view', 3700);
        $this->fixtureEvent($before, 'drama_view', 3701, ['drama_id' => $this->dramaB]);
        $this->fixtureEvent($before, 'play_started', 3702, ['drama_id' => $this->dramaB, 'position_seconds' => .1]);
        $s = $this->fixtureSession();
        $this->fixtureEvent($s, 'drama_view', 3599, ['drama_id' => $this->dramaA]);
        $this->fixtureEvent($s, 'page_view', 3600);
        $this->fixtureEvent($s, 'drama_view', 3601, ['drama_id' => $this->dramaB]);
        $this->fixtureEvent($s, 'play_started', 3602, ['drama_id' => $this->dramaB, 'position_seconds' => .2]);
        $r = $this->report();
        $this->assertSame(1, $r['coverage']['eligible_entries']);
        $this->assertSame([1, 0, 0], [$r['groups'][0]['E'], $r['groups'][0]['D'], $r['groups'][0]['P']]);
        $this->assertSame(1, $r['branches']['entry_outside_window']);
        $this->assertSame([], $this->report(['drama_id' => $this->dramaB])['groups']);
        $this->assertSame(1, $this->report(['drama_id' => $this->dramaA])['groups'][0]['E']);
    }

    public function test_known_end_microsecond_boundary_no_cross_session_or_necessary_log_backfill(): void
    {
        $end = $this->base->addSeconds(3800)->microsecond(123456);
        $s = $this->fixtureSession([], ['ended_at' => Clock::stamp($end)]);
        $this->fixtureEvent($s, 'page_view', 3600);
        $this->fixtureEvent($s, 'drama_view', 3700, ['drama_id' => $this->dramaA]);
        $this->fixtureEvent($s, 'play_started', 3800, ['drama_id' => $this->dramaA, 'position_seconds' => .2], ['received_at' => Clock::stamp($end)]);
        $new = $this->fixtureSession();
        $this->fixtureEvent($new, 'page_view', 3900, ['page_type' => 'player']);
        $this->fixtureEvent($new, 'play_started', 3910, ['drama_id' => $this->dramaA, 'position_seconds' => .5]);
        $noE = $this->fixtureSession();
        $this->fixtureEvent($noE, 'play_started', 3920, ['drama_id' => $this->dramaA, 'position_seconds' => .5]);
        $r = $this->report();
        $this->assertSame(2, $r['groups'][0]['E']);
        $this->assertSame(1, $r['groups'][0]['D']);
        $this->assertSame(0, $r['groups'][0]['P']);
        $this->assertSame(1, $r['groups'][0]['known_truncated']);
        $this->assertSame(1, $r['branches']['no_retained_page_entry']);
        $this->assertSame(1, $r['groups'][0]['play_without_ordered_drama']);
        // One microsecond before the known end is still observable.
        DB::table('acquisition_c_events')->where('measurement_session_id', $s[1])->where('event_name', 'play_started')->update(['received_at' => Clock::stamp($end->subMicrosecond())]);
        $this->assertSame(1, $this->report()['groups'][0]['P']);
        DB::table('acquisition_c_events')->where('measurement_session_id', $s[1])->where('event_name', 'play_started')->update(['received_at' => Clock::stamp($end->addMicrosecond())]);
        $this->assertSame(0, $this->report()['groups'][0]['P']);
    }

    public function test_registration_results_use_their_received_window_even_without_entry_and_have_no_evidence_state(): void
    {
        $this->assertSame('no_evidence', $this->report()['trusted_registration_results']['status']);
        $s = $this->fixtureSession();
        $this->fixtureEvent($s, 'page_view', 3500);
        foreach ([3600, 3601] as $at) {
            $this->fixtureEvent($s, 'registration_failed', $at, ['error_code' => 'validation'], ['policy_version' => 'registration-purpose-r1', 'notice_version' => 'c-purpose-choice-only-r1-v1']);
        }
        $this->fixtureEvent($s, 'registration_succeeded', 3700, [], ['policy_version' => 'registration-purpose-r1', 'notice_version' => 'c-purpose-choice-only-r1-v1']);
        $atUntil = $this->fixtureSession();
        $this->fixtureEvent($atUntil, 'registration_succeeded', 7200, [], ['policy_version' => 'registration-purpose-r1', 'notice_version' => 'c-purpose-choice-only-r1-v1']);
        $r = $this->report();
        $this->assertSame([], $r['groups']);
        $card = $r['trusted_registration_results']['groups'][0];
        $this->assertSame([1, 1, 1], [$card['sessions'], $card['failed_sessions'], $card['succeeded_sessions']]);
        $this->assertArrayNotHasKey('ratio', $card);
    }

    public function test_groups_test_attribute_and_coverage_unknown_are_explicit(): void
    {
        foreach ([['policy_version' => 'policy-A'], ['notice_version' => 'notice-B'], []] as $attributes) {
            $s = $this->fixtureSession($attributes);
            $this->fixtureEvent($s, 'page_view', 3600);
        }
        $schema = $this->fixtureSession([], ['last_seen_at' => Clock::stamp($this->base->addHour())]);
        $this->fixtureEvent($schema, 'page_view', 3600, [], ['schema_version' => 3]);
        $real = $this->fixtureSession(['is_test' => false]);
        $this->fixtureEvent($real, 'page_view', 3600);
        $mismatch = $this->fixtureSession();
        $this->fixtureEvent($mismatch, 'page_view', 3600, [], ['policy_version' => 'wrong-policy']);
        $this->fixtureEvent($mismatch, 'page_view', 3601); // No fallback to this matching refresh.
        $old = $this->fixtureSession([], ['created_at' => Clock::stamp($this->base->subDays(30))]);
        $this->fixtureEvent($old, 'page_view', 3600);
        $mixed = $this->fixtureSession();
        $this->fixtureEvent($mixed, 'page_view', 3600);
        $this->fixtureEvent($mixed, 'drama_view', 3601, ['drama_id' => $this->dramaA], ['notice_version' => 'mismatch']);
        $r = $this->report();
        $this->assertCount(4, $r['groups']);
        $this->assertSame(6, array_sum(array_column($r['groups'], 'E')));
        $this->assertSame(2, array_sum(array_column($r['groups'], 'coverage_unknown')));
        $this->assertSame(3, array_sum(array_column($r['groups'], 'observing')));
        $this->assertSame(1, array_sum(array_column($r['groups'], 'window_ended')));
        $this->assertSame(1, array_sum(array_column($r['groups'], 'window_ended_without_P')));
        $this->assertSame(1, $r['branches']['unmatched_entry_sample']);
        $this->assertSame(2, $r['branches']['association_or_sample']['mismatched_sample_events']);
        $this->assertSame(0, array_sum(array_column($r['groups'], 'D')));
        $this->assertTrue(collect($r['groups'])->every(fn ($g) => $g['is_test']));
    }

    public static function invalidFilters(): array
    {
        return [[['from' => '0000-01-01T00:00:00Z']], [['from' => 'now']], [['from' => '2026-02-30T00:00:00Z']], [['until' => '2026-01-01']],
            [['from' => '2026-01-01T00:00:00+08:00']], [['from' => '2026-01-01T00:00:00Z', 'until' => '2026-01-09T00:00:00Z']],
            [['from' => '2026-01-02T00:00:00Z', 'until' => '2026-01-01T00:00:00Z']], [['until' => '2099-01-01T00:00:00Z']],
            [['as_of' => '2026-01-01T00:00:00Z']], [['drama_id' => 'bad']], [['test' => 'all']]];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_or_future_ranges_and_unapproved_filters_are_rejected(array $filters): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CoreSessionReport::class)->read($filters);
    }

    public function test_default_window_comes_from_one_statement_clock_and_accepts_exact_seven_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2001-01-01T00:00:00Z'));
        $r = app(CoreSessionReport::class)->read();
        $asOf = CarbonImmutable::parse($r['as_of']);
        $this->assertTrue($asOf->greaterThan($this->base));
        $this->assertSame($r['as_of'], $r['window']['until']);
        $this->assertTrue($asOf->subDays(7)->equalTo(CarbonImmutable::parse($r['window']['from'])));
        $r = app(CoreSessionReport::class)->read(['from' => $this->base->addHours(2)->subDays(7)->toIso8601ZuluString('microsecond'), 'until' => $this->base->addHours(2)->toIso8601ZuluString('microsecond')]);
        $this->assertFalse($r['coverage']['limited']);
    }

    public function test_owner_requires_permission_and_all_other_roles_are_denied_despite_assigned_assets(): void
    {
        $acq = $this->staff(Role::Acquisition);
        $this->signIn($acq);
        $this->assets();
        $this->problem('NOT_AUTHORIZED', fn () => $this->report());
        foreach ([Role::Support, Role::ContentOperator] as $role) {
            $this->signIn($this->staff($role));
            $this->problem('NOT_AUTHORIZED', fn () => $this->report());
        }
        auth('staff')->logout();
        $this->problem('AUTHENTICATION_REQUIRED', fn () => $this->report());
    }

    public function test_dependency_and_environment_failures_do_not_return_empty_data(): void
    {
        config(['h5.modules.acquisition.ready' => false]);
        $this->problem('DEPENDENCY_NOT_READY', fn () => $this->report());
        config(['h5.modules.acquisition.ready' => true]);
        $this->app->instance('env', 'production');
        $this->problem('DEPENDENCY_NOT_READY', fn () => $this->report());
        $this->app->instance('env', 'testing');
        DB::statement('ALTER TABLE acquisition_c_events RENAME TO acquisition_c_events_report_fault');
        try {
            // Use a savepoint because missing-relation errors abort PostgreSQL's enclosing test transaction.
            DB::transaction(fn () => $this->report());
            $this->fail('Missing dependency returned data');
        } catch (ApiProblem $e) {
            $this->assertSame('DEPENDENCY_NOT_READY', $e->errorCode);
        } finally {
            DB::statement('ALTER TABLE acquisition_c_events_report_fault RENAME TO acquisition_c_events');
        }
    }

    public function test_one_aggregate_select_is_read_only_and_does_not_expose_raw_identifiers(): void
    {
        $s = $this->fixtureSession();
        $this->fixtureEvent($s, 'page_view', 3600);
        $this->fixtureEvent($s, 'drama_view', 3601, ['drama_id' => $this->dramaA]);
        // Prime server identity before observing the report SQL; its authentication is an existing 01 boundary.
        app(IdentityContext::class)->staff();
        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $before = $this->snapshotC();
        DB::beginTransaction(); // Savepoint restores the test's prior mode without changing it mid-transaction.
        DB::statement('SET LOCAL transaction_read_only = on');
        try {
            $this->assertSame('on', DB::selectOne("SELECT current_setting('transaction_read_only') AS v")->v);
            $r = $this->report();
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->snapshotC());
        $reportQueries = array_values(array_filter($queries, fn ($q) => str_contains($q, 'WITH clock AS MATERIALIZED')));
        $this->assertCount(1, $reportQueries);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|FOR UPDATE|nextval)\b/i', $reportQueries[0]);
        $this->assertDoesNotMatchRegularExpression('/\b(identity_|billing_|acquisition_behaviors|acquisition_privacy|content_)/i', $reportQueries[0]);
        foreach ($this->secretIds as $id) {
            $this->assertStringNotContainsString($id, json_encode($r));
        }
        foreach (['session_id', 'subject_id', 'proof_id', 'viewer_id', 'email', 'url', 'properties', 'entry_sequence'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'":', json_encode($r));
        }
        Http::assertNothingSent();
    }

    private function snapshotC(): array
    {
        $out = [];
        foreach (['preferences', 'subjects', 'sessions', 'events', 'event_keys', 'plays'] as $name) {
            $out[$name] = DB::selectOne("SELECT md5(coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text), '[]'::jsonb)::text) AS h FROM acquisition_c_".$name.' t')->h;
        }

        return $out;
    }

    public function test_limit_10000_and_10001_is_explicit_and_does_not_limit_raw_stage_events(): void
    {
        $s = $this->fixtureSession();
        // Set-based synthetic rows keep this real database boundary test bounded in runtime.
        DB::statement("INSERT INTO acquisition_c_sessions(id,subject_id,created_at,last_seen_at,expires_at)
            SELECT md5('R2A-session-' || i)::uuid, CAST(? AS uuid), CAST(? AS timestamptz), CAST(? AS timestamptz), CAST(? AS timestamptz) FROM generate_series(1,10000) i", [$s[0], Clock::stamp($this->base), Clock::stamp($this->base->addHours(3)), Clock::stamp($this->base->addDay())]);
        DB::statement("INSERT INTO acquisition_c_events(id,dedup_key,fingerprint,subject_id,measurement_session_id,event_name,schema_version,policy_version,notice_version,is_test,properties,received_at)
            SELECT md5('R2A-event-' || i)::uuid, md5(i::text)||md5(i::text), repeat('f',64), CAST(? AS uuid), md5('R2A-session-' || i)::uuid,
            'page_view',2,'c-core-1-synthetic-v1','c-notice-c-core-1',true,'{}'::jsonb, CAST(? AS timestamptz) FROM generate_series(1,10000) i", [$s[0], Clock::stamp($this->base->addHour())]);
        $r = $this->report();
        $this->assertSame(10000, $r['coverage']['processed_entries']);
        $this->assertFalse($r['coverage']['limited']);
        $this->fixtureEvent($s, 'page_view', 3601);
        // These tail events occur after >10k stored envelopes but belong to an admitted entry.
        $first = [$s[0], (string) Str::uuid(), $s[2]];
        $first[1] = DB::selectOne("SELECT md5('R2A-session-1')::uuid AS id")->id;
        $this->fixtureEvent($first, 'drama_view', 3700, ['drama_id' => $this->dramaA]);
        $this->fixtureEvent($first, 'play_started', 3800, ['drama_id' => $this->dramaA, 'position_seconds' => .25]);
        $r = $this->report();
        $this->assertSame(10001, $r['coverage']['eligible_entries']);
        $this->assertSame(10000, $r['coverage']['processed_entries']);
        $this->assertTrue($r['coverage']['limited']);
        $this->assertTrue($r['coverage']['last_timestamp_may_be_partial']);
        $this->assertSame('processed_entry_cohort_only', $r['coverage']['scope']);
        $this->assertSame(10000, $r['groups'][0]['E']);
        $this->assertSame([1, 1], [$r['groups'][0]['D'], $r['groups'][0]['P']]);
        $this->assertTrue($r['groups'][0]['limited']);
        $this->assertSame('NARROW_WINDOW', $r['coverage']['recommendation']);
        $this->assertSame('100.00%', $r['groups'][0]['observed_ratios']['P/D']['display']);
    }
}
