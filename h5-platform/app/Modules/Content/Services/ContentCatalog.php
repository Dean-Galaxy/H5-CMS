<?php

namespace App\Modules\Content\Services;

use App\Contracts\CatalogReader;
use App\Contracts\RegionResolver;
use App\Data\CatalogPage;
use App\Data\Category;
use App\Data\CategoryList;
use App\Data\Drama;
use App\Data\DramaCard;
use App\Data\Episode;
use App\Data\HomeBanner;
use App\Data\HomePage;
use App\Modules\Content\Models\Banner;
use App\Modules\Content\Models\CategoryRecord;
use App\Modules\Content\Models\DramaRecord;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ContentCatalog implements CatalogReader
{
    public function __construct(private RegionResolver $regions, private Availability $availability) {}

    private function region(): string
    {
        $region = $this->regions->resolve()->country_code;
        if ($region === 'ZZ' || ! preg_match('/^[A-Z]{2}$/D', $region)) {
            throw new ApiProblem('REGION_BLOCKED', 403, '无法核实地区，当前内容不可用');
        }

        return $region;
    }

    private function locale(string $locale): void
    {
        ContentGuard::validate(['locale' => $locale], ['locale' => 'required|in:en,zh_CN']);
    }

    public function text(object $row, string $field, string $locale): string
    {
        $first = $locale === 'zh_CN' ? 'zh' : 'en';
        $second = $first === 'zh' ? 'en' : 'zh';

        return trim($row->{$field.'_'.$first} ?? '') ?: trim($row->{$field.'_'.$second} ?? '');
    }

    public function categories(string $locale = 'en'): CategoryList
    {
        $this->locale($locale);
        $this->region();

        return new CategoryList($locale, CategoryRecord::where('active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($c) => new Category($c->id, $this->text($c, 'name', $locale), $c->sort_order))->all());
    }

    public function homepage(string $locale = 'en'): HomePage
    {
        $this->locale($locale);
        $region = $this->region();
        $visible = $this->availability->query($region);
        $banners = Banner::with('image')->where('active', true)->whereIn('drama_id', (clone $visible)->select('id'))
            ->whereHas('image', fn ($q) => $q->where('state', 'ready'))->orderBy('sort_order')->orderBy('id')->get();
        $dramas = $visible->where('recommended', true)->with(['cover', 'episodes.video'])->orderBy('sort_order')->orderBy('id')->limit(50)->get();

        return new HomePage($locale, $this->categories($locale)->items,
            $banners->map(fn ($b) => new HomeBanner($b->id, $this->text($b, 'title', $locale), $this->imageUrl($b->image_asset_id), $b->drama_id, $b->sort_order))->all(),
            $dramas->map(function ($d) use ($locale) {
                $dto = $this->toDrama($d, $locale);

                return new DramaCard($dto->id, $dto->title, $dto->synopsis, $dto->cover_url, $dto->published_episode_count, $dto->total_episode_count, $dto->free_episode_count, $dto->publication_state, $d->sort_order);
            })->all());
    }

    public function catalog(?string $cursor = null, int $limit = 20, string $locale = 'en', ?string $categoryId = null): CatalogPage
    {
        $this->locale($locale);
        $region = $this->region();
        ContentGuard::validate(['limit' => $limit, 'category_id' => $categoryId], ['limit' => 'integer|min:1|max:50', 'category_id' => 'nullable|uuid']);
        if ($categoryId && ! CategoryRecord::whereKey($categoryId)->where('active', true)->exists()) {
            throw new ApiProblem('NOT_FOUND', 404, '分类不存在');
        }
        $version = DB::table('content_settings')->where('id', 1)->value('catalog_version');
        $query = $this->availability->query($region)->with(['cover', 'episodes.video'])->orderBy('sort_order')->orderBy('id');
        if ($categoryId) {
            $query->whereHas('categories', fn ($q) => $q->where('content_categories.id', $categoryId));
        }
        if ($cursor !== null) {
            $point = $this->decodeCursor($cursor);
            if (($point['category'] ?? null) !== $categoryId || ($point['locale'] ?? null) !== $locale || ($point['region'] ?? null) !== $region || ($point['version'] ?? null) !== $version || ! isset($point['sort'], $point['id'])) {
                $this->badCursor();
            }
            $query->where(fn ($q) => $q->where('sort_order', '>', $point['sort'])->orWhere(fn ($q) => $q->where('sort_order', $point['sort'])->where('id', '>', $point['id'])));
        }
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();
        $next = $more ? $this->encodeCursor(['category' => $categoryId, 'locale' => $locale, 'region' => $region, 'version' => $version, 'sort' => $last->sort_order, 'id' => $last->id]) : null;

        return new CatalogPage($rows->map(fn ($d) => $this->toDrama($d, $locale))->all(), $next);
    }

    public function drama(string $dramaId, string $locale = 'en'): Drama
    {
        $this->locale($locale);
        ContentGuard::validate(['id' => $dramaId], ['id' => 'uuid']);
        $drama = $this->availability->query($this->region())->with(['cover', 'episodes.video'])->find($dramaId);
        if (! $drama) {
            throw new ApiProblem('NOT_FOUND', 404, '剧目不可用');
        }

        return $this->toDrama($drama, $locale);
    }

    private function toDrama(DramaRecord $d, string $locale): Drama
    {
        $published = $d->episodes->filter(fn ($ep) => $ep->published_at !== null)->values();
        $episodes = $published->map(fn ($ep) => new Episode($ep->id, $ep->number, $this->text($ep, 'title', $locale) ?: ($locale === 'zh_CN' ? '第 '.$ep->number.' 集' : 'Episode '.$ep->number),
            max(1, (int) ($ep->video?->metadata['duration_seconds'] ?? 1)), $ep->number <= $d->free_count ? 'free' : 'membership', $ep->video?->state === 'ready' ? 'ready' : 'not_ready'))->all();
        $complete = $d->total_episodes !== null && $published->pluck('number')->all() === range(1, $d->total_episodes);

        return new Drama($d->id, $this->text($d, 'title', $locale), $this->text($d, 'synopsis', $locale), $d->cover?->state === 'ready' ? $this->imageUrl($d->cover_asset_id) : null,
            $published->count(), $d->total_episodes, $d->free_count, $complete ? 'completed' : 'updating', $episodes);
    }

    private function imageUrl(string $id): string
    {
        return rtrim(config('app.url'), '/').'/api/v1/content/images/'.$id;
    }

    private function encodeCursor(array $point): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($point, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, config('app.key'));
    }

    private function decodeCursor(string $cursor): array
    {
        if (strlen($cursor) > 2048 || ! preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $m) || ! hash_equals(hash_hmac('sha256', $m[1], config('app.key')), $m[2])) {
            $this->badCursor();
        }
        try {
            $data = json_decode(base64_decode(strtr($m[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->badCursor();
        }
        if (! is_array($data)) {
            $this->badCursor();
        }

        return $data;
    }

    private function badCursor(): never
    {
        throw ValidationException::withMessages(['cursor' => '游标无效、条件已改变或排序版本已更新，请从第一页重新读取']);
    }
}
