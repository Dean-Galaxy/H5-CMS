<?php

namespace App\Modules\Content\Services;

use App\Contracts\IdentityContext;
use App\Contracts\PromotableCatalog;
use App\Data\PromotableDrama;
use App\Data\PromotableDramaPage;
use App\Enums\Permission;
use App\Modules\Content\Models\DramaRecord;
use App\Support\ApiProblem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ContentPromotableCatalog implements PromotableCatalog
{
    private const PURPOSE = 'content.promotable.v1';

    private const COUNTRY = 'US';

    private const ORDER = 'sort_order:asc,id:asc';

    public function __construct(
        private IdentityContext $identity,
        private Availability $availability,
        private ContentCatalog $catalog,
        private MediaStore $media,
    ) {}

    public function promotable(?string $cursor = null, int $limit = 20, string $locale = 'en'): PromotableDramaPage
    {
        $this->identity->requireStaff(Permission::PublishedCatalogRead);
        ContentGuard::validate(['limit' => $limit, 'locale' => $locale], ['limit' => 'integer|min:1|max:50', 'locale' => 'required|in:en,zh_CN']);
        clearstatcache();
        $version = $this->version();
        $after = $cursor === null ? null : $this->decodeCursor($cursor, $locale, $version);
        $matches = [];
        // Filesystem availability cannot be expressed by SQL's LIMIT. Keep scanning
        // keyset batches until a full page plus one eligible item has been found.
        do {
            $query = $this->query()->orderBy('sort_order')->orderBy('id');
            if ($after !== null) {
                $query->where(fn ($q) => $q->where('sort_order', '>', $after['sort'])
                    ->orWhere(fn ($q) => $q->where('sort_order', $after['sort'])->where('id', '>', $after['id'])));
            }
            $rows = $query->limit(50)->get();
            foreach ($rows as $drama) {
                $after = ['sort' => $drama->sort_order, 'id' => $drama->id];
                if ($dto = $this->eligible($drama, $locale)) {
                    $matches[] = ['item' => $dto, 'point' => $after];
                    if (count($matches) > $limit) {
                        break;
                    }
                }
            }
        } while ($rows->count() === 50 && count($matches) <= $limit);
        $this->assertVersion($version);
        $more = count($matches) > $limit;
        $page = array_slice($matches, 0, $limit);
        $next = $more ? $this->encodeCursor([...$this->context($locale, $version), ...$page[array_key_last($page)]['point']]) : null;

        return new PromotableDramaPage(array_column($page, 'item'), $next);
    }

    public function promotableDrama(string $dramaId, string $locale = 'en'): PromotableDrama
    {
        $this->identity->requireStaff(Permission::PublishedCatalogRead);
        ContentGuard::validate(['id' => $dramaId, 'locale' => $locale], ['id' => 'required|uuid', 'locale' => 'required|in:en,zh_CN']);
        clearstatcache();
        $version = $this->version();
        $drama = $this->query()->find($dramaId);
        $dto = $drama ? $this->eligible($drama, $locale) : null;
        $this->assertVersion($version);
        if ($dto === null) {
            throw new ApiProblem('NOT_FOUND', 404, '剧目当前不可投放');
        }

        return $dto;
    }

    private function query(): Builder
    {
        $published = fn ($q) => $q->whereNotNull('published_at')->where('published_at', '<=', now());

        // Inventory is fixed to the licensed campaign country, without resolving or
        // changing the visitor region. Reading it never creates playback permission.
        return $this->availability->query(self::COUNTRY)->whereHas('episodes', $published)
            ->with(['episodes' => $published, 'episodes.video', 'episodes.subtitle']);
    }

    private function eligible(DramaRecord $drama, string $locale): ?PromotableDrama
    {
        if (! $this->availability->licensed($drama, self::COUNTRY)) {
            return null;
        }
        $title = $this->catalog->text($drama, 'title', $locale);
        if ($title === '') {
            return null;
        }
        foreach ($drama->episodes as $episode) {
            try {
                if (! $episode->video || ! $this->media->playable($episode->video)) {
                    continue;
                }
                if ($episode->subtitle_asset_id) {
                    if (! $episode->subtitle || $episode->subtitle->state !== 'ready' || ! $episode->subtitle->output_key) {
                        continue;
                    }
                    $path = $this->media->path($episode->subtitle->output_key.'/subtitles.vtt');
                    if (! is_file($path) || filesize($path) === 0) {
                        continue;
                    }
                }
            } catch (\LogicException|\RuntimeException) {
                // An inaccessible root, unsafe key or symlink is not usable media.
                continue;
            }

            // Count publication facts, including a published episode whose media later
            // became unavailable. At least one usable published episode is required.
            return new PromotableDrama($drama->id, $title, $locale, self::COUNTRY, $drama->episodes->count());
        }

        return null;
    }

    private function version(): int
    {
        return (int) DB::table('content_settings')->where('id', 1)->value('catalog_version');
    }

    private function assertVersion(int $version): void
    {
        if ($version !== $this->version()) {
            $this->badCursor();
        }
    }

    private function context(string $locale, int $version): array
    {
        return ['purpose' => self::PURPOSE, 'country_code' => self::COUNTRY, 'locale' => $locale, 'order' => self::ORDER, 'version' => $version];
    }

    private function encodeCursor(array $point): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($point, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, config('app.key'));
    }

    private function decodeCursor(string $cursor, string $locale, int $version): array
    {
        if (strlen($cursor) > 2048 || ! preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $m)
            || ! hash_equals(hash_hmac('sha256', $m[1], config('app.key')), $m[2])) {
            $this->badCursor();
        }
        try {
            $point = json_decode(base64_decode(strtr($m[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->badCursor();
        }
        if (! is_array($point)) {
            $this->badCursor();
        }
        foreach ($this->context($locale, $version) as $key => $expected) {
            if (($point[$key] ?? null) !== $expected) {
                $this->badCursor();
            }
        }
        if (! is_int($point['sort'] ?? null) || $point['sort'] < 0 || $point['sort'] > 1000000
            || ! is_string($point['id'] ?? null) || ! Str::isUuid($point['id'])) {
            $this->badCursor();
        }

        return $point;
    }

    private function badCursor(): never
    {
        throw ValidationException::withMessages(['cursor' => '游标无效、条件已改变或排序版本已更新，请从第一页重新读取']);
    }
}
