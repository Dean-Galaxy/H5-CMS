<?php

namespace App\Modules\Audience\Progress;

use App\Contracts\CatalogReader;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\ProgressService;
use App\Data\Episode;
use App\Data\PlaybackGrant;
use App\Data\Progress;
use App\Data\ProgressInput;
use App\Data\ViewerIdentity;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Progress is an account bookmark, never evidence of membership or a billable/ad view. */
final class ProgressRecorder implements ProgressService
{
    public function __construct(private CatalogReader $catalog, private PlaybackAuthorizer $playback) {}

    /** Reserve ordering before Content authorization, not when its possibly delayed response returns. */
    public function authorizePlayback(?ViewerIdentity $viewer, string $dramaId, string $episodeId, string $region, ?string $playSessionId = null): PlaybackGrant
    {
        $this->validateIds($dramaId, $episodeId, $playSessionId);
        if (! $viewer) {
            $this->episode($dramaId, $episodeId);

            return $this->playback->authorizePlayback(null, $episodeId, $region, $playSessionId);
        }

        if ($playSessionId !== null) {
            $record = DB::table('audience_playback_sessions')->where('viewer_id', $viewer->id)
                ->where('drama_id', $dramaId)->where('episode_id', $episodeId)
                ->where('play_session_id', $playSessionId)->first();
            if (! $record) {
                throw new ApiProblem('NOT_FOUND', 404, 'Playback session is not available.');
            }
            $generation = $record->id;
        } else {
            $generation = DB::table('audience_playback_sessions')->insertGetId([
                'viewer_id' => $viewer->id, 'drama_id' => $dramaId, 'episode_id' => $episodeId,
                'created_at' => now(),
            ]);
        }

        $this->episode($dramaId, $episodeId);
        $grant = $this->playback->authorizePlayback($viewer, $episodeId, $region, $playSessionId);
        if ($grant->allowed && $grant->play_session_id && $grant->expires_at && $grant->episode_id === $episodeId) {
            // Concurrent renewals may return out of order; an older response must not shorten the latest permission.
            $expiresAt = CarbonImmutable::parse($grant->expires_at);
            DB::table('audience_playback_sessions')->where('id', $generation)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '<', $expiresAt))
                ->update(['play_session_id' => $grant->play_session_id, 'expires_at' => $expiresAt]);
        }

        return $grant;
    }

    /** Read retained bookmarks even when content or membership is now unavailable. */
    public function progress(ViewerIdentity $viewer, string $dramaId): ?Progress
    {
        $this->validateIds($dramaId);
        $row = DB::table('audience_progress')->where('viewer_id', $viewer->id)->where('drama_id', $dramaId)->first();

        return $row && $row->play_session_id ? $this->data($row) : null;
    }

    public function saveProgress(ViewerIdentity $viewer, string $dramaId, ProgressInput $input): Progress
    {
        $this->validateIds($dramaId, $input->episode_id, $input->play_session_id);
        Validator::make($input->toArray(), [
            'position_seconds' => 'required|integer|min:0',
            'sequence' => 'required|integer|min:1|max:2147483647',
        ])->validate();

        return DB::transaction(function () use ($viewer, $dramaId, $input) {
            // Own module row serializes concurrent writes, including the first save.
            DB::table('audience_progress')->insertOrIgnore(['viewer_id' => $viewer->id, 'drama_id' => $dramaId]);
            $row = DB::table('audience_progress')->where('viewer_id', $viewer->id)->where('drama_id', $dramaId)->lockForUpdate()->first();
            $session = DB::table('audience_playback_sessions')->where('viewer_id', $viewer->id)
                ->where('drama_id', $dramaId)->where('episode_id', $input->episode_id)
                ->where('play_session_id', $input->play_session_id)->first();
            if (! $session) {
                throw new ApiProblem('NOT_FOUND', 404, 'Playback session is not available.');
            }
            // Already accepted newer playback wins, even over an older session's first packet.
            if ($row->play_session_id && ($session->id < $row->session_generation
                || ($session->id === $row->session_generation && $input->sequence <= $row->sequence))) {
                return $this->data($row);
            }
            if (! $session->expires_at || CarbonImmutable::parse($session->expires_at)->lte(now())) {
                throw new ApiProblem('STALE_VERSION', 409, 'Playback session expired. Request a new playback permission.');
            }
            $episode = $this->episode($dramaId, $input->episode_id);
            if ($episode->media_state !== 'ready' || $episode->duration_seconds < 1) {
                throw new ApiProblem('MEDIA_NOT_READY', 409, 'This episode is not ready for progress updates.');
            }
            if ($input->position_seconds > $episode->duration_seconds) {
                throw ValidationException::withMessages(['position_seconds' => 'Playback position exceeds the verified episode duration.']);
            }
            $savedAt = now();
            DB::table('audience_progress')->where('viewer_id', $viewer->id)->where('drama_id', $dramaId)->update([
                'episode_id' => $input->episode_id, 'position_seconds' => $input->position_seconds,
                'play_session_id' => $input->play_session_id, 'session_generation' => $session->id,
                'sequence' => $input->sequence, 'saved_at' => $savedAt,
            ]);

            return new Progress($dramaId, $input->episode_id, $input->position_seconds, $input->play_session_id,
                $input->sequence, CarbonImmutable::instance($savedAt)->toIso8601ZuluString());
        }, 3);
    }

    private function episode(string $dramaId, string $episodeId): Episode
    {
        foreach ($this->catalog->drama($dramaId)->episodes as $episode) {
            if ($episode->id === $episodeId) {
                return $episode;
            }
        }

        throw new ApiProblem('NOT_FOUND', 404, 'Episode is not available in this drama.');
    }

    private function validateIds(string $dramaId, ?string $episodeId = null, ?string $sessionId = null): void
    {
        Validator::make(['drama_id' => $dramaId, 'episode_id' => $episodeId, 'play_session_id' => $sessionId], [
            'drama_id' => 'required|uuid', 'episode_id' => 'nullable|uuid', 'play_session_id' => 'nullable|uuid',
        ])->validate();
    }

    private function data(object $row): Progress
    {
        return new Progress($row->drama_id, $row->episode_id, $row->position_seconds, $row->play_session_id,
            $row->sequence, CarbonImmutable::parse($row->saved_at)->toIso8601ZuluString());
    }
}
