<?php

namespace Tests\Feature\Audience;

use App\Contracts\IdentityContext;
use App\Data\ProgressInput;
use App\Modules\Audience\Progress\ProgressRecorder;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use Illuminate\Support\Str;
use Tests\Feature\Content\ContentTestCase;

/** Real accepted Content + Identity services, with explicitly testing-only media bytes. */
final class ProgressContentTest extends ContentTestCase
{
    public function test_real_content_grant_and_catalogue_feed_progress_without_persisting_media_urls(): void
    {
        $drama = $this->fixture(2, 2, true);
        $viewer = Viewer::create(['name' => 'Progress test', 'email' => Str::uuid7().'@example.test', 'password' => 'TestPassword1234']);
        $this->asViewer($viewer);
        $identity = app(IdentityContext::class)->requireViewer();
        $episode = $drama->episodes()->orderBy('number')->first();
        $recorder = app(ProgressRecorder::class);
        $grant = $recorder->authorizePlayback($identity, $drama->id, $episode->id, 'US');
        $this->assertTrue($grant->allowed);
        $this->assertNull($recorder->progress($identity, $drama->id));
        $saved = $recorder->saveProgress($identity, $drama->id, new ProgressInput($episode->id, 8, $grant->play_session_id, 1));
        $this->assertSame(8, $saved->position_seconds);
        $this->assertArrayNotHasKey('short_lived_url', $saved->toArray());
        $drama->update(['state' => 'draft']);
        $this->assertEquals($saved, $recorder->progress($identity, $drama->id));
        try {
            $recorder->authorizePlayback($identity, $drama->id, $episode->id, 'US');
            $this->fail('Removed content must be checked again.');
        } catch (ApiProblem $problem) {
            $this->assertSame('NOT_FOUND', $problem->errorCode);
        }
    }
}
