<?php

namespace Tests\Feature\Audience;

use App\Contracts\CatalogReader;
use App\Contracts\PlaybackAuthorizer;
use App\Data\Drama;
use App\Data\Episode;
use App\Data\PlaybackGrant;
use App\Data\ProgressInput;
use App\Data\ViewerIdentity;
use App\Enums\PlaybackReason;
use App\Modules\Audience\Progress\ProgressRecorder;
use App\Support\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/** Explicit isolated contract samples. No sample provider is registered by the application. */
final class ProgressTest extends TestCase
{
    use RefreshDatabase;

    private ProgressRecorder $recorder;

    private ViewerIdentity $viewer;

    private Drama $drama;

    private $catalog;

    private $playback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->viewer = new ViewerIdentity((string) Str::uuid7(), 'progress@example.test', false);
        $this->drama = new Drama((string) Str::uuid7(), 'Testing only', '', null, 2, 2, 2, 'completed', [
            new Episode((string) Str::uuid7(), 1, 'Episode 1', 90, 'free', 'ready'),
            new Episode((string) Str::uuid7(), 2, 'Episode 2', 100, 'free', 'ready'),
        ]);
        $this->catalog = Mockery::mock(CatalogReader::class);
        $this->catalog->shouldReceive('drama')->with($this->drama->id)->andReturn($this->drama)->byDefault();
        $this->playback = Mockery::mock(PlaybackAuthorizer::class);
        $this->playback->shouldReceive('authorizePlayback')->andReturnUsing(
            fn ($viewer, $episode, $region, $session) => $this->grant($episode, $session)
        )->byDefault();
        $this->recorder = new ProgressRecorder($this->catalog, $this->playback);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function grant(string $episodeId, ?string $session = null): PlaybackGrant
    {
        return new PlaybackGrant((string) Str::uuid7(), $episodeId, true, PlaybackReason::AllowedFree,
            '/testing-only-short-grant', now()->addSeconds(120)->toIso8601ZuluString(), $session ?? (string) Str::uuid7());
    }

    private function start(int $episode = 0): PlaybackGrant
    {
        return $this->recorder->authorizePlayback($this->viewer, $this->drama->id, $this->drama->episodes[$episode]->id, 'US');
    }

    private function save(PlaybackGrant $grant, int $position = 10, int $sequence = 1, ?ViewerIdentity $viewer = null)
    {
        return $this->recorder->saveProgress($viewer ?? $this->viewer, $this->drama->id,
            new ProgressInput($grant->episode_id, $position, $grant->play_session_id, $sequence));
    }

    private function assertProblem(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (ApiProblem $error) {
            $this->assertSame($code, $error->errorCode);
        }
    }

    public function test_permission_and_preload_do_not_create_a_progress_record_and_guests_are_not_persisted(): void
    {
        $grant = $this->start();
        $this->assertNull($this->recorder->progress($this->viewer, $this->drama->id));
        $this->assertDatabaseCount('audience_progress', 0);
        $this->recorder->authorizePlayback(null, $this->drama->id, $this->drama->episodes[0]->id, 'US');
        $this->assertDatabaseCount('audience_playback_sessions', 1);
        $this->recorder->authorizePlayback($this->viewer, $this->drama->id, $grant->episode_id, 'US', $grant->play_session_id);
        $this->assertDatabaseCount('audience_playback_sessions', 1);
        $this->assertDatabaseCount('audience_progress', 0);
    }

    public function test_same_session_sequence_retries_and_delayed_packets_cannot_overwrite_newer_position(): void
    {
        $grant = $this->start();
        $latest = $this->save($grant, 40, 3);
        $this->travel(5)->seconds();
        $this->assertEquals($latest, $this->save($grant, 5, 2));
        $this->assertEquals($latest, $this->save($grant, 20, 3));
        $this->assertEquals($latest, $this->recorder->progress($this->viewer, $this->drama->id));
        $this->assertSame(7, $this->save($grant, 7, 4)->position_seconds, 'An intentional backwards seek with a new sequence is valid.');
    }

    public function test_a_new_session_can_rewatch_an_earlier_episode_and_old_session_first_save_is_ignored(): void
    {
        $old = $this->start(1);
        $rewatch = $this->start(0);
        $latest = $this->save($rewatch, 4, 1);
        $this->assertEquals($latest, $this->save($old, 90, 999));
        $this->assertSame($this->drama->episodes[0]->id, $this->recorder->progress($this->viewer, $this->drama->id)->episode_id);
    }

