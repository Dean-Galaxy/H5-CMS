<?php

namespace App\Modules\Content\Services;

use App\Contracts\IdentityContext;
use App\Contracts\MembershipReader;
use App\Contracts\PlaybackAuthorizer;
use App\Data\PlaybackGrant;
use App\Data\ViewerIdentity;
use App\Enums\PlaybackReason;
use App\Enums\SubscriptionState;
use App\Modules\Content\Models\EpisodeRecord;
use App\Modules\Content\Models\Grant;
use App\Modules\Content\Models\PlaySession;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

final class PlaybackService implements PlaybackAuthorizer
{
    public function __construct(private Availability $availability, private MembershipReader $memberships, private MediaStore $media) {}

    public function authorizePlayback(?ViewerIdentity $viewer, string $episodeId, string $region, ?string $playSessionId = null): PlaybackGrant
    {
        ContentGuard::validate(['episode' => $episodeId, 'session' => $playSessionId], ['episode' => 'required|uuid', 'session' => 'nullable|uuid']);

        return ContentGuard::mutate(function () use ($viewer, $episodeId, $region, $playSessionId) {
            if ($region === 'ZZ' || ! preg_match('/^[A-Z]{2}$/D', $region)) {
                return $this->denied($episodeId, PlaybackReason::RegionBlocked);
            }
            $ep = EpisodeRecord::with(['drama', 'video', 'subtitle'])->find($episodeId);
            $session = $playSessionId ? PlaySession::find($playSessionId) : null;
            if ($playSessionId) {
                $this->assertSession($session, $episodeId, $viewer, $ep);
            }
            $reason = $this->restriction($ep, $region);
            if ($reason) {
                return $this->denied($episodeId, $reason);
            }
            [$basis, $memberUntil] = $this->entitlement($ep, $session, $viewer);
            if ($basis === null) {
                return $this->denied($episodeId, PlaybackReason::MembershipRequired);
            }
            if (! $session) {
                $session = PlaySession::create(['episode_id' => $episodeId, 'viewer_id' => $viewer?->id, 'browser_hash' => $this->browserHash(),
                    'region' => $region, 'basis' => $basis, 'access_version' => $ep->drama->access_version,
                    'started_at' => null, 'until' => now()->addSeconds(120)]);
            } elseif (! $session->started_at) {
                $session->update(['basis' => $basis]);
            }
            $expires = $this->earliest(now()->addSeconds(120), $session->until, $ep->drama->license_until, $memberUntil);
            $grant = Grant::create(['session_id' => $session->id, 'asset_id' => $ep->video_asset_id, 'expires_at' => $expires]);

            return new PlaybackGrant($grant->id, $episodeId, true, $basis === 'free' ? PlaybackReason::AllowedFree : PlaybackReason::AllowedMember,
                $this->url($grant, 'master.m3u8'), $expires->toIso8601ZuluString(), $session->id);
        });
    }

    /** Called on EVERY protected manifest, segment, subtitle and Range/HEAD request. */
    public function readGrant(string $id, string $region, bool $startsPlayback = false): Grant
    {
        return ContentGuard::mutate(function () use ($id, $region, $startsPlayback) {
            $grant = Grant::with(['session.episode.drama', 'session.episode.video', 'session.episode.subtitle', 'asset'])->find($id);
            if (! $grant) {
                throw new ApiProblem('NOT_FOUND', 404, '播放许可不存在');
            }
            $ep = $grant->session->episode;
            $viewer = app(IdentityContext::class)->viewer();
            $session = $grant->session;
            $this->assertSession($session, $ep->id, $viewer, $ep);
            if ($grant->expires_at <= now()) {
                throw new ApiProblem('NOT_AUTHORIZED', 403, '播放许可已过期');
            }
            if ($grant->asset_id !== $ep->video_asset_id) {
                throw new ApiProblem('STALE_VERSION', 409, '媒体已变更，请重新开始播放');
            }
            if ($reason = $this->restriction($ep, $region)) {
                throw new ApiProblem($reason->value, 403, '当前内容不能继续读取');
            }
            if ($session->region !== $region) {
                throw new ApiProblem('REGION_BLOCKED', 403, '播放地区已变化，请重新申请');
            }
            [$basis] = $this->entitlement($ep, $session, $viewer);
            if ($basis === null) {
                throw new ApiProblem('MEMBERSHIP_REQUIRED', 403, '当前集需要有效会员');
            }
            if (! $session->started_at && $startsPlayback) {
                $session->update(['basis' => $basis, 'started_at' => now(), 'until' => now()->addSeconds(2 * $ep->video->metadata['duration_seconds'] + 300)]);
            }

            return $grant;
        });
    }

