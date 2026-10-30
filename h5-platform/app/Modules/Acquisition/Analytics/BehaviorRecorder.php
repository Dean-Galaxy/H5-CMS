<?php

namespace App\Modules\Acquisition\Analytics;

use App\Contracts\BehaviorEventRecorder;
use App\Contracts\BillingAnalyticsReader;
use App\Contracts\CatalogReader;
use App\Contracts\RegionResolver;
use App\Enums\BehaviorRecordingResult as Result;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class BehaviorRecorder implements BehaviorEventRecorder
{
    public const FINANCIAL = ['payment_confirmed', 'refund_confirmed', 'subscription_cancelled', 'subscription_resumed'];

    public function record(string $eventName, array $properties = [], ?string $deduplicationKey = null): bool
    {
        return $this->recordFact($eventName, $properties, $deduplicationKey ?? (string) Str::uuid()) === Result::Accepted;
    }

    public function recordFact(string $eventName, array $properties, string $deduplicationKey): Result
    {
        if (! app(TrackingContext::class)->enabled()) {
            return Result::Retry;
        }
        if ($deduplicationKey === '' || strlen($deduplicationKey) > 256) {
            return Result::Skipped;
        }
        try {
            // A savepoint prevents a failed capture from poisoning a surrounding business transaction.
            return DB::transaction(function () use ($eventName, $properties, $deduplicationKey) {
                $p = app(EventProperties::class)->validate($eventName, $properties, false);
                if ($p === null) {
                    $this->diagnostic('invalid_server_event');

                    return Result::Skipped;
                }
                $time = isset($p['occurred_at']) ? CarbonImmutable::parse($p['occurred_at']) : now();
                if ($time->greaterThan(now()->addMinute())) {
                    return Result::Skipped;
                }
                $financial = in_array($eventName, self::FINANCIAL, true);
                $sourceId = null;
                if ($financial) {
                    [$subject, $p, $sourceId] = $this->financialContext($eventName, $p);
                    $time = isset($p['occurred_at']) ? CarbonImmutable::parse($p['occurred_at']) : $time;
                } else {
                    $subject = app(TrackingContext::class)->subject($p['viewer_id'] ?? null);
                    $sourceId = app(AttributionRecorder::class)->snapshot(null)?->source_id;
                }
                if (! $subject) {
                    return Result::Skipped;
                }
                $privacy = DB::table('acquisition_privacy')->where('id', $subject->privacy_id)->first();
                $consentTime = $this->orderingTime($eventName, $p, $subject, $time);
                // Late consent does not authorize retrospective capture. Withdrawal wins over snapshots.
                if (! $privacy || $privacy->analytics !== 'granted' || ! $privacy->analytics_granted_at
                    || CarbonImmutable::parse($privacy->analytics_granted_at)->greaterThan($consentTime)
                    || CarbonImmutable::parse($subject->created_at)->lessThan(CarbonImmutable::parse($privacy->analytics_granted_at))) {
                    return Result::Skipped;
                }
                if ($eventName === 'refund_confirmed') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.refund:'.$p['payment_id']]);
                    $version = DB::table('acquisition_refund_versions')->where('payment_id', $p['payment_id'])->first();
                    if ($version && $version->fact_version > $p['fact_version']) {
                        return Result::Skipped;
                    }
                    if ($version && $version->fact_version === $p['fact_version']) {
                        return (int) $version->refunded_amount_minor === $p['refunded_amount_minor'] ? Result::Accepted : Result::Skipped;
                    }
                    DB::table('acquisition_refund_versions')->updateOrInsert(['payment_id' => $p['payment_id']], ['fact_version' => $p['fact_version'], 'refunded_amount_minor' => $p['refunded_amount_minor']]);
                }
                $key = hash('sha256', $eventName.':'.$deduplicationKey);
                $result = $this->insert($eventName, $p, $subject, $sourceId, $key, null, $time, 'server');
                if ($result === Result::Accepted && $eventName === 'checkout_created' && isset($p['order_id']) && $subject->viewer_id) {
                    DB::table('acquisition_analytics_orders')->insertOrIgnore(['order_id' => $p['order_id'], 'subject_id' => $subject->id,
                        'privacy_id' => $subject->privacy_id, 'viewer_id' => $subject->viewer_id, 'source_id' => $sourceId,
                        'subscription_id' => $p['subscription_id'] ?? null, 'checkout_at' => Clock::stamp($time), 'created_at' => Clock::stamp($time)]);
                }

                return $result;
            });
        } catch (\InvalidArgumentException|InvalidFormatException $e) {
            $this->diagnostic('invalid_server_event');

            return Result::Skipped;
        } catch (Throwable) {
            $this->diagnostic('capture_failed');

            return Result::Retry;
        }
    }

    /** Returns Accepted for an identical retry, Skipped for invalid or unconsented input. */
    public function browser(string $id, string $name, array $properties): Result
    {
        if (! app(TrackingContext::class)->enabled()) {
            return Result::Skipped;
        }
        try {
            return DB::transaction(function () use ($id, $name, $properties) {
                $p = app(EventProperties::class)->validate($name, $properties, true);
                if ($p === null || ! Str::isUuid($id)) {
                    $this->diagnostic('invalid_browser_event');

                    return Result::Skipped;
                }
                $c = app(TrackingContext::class);
                $c->syncViewer();
                // Only the dedicated landing endpoint may create a source with click context.
                $landing = false;
                if ($c->session()?->has('tracking.pending') && in_array($name, ['page_view', 'landing_view', 'drama_view'], true) && $c->privacy()?->advertising === 'granted') {
                    $this->diagnostic('landing_confirmation_pending');

                    return Result::Retry;
                }
                $subject = $c->subject();
                if (! $subject) {
                    if (! $landing) {
                        $this->diagnostic('consent_skipped');
                    }

                    return $landing ? Result::Accepted : Result::Skipped;
                }
                $source = app(AttributionRecorder::class)->snapshot(null);
                $semantic = match ($name) {
                    'page_view', 'landing_view', 'drama_view' => $name.':'.$subject->id.':'.$p['page_instance_id'],
                    'play_started', 'episode_completed' => $name.':'.$subject->id.':'.$p['play_instance_id'],
                    default => 'browser:'.$id,
                };

                return $this->insert($name, $p, $subject, $source?->source_id, hash('sha256', $semantic), $id, now(), 'browser');
            });
        } catch (ApiProblem $e) {
            $this->diagnostic($e->status >= 500 ? 'catalog_unavailable' : 'invalid_browser_reference');

            return $e->status >= 500 ? Result::Retry : Result::Skipped;
        } catch (Throwable) {
            $this->diagnostic('capture_failed');

            return Result::Retry;
        }
    }

    private function financialContext(string $name, array $p): array
    {
        $row = null;
        if (isset($p['payment_id'])) {
            $rows = app(BillingAnalyticsReader::class)->payments([$p['payment_id']]);
            $row = $rows[$p['payment_id']] ?? null;
            if (! $row) {
                throw new \RuntimeException('TRUSTED_PAYMENT_NOT_AVAILABLE');
            }
            foreach (['viewer_id', 'order_id', 'subscription_id', 'amount_minor', 'currency', 'is_test', 'transaction_kind'] as $key) {
                $p[$key] = $row[$key];
            }
            if ($name === 'payment_confirmed') {
                $p['occurred_at'] = $row['paid_at'];
            }
            if ($name === 'refund_confirmed' && (! isset($p['fact_version'], $p['refunded_amount_minor']) || $p['refunded_amount_minor'] > $row['amount_minor'])) {
                return [null, $p, null];
            }
        }
        $mapping = isset($p['order_id']) ? DB::table('acquisition_analytics_orders')->where('order_id', $p['order_id'])->first() : null;
        if (! $mapping && isset($p['subscription_id'])) {
            $mapping = DB::table('acquisition_analytics_orders')->where('subscription_id', $p['subscription_id'])->orderByDesc('created_at')->first();
        }
        $snapshot = $row['attribution_snapshot'] ?? null;
        $sourceId = $mapping?->source_id ?? ($snapshot['source_id'] ?? null);
        $source = $sourceId ? DB::table('acquisition_sources')->where('id', $sourceId)->first() : null;
        $subjectId = $mapping?->subject_id ?? $source?->subject_id;
        $subject = $subjectId ? DB::table('acquisition_subjects')->where('id', $subjectId)->first() : null;
        if ($subject && isset($p['viewer_id']) && $subject->viewer_id !== $p['viewer_id']) {
            return [null, $p, null];
        }
        if ($subject && isset($p['order_id'], $p['subscription_id'])) {
            DB::table('acquisition_analytics_orders')->insertOrIgnore(['order_id' => $p['order_id'], 'subject_id' => $subject->id,
                'privacy_id' => $subject->privacy_id, 'viewer_id' => $subject->viewer_id, 'source_id' => $sourceId,
                'subscription_id' => $p['subscription_id'], 'created_at' => Clock::stamp()]);
            DB::table('acquisition_analytics_orders')->where('order_id', $p['order_id'])->update(['subscription_id' => $p['subscription_id']]);
        }
        ksort($p);

        return [$subject, $p, $sourceId];
    }

    private function insert(string $name, array $p, object $subject, ?string $sourceId, string $key, ?string $id, $time, string $origin): Result
    {
        $fingerprint = hash('sha256', json_encode([$name, $p], JSON_THROW_ON_ERROR));
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.event:'.($id ?? $key)]);
        if ($id) {
            $old = DB::table('acquisition_event_keys')->where('id', $id)->first();
            if ($old) {
                $same = $old->subject_id === $subject->id && hash_equals($old->fingerprint, $fingerprint);
                if (! $same) {
                    $this->diagnostic('event_id_conflict');
                }

                return $same ? Result::Accepted : Result::Skipped;
            }
        }
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.semantic:'.$key]);
        $old = DB::table('acquisition_behaviors')->where('dedup_key', $key)->first();
        if ($old) {
            if ($id) {
                DB::table('acquisition_event_keys')->insert(['id' => $id, 'subject_id' => $subject->id, 'fingerprint' => $fingerprint, 'created_at' => Clock::stamp()]);
            }

            return $origin === 'browser' || ($old->subject_id === $subject->id && hash_equals($old->fingerprint, $fingerprint)) ? Result::Accepted : Result::Skipped;
        }
        if (isset($p['play_instance_id'], $p['episode_id'])) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.play:'.$subject->id.':'.$p['play_instance_id']]);
            $q = DB::table('acquisition_play_states')->where('subject_id', $subject->id)->where('play_instance_id', $p['play_instance_id']);
            $state = $q->first();
            if ($state && $state->episode_id !== $p['episode_id']) {
                $this->diagnostic('play_reference_conflict');

                return Result::Skipped;
            }
            if (! $state) {
                DB::table('acquisition_play_states')->insert(['subject_id' => $subject->id, 'play_instance_id' => $p['play_instance_id'], 'episode_id' => $p['episode_id'], 'created_at' => Clock::stamp(), 'updated_at' => Clock::stamp()]);
                $state = $q->first();
            }
            if ($name === 'episode_completed') {
                $drama = app(CatalogReader::class)->drama($p['drama_id'], $p['locale'] ?? 'zh_CN');
                $episode = collect($drama->episodes)->first(fn ($e) => $e->id === $p['episode_id']);
                $elapsed = max(0, now()->diffInSeconds(CarbonImmutable::parse($state->created_at), true));
                $observed = min((int) floor($p['watched_seconds'] ?? 0), (int) $elapsed + 5);
                if (! $state->started || ! $episode || $episode->duration_seconds <= 0 || max($state->watched_seconds, $observed) < $episode->duration_seconds * 0.9) {
                    $this->diagnostic('play_order_invalid');

                    return Result::Skipped;
                }
            }
            if ($name === 'play_started') {
                $q->update(['started' => true, 'updated_at' => Clock::stamp()]);
            }
            if ($name === 'episode_completed') {
                $q->update(['completed' => true, 'updated_at' => Clock::stamp()]);
            }
            if (isset($p['watched_seconds'])) {
                // Position and seeks never contribute to watched time. Cumulative progress is monotonic.
                $elapsed = max(0, now()->diffInSeconds(CarbonImmutable::parse($state->created_at), true));
                $watched = min((int) floor($p['watched_seconds']), (int) $elapsed + 5);
                $p['watched_delta'] = max(0, $watched - $state->watched_seconds);
                $q->update(['watched_seconds' => max($state->watched_seconds, $watched), 'updated_at' => Clock::stamp()]);
            }
        }
        $source = $sourceId ? DB::table('acquisition_sources')->where('id', $sourceId)->first() : null;
        $region = app(RegionResolver::class)->resolve();
        $ua = request()->userAgent() ?? '';
        $device = preg_match('/ipad|tablet/i', $ua) ? 'tablet' : (preg_match('/mobile|iphone|android/i', $ua) ? 'mobile' : ($ua !== '' ? 'desktop' : 'unknown'));
        $inherited = $origin === 'server' ? DB::table('acquisition_behaviors')->where('subject_id', $subject->id)->where('origin', 'browser')->where('occurred_at', '<=', Clock::stamp($this->orderingTime($name, $p, $subject, $time)))->orderByDesc('occurred_at')->first() : null;
        $dramaId = $p['drama_id'] ?? ($source ? DB::table('acquisition_links')->where('id', $source->link_id)->value('drama_id') : null);
        $dramaTitle = null;
        $episodeNumber = null;
        $episodeTitle = null;
        if ($dramaId) {
            try {
                $drama = app(CatalogReader::class)->drama($dramaId, $p['locale'] ?? 'zh_CN');
                $dramaTitle = mb_substr($drama->title, 0, 200);
                $episode = collect($drama->episodes)->first(fn ($e) => $e->id === ($p['episode_id'] ?? null));
                if ($episode) {
                    $episodeNumber = $episode->number;
                    $episodeTitle = mb_substr($episode->title, 0, 200);
                }
            } catch (Throwable) { /* A later withdrawal must not prevent verified financial capture. Labels remain unknown. */
            }
        }
        $orderedAt = $this->orderingTime($name, $p, $subject, $time);
        if ($id) {
            DB::table('acquisition_event_keys')->insert(['id' => $id, 'subject_id' => $subject->id, 'fingerprint' => $fingerprint, 'created_at' => Clock::stamp()]);
        }
        DB::table('acquisition_behaviors')->insert(['id' => $id ?? (string) Str::uuid(), 'dedup_key' => $key, 'fingerprint' => $fingerprint,
            'event_name' => $name, 'origin' => $origin, 'subject_id' => $subject->id, 'privacy_id' => $subject->privacy_id, 'session_key' => $subject->session_key,
            'viewer_id' => $subject->viewer_id, 'source_id' => $source?->id, 'link_id' => $source?->link_id,
            'drama_id' => $dramaId, 'drama_title' => $dramaTitle, 'episode_number' => $episodeNumber, 'episode_title' => $episodeTitle,
            'episode_id' => $p['episode_id'] ?? null, 'channel' => $source?->channel ?? 'unknown', 'device' => $origin === 'browser' ? $device : ($inherited?->device ?? 'unknown'),
            'country' => $inherited?->country ?? ($region->country_code === 'ZZ' ? 'unknown' : $region->country_code), 'locale' => $p['locale'] ?? (app()->getLocale() === 'zh_CN' ? 'zh_CN' : 'en'),
            'is_test' => $p['is_test'] ?? app()->environment('local', 'testing'), 'policy_version' => 'analytics-v1',
            'properties' => json_encode($p, JSON_THROW_ON_ERROR), 'occurred_at' => Clock::stamp($time), 'ordered_at' => Clock::stamp($orderedAt), 'received_at' => Clock::stamp()]);

        return Result::Accepted;
    }

    private function orderingTime(string $name, array $p, object $subject, $time): CarbonImmutable
    {
        $orderedAt = CarbonImmutable::parse($time);
        if ($name === 'payment_confirmed' && isset($p['order_id'])) {
            $checkout = DB::table('acquisition_analytics_orders')->where('order_id', $p['order_id'])->where('subject_id', $subject->id)->value('checkout_at');
            if ($checkout) {
                $checkoutTime = CarbonImmutable::parse($checkout);
                $paidTime = CarbonImmutable::parse($time);
                // Provider timestamps may be truncated to seconds. Only a matching order in that exact second may supply the causal lower bound.
                if ($checkoutTime->format('Y-m-d H:i:s') === $paidTime->format('Y-m-d H:i:s') && $checkoutTime->greaterThan($paidTime)) {
                    $orderedAt = $checkoutTime;
                }
            }
        }

        return CarbonImmutable::parse($orderedAt);
    }

    public function diagnostic(string $reason): void
    {
        try {
            DB::transaction(fn () => DB::statement('INSERT INTO acquisition_capture_diagnostics(reason,count,last_at) VALUES (?,1,?) ON CONFLICT(reason) DO UPDATE SET count=acquisition_capture_diagnostics.count+1,last_at=EXCLUDED.last_at', [$reason, Clock::stamp()]));
        } catch (Throwable) {
            Log::warning('acquisition.capture_failed', ['reason' => 'storage_unavailable']);
        }
    }

    public function clearBrowserContext(): void
    {
        try {
            app(\App\Modules\Acquisition\Measurement\CoreContext::class)->end();
        } catch (Throwable) {
            // C is independent: its storage failure must not prevent legacy logout/context clearing.
            if (request()->hasSession()) {
                request()->session()->forget(['measurement_c.subject', 'measurement_c.session']);
            }
        }
        try {
            DB::transaction(fn () => app(TrackingContext::class)->clear());
        } catch (Throwable) {
            $this->diagnostic('context_clear_failed');
            $this->sessionForget();
        }
    }

    private function sessionForget(): void
    {
        app(TrackingContext::class)->session()?->forget('tracking');
    }
}
