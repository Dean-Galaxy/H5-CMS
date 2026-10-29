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
            $links = app(AssetAccess::class)->query('link', $staff)->pluck('id');
            $q->whereIn('link_id', $links);
            $delivery->whereIn('link_id', $links);
            $receipts->whereIn('link_id', $links);
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
        $selected = (clone $q)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $until)->limit(10001)->get(['id', 'subject_id']);
        $selectedIds = $selected->pluck('id')->flip();
        $allAuthorized->whereIn('subject_id', $selected->pluck('subject_id')->unique()->all());
        // Subsequent stages may occur seven days after the selected entrance date.
        $rows = $allAuthorized->where('ordered_at', '>=', $from)->where('ordered_at', '<', $until->addDays(7))->orderBy('ordered_at')->orderBy('sequence')->limit(10001)->get();
        $limited = $rows->count() > 10000 || $selectedIds->count() > 10000;
        $rows = $rows->take(10000);
        foreach ($rows as $row) {
            $row->p = json_decode($row->properties, true, flags: JSON_THROW_ON_ERROR);
        }
        $period = $rows->filter(fn ($e) => $selectedIds->has($e->id));
        $counts = $period->groupBy(fn ($e) => ($e->is_test ? '测试：' : '真实：').$e->event_name)->map(fn ($list) => ['events' => $list->count(), 'people' => $list->pluck('subject_id')->unique()->count()])->all();
        $cohorts = [];
        foreach ($rows->groupBy('subject_id') as $subject => $events) {
            $entry = $events->first(fn ($e) => in_array($e->event_name, ['page_view', 'landing_view'], true) && $selectedIds->has($e->id));
            if ($entry) {
                $cohorts[] = [$entry, $events->filter(fn ($e) => $e->is_test === $entry->is_test && CarbonImmutable::parse($e->ordered_at)->greaterThanOrEqualTo(CarbonImmutable::parse($entry->occurred_at)) && CarbonImmutable::parse($e->ordered_at)->lessThan(CarbonImmutable::parse($entry->occurred_at)->addDays(7)))];
            }
        }
        $finance = ['status' => 'unknown', 'gross_minor' => null, 'refund_minor' => null, 'net_minor' => null, 'kinds' => []];
        try {
            $ids = $period->where('event_name', 'payment_confirmed')->map(fn ($e) => $e->p['payment_id'] ?? null)->filter()->unique()->values();
            $facts = [];
            if ($ids->isEmpty()) {
                app(BillingAnalyticsReader::class)->payments([]);
            }
            foreach ($ids->chunk(200) as $chunk) {
                $facts += app(BillingAnalyticsReader::class)->payments($chunk->all());
            }
            if (count($facts) !== $ids->count()) {
                throw new \RuntimeException('Missing trusted payment facts');
            }
            $gross = 0;
            $refund = 0;
            $kinds = [];
            foreach ($facts as $fact) {
                if (! is_int($fact['amount_minor'] ?? null) || ! is_int($fact['refunded_amount_minor'] ?? null) || $fact['currency'] !== 'USD'
                    || $fact['amount_minor'] < 0 || $fact['refunded_amount_minor'] < 0 || $fact['refunded_amount_minor'] > $fact['amount_minor']) {
                    throw new \RuntimeException('Invalid financial facts');
                }
                $gross += $fact['amount_minor'];
                $refund += $fact['refunded_amount_minor'];
                $kind = ($fact['is_test'] ? 'test:' : 'real:').$fact['transaction_kind'];
                $kinds[$kind] ??= ['payments' => 0, 'gross_minor' => 0];
                $kinds[$kind]['payments']++;
                $kinds[$kind]['gross_minor'] += $fact['amount_minor'];
            }
            $finance = ['status' => $limited ? 'partial' : 'known', 'gross_minor' => $gross, 'refund_minor' => $refund, 'net_minor' => $gross - $refund, 'kinds' => $kinds];
        } catch (\Throwable) { /* Keep unknown, never invent zero from an unavailable reader. */
        }
        $diagnostics = ['enabled' => $enabled, 'transport' => config('acquisition.transport', 'disabled'),
            'last_received_at' => $scoped->max('received_at'),
            'consent_skipped' => $staff->role === Role::Owner ? (int) DB::table('acquisition_capture_diagnostics')->where('reason', 'consent_skipped')->value('count') : null,
            'capture_failures' => $staff->role === Role::Owner ? DB::table('acquisition_capture_diagnostics')->where('reason', '!=', 'consent_skipped')->get(['reason', 'count', 'last_at'])->toArray() : [],
            'deliveries' => $delivery->select('environment', 'state', 'last_error')->selectRaw('COUNT(*) AS count')->groupBy('environment', 'state', 'last_error')->get()->toArray(),
            'source_status' => $receipts->select('is_test', 'source_status')->selectRaw('COUNT(*) AS count')->groupBy('is_test', 'source_status')->get()->toArray(),
            'platform_confirmation' => 'Meta/TikTok真实平台均未核验；接口接受不等于平台归因或实际投放有效'];

        $episodes = [];
        $episodeEvents = $period->whereNotNull('episode_id')->groupBy(fn ($e) => ($e->is_test ? 'test:' : 'real:').($e->drama_id ?? 'unknown').':'.$e->episode_id);
        foreach ($episodeEvents as $key => $events) {
            $first = $events->first();
            $starts = $events->where('event_name', 'play_started');
            $completed = [];
            foreach ($starts as $start) {
                $end = $rows->first(fn ($e) => $e->event_name === 'episode_completed' && $e->subject_id === $start->subject_id && $e->episode_id === $start->episode_id
                    && ($e->p['play_instance_id'] ?? null) === ($start->p['play_instance_id'] ?? null) && CarbonImmutable::parse($e->occurred_at)->greaterThanOrEqualTo(CarbonImmutable::parse($start->occurred_at))
                    && CarbonImmutable::parse($e->occurred_at)->lessThan(CarbonImmutable::parse($start->occurred_at)->addDays(7)));
                if ($end) {
                    $completed[$start->subject_id] = true;
                }
            }
            $people = $starts->pluck('subject_id')->unique()->count();
            $startup = $events->where('event_name', 'player_startup');
            $episodes[] = ['drama_id' => $first->drama_id, 'episode_id' => $first->episode_id, 'drama_title' => $first->drama_title ?? '未知剧目',
                'episode_number' => $first->episode_number, 'episode_title' => $first->episode_title ?? '未知分集', 'is_test' => $first->is_test,
                'play_people' => $people, 'complete_people' => count($completed), 'completion_rate' => ! $limited && $people > 0 ? round(100 * count($completed) / $people, 2) : null,
                'watched_seconds' => $events->sum(fn ($e) => $e->p['watched_delta'] ?? 0), 'play_errors' => $events->where('event_name', 'player_error')->count(),
                'startup_samples' => $startup->count(), 'startup_avg_ms' => $startup->count() ? round($startup->avg(fn ($e) => $e->p['startup_ms'] ?? 0), 1) : null,
                'stall_count' => $events->where('event_name', 'player_stall')->count(), 'stall_ms' => $events->where('event_name', 'player_stall')->sum(fn ($e) => $e->p['stall_ms'] ?? 0)];
        }
        $dimensions = ['dramas' => [], 'episodes' => []];
        foreach ($rows as $e) {
            if ($e->drama_id) {
                $dimensions['dramas'][$e->drama_id] = $e->drama_title ?? '未知剧目';
            }
            if ($e->episode_id) {
                $dimensions['episodes'][$e->episode_id] = ($e->drama_title ?? '未知剧目').' · '.($e->episode_number === null ? '未知集号' : '第'.$e->episode_number.'集').' · '.($e->episode_title ?? '未知分集');
            }
        }
        $segments = [];
        foreach (['test' => true, 'real' => false] as $label => $isTest) {
            $group = array_values(array_filter($cohorts, fn ($c) => $c[0]->is_test === $isTest));
            $segments[$label] = ['funnel' => $this->funnel($group, ['entry', 'play_started', 'checkout_created', 'payment_confirmed', 'paid_play'], $limited),
                'registration_funnel' => $this->funnel($group, ['entry', 'play_started', 'paywall_view', 'registration_succeeded', 'email_verified', 'checkout_created', 'payment_confirmed', 'paid_play'], $limited)];
        }

        return ['episodes' => $episodes, 'dimensions' => $dimensions, 'segments' => $segments, 'enabled' => $enabled, 'limited' => $limited, 'from' => $from->toDateString(), 'until' => $until->subDay()->toDateString(),
            'definition' => '仅已同意样本；伪名主体不跨设备合并。入口日期UTC，观察窗7天；各环节独立计数不等于漏斗。毛收按付款日期队列、退款为当前已确认累计，不是利润或ROAS。',
            'counts' => $enabled ? $counts : null, 'visitors' => $enabled ? $period->whereIn('event_name', ['page_view', 'landing_view'])->pluck('subject_id')->unique()->count() : null,
            'sessions' => $enabled ? $period->pluck('session_key')->unique()->count() : null,
            'watched_seconds' => $enabled ? $period->sum(fn ($e) => $e->p['watched_delta'] ?? 0) : null,
            'errors' => $period->filter(fn ($e) => in_array($e->event_name, ['player_error', 'checkout_failed', 'payment_failed', 'payment_pending', 'payment_cancelled'], true))
                ->groupBy(fn ($e) => $e->event_name.':'.($e->p['error_code'] ?? 'unknown'))->map->count()->all(),
            'funnel' => $this->funnel($cohorts, ['entry', 'play_started', 'checkout_created', 'payment_confirmed', 'paid_play'], $limited),
            'registration_funnel' => $this->funnel($cohorts, ['entry', 'play_started', 'paywall_view', 'registration_succeeded', 'email_verified', 'checkout_created', 'payment_confirmed', 'paid_play'], $limited),
            'observing_people' => count(array_filter($cohorts, fn ($c) => CarbonImmutable::parse($c[0]->occurred_at)->addDays(7)->greaterThan(now()))),
            'finance' => $enabled ? $finance : ['status' => 'disabled', 'gross_minor' => null, 'refund_minor' => null, 'net_minor' => null, 'kinds' => []], 'diagnostics' => $diagnostics];
    }

    private function funnel(array $cohorts, array $steps, bool $limited): array
    {
        $counts = array_fill_keys($steps, 0);
        foreach ($cohorts as [$entry, $events]) {
            $counts['entry']++;
            $after = CarbonImmutable::parse($entry->occurred_at);
            $afterSequence = $entry->sequence;
            foreach (array_slice($steps, 1) as $step) {
                $event = $events->first(function ($e) use ($step, $after, $afterSequence) {
                    if (CarbonImmutable::parse($e->ordered_at)->lessThan($after) || (CarbonImmutable::parse($e->ordered_at)->equalTo($after) && $e->sequence <= $afterSequence)) {
                        return false;
                    }
                    if ($step === 'paid_play') {
                        return $e->event_name === 'play_started';
                    }

                    return $e->event_name === $step && ($step !== 'payment_confirmed' || ($e->p['transaction_kind'] ?? '') !== 'renewal');
                });
                if (! $event) {
                    break;
                }
                $counts[$step]++;
                $after = CarbonImmutable::parse($event->ordered_at);
                $afterSequence = $event->sequence;
            }
        }
        $result = [];
        $previous = null;
        foreach ($counts as $step => $count) {
            $result[] = ['step' => $step, 'people' => $count, 'rate' => ! $limited && $previous !== null && $previous > 0 ? round(100 * $count / $previous, 2) : null];
            $previous = $count;
        }

        return $result;
    }
}
