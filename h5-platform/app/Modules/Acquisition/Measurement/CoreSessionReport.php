<?php

namespace App\Modules\Acquisition\Measurement;

use App\Enums\Role;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Services\AssetAccess;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** c-session-report-r2a-1: aggregates only. No context methods, writes or identity/finance joins. */
final class CoreSessionReport
{
    public const VERSION = 'c-session-report-r2a-1';

    public const MAX_ENTRANCES = 10000;

    public function read(array $filters = []): array
    {
        if (! app()->environment('local', 'testing') || ! config('h5.modules.acquisition.ready', false)) {
            throw new ApiProblem('DEPENDENCY_NOT_READY', 503, 'C会话报表仅在就绪的隔离环境可用');
        }
        // Reuse the server permission check; an assigned v1 asset never grants C-wide access.
        if (app(AssetAccess::class)->actor()->role !== Role::Owner) {
            throw new ApiProblem('NOT_AUTHORIZED', 403, '仅负责人可查看隔离C会话汇总');
        }
        if (array_diff(array_keys($filters), ['from', 'until', 'drama_id'])) {
            throw new \InvalidArgumentException('不支持的C会话报表筛选');
        }
        $from = array_key_exists('from', $filters) ? $this->utc($filters['from']) : null;
        $until = array_key_exists('until', $filters) ? $this->utc($filters['until']) : null;
        $drama = $filters['drama_id'] ?? null;
        if ($drama !== null && (! is_string($drama) || ! Str::isUuid($drama))) {
            throw new \InvalidArgumentException('目标剧编号无效');
        }
        try {
            // One SELECT means one MVCC snapshot, including the count, cap and independent result card.
            // statement_timestamp is taken by PostgreSQL once, not from the application/transaction clock.
            $row = DB::selectOne($this->sql(), [$from, $until, $drama === null ? null : strtolower($drama)]);
            $data = json_decode($row->report, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiProblem('DEPENDENCY_NOT_READY', 503, 'C会话汇总暂不可读，未返回零报表', true);
        }
        if (! $data['valid_window']) {
            throw new \InvalidArgumentException('请选择UTC半开区间，最长7天且截止不晚于本次快照');
        }
        unset($data['valid_window']);
        foreach ($data['groups'] as &$group) {
            $group['limited'] = $data['coverage']['limited'];
            $group['observed_ratios'] = ['D/E' => $this->ratio($group['D'], $group['E']), 'P/D' => $this->ratio($group['P'], $group['D'])];
        }
        unset($group);

        return ['report_version' => self::VERSION, 'sample' => 'isolated_synthetic_only', 'unit' => 'measurement_session',
            'coverage_labels' => ['observing' => '观察中，非在线或政策仍有效证明', 'known_truncated' => '已知截断，原因未证',
                'window_ended_without_P' => '窗口结束未观察到真实播放', 'coverage_unknown' => '覆盖未知'],
            'order_basis' => 'server_insert_sequence', 'snapshot' => 'single_select_mvcc',
            ...$data, 'unavailable_stages' => ['registration_funnel' => 'NOT_INTEGRATED', 'verification_success' => 'NOT_INTEGRATED', 'payment' => 'NOT_APPLICABLE'],
            'limitations' => ['observed_not_final_conversion', 'no_actual_action_order_or_loss_proof', 'no_end_reason_or_historical_policy_cutoff',
                'retained_prefix_only_no_historical_reconstruction', 'trusted_results_are_independent_received_window_not_entry_cohort',
                'no_identity_payment_or_legacy_join', 'no_raw_identifiers_or_export']];
    }

    private function utc(mixed $value): string
    {
        if (! is_string($value) || (int) substr($value, 0, 4) < 1 || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $value)
            || date_parse($value)['warning_count'] !== 0 || date_parse($value)['error_count'] !== 0) {
            throw new \InvalidArgumentException('日期必须是有效UTC ISO8601');
        }

        return Clock::stamp(CarbonImmutable::parse($value));
    }

    private function ratio(int $n, int $d): array
    {
        return ['numerator' => $n, 'denominator' => $d, 'value' => $d === 0 ? null : $n / $d,
            'display' => $d === 0 ? '—' : number_format($n / $d * 100, 2, '.', '').'%', 'kind' => 'observed_only'];
    }

