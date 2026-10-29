<?php

namespace App\Modules\Acquisition\Analytics;

use App\Contracts\BillingAnalyticsReader;
use App\Enums\Role;
use App\Modules\Acquisition\Services\AssetAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AnalyticsReport
{
    public function read(array $filters = []): array
    {
        $staff = app(AssetAccess::class)->actor();
        $from = CarbonImmutable::parse($filters['from'] ?? now()->subDays(6)->toDateString(), 'UTC')->startOfDay();
        $until = CarbonImmutable::parse($filters['until'] ?? now()->toDateString(), 'UTC')->startOfDay()->addDay();
        if ($until->lessThanOrEqualTo($from) || $from->diffInDays($until) > 90 || $until->greaterThan(now()->addDay()->startOfDay())) {
            throw new \InvalidArgumentException('请选择最多90天且不含未来的日期范围');
        }
        $q = DB::table('acquisition_behaviors');
        $delivery = DB::table('acquisition_deliveries');
        $receipts = DB::table('acquisition_receipts');
        if ($staff->role !== Role::Owner) {
            $links = app(AssetAccess::class)->query('link', $staff)->select('id');
            $q->whereIn('link_id', clone $links);
            $delivery->whereIn('link_id', clone $links);
            $receipts->whereIn('link_id', clone $links);
        }
        $allAuthorized = clone $q;
        foreach (['link_id', 'drama_id', 'episode_id'] as $field) {
            if (! empty($filters[$field])) {
                if (! Str::isUuid($filters[$field])) {
                    throw new \InvalidArgumentException('筛选编号无效');
                }
                $q->where($field, $filters[$field]);
                if ($field === 'link_id') {
                    app(AssetAccess::class)->get('link', $filters[$field]);
                    $delivery->where('link_id', $filters[$field]);
                    $receipts->where('link_id', $filters[$field]);
                }
            }
        }
        foreach (['channel' => ['meta', 'tiktok', 'unknown'], 'device' => ['desktop', 'mobile', 'tablet', 'unknown']] as $field => $choices) {
            if (! empty($filters[$field])) {
                if (! in_array($filters[$field], $choices, true)) {
                    throw new \InvalidArgumentException('筛选项无效');
                }
                $q->where($field, $filters[$field]);
            }
        }
        $test = $filters['test'] ?? 'all';
        if (! in_array($test, ['all', 'test', 'real'], true)) {
            throw new \InvalidArgumentException('环境筛选无效');
        }
        if ($test !== 'all') {
            $q->where('is_test', $test === 'test');
            $delivery->where('environment', $test === 'test' ? 'test' : 'production');
            $receipts->where('is_test', $test === 'test');
        }
        $enabled = app(TrackingContext::class)->enabled();
        $scoped = clone $q;
        $period = (clone $q)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $until);
        // Aggregate the complete filtered period. Heartbeat volume never truncates independent metrics.
        $counts = [];
        foreach ((clone $period)->select('is_test', 'event_name')->selectRaw('COUNT(*) AS events, COUNT(DISTINCT subject_id) AS people')->groupBy('is_test', 'event_name')->get() as $row) {
            $counts[($row->is_test ? '测试：' : '真实：').$row->event_name] = ['events' => (int) $row->events, 'people' => (int) $row->people];
        }
        $totals = (clone $period)->selectRaw("COUNT(DISTINCT subject_id) FILTER (WHERE event_name IN ('page_view','landing_view')) AS visitors, COUNT(DISTINCT session_key) AS sessions, COALESCE(SUM((properties->>'watched_delta')::bigint),0) AS watched_seconds")->first();
        $errors = [];
        foreach ((clone $period)->whereIn('event_name', ['player_error', 'checkout_failed', 'payment_failed', 'payment_pending', 'payment_cancelled'])
            ->select('event_name')->selectRaw("COALESCE(properties->>'error_code','unknown') AS error_code, COUNT(*) AS count")
            ->groupBy('event_name')->groupByRaw("COALESCE(properties->>'error_code','unknown')")->get() as $row) {
            $errors[$row->event_name.':'.$row->error_code] = (int) $row->count;
        }
        [$episodes, $dimensions] = $this->episodes($period, $allAuthorized);
        [$funnel, $registration, $segments, $entryPeople, $processed, $observing] = $this->cohorts($period, $allAuthorized);
        $limited = $entryPeople > self::MAX_ENTRANCES;
        $finance = $this->finance($period);
        $diagnostics = ['enabled' => $enabled, 'transport' => config('acquisition.transport', 'disabled'),
            'last_received_at' => $scoped->max('received_at'),
            'consent_skipped' => $staff->role === Role::Owner ? (int) DB::table('acquisition_capture_diagnostics')->where('reason', 'consent_skipped')->value('count') : null,
            'capture_failures' => $staff->role === Role::Owner ? DB::table('acquisition_capture_diagnostics')->where('reason', '!=', 'consent_skipped')->get(['reason', 'count', 'last_at'])->toArray() : [],
            'deliveries' => $delivery->select('environment', 'state', 'last_error')->selectRaw('COUNT(*) AS count')->groupBy('environment', 'state', 'last_error')->get()->toArray(),
            'source_status' => $receipts->select('is_test', 'source_status')->selectRaw('COUNT(*) AS count')->groupBy('is_test', 'source_status')->get()->toArray(),
            'platform_confirmation' => 'Meta/TikTok真实平台均未核验；接口接受不等于平台归因或实际投放有效'];

        return ['episodes' => $episodes, 'dimensions' => $dimensions, 'segments' => $segments, 'enabled' => $enabled,
            'limited' => $limited, 'funnel_entry_people' => $entryPeople, 'funnel_processed_people' => $processed, 'from' => $from->toDateString(), 'until' => $until->subDay()->toDateString(),
            'definition' => '仅已同意样本；伪名主体不跨设备合并。入口日期UTC，观察窗7天；各环节独立计数不等于漏斗。毛收按付款日期队列、退款为当前已确认累计，不是利润或ROAS。',
            'counts' => $enabled ? $counts : null, 'visitors' => $enabled ? (int) $totals->visitors : null,
            'sessions' => $enabled ? (int) $totals->sessions : null, 'watched_seconds' => $enabled ? (int) $totals->watched_seconds : null,
            'errors' => $errors, 'funnel' => $funnel, 'registration_funnel' => $registration, 'observing_people' => $observing,
            'finance' => $enabled ? $finance : ['status' => 'disabled', 'gross_minor' => null, 'refund_minor' => null, 'net_minor' => null, 'kinds' => []], 'diagnostics' => $diagnostics];
    }

    private const MAX_ENTRANCES = 10000;

    private const CORE = ['entry', 'play_started', 'checkout_created', 'payment_confirmed', 'paid_play'];

    private const REGISTRATION = ['entry', 'play_started', 'paywall_view', 'registration_succeeded', 'email_verified', 'checkout_created', 'payment_confirmed', 'paid_play'];

    private function finance($period): array
    {
        $unknown = ['status' => 'unknown', 'gross_minor' => null, 'refund_minor' => null, 'net_minor' => null, 'kinds' => []];
        try {
            $paymentIds = (clone $period)->where('event_name', 'payment_confirmed')->selectRaw("DISTINCT properties->>'payment_id' AS payment_id");
            $payments = DB::query()->fromSub($paymentIds, 'payments');
            $gross = 0;
            $refund = 0;
            $kinds = [];
            $seen = false;
            $payments->chunkById(200, function ($rows) use (&$gross, &$refund, &$kinds, &$seen) {
                $seen = true;
                $ids = $rows->pluck('payment_id')->all();
                $facts = app(BillingAnalyticsReader::class)->payments($ids);
                foreach ($ids as $id) {
                    $fact = $facts[$id] ?? null;
                    if (! $fact || ! is_int($fact['amount_minor'] ?? null) || ! is_int($fact['refunded_amount_minor'] ?? null)
                        || ($fact['currency'] ?? null) !== 'USD' || ! is_bool($fact['is_test'] ?? null)
                        || ! in_array($fact['transaction_kind'] ?? '', ['first_subscription', 'resubscription', 'renewal'], true)
                        || $fact['amount_minor'] < 0 || $fact['refunded_amount_minor'] < 0 || $fact['refunded_amount_minor'] > $fact['amount_minor']) {
                        throw new \RuntimeException('Missing or invalid trusted payment facts');
                    }
                    $gross += $fact['amount_minor'];
                    $refund += $fact['refunded_amount_minor'];
                    $key = ($fact['is_test'] ? 'test:' : 'real:').$fact['transaction_kind'];
                    $kinds[$key] ??= ['payments' => 0, 'gross_minor' => 0];
                    $kinds[$key]['payments']++;
                    $kinds[$key]['gross_minor'] += $fact['amount_minor'];
                }
            }, 'payment_id');
            if (! $seen) {
                app(BillingAnalyticsReader::class)->payments([]);
            }

            return ['status' => 'known', 'gross_minor' => $gross, 'refund_minor' => $refund, 'net_minor' => $gross - $refund, 'kinds' => $kinds];
        } catch (\Throwable) {
            return $unknown;
        }
    }

    private function episodes($period, $authorized): array
    {
        $base = (clone $period)->whereNotNull('episode_id');
        $labels = [];
        foreach ((clone $base)->selectRaw('DISTINCT ON (is_test,drama_id,episode_id) is_test,drama_id,episode_id,drama_title,episode_title,episode_number')
            ->orderBy('is_test')->orderBy('drama_id')->orderBy('episode_id')->orderBy('occurred_at')->orderBy('sequence')->get() as $row) {
            $labels[$this->episodeKey($row)] = $row;
        }
        $ending = (clone $authorized)->where('event_name', 'episode_completed')->whereColumn('subject_id', 'started.subject_id')
            ->whereColumn('is_test', 'started.is_test')->whereColumn('episode_id', 'started.episode_id')
            ->whereRaw("properties->>'play_instance_id' = started.properties->>'play_instance_id'")
            ->whereColumn('ordered_at', '>=', 'started.ordered_at')->whereRaw("ordered_at < started.occurred_at + INTERVAL '168 hours'");
        $starts = (clone $base)->where('event_name', 'play_started');
        $completed = [];
        foreach (DB::query()->fromSub($starts, 'started')->whereExists($ending)->select('is_test', 'drama_id', 'episode_id')
            ->selectRaw('COUNT(DISTINCT subject_id) AS people')->groupBy('is_test', 'drama_id', 'episode_id')->get() as $row) {
            $completed[$this->episodeKey($row)] = (int) $row->people;
        }
        $stats = (clone $base)->select('is_test', 'drama_id', 'episode_id')->selectRaw("COUNT(DISTINCT subject_id) FILTER (WHERE event_name='play_started') AS play_people,
            COALESCE(SUM((properties->>'watched_delta')::bigint),0) AS watched_seconds,
            COUNT(*) FILTER (WHERE event_name='player_error') AS play_errors,
            COUNT(*) FILTER (WHERE event_name='player_startup') AS startup_samples,
            AVG((properties->>'startup_ms')::numeric) FILTER (WHERE event_name='player_startup') AS startup_avg_ms,
            COUNT(*) FILTER (WHERE event_name='player_stall') AS stall_count,
            COALESCE(SUM((properties->>'stall_ms')::bigint) FILTER (WHERE event_name='player_stall'),0) AS stall_ms")
            ->groupBy('is_test', 'drama_id', 'episode_id')->get();
        $episodes = [];
        $dimensions = ['dramas' => [], 'episodes' => []];
        foreach ($stats as $row) {
            $key = $this->episodeKey($row);
            $label = $labels[$key];
            $people = (int) $row->play_people;
            $complete = $completed[$key] ?? 0;
            $episodes[] = ['drama_id' => $row->drama_id, 'episode_id' => $row->episode_id, 'drama_title' => $label->drama_title ?? '未知剧目',
                'episode_number' => $label->episode_number, 'episode_title' => $label->episode_title ?? '未知分集', 'is_test' => (bool) $row->is_test,
                'play_people' => $people, 'complete_people' => $complete, 'completion_rate' => $people > 0 ? round(100 * $complete / $people, 2) : null,
                'watched_seconds' => (int) $row->watched_seconds, 'play_errors' => (int) $row->play_errors, 'startup_samples' => (int) $row->startup_samples,
                'startup_avg_ms' => $row->startup_avg_ms === null ? null : round((float) $row->startup_avg_ms, 1), 'stall_count' => (int) $row->stall_count, 'stall_ms' => (int) $row->stall_ms];
            $dimensions['episodes'][$row->episode_id] = ($label->drama_title ?? '未知剧目').' · '.($label->episode_number === null ? '未知集号' : '第'.$label->episode_number.'集').' · '.($label->episode_title ?? '未知分集');
        }
        foreach ((clone $period)->whereNotNull('drama_id')->selectRaw('DISTINCT ON (drama_id) drama_id,drama_title')
            ->orderBy('drama_id')->orderBy('occurred_at')->orderBy('sequence')->get() as $row) {
            $dimensions['dramas'][$row->drama_id] = $row->drama_title ?? '未知剧目';
        }

        return [$episodes, $dimensions];
    }

    private function episodeKey(object $row): string
    {
        return ($row->is_test ? 'test:' : 'real:').($row->drama_id ?? '').':'.$row->episode_id;
    }

    private function cohorts($period, $authorized): array
    {
        $ranked = (clone $period)->whereIn('event_name', ['page_view', 'landing_view'])
            ->select('subject_id', 'is_test', 'occurred_at', 'ordered_at', 'sequence')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY subject_id ORDER BY ordered_at,sequence) AS position');
        $entrances = DB::query()->fromSub($ranked, 'ranked')->where('position', 1);
        $total = (clone $entrances)->count();
        $observing = (clone $entrances)->whereRaw("occurred_at + INTERVAL '168 hours' > ?", [Clock::stamp()])->count();
        // Only entrance people are capped, never heartbeat rows. Database stage lookups return at most one event per step.
        $entries = (clone $entrances)->orderBy('ordered_at')->orderBy('sequence')->limit(self::MAX_ENTRANCES)->get(['sequence', 'is_test']);
        $counts = [];
        foreach (['test', 'real'] as $environment) {
            $counts[$environment] = ['funnel' => array_fill_keys(self::CORE, 0), 'registration_funnel' => array_fill_keys(self::REGISTRATION, 0)];
        }
        foreach ($entries->chunk(200) as $batch) {
            $query = DB::table('acquisition_behaviors as entrance')->whereIn('entrance.sequence', $batch->pluck('sequence')->all())->select('entrance.is_test');
            foreach (['funnel' => self::CORE, 'registration_funnel' => self::REGISTRATION] as $kind => $steps) {
                $prior = 'entrance';
                foreach (array_slice($steps, 1) as $i => $step) {
                    $alias = ($kind === 'funnel' ? 'core' : 'registration').$i;
                    $stage = (clone $authorized)->select('ordered_at', 'sequence')->where('event_name', $step === 'paid_play' ? 'play_started' : $step)
                        ->whereColumn('subject_id', 'entrance.subject_id')->whereColumn('is_test', 'entrance.is_test')
                        ->whereColumn('ordered_at', '>=', 'entrance.ordered_at')->whereRaw("ordered_at < entrance.occurred_at + INTERVAL '168 hours'")
                        ->whereRaw('(ordered_at, sequence) > ('.$prior.'.ordered_at, '.$prior.'.sequence)')->orderBy('ordered_at')->orderBy('sequence')->limit(1);
                    if ($step === 'payment_confirmed') {
                        $stage->whereRaw("properties->>'transaction_kind' IS DISTINCT FROM 'renewal'");
                    }
                    $query->leftJoinLateral($stage, $alias)->addSelect($alias.'.sequence as '.$kind.'_'.$i);
                    $prior = $alias;
                }
            }
            foreach ($query->get() as $row) {
                $environment = $row->is_test ? 'test' : 'real';
                foreach (['funnel' => self::CORE, 'registration_funnel' => self::REGISTRATION] as $kind => $steps) {
                    $counts[$environment][$kind]['entry']++;
                    foreach (array_slice($steps, 1) as $i => $step) {
                        if ($row->{$kind.'_'.$i} !== null) {
                            $counts[$environment][$kind][$step]++;
                        }
                    }
                }
            }
        }
        $limited = $total > self::MAX_ENTRANCES;
        $segments = [];
        $sum = ['funnel' => array_fill_keys(self::CORE, 0), 'registration_funnel' => array_fill_keys(self::REGISTRATION, 0)];
        foreach ($counts as $environment => $kinds) {
            foreach ($kinds as $kind => $values) {
                $segments[$environment][$kind] = $this->rates($values, $limited);
                foreach ($values as $step => $value) {
                    $sum[$kind][$step] += $value;
                }
            }
        }

        return [$this->rates($sum['funnel'], $limited), $this->rates($sum['registration_funnel'], $limited), $segments, $total, $entries->count(), $observing];
    }

    private function rates(array $counts, bool $limited): array
    {
        $result = [];
        $previous = null;
        foreach ($counts as $step => $count) {
            $result[] = ['step' => $step, 'people' => $count, 'rate' => ! $limited && $previous !== null && $previous > 0 ? round(100 * $count / $previous, 2) : null];
            $previous = $count;
        }

        return $result;
    }
}
