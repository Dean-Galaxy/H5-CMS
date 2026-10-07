<?php

namespace Tests\Feature\Content;

use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Enums\Role;
use App\Modules\Content\ContentServiceProvider;
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Models\EpisodeRecord;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class ContentTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $mediaRoot;

    protected Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->travelTo(CarbonImmutable::parse('2026-10-07T02:00:00+00:00'));
        $this->app->register(ContentServiceProvider::class);
        $this->mediaRoot = base_path('../.runtime/content-tests/'.Str::uuid7());
        config(['h5.media_root' => $this->mediaRoot, 'session.driver' => 'database']);
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
        $this->owner = $this->makeStaff(Role::Owner);
        $this->asStaff($this->owner);
    }

    // Model a browser cookie jar; Laravel JSON tests otherwise omit all cookies.
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // A real PHP HTTP request gets fresh guards/session services. Laravel's test app is reused.
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        auth()->forgetGuards();
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);
        $this->travelBack();
        parent::tearDown();
    }

    protected function makeStaff(Role $role): Staff
    {
        $s = Staff::create(['name' => 'Content test', 'email' => Str::uuid7().'@example.test', 'password' => 'TestPassword1234', 'role' => $role]);
        $s->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $s->refresh();
    }

    protected function asStaff(Staff $s): void
    {
        $this->signInSession($s, 'staff');
        $this->getJson('/api/v1/admin/csrf')->assertOk();
    }

    protected function asViewer(Viewer $viewer): void
    {
        $this->signInSession($viewer, 'web');
        $this->getJson('/api/v1/auth/csrf')->assertOk();
    }

    private function signInSession($user, string $guard): void
    {
        $name = config('h5.workspace').($guard === 'staff' ? '_admin' : '_viewer');
        config(['session.cookie' => $name, 'session.encrypt' => true]);
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        auth()->forgetGuards();
        $store = app('session.store');
        $store->start();
        auth($guard)->login($user);
        $store->put('identity.version.'.$guard, $user->session_version);
        if ($guard === 'staff') {
            $store->put('identity.mfa_staff', $user->id);
        }
        $store->save();
        unset($this->unencryptedCookies[$name]);
        $this->withCookie($name, $store->getId());
    }

    protected function draftInput(): array
    {
        return ['title_en' => 'A test drama', 'title_zh' => '', 'synopsis_en' => 'Testing only.', 'synopsis_zh' => '',
            'total_episodes' => 62, 'cover_asset_id' => null, 'regions' => ['US'], 'license_from' => now()->subDay()->toIso8601String(),
            'license_until' => now()->addYear()->toIso8601String(), 'rights_note' => 'Isolated test fixtures; no copyright verification.',
            'recommended' => true, 'sort_order' => 0, 'category_ids' => []];
    }

    protected function createDrama(): DramaRecord
    {
        $id = $this->postJson('/api/v1/admin/content/dramas', $this->draftInput())->assertOk()->json('id');

        return DramaRecord::findOrFail($id);
    }

    /** Explicit testing-only files and state; never used by ordinary runtime or real media imports. */
    protected function readyAsset(string $kind = 'video'): Asset
    {
        $a = Asset::create(['created_by' => $this->owner->id, 'kind' => $kind, 'state' => 'ready', 'extension' => $kind === 'video' ? 'mp4' : 'png',
            'expected_bytes' => 1024, 'received_bytes' => 1024, 'metadata' => ['duration' => 10.0, 'duration_seconds' => 10, 'last_end' => 5.0, 'width' => 48, 'height' => 64]]);
        $key = 'fixtures/'.$a->id;
        $path = app(MediaStore::class)->path($key);
        mkdir($path, 0700, true);
        foreach (['media.mp4', 'segment-00000.ts', 'image.png'] as $file) {
            file_put_contents($path.'/'.$file, str_repeat('a', 1024));
        }
        file_put_contents($path.'/index.m3u8', "#EXTM3U\n#EXT-X-TARGETDURATION:10\n#EXTINF:10,\nsegment-00000.ts\n#EXT-X-ENDLIST\n");
        file_put_contents($path.'/subtitles.vtt', "WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\nTest\n");
        $a->update(['output_key' => $key]);

        return $a;
    }

    protected function fixture(int $count = 6, ?int $total = null, bool $published = false): DramaRecord
    {
        $d = $this->createDrama();
        $cover = $this->readyAsset('cover');
        $video = $this->readyAsset();
        $d->update(['total_episodes' => $total ?? $count, 'cover_asset_id' => $cover->id, 'state' => $published ? 'published' : 'draft']);
        for ($n = 1; $n <= $count; $n++) {
            EpisodeRecord::create(['drama_id' => $d->id, 'number' => $n, 'video_asset_id' => $video->id, 'published_at' => $published ? now() : null]);
        }
        $d->plan->update(['mode' => 'updating', 'state' => 'active', 'launch_at' => now(), 'next_at' => now()]);

        return $d->fresh();
    }

    protected function publish(DramaRecord $d, int $through)
    {
        $preview = $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/publication-preview', ['through' => $through])->assertOk()->json();

        return $this->postJson('/api/v1/admin/content/dramas/'.$d->id.'/publish', ['version' => $preview['drama_version'], 'through' => $through, 'confirmation' => $preview['confirmation']]);
    }
}