    private function sql(): string
    {
        return <<<'SQL'
WITH clock AS MATERIALIZED (
    SELECT statement_timestamp() AS as_of, CAST(? AS timestamptz) AS input_from,
           CAST(? AS timestamptz) AS input_until, CAST(? AS text) AS drama
), bounds AS MATERIALIZED (
    SELECT as_of, coalesce(input_until, as_of) AS until,
           coalesce(input_from, coalesce(input_until, as_of) - interval '7 days') AS from_at, drama
    FROM clock
), params AS MATERIALIZED (
    SELECT *, until > from_at AND until <= as_of AND until - from_at <= interval '7 days' AS valid FROM bounds
),
-- Only sessions with a record in the requested received window need a retained-prefix lookup.
window_sessions AS MATERIALIZED (
    SELECT DISTINCT e.measurement_session_id AS id FROM acquisition_c_events e CROSS JOIN params b
    WHERE b.valid AND e.is_test AND e.received_at >= b.from_at AND e.received_at < b.until AND e.received_at < b.as_of
), first_pages AS MATERIALIZED (
    SELECT DISTINCT ON (e.measurement_session_id) e.measurement_session_id, e.sequence, e.received_at, e.schema_version,
           e.subject_id, e.is_test, e.policy_version, e.notice_version, e.properties
    FROM acquisition_c_events e JOIN window_sessions w ON w.id = e.measurement_session_id CROSS JOIN params b
    WHERE e.event_name = 'page_view' AND e.received_at < b.as_of ORDER BY e.measurement_session_id, e.sequence
), first_targets AS MATERIALIZED (
    SELECT DISTINCT ON (e.measurement_session_id) e.measurement_session_id, e.properties
    FROM acquisition_c_events e JOIN window_sessions w ON w.id = e.measurement_session_id
    JOIN acquisition_c_sessions s ON s.id = e.measurement_session_id
    JOIN acquisition_c_subjects u ON u.id = s.subject_id AND u.is_test
    JOIN first_pages ep ON ep.measurement_session_id = s.id CROSS JOIN params b
    WHERE e.subject_id = u.id AND e.event_name = 'drama_view' AND e.is_test
      AND e.policy_version = u.policy_version AND e.notice_version = u.notice_version AND e.schema_version = ep.schema_version
      AND e.received_at < b.as_of AND e.properties->>'drama_id' ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
    ORDER BY e.measurement_session_id, e.sequence
), session_facts AS MATERIALIZED (
    SELECT s.id, s.subject_id, s.created_at, s.last_seen_at, u.policy_version, u.notice_version,
           least(s.ended_at, u.ended_at) AS ended_at,
           least(s.expires_at, u.expires_at, s.last_seen_at + interval '30 minutes', s.ended_at, u.ended_at) AS boundary,
           ep.sequence AS entry_sequence, ep.received_at AS entry_at, ep.schema_version,
           ep.properties->>'page_type' AS entry_type,
           ep.subject_id = u.id AND ep.is_test AND ep.policy_version = u.policy_version AND ep.notice_version = u.notice_version AS entry_matches,
           lower(target.properties->>'drama_id') AS target_drama
    FROM window_sessions w JOIN acquisition_c_sessions s ON s.id = w.id
    JOIN acquisition_c_subjects u ON u.id = s.subject_id AND u.is_test CROSS JOIN params b
    LEFT JOIN first_pages ep ON ep.measurement_session_id = s.id
    LEFT JOIN first_targets target ON target.measurement_session_id = s.id
), candidates AS MATERIALIZED (
    SELECT f.* FROM session_facts f CROSS JOIN params b
    WHERE f.entry_matches AND f.entry_at >= b.from_at AND f.entry_at < b.until
      AND (b.drama IS NULL OR f.target_drama = b.drama)
), admitted AS MATERIALIZED (
    SELECT * FROM candidates ORDER BY entry_at, entry_sequence LIMIT 10000
),
-- Aggregate over retained raw rows inside SQL; never transfer them to PHP or limit the raw event stream.
drama_steps AS MATERIALIZED (
    SELECT f.id, min(e.sequence) AS sequence
    FROM admitted f JOIN acquisition_c_events e ON e.measurement_session_id = f.id AND e.subject_id = f.subject_id CROSS JOIN params b
    WHERE e.is_test AND e.policy_version = f.policy_version AND e.notice_version = f.notice_version AND e.schema_version = f.schema_version
      AND e.event_name = 'drama_view' AND lower(e.properties->>'drama_id') = f.target_drama AND e.sequence > f.entry_sequence
      AND e.received_at >= f.created_at AND e.received_at < least(b.as_of, f.boundary)
    GROUP BY f.id
), play_steps AS MATERIALIZED (
    SELECT f.id, min(e.sequence) AS sequence
    FROM admitted f JOIN drama_steps d ON d.id = f.id
    JOIN acquisition_c_events e ON e.measurement_session_id = f.id AND e.subject_id = f.subject_id CROSS JOIN params b
    WHERE e.is_test AND e.policy_version = f.policy_version AND e.notice_version = f.notice_version AND e.schema_version = f.schema_version
      AND e.event_name = 'play_started' AND lower(e.properties->>'drama_id') = f.target_drama AND e.sequence > d.sequence
      AND CASE WHEN jsonb_typeof(e.properties->'position_seconds') = 'number' THEN (e.properties->>'position_seconds')::numeric > 0 ELSE false END
      AND e.received_at >= f.created_at AND e.received_at < least(b.as_of, f.boundary)
    GROUP BY f.id
), path_evidence AS MATERIALIZED (
    SELECT f.id, bool_or(e.event_name IN ('page_view','drama_view','play_started')
               AND (e.subject_id <> f.subject_id OR NOT e.is_test OR e.policy_version <> f.policy_version
                    OR e.notice_version <> f.notice_version OR e.schema_version <> f.schema_version)) AS mismatch,
           bool_or(e.subject_id = f.subject_id AND e.is_test AND e.policy_version = f.policy_version AND e.notice_version = f.notice_version
               AND e.schema_version = f.schema_version AND e.event_name = 'play_started'
               AND e.received_at < f.boundary AND e.received_at >= f.created_at
               AND (d.sequence IS NULL OR e.sequence < d.sequence)) AS play_without_prefix
    FROM admitted f JOIN acquisition_c_events e ON e.measurement_session_id = f.id
    LEFT JOIN drama_steps d ON d.id = f.id CROSS JOIN params b
    WHERE e.received_at < b.as_of GROUP BY f.id
), paths AS MATERIALIZED (
    SELECT f.*, d.sequence AS drama_sequence, play.sequence AS play_sequence,
           f.created_at < b.as_of - interval '29 days' OR f.entry_at < f.created_at OR f.entry_at >= f.boundary
               OR coalesce(evidence.mismatch, false) AS coverage_unknown,
           coalesce(evidence.play_without_prefix, false) AS play_without_prefix
    FROM admitted f CROSS JOIN params b
    LEFT JOIN drama_steps d ON d.id = f.id LEFT JOIN play_steps play ON play.id = f.id
    LEFT JOIN path_evidence evidence ON evidence.id = f.id
), grouped AS (
    SELECT policy_version, notice_version, schema_version, true AS is_test, count(*) AS "E",
           count(drama_sequence) FILTER (WHERE entry_at < boundary) AS "D",
           count(play_sequence) FILTER (WHERE entry_at < boundary) AS "P",
           count(*) FILTER (WHERE coverage_unknown) AS coverage_unknown,
           count(*) FILTER (WHERE NOT coverage_unknown AND ended_at <= b.as_of) AS known_truncated,
           count(*) FILTER (WHERE NOT coverage_unknown AND (ended_at IS NULL OR ended_at > b.as_of) AND boundary <= b.as_of) AS window_ended,
           count(*) FILTER (WHERE NOT coverage_unknown AND boundary > b.as_of) AS observing,
           count(*) FILTER (WHERE NOT coverage_unknown AND (ended_at IS NULL OR ended_at > b.as_of) AND boundary <= b.as_of AND play_sequence IS NULL) AS "window_ended_without_P",
           count(*) FILTER (WHERE entry_type IN ('drama','player')) AS direct_drama_or_player,
           count(*) FILTER (WHERE entry_type = 'registration') AS direct_registration,
           count(*) FILTER (WHERE play_without_prefix) AS play_without_ordered_drama
    FROM paths CROSS JOIN params b GROUP BY policy_version, notice_version, schema_version
), result_groups AS (
    SELECT e.policy_version, e.notice_version, e.schema_version, true AS is_test,
           count(DISTINCT e.measurement_session_id) AS sessions,
           count(DISTINCT e.measurement_session_id) FILTER (WHERE e.event_name = 'registration_succeeded') AS succeeded_sessions,
           count(DISTINCT e.measurement_session_id) FILTER (WHERE e.event_name = 'registration_failed') AS failed_sessions
    FROM acquisition_c_events e JOIN acquisition_c_sessions s ON s.id = e.measurement_session_id AND s.subject_id = e.subject_id
    JOIN acquisition_c_subjects u ON u.id = s.subject_id AND u.is_test CROSS JOIN params b
    WHERE b.valid AND e.is_test AND e.event_name IN ('registration_succeeded','registration_failed')
      AND e.received_at >= b.from_at AND e.received_at < b.until AND e.received_at < b.as_of
    GROUP BY e.policy_version, e.notice_version, e.schema_version
), diagnostics AS (
    SELECT count(DISTINCT e.measurement_session_id) FILTER (WHERE s.id IS NULL OR u.id IS NULL OR e.subject_id <> s.subject_id) AS missing_or_mismatched_association,
           count(*) FILTER (WHERE e.event_name NOT IN ('registration_succeeded','registration_failed')
             AND (NOT coalesce(u.is_test, false) OR e.policy_version <> u.policy_version OR e.notice_version <> u.notice_version)) AS mismatched_sample_events
    FROM acquisition_c_events e CROSS JOIN params b
    LEFT JOIN acquisition_c_sessions s ON s.id = e.measurement_session_id LEFT JOIN acquisition_c_subjects u ON u.id = s.subject_id
    WHERE b.valid AND e.is_test AND e.received_at >= b.from_at AND e.received_at < b.until AND e.received_at < b.as_of
)
SELECT jsonb_build_object(
    'valid_window', b.valid,
    'as_of', to_char(b.as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
    'window', jsonb_build_object('from', to_char(b.from_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                                'until', to_char(b.until AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'), 'kind', '[from,until)'),
    'coverage', jsonb_build_object('eligible_entries', (SELECT count(*) FROM candidates), 'processed_entries', (SELECT count(*) FROM admitted),
        'limit', 10000, 'limited', (SELECT count(*) > 10000 FROM candidates), 'scope', 'processed_entry_cohort_only',
        'first_processed_entry_at', (SELECT to_char(min(entry_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') FROM admitted),
        'last_processed_entry_at', (SELECT to_char(max(entry_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') FROM admitted),
        'last_timestamp_may_be_partial', (SELECT count(*) > 10000 FROM candidates), 'order', 'entry_received_at_then_entry_sequence',
        'recommendation', CASE WHEN (SELECT count(*) > 10000 FROM candidates) THEN 'NARROW_WINDOW' ELSE NULL END),
    'groups', coalesce((SELECT jsonb_agg(to_jsonb(g) ORDER BY policy_version, notice_version, schema_version) FROM grouped g), '[]'::jsonb),
    'branches', jsonb_build_object('scope', 'received_window_sessions_independent_of_processed_cohort',
        'no_retained_page_entry', (SELECT count(*) FROM session_facts WHERE entry_sequence IS NULL),
        'entry_outside_window', (SELECT count(*) FROM session_facts WHERE entry_at < b.from_at OR entry_at >= b.until),
        'unmatched_entry_sample', (SELECT count(*) FROM session_facts WHERE entry_sequence IS NOT NULL AND NOT entry_matches),
        'association_or_sample', (SELECT to_jsonb(x) FROM diagnostics x)),
    'trusted_registration_results', jsonb_build_object('scope', 'independent_received_window', 'status', CASE WHEN EXISTS (SELECT 1 FROM result_groups) THEN 'recorded' ELSE 'no_evidence' END,
        'groups', coalesce((SELECT jsonb_agg(to_jsonb(r) ORDER BY policy_version, notice_version, schema_version) FROM result_groups r), '[]'::jsonb),
        'denominator_relation', 'not_an_entry_cohort_ratio', 'drama_filter_applied', false)
) AS report FROM params b
SQL;
    }
}
