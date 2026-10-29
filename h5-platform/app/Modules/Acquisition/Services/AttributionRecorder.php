<?php

namespace App\Modules\Acquisition\Services;

use App\Contracts\AttributionService;
use App\Contracts\CatalogReader;
use App\Data\AttributionSnapshot;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Analytics\ClickContext;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AttributionRecorder implements AttributionService
{
    public function enabled(): bool
    {
        return app(TrackingContext::class)->enabled();
    }

    public function eligibleRequest(): bool
    {
        $r = request();

        return ! preg_match('/prefetch|prerender/i', $r->header('Purpose', '').' '.$r->header('Sec-Purpose', ''))
            && ! preg_match('/bot|crawler|spider|headless|preview/i', $r->userAgent() ?? '');
    }

    public function prepare(string $code): string
    {
        $link = DB::table('acquisition_links')->where('code', $code)->first();
        $this->validLink($link);
        if ($this->eligibleRequest()) {
            app(TrackingContext::class)->session()?->put('tracking.pending', [
                'link_id' => $link->id, 'key' => (string) Str::uuid(), 'created_at' => now()->toIso8601String(),
            ]);
        }

        $query = app(ClickContext::class)->forward($link->platform, request()->query());

        return '/dramas/'.$link->drama_id.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    private function validLink(?object $link): void
    {
        if (! $link || ! $link->active) {
            throw new ApiProblem('NOT_FOUND', 404, '推广链接已停用或不可用');
        }
        $pixel = DB::table('acquisition_pixels')->where('id', $link->pixel_id)->first();
        if (! $pixel || ! $pixel->active || $pixel->platform !== $link->platform) {
            throw new ApiProblem('NOT_FOUND', 404, '推广资产不可用');
        }
        // The staff-only promotable picker must never be queried by an anonymous landing request.
        app(CatalogReader::class)->drama($link->drama_id, 'zh_CN');
    }

    public function confirm(string $dramaId, ?string $pageInstanceId = null, array $clickContext = []): bool
    {
        $c = app(TrackingContext::class);
        $c->syncViewer();
        $pending = $c->session()?->get('tracking.pending');
        $privacy = $c->privacy();
        if (! $this->enabled() || ! $this->eligibleRequest() || ! $privacy || $privacy->advertising !== 'granted') {
            return false;
        }
        if (! $pending) {
            $last = $c->session()?->get('tracking.confirmed');

            return $pageInstanceId !== null && $last && $last['page'] === $pageInstanceId && $last['drama'] === $dramaId && $this->snapshot(null)?->source_id === $last['source'];
        }
        if (CarbonImmutable::parse($pending['created_at'])->addDay()->lessThanOrEqualTo(now())) {
            $c->session()->forget('tracking.pending');

            return false;
        }

        return DB::transaction(function () use ($c, $pending, $privacy, $dramaId, $pageInstanceId, $clickContext) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.landing:'.$pending['key']]);
            $privacy = DB::table('acquisition_privacy')->where('id', $privacy->id)->lockForUpdate()->first();
            if (! $privacy || $privacy->advertising !== 'granted') {
                return false;
            }
            $link = DB::table('acquisition_links')->where('id', $pending['link_id'])->lockForUpdate()->first();
            $this->validLink($link);
            if ($link->drama_id !== $dramaId) {
                return false;
            }
            app(CatalogReader::class)->drama($dramaId, 'zh_CN');
            $row = DB::table('acquisition_sources')->where('landing_key', $pending['key'])->first();
            if (! $row) {
                $pixel = DB::table('acquisition_pixels')->where('id', $link->pixel_id)->lockForUpdate()->first();
                $id = (string) Str::uuid();
                $subject = $c->subject();
                $matching = app(ClickContext::class)->forPlatform($link->platform, $clickContext);
                if (! isset($matching[$link->platform === 'meta' ? 'fbc' : 'ttclid'])) {
                    app(BehaviorRecorder::class)->diagnostic('click_match_missing');
                }
                DB::table('acquisition_sources')->insert(['id' => $id, 'link_id' => $link->id, 'pixel_id' => $pixel->id,
                    'pixel_version' => $pixel->version, 'channel' => $link->platform, 'consent' => 'granted', 'policy_version' => 'analytics-v1',
                    'captured_at' => Clock::stamp(), 'expires_at' => Clock::stamp(now()->addDays(7)), 'is_simulation' => false, 'privacy_id' => $privacy->id,
                    'subject_id' => $subject?->id, 'landing_key' => $pending['key'],
                    'context_encrypted' => Crypt::encryptString(json_encode(['page_url' => rtrim(config('app.url'), '/').'/dramas/'.$dramaId,
                        'ip' => request()->ip(), 'user_agent' => mb_substr(request()->userAgent() ?? '', 0, 512)] + $matching, JSON_THROW_ON_ERROR))]);
                $row = DB::table('acquisition_sources')->where('id', $id)->first();
            }
            $c->session()->put('tracking.source', $row->id);
            DB::table('acquisition_privacy')->where('id', $privacy->id)->update(['active_source' => $row->id]);
            $c->rememberConsent($privacy->id);
            $c->session()->put('tracking.confirmed', ['page' => $pageInstanceId, 'drama' => $dramaId, 'source' => $row->id]);
            $c->session()->forget('tracking.pending');

            return true;
        });
    }

    public function snapshot(?string $untrustedMarker): ?AttributionSnapshot
    {
        if (! $this->enabled() || $untrustedMarker !== null) {
            return null;
        }
        $c = app(TrackingContext::class);
        $c->syncViewer();
        $p = $c->privacy();
        $id = $c->session()?->get('tracking.source');
        if (! $p || $p->advertising !== 'granted' || ! $id) {
            return null;
        }
        $row = DB::table('acquisition_sources')->where('id', $id)->where('privacy_id', $p->id)->whereNull('revoked_at')->first();
        if (! $row || $row->consent !== 'granted' || CarbonImmutable::parse($row->captured_at)->greaterThan(now())
            || CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(now())) {
            return null;
        }

        return new AttributionSnapshot($row->id, $row->link_id, $row->channel, $row->pixel_version,
            CarbonImmutable::parse($row->captured_at)->utc()->format('Y-m-d\TH:i:s.u\Z'), 'granted');
    }
}