    private function assertSession(?PlaySession $s, string $episode, ?ViewerIdentity $viewer, ?EpisodeRecord $ep): void
    {
        if (! $s || $s->episode_id !== $episode || $s->viewer_id !== $viewer?->id || ! hash_equals($s->browser_hash, $this->browserHash())) {
            throw new ApiProblem('NOT_FOUND', 404, '播放会话不存在');
        }
        if (! $ep || $s->until <= now() || $s->access_version !== $ep->drama->access_version) {
            throw new ApiProblem('STALE_VERSION', 409, '播放会话已失效，请按当前权限重新开始');
        }
    }

    private function restriction(?EpisodeRecord $ep, string $region): ?PlaybackReason
    {
        if (! $ep || ! $ep->published_at || $ep->drama->state !== 'published') {
            return PlaybackReason::Unpublished;
        }
        if (! $this->availability->licensed($ep->drama, $region)) {
            return PlaybackReason::RegionBlocked;
        }
        if (! $ep->video || $ep->video->state !== 'ready' || ! $ep->video->output_key || ! is_file($this->media->path($ep->video->output_key.'/media.mp4'))) {
            return PlaybackReason::MediaNotReady;
        }
        if ($ep->subtitle_asset_id && (! $ep->subtitle || $ep->subtitle->state !== 'ready')) {
            return PlaybackReason::MediaNotReady;
        }

        return null;
    }

    private function entitlement(EpisodeRecord $ep, ?PlaySession $session, ?ViewerIdentity $viewer): array
    {
        // A grant or manifest alone is not playback. Preserve N only after actual video bytes started.
        if (($session?->started_at && $session->basis === 'free') || $ep->number <= $ep->drama->free_count) {
            return ['free', null];
        }
        if (! $viewer) {
            return [null, null];
        }
        $m = $this->memberships->membership($viewer->id, now()->toDateTimeImmutable());
        if ($m->subscription_state === SubscriptionState::Unknown) {
            throw new ApiProblem('BILLING_PENDING', 409, '会员状态待核实');
        }
        if (! $m->access_active || ! $m->access_from || ! $m->access_until) {
            return [null, null];
        }
        $from = CarbonImmutable::parse($m->access_from);
        $until = CarbonImmutable::parse($m->access_until);

        return $from <= now() && now() < $until ? ['member', $until] : [null, null];
    }

    private function denied(string $id, PlaybackReason $reason): PlaybackGrant
    {
        return new PlaybackGrant(null, $id, false, $reason, null, null, null);
    }

    private function browserHash(): string
    {
        if (! request()->hasSession()) {
            throw new ApiProblem('AUTHENTICATION_REQUIRED', 401, '播放需要当前浏览器会话');
        }

        return hash_hmac('sha256', request()->session()->getId(), config('app.key'));
    }

    private function earliest(?CarbonImmutable ...$dates): CarbonImmutable
    {
        return collect($dates)->filter()->sort()->first();
    }

    public function url(Grant $grant, string $file): string
    {
        return URL::temporarySignedRoute('content.media', $grant->expires_at, ['id' => $grant->id, 'file' => $file]);
    }
}
