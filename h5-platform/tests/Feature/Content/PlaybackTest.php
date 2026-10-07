<?php

namespace Tests\Feature\Content;

use App\Contracts\MembershipReader;
use App\Contracts\RegionResolver;
use App\Data\Membership;
use App\Data\ResolvedRegion;
use App\Enums\SubscriptionState;
use App\Modules\Content\Models\Grant;
use App\Modules\Content\Models\PlaySession;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Content\Services\PlaybackService;
use App\Modules\Identity\Models\Viewer;

final class PlaybackTest extends ContentTestCase
{
    private function grant(string $ep, ?string $session = null)
    {
        return $this->postJson('/api/v1/episodes/'.$ep.'/playback-grants', ['play_session_id' => $session]);
    }

    private function signed(string $id, string $file): string
    {
        return app(PlaybackService::class)->url(Grant::findOrFail($id), $file);
    }

    private function viewer(): Viewer
    {
        $v = Viewer::create(['name' => 'Viewer', 'email' => uniqid().'@example.test', 'password' => 'ViewerPass1234', 'email_verified_at' => now()]);
        $this->asViewer($v);

        return $v;
    }

    private function membership(bool $active = true): object
    {
        $fake = new class($active) implements MembershipReader
        {
            public function __construct(public bool $active) {}

            public function membership(string $viewerId, \DateTimeImmutable $at): Membership
            {
                return new Membership($this->active, $this->active ? now()->subDay()->toIso8601String() : null, $this->active ? now()->addMinutes(10)->toIso8601String() : null, false, SubscriptionState::Canceling, false, null, null, []);
            }
        };
        $this->app->instance(MembershipReader::class, $fake);

        return $fake;
    }

