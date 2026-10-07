<?php

namespace Tests\Feature\Content;

use App\Contracts\CatalogReader;
use App\Contracts\MembershipReader;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\PromotableCatalog;
use App\Contracts\RegionResolver;
use App\Data\Membership;
use App\Data\PromotableDrama;
use App\Data\PromotableDramaPage;
use App\Data\ResolvedRegion;
use App\Enums\Permission;
use App\Enums\Role;
use App\Modules\Content\Models\EpisodeRecord;
use App\Modules\Content\Models\Grant;
use App\Modules\Content\Models\PlaySession;
use App\Modules\Content\Services\ContentPromotableCatalog;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PromotableCatalogTest extends ContentTestCase
{
    public function test_provider_returns_minimal_dtos_from_real_publication_facts_and_existing_language_fallback(): void
    {
        $d = $this->fixture(5, 62);
        $this->publish($d, 3)->assertOk();
        $d->update(['title_en' => ' English title ', 'title_zh' => ' 中文剧名 ']);
        $bad = $this->readyAsset();
        $bad->update(['state' => 'failed']);
        $d->episodes()->where('number', 1)->update(['video_asset_id' => $bad->id]);
        $port = app(PromotableCatalog::class);
        $this->assertInstanceOf(ContentPromotableCatalog::class, $port);
        $page = $port->promotable();
        $this->assertInstanceOf(PromotableDramaPage::class, $page);
        $this->assertCount(1, $page->items);
        $this->assertInstanceOf(PromotableDrama::class, $page->items[0]);
        $this->assertSame(['id' => $d->id, 'title' => 'English title', 'locale' => 'en', 'country_code' => 'US', 'published_episode_count' => 3], $page->items[0]->toArray());
        $this->assertNull($page->next_cursor);
        $this->assertSame('中文剧名', $port->promotableDrama($d->id, 'zh_CN')->title);
        $d->update(['title_zh' => '']);
        $this->assertSame('English title', $port->promotableDrama($d->id, 'zh_CN')->title);
        $d->update(['title_en' => '', 'title_zh' => '只有中文']);
        $this->assertSame('只有中文', $port->promotableDrama($d->id)->title);
        $encoded = json_encode($page, JSON_THROW_ON_ERROR);
        foreach (['source_key', 'output_key', 'asset_id', 'media.mp4', 'image_url', 'cover_url', 'short_lived_url', 'viewer', 'http'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertSame(['items', 'next_cursor'], array_keys($page->toArray()));
    }

    public function test_both_methods_require_current_staff_mfa_and_published_catalog_permission(): void
    {
        $d = $this->fixture(1, 1, true);
        $port = app(PromotableCatalog::class);
        foreach ([Role::Owner, Role::ContentOperator, Role::Acquisition] as $role) {
            $this->asStaff($this->makeStaff($role));
            $this->assertSame([$d->id], array_map(fn ($item) => $item->id, $port->promotable()->items));
            $this->assertSame($d->id, $port->promotableDrama($d->id)->id);
            request()->session()->forget('identity.mfa_staff');
            $this->problem(fn () => $port->promotable(), 'MFA_REQUIRED', 403);
            $this->problem(fn () => $port->promotableDrama($d->id), 'MFA_REQUIRED', 403);
        }
        $this->asStaff($this->makeStaff(Role::Support));
        $this->problem(fn () => $port->promotable(), 'NOT_AUTHORIZED', 403);
        $this->problem(fn () => $port->promotableDrama($d->id), 'NOT_AUTHORIZED', 403);
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'permission.denied', 'subject_id' => Permission::PublishedCatalogRead->value]);
        auth('staff')->logout();
        app('session.store')->save();
        $viewer = Viewer::create(['name' => 'Viewer', 'email' => 'campaign-viewer@example.test', 'password' => 'TestPassword1234']);
        $this->asViewer($viewer);
        $this->problem(fn () => $port->promotable(), 'AUTHENTICATION_REQUIRED', 401);
        $this->problem(fn () => $port->promotableDrama($d->id), 'AUTHENTICATION_REQUIRED', 401);
        auth('web')->logout();
        $this->problem(fn () => $port->promotable(), 'AUTHENTICATION_REQUIRED', 401);
        $this->problem(fn () => $port->promotableDrama($d->id), 'AUTHENTICATION_REQUIRED', 401);
    }

    public function test_inventory_is_us_scoped_without_reading_or_changing_viewer_region_or_granting_playback(): void
    {
        $d = $this->fixture(1, 1, true);
        $d->update(['free_count' => 0]);
        $region = new class implements RegionResolver
        {
            public int $calls = 0;

            public function resolve(): ResolvedRegion
            {
                $this->calls++;

                return new ResolvedRegion('ZZ', 'unresolved');
            }
        };
        $this->app->instance(RegionResolver::class, $region);
        $this->app->instance(MembershipReader::class, new class implements MembershipReader
        {
            public function membership(string $viewerId, \DateTimeImmutable $at): Membership
            {
                throw new \LogicException('Inventory must not ask for viewer membership');
            }
        });
        $port = app(PromotableCatalog::class);
        $this->assertCount(1, $port->promotable()->items);
        $this->assertSame('US', $port->promotableDrama($d->id)->country_code);
        $this->assertSame(0, $region->calls);
        $this->assertSame('ZZ', app(RegionResolver::class)->resolve()->country_code);
        $this->problem(fn () => app(CatalogReader::class)->catalog(), 'REGION_BLOCKED', 403);
        $grant = app(PlaybackAuthorizer::class)->authorizePlayback(null, $d->episodes[0]->id, 'ZZ');
        $this->assertFalse($grant->allowed);
        $this->assertNull($grant->short_lived_url);
        $this->assertSame(0, Grant::count());
        $this->assertSame(0, PlaySession::count());
    }

    public function test_us_licence_uses_current_half_open_interval_and_each_read_revalidates(): void
    {
        $d = $this->fixture(1, 1, true);
        $port = app(PromotableCatalog::class);
        $at = now();
        $d->update(['license_from' => $at->addSecond(), 'license_until' => $at->addSeconds(3)]);
        $this->unavailable($port, $d->id);
        $this->travelTo($at->addSecond());
        $this->assertCount(1, $port->promotable()->items);
        $this->assertSame($d->id, $port->promotableDrama($d->id)->id);
        $this->travelTo($at->addSeconds(3));
        $this->unavailable($port, $d->id);
        $d->update(['license_from' => $at, 'license_until' => $at->addDay(), 'regions' => ['JP', 'TW']]);
        $this->unavailable($port, $d->id);
        $d->update(['regions' => ['JP', 'US']]);
        $this->assertSame($d->id, $port->promotableDrama($d->id)->id);
        $d->update(['state' => 'withdrawn']);
        $this->unavailable($port, $d->id);
        $d->update(['state' => 'draft']);
        $this->unavailable($port, $d->id);
        $d->update(['state' => 'published', 'license_until' => null]);
        $this->unavailable($port, $d->id);
    }

    public function test_only_currently_published_episodes_with_usable_media_qualify(): void
    {
        $d = $this->fixture(2, 62);
        $port = app(PromotableCatalog::class);
        $this->unavailable($port, $d->id);
        $d->update(['state' => 'published']);
        $this->unavailable($port, $d->id); // Uploads alone do not publish.
        $ep = $d->episodes()->first();
        $ep->update(['published_at' => now()->addHour()]);
        $this->unavailable($port, $d->id);
        $ep->update(['published_at' => now()]);
        $video = $ep->video;
        foreach (['pending', 'processing', 'failed', 'canceled'] as $state) {
            $video->update(['state' => $state]);
            $this->unavailable($port, $d->id);
        }
        $video->update(['state' => 'ready']);
        $this->assertSame(1, $port->promotableDrama($d->id)->published_episode_count);
        $ep->update(['video_asset_id' => null]);
        $this->unavailable($port, $d->id); // The other ready episode is still a draft.
        $ep->update(['video_asset_id' => $video->id]);
        $this->assertSame(1, $port->promotableDrama($d->id)->published_episode_count);
    }

    public function test_missing_or_unsafe_media_and_subtitle_files_are_rechecked_on_every_call(): void
    {
        $d = $this->fixture(1, 62, true);
        $ep = $d->episodes()->first();
        $video = $ep->video;
        $port = app(PromotableCatalog::class);
        $media = app(MediaStore::class);
        foreach (['media.mp4', 'index.m3u8', 'segment-00000.ts'] as $file) {
            $path = $media->path($video->output_key.'/'.$file);
            $content = file_get_contents($path);
            unlink($path);
            $this->unavailable($port, $d->id);
            file_put_contents($path, '');
            $this->unavailable($port, $d->id);
            file_put_contents($path, $content);
            $this->assertSame($d->id, $port->promotableDrama($d->id)->id);
        }
        $key = $video->output_key;
        $video->update(['output_key' => '../unsafe']);
        $this->unavailable($port, $d->id);
        $video->update(['output_key' => $key]);
        $sub = $this->readyAsset('subtitle');
        $ep->update(['subtitle_asset_id' => $sub->id, 'subtitle_language' => 'en']);
        $this->assertSame($d->id, $port->promotableDrama($d->id)->id);
        $sub->update(['state' => 'processing']);
        $this->unavailable($port, $d->id);
        $sub->update(['state' => 'ready']);
        unlink($media->path($sub->output_key.'/subtitles.vtt'));
        $this->unavailable($port, $d->id);
    }

    public function test_keyset_pagination_skips_unusable_batches_and_single_lookup_fills_an_off_page_selection(): void
    {
        $bad = $this->fixture(1, 1, true);
        unlink(app(MediaStore::class)->path($bad->episodes[0]->video->output_key.'/segment-00000.ts'));
        for ($i = 0; $i < 50; $i++) {
            $copy = $bad->replicate();
            $copy->save();
            EpisodeRecord::create(['drama_id' => $copy->id, 'number' => 1, 'published_at' => now(), 'video_asset_id' => $bad->episodes[0]->video_asset_id]);
        }
        $good = [];
        foreach ([2, 1, 1] as $sort) {
            $d = $this->fixture(1, 1, true);
            $d->update(['sort_order' => $sort]);
            $good[] = $d;
        }
        usort($good, fn ($a, $b) => ($a->sort_order <=> $b->sort_order) ?: strcmp($a->id, $b->id));
        $port = app(PromotableCatalog::class);
        $page = $port->promotable(null, 2);
        $this->assertSame([$good[0]->id, $good[1]->id], array_column($page->items, 'id'));
        $this->assertNotNull($page->next_cursor);
        $this->assertSame($good[2]->id, $port->promotableDrama($good[2]->id)->id);
        $last = $port->promotable($page->next_cursor, 50);
        $this->assertSame([$good[2]->id], array_column($last->items, 'id'));
        $this->assertNull($last->next_cursor);
        $this->assertSame(array_column($page->items, 'id'), array_column($port->promotable(null, 2)->items, 'id'));
    }

    public function test_cursor_integrity_purpose_country_locale_order_and_version_are_bound(): void
    {
        $a = $this->fixture(1, 1, true);
        $this->fixture(1, 1, true);
        $port = app(PromotableCatalog::class);
        $cursor = $port->promotable(null, 1)->next_cursor;
        $this->assertNotNull($cursor);
        $this->invalid(fn () => $port->promotable($cursor, 1, 'zh_CN'));
        foreach (['', 'forged', str_repeat('a', 2049), substr($cursor, 0, -1).(str_ends_with($cursor, 'a') ? 'b' : 'a')] as $bad) {
            $this->invalid(fn () => $port->promotable($bad));
        }
        $point = json_decode(base64_decode(strtr(explode('.', $cursor)[0], '-_', '+/')), true);
        foreach (['purpose' => 'public-catalog', 'country_code' => 'JP', 'locale' => 'zh_CN', 'order' => 'id:desc', 'version' => $point['version'] + 1, 'id' => 'bad-id', 'sort' => -1] as $key => $value) {
            $signed = $this->signedCursor([...$point, $key => $value]);
            $this->invalid(fn () => $port->promotable($signed));
        }
        $this->invalid(fn () => $port->promotable($this->signedCursor('scalar')));
        $publicCursor = app(CatalogReader::class)->catalog(null, 1)->next_cursor;
        $this->invalid(fn () => $port->promotable($publicCursor));
        $this->invalid(fn () => app(CatalogReader::class)->catalog($cursor));
        $this->patchJson('/api/v1/admin/content/dramas/'.$a->id.'/free', ['version' => $a->fresh()->version, 'free_count' => 4])->assertOk();
        $this->invalid(fn () => $port->promotable($cursor));
        $this->assertSame($a->id, $port->promotableDrama($a->id)->id);
        $this->postJson('/api/v1/admin/content/dramas/'.$a->id.'/withdraw', ['version' => $a->fresh()->version])->assertOk();
        $this->problem(fn () => $port->promotableDrama($a->id), 'NOT_FOUND', 404);
    }

    public function test_empty_inventory_and_invalid_inputs_do_not_fabricate_candidates(): void
    {
        $port = app(PromotableCatalog::class);
        $this->assertSame(['items' => [], 'next_cursor' => null], $port->promotable()->toArray());
        foreach ([0, -1, 51] as $limit) {
            $this->invalid(fn () => $port->promotable(null, $limit));
        }
        foreach (['', 'EN', 'ja', 'zh_TW'] as $locale) {
            $this->invalid(fn () => $port->promotable(null, 20, $locale));
            $this->invalid(fn () => $port->promotableDrama((string) Str::uuid7(), $locale));
        }
        $this->invalid(fn () => $port->promotableDrama('bad-id'));
        $this->problem(fn () => $port->promotableDrama((string) Str::uuid7()), 'NOT_FOUND', 404);
    }

    private function unavailable(PromotableCatalog $port, string $id): void
    {
        $this->assertSame([], $port->promotable()->items);
        $this->problem(fn () => $port->promotableDrama($id), 'NOT_FOUND', 404);
    }

    private function problem(callable $read, string $code, int $status): void
    {
        try {
            $read();
            $this->fail('Expected '.$code);
        } catch (ApiProblem $error) {
            $this->assertSame($code, $error->errorCode);
            $this->assertSame($status, $error->status);
        }
    }

    private function invalid(callable $read): void
    {
        try {
            $read();
            $this->fail('Expected invalid input');
        } catch (ValidationException $error) {
            $this->assertSame(422, $error->status);
            $this->assertNotEmpty($error->errors());
        }
    }

    private function signedCursor(mixed $point): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($point, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, config('app.key'));
    }
}
