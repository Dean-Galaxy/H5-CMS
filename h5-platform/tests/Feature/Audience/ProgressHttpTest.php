<?php

namespace Tests\Feature\Audience;

use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Content\ContentTestCase;

/** Laravel HTTP integration; real Identity and Content, explicit testing-only media bytes. */
final class ProgressHttpTest extends ContentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.modules.audience.ready' => true]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
    }

    private function loginViewer(): Viewer
    {
        $viewer = Viewer::create(['name' => 'Progress HTTP test', 'email' => Str::uuid7().'@example.test', 'password' => 'TestPassword1234']);
        $this->asViewer($viewer);

        return $viewer;
    }

    public function test_progress_requires_viewer_authentication_while_free_playback_is_available_to_guests(): void
    {
        $drama = $this->fixture(published: true);
        $episode = $drama->episodes()->orderBy('number')->first();
        $path = '/api/v1/me/progress/'.$drama->id;
        $this->getJson($path)->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->putJson($path, [])->assertUnauthorized();
        $this->postJson('/audience/dramas/'.$drama->id.'/episodes/'.$episode->id.'/playback', ['play_session_id' => null])
            ->assertOk()->assertJsonPath('allowed', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('audience_progress', 0);
        $this->assertDatabaseCount('audience_playback_sessions', 0);
    }

    public function test_http_progress_is_null_before_viewing_and_returns_the_saved_contract_after_a_valid_write(): void
    {
        $drama = $this->fixture(2, 2, true);
        $episode = $drama->episodes()->orderBy('number')->first();
        $this->loginViewer();
        $path = '/api/v1/me/progress/'.$drama->id;
        $this->getJson($path)->assertOk()->assertContent('null')->assertHeader('Cache-Control', 'no-store, private');
        $grant = $this->postJson('/audience/dramas/'.$drama->id.'/episodes/'.$episode->id.'/playback', ['play_session_id' => null])
            ->assertOk()->assertJsonPath('allowed', true)->json();
        $this->getJson($path)->assertOk()->assertContent('null');
        $input = ['episode_id' => $episode->id, 'position_seconds' => 7, 'play_session_id' => $grant['play_session_id'], 'sequence' => 1];
        $saved = $this->putJson($path, $input)->assertOk()->assertJsonPath('position_seconds', 7)->assertJsonPath('sequence', 1)->json();
        $this->getJson($path)->assertOk()->assertExactJson($saved);
        $drama->update(['state' => 'withdrawn']);
        $this->getJson($path)->assertOk()->assertExactJson($saved);
    }

    public function test_http_rejects_extra_fields_foreign_sessions_and_unregistered_content_sessions(): void
    {
        $drama = $this->fixture(2, 2, true);
        $episode = $drama->episodes()->orderBy('number')->first();
        $this->loginViewer();
        $path = '/api/v1/me/progress/'.$drama->id;
        $playbackPath = '/audience/dramas/'.$drama->id.'/episodes/'.$episode->id.'/playback';
        $this->postJson($playbackPath, ['play_session_id' => null, 'region' => 'US'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $grant = $this->postJson($playbackPath, ['play_session_id' => null])->assertOk()->json();
        $input = ['episode_id' => $episode->id, 'position_seconds' => 7, 'play_session_id' => $grant['play_session_id'], 'sequence' => 1];
        $this->putJson($path, [...$input, 'viewer_id' => (string) Str::uuid7()])->assertUnprocessable();
        $this->putJson($path, [...$input, 'duration_seconds' => 999])->assertUnprocessable();
        $this->putJson($path, [...$input, 'position_seconds' => 11])->assertUnprocessable();
        $direct = $this->postJson('/api/v1/episodes/'.$episode->id.'/playback-grants', ['play_session_id' => null])->assertOk()->json();
        $this->putJson($path, [...$input, 'play_session_id' => $direct['play_session_id']])->assertNotFound();
        $this->loginViewer();
        $this->putJson($path, $input)->assertNotFound();
        $this->postJson($playbackPath, ['play_session_id' => $grant['play_session_id']])->assertNotFound();
        $this->assertDatabaseCount('audience_progress', 0);
    }
}