    public function test_free_fifth_paid_sixth_and_no_raw_media_or_forged_grant_access(): void
    {
        $d = $this->fixture(6, 62, true);
        $eps = $d->episodes;
        $grant = $this->grant($eps[4]->id)->assertOk()->assertJsonPath('reason', 'allowed_free')->json();
        $this->grant($eps[5]->id)->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('reason', 'MEMBERSHIP_REQUIRED')->assertJsonPath('short_lived_url', null);
        $response = $this->get($grant['short_lived_url']);
        $this->assertSame(200, $response->status(), $response->content());
        $response->assertSee('#EXTM3U', false);
        $this->assertNull(PlaySession::find($grant['play_session_id'])->started_at);
        $this->get($this->signed($grant['grant_id'], 'media.mp4'), ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/1024');
        $this->assertNotNull(PlaySession::find($grant['play_session_id'])->started_at);
        $this->get('/api/v1/content/playback/'.$grant['grant_id'].'/media.mp4')->assertForbidden();
        $this->get($grant['short_lived_url'].'tampered')->assertForbidden();
        foreach (['/storage/'.$eps[4]->video->output_key.'/media.mp4', '/.runtime/media/'.$eps[4]->video->output_key.'/media.mp4', '/api/v1/content/playback/'.$grant['grant_id'].'/../../source.mp4'] as $path) {
            $this->assertContains($this->get($path)->status(), [403, 404]);
        }
        $this->viewer();
        $this->grant($eps[5]->id)->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
    }

    public function test_hls_subtitle_map_and_every_caption_request_are_protected(): void
    {
        $d = $this->fixture(1, 1, true);
        $ep = $d->episodes[0];
        $sub = $this->readyAsset('subtitle');
        $ep->update(['subtitle_asset_id' => $sub->id, 'subtitle_language' => 'en']);
        $ep->video->update(['metadata' => [...$ep->video->metadata, 'hls_timestamp_base' => 126000]]);
        $g = $this->grant($ep->id)->assertOk()->json();
        $this->get($g['short_lived_url'])->assertOk()->assertSee('TYPE=SUBTITLES', false);
        $this->get($this->signed($g['grant_id'], 'captions.m3u8'))->assertOk()->assertSee('subtitles.vtt', false);
        $this->get($this->signed($g['grant_id'], 'subtitles.vtt'))->assertOk()->assertSee('X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:126000', false);
        $this->assertNull(PlaySession::find($g['play_session_id'])->started_at);
        $this->get('/api/v1/content/playback/'.$g['grant_id'].'/subtitles.vtt')->assertForbidden();
        $this->viewer();
        $this->get($this->signed($g['grant_id'], 'captions.m3u8'))->assertNotFound();
    }

    public function test_head_and_invalid_range_do_not_start_a_session_and_missing_hls_blocks_grants(): void
    {
        $d = $this->fixture(1, 1, true);
        $ep = $d->episodes[0];
        $g = $this->grant($ep->id)->assertOk()->json();
        $url = $this->signed($g['grant_id'], 'media.mp4');
        $this->head($url)->assertOk();
        $this->get($url, ['Range' => 'bytes=10000-11000'])->assertStatus(416);
        $this->assertNull(PlaySession::find($g['play_session_id'])->started_at);
        $this->get($url, ['Range' => 'bytes=0-0'])->assertStatus(206);
        $this->assertNotNull(PlaySession::find($g['play_session_id'])->started_at);
        unlink(app(MediaStore::class)->path($ep->video->output_key.'/segment-00000.ts'));
        $this->grant($ep->id)->assertOk()->assertJsonPath('reason', 'MEDIA_NOT_READY')->assertJsonPath('allowed', false);
        $this->get($g['short_lived_url'])->assertForbidden()->assertJsonPath('error.code', 'MEDIA_NOT_READY');
    }

    public function test_n_change_only_protects_started_same_episode_with_finite_window(): void
    {
        $d = $this->fixture(6, 62, true);
        $ep = $d->episodes[4];
        $notStarted = $this->grant($ep->id)->json();
        $started = $this->grant($ep->id)->json();
        $this->get($this->signed($started['grant_id'], 'segment-00000.ts'))->assertOk();
        $this->patchJson('/api/v1/admin/content/dramas/'.$d->id.'/free', ['version' => $d->version, 'free_count' => 0])->assertOk();
        $this->get($this->signed($notStarted['grant_id'], 'media.mp4'))->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
        $this->grant($ep->id)->assertOk()->assertJsonPath('allowed', false);
        $this->grant($ep->id, $started['play_session_id'])->assertOk()->assertJsonPath('allowed', true);
        $this->grant($d->episodes[5]->id, $started['play_session_id'])->assertNotFound();
        $until = PlaySession::find($started['play_session_id'])->until;
        $this->travel(90)->seconds();
        $this->grant($ep->id, $started['play_session_id'])->assertOk();
        $this->assertTrue($until->equalTo(PlaySession::find($started['play_session_id'])->until));
        $this->travelTo($until);
        $this->grant($ep->id, $started['play_session_id'])->assertStatus(409)->assertJsonPath('error.code', 'STALE_VERSION');
    }

    public function test_refund_membership_loss_is_checked_on_every_manifest_segment_range_and_renewal(): void
    {
        $d = $this->fixture(6, 62, true);
        $ep = $d->episodes[5];
        $this->viewer();
        $fake = $this->membership();
        $g = $this->grant($ep->id)->assertOk()->assertJsonPath('reason', 'allowed_member')->json();
        $this->get($this->signed($g['grant_id'], 'media.mp4'), ['Range' => 'bytes=0-3'])->assertStatus(206);
        $fake->active = false; // Explicit contract test double, never a real refund.
        foreach (['master.m3u8', 'index.m3u8', 'segment-00000.ts', 'media.mp4'] as $file) {
            $this->get($this->signed($g['grant_id'], $file), ['Range' => 'bytes=0-3'])->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
        }
        $this->grant($ep->id, $g['play_session_id'])->assertOk()->assertJsonPath('allowed', false);
        $this->grant($d->episodes[0]->id)->assertOk()->assertJsonPath('allowed', true);
    }

    public function test_expiry_region_withdrawal_and_other_identity_cannot_reuse_grants(): void
    {
        $d = $this->fixture(6, 62, true);
        $ep = $d->episodes[0];
        $g = $this->grant($ep->id)->json();
        $this->viewer();
        $this->grant($ep->id, $g['play_session_id'])->assertNotFound();
        auth('web')->logout();
        app('session.store')->save();
        $this->travel(121)->seconds();
        $this->get($g['short_lived_url'])->assertForbidden();
        $g = $this->grant($ep->id)->json();
        $d->update(['license_until' => now()]);
        $this->get($g['short_lived_url'])->assertForbidden()->assertJsonPath('error.code', 'REGION_BLOCKED');
        $d->update(['license_until' => now()->addYear()]);
        $g = $this->grant($ep->id)->json();
        $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/withdraw', ['version' => $d->version])->assertOk();
        $this->get($g['short_lived_url'])->assertStatus(409);
        $this->grant($ep->id)->assertOk()->assertJsonPath('reason', 'UNPUBLISHED');
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('ZZ', 'unresolved');
            }
        });
        $this->grant($ep->id)->assertOk()->assertJsonPath('reason', 'REGION_BLOCKED');
        $this->postJson('/api/v1/episodes/'.$ep->id.'/playback-grants', ['play_session_id' => null, 'region' => 'US'])->assertUnprocessable();
    }
}