    public function test_authorization_responses_arriving_out_of_order_do_not_reverse_session_generation(): void
    {
        $newer = null;
        $entered = false;
        $this->playback->shouldReceive('authorizePlayback')->andReturnUsing(function ($viewer, $episode, $region, $session) use (&$entered, &$newer) {
            if (! $entered) {
                $entered = true;
                // A second request completes while the first is still awaiting Content.
                $newer = $this->start(0);
            }

            return $this->grant($episode);
        });
        $older = $this->start(1);
        $latest = $this->save($newer, 8);
        $this->assertEquals($latest, $this->save($older, 50));
    }

    public function test_delayed_renewal_response_cannot_shorten_a_newer_permission(): void
    {
        $grant = $this->start();
        $oldExpiry = $grant->expires_at;
        $entered = false;
        $this->playback->shouldReceive('authorizePlayback')->andReturnUsing(function ($viewer, $episode, $region, $session) use (&$entered, $oldExpiry) {
            if (! $entered) {
                $entered = true;
                $this->travel(20)->seconds();
                $this->recorder->authorizePlayback($this->viewer, $this->drama->id, $episode, 'US', $session);

                return new PlaybackGrant((string) Str::uuid7(), $episode, true, PlaybackReason::AllowedFree,
                    '/testing-only-short-grant', $oldExpiry, $session);
            }

            return $this->grant($episode, $session);
        });
        $this->recorder->authorizePlayback($this->viewer, $this->drama->id, $grant->episode_id, 'US', $grant->play_session_id);
        $this->travel(105)->seconds();
        $this->assertSame(15, $this->save($grant, 15)->position_seconds);
        $this->assertDatabaseCount('audience_playback_sessions', 1);
    }

    public function test_unknown_other_account_other_episode_and_other_drama_sessions_are_rejected(): void
    {
        $grant = $this->start();
        $other = new ViewerIdentity((string) Str::uuid7(), 'other@example.test', true);
        $this->assertProblem('NOT_FOUND', fn () => $this->save($grant, viewer: $other));
        $this->assertProblem('NOT_FOUND', fn () => $this->save($this->grant($grant->episode_id)));
        $this->assertProblem('NOT_FOUND', fn () => $this->recorder->saveProgress($this->viewer, $this->drama->id,
            new ProgressInput($this->drama->episodes[1]->id, 10, $grant->play_session_id, 1)));
        $this->assertProblem('NOT_FOUND', fn () => $this->recorder->saveProgress($this->viewer, (string) Str::uuid7(),
            new ProgressInput($grant->episode_id, 10, $grant->play_session_id, 1)));
        $this->assertDatabaseCount('audience_progress', 0);
    }

    public function test_position_uses_server_catalogue_duration_not_a_client_duration(): void
    {
        $grant = $this->start();
        foreach ([-1, 91] as $invalid) {
            try {
                $this->save($grant, $invalid);
                $this->fail('Out-of-range position must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('position_seconds', $exception->errors());
            }
        }
        $this->assertDatabaseCount('audience_progress', 0);
        $this->assertSame(90, $this->save($grant, 90)->position_seconds);
    }

    public function test_expired_session_cannot_save_but_retained_progress_remains_readable(): void
    {
        $grant = $this->start();
        $saved = $this->save($grant, 12);
        $this->travel(120)->seconds();
        $this->assertProblem('STALE_VERSION', fn () => $this->save($grant, 15, 2));
        $this->catalog->shouldReceive('drama')->never();
        $this->assertEquals($saved, $this->recorder->progress($this->viewer, $this->drama->id));
    }

    public function test_unpublished_content_rejects_new_save_without_deleting_previous_progress(): void
    {
        $grant = $this->start();
        $saved = $this->save($grant, 12);
        $this->catalog->shouldReceive('drama')->andThrow(new ApiProblem('NOT_FOUND', 404, 'Testing removed drama.'));
        $this->assertProblem('NOT_FOUND', fn () => $this->save($grant, 20, 2));
        $this->assertEquals($saved, $this->recorder->progress($this->viewer, $this->drama->id));
    }

    public function test_denied_playback_and_unrelated_episode_cannot_register_a_saveable_session(): void
    {
        $this->playback->shouldReceive('authorizePlayback')->andReturn(new PlaybackGrant(null, $this->drama->episodes[0]->id,
            false, PlaybackReason::MembershipRequired, null, null, null));
        $this->assertFalse($this->start()->allowed);
        $this->assertSame(0, DB::table('audience_playback_sessions')->whereNotNull('play_session_id')->count());
        $this->assertProblem('NOT_FOUND', fn () => $this->recorder->authorizePlayback($this->viewer,
            $this->drama->id, (string) Str::uuid7(), 'US'));
        $this->assertDatabaseCount('audience_progress', 0);
    }
}
