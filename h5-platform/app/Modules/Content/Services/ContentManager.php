<?php

namespace App\Modules\Content\Services;

use App\Contracts\IdentityContext;
use App\Enums\Permission;
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\Banner;
use App\Modules\Content\Models\CategoryRecord;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Models\EpisodeRecord;
use App\Modules\Content\Models\Plan;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ContentManager
{
    public function saveDrama(array $input, ?string $id = null): DramaRecord
    {
        $staff = ContentGuard::staff();
        $data = ContentGuard::validate($input, [
            'version' => $id ? 'required|integer|min:1' : 'nullable|integer',
            'title_en' => 'present|nullable|string|max:200', 'title_zh' => 'present|nullable|string|max:200',
            'synopsis_en' => 'present|nullable|string|max:10000', 'synopsis_zh' => 'present|nullable|string|max:10000',
            'total_episodes' => 'nullable|integer|min:1|max:10000', 'cover_asset_id' => 'nullable|uuid',
            'regions' => 'present|array|max:250', 'regions.*' => 'required|string|regex:/^[A-Z]{2}$/|distinct',
            'license_from' => 'nullable|date_format:Y-m-d\\TH:i:sP', 'license_until' => 'nullable|date_format:Y-m-d\\TH:i:sP|after:license_from',
            'rights_note' => 'present|nullable|string|max:5000', 'recommended' => 'required|boolean',
            'sort_order' => 'required|integer|min:0|max:1000000', 'category_ids' => 'present|array|max:50', 'category_ids.*' => 'required|uuid|distinct',
        ]);

        return ContentGuard::mutate(function ($settings) use ($data, $id, $staff) {
            $drama = $id ? DramaRecord::findOrFail($id) : new DramaRecord(['created_by' => $staff->id, 'free_count' => $settings->default_free_count]);
            if ($id) {
                ContentGuard::staff($drama);
                ContentGuard::version($drama, $data['version']);
                if (isset($data['total_episodes']) && $drama->episodes()->max('number') > $data['total_episodes']) {
                    throw ValidationException::withMessages(['total_episodes' => '总集数不能少于已登记的分集序号']);
                }
            }
            $this->asset($data['cover_asset_id'] ?? null, ['cover']);
            if (CategoryRecord::whereIn('id', $data['category_ids'])->count() !== count($data['category_ids'])) {
                throw ValidationException::withMessages(['category_ids' => '分类不存在']);
            }
            $fields = $data;
            unset($fields['version'], $fields['category_ids']);
            foreach (['title_en', 'title_zh', 'synopsis_en', 'synopsis_zh', 'rights_note'] as $key) {
                $fields[$key] = trim($fields[$key] ?? '');
            }
            foreach (['license_from', 'license_until'] as $key) {
                $fields[$key] = empty($fields[$key]) ? null : CarbonImmutable::parse($fields[$key])->utc();
            }
            $drama->fill($fields);
            if ($id && $drama->isDirty(['regions', 'license_from', 'license_until'])) {
                $drama->access_version++;
            }
            if ($drama->state === 'published' && ! trim($drama->title_en.$drama->title_zh)) {
                throw ValidationException::withMessages(['title_en' => '公开剧目至少需要一种语言的标题']);
            }
            $drama->version = $id ? $drama->version + 1 : 1;
            $drama->save();
            $drama->categories()->sync($data['category_ids']);
            if (! $id) {
                Plan::create(['drama_id' => $drama->id, 'launch_at' => now()->setTimezone('Asia/Shanghai')->addDay()->startOfDay()->utc()]);
            }
            ContentGuard::changed('drama.saved', 'content_drama', $drama->id, $drama->version);

            return $drama->fresh(['plan', 'categories']);
        });
    }

    public function saveEpisode(string $dramaId, array $input, ?string $id = null): EpisodeRecord
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, [
            'version' => $id ? 'required|integer|min:1' : 'nullable|integer',
            'number' => 'required|integer|min:1|max:10000',
            'title_en' => 'present|nullable|string|max:200', 'title_zh' => 'present|nullable|string|max:200',
            'video_asset_id' => 'nullable|uuid', 'subtitle_asset_id' => 'nullable|uuid',
            'subtitle_language' => 'nullable|in:en,zh_CN',
        ]);

        return ContentGuard::mutate(function () use ($dramaId, $data, $id) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            $episode = $id ? $drama->episodes()->findOrFail($id) : new EpisodeRecord(['drama_id' => $drama->id]);
            if ($id) {
                ContentGuard::version($episode, $data['version']);
            }
            if ($drama->total_episodes && $data['number'] > $drama->total_episodes) {
                throw ValidationException::withMessages(['number' => '分集序号超过已确认总集数']);
            }
            if ($drama->episodes()->where('number', $data['number'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['number' => '同一剧目的分集序号不能重复']);
            }
            if ($episode->published_at && $episode->number !== $data['number']) {
                throw ValidationException::withMessages(['number' => '已公开分集不能改变序号']);
            }
            $this->asset($data['video_asset_id'] ?? null, ['video']);
            $this->asset($data['subtitle_asset_id'] ?? null, ['subtitle']);
            if (! empty($data['subtitle_asset_id']) && empty($data['subtitle_language'])) {
                throw ValidationException::withMessages(['subtitle_language' => '请登记实际字幕语言，不会自动翻译']);
            }
            unset($data['version']);
            $episode->fill($data);
            $episode->title_en = trim($episode->title_en ?? '');
            $episode->title_zh = trim($episode->title_zh ?? '');
            if ($drama->state === 'published' && $episode->published_at && $episode->isDirty(['video_asset_id', 'subtitle_asset_id'])) {
                throw ValidationException::withMessages(['video_asset_id' => '请先整剧下架，再更换已公开分集的素材']);
            }
            $episode->version = $id ? $episode->version + 1 : 1;
            $episode->save();
            $drama->increment('version');
            ContentGuard::changed('episode.saved', 'content_episode', $episode->id, $episode->version);

            return $episode;
        });
    }

    public function removeEpisode(string $dramaId, string $id, int $version): void
    {
        ContentGuard::staff();
        ContentGuard::mutate(function () use ($dramaId, $id, $version) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            $episode = $drama->episodes()->findOrFail($id);
            ContentGuard::version($episode, $version);
            if ($episode->published_at) {
                throw new ApiProblem('STALE_VERSION', 409, '已发布记录保留，不可删除');
            }
            $episode->delete();
            $drama->increment('version');
            ContentGuard::changed('episode.deleted', 'content_episode', $id, $version);
        });
    }

    public function freeCount(string $id, array $input): DramaRecord
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => 'required|integer|min:1', 'free_count' => 'required|integer|min:0|max:10000']);

        return ContentGuard::mutate(function () use ($id, $data) {
            $drama = DramaRecord::findOrFail($id);
            ContentGuard::staff($drama);
            ContentGuard::version($drama, $data['version']);
            // An explicit save of the same number is still an override. Do not revoke sessions for N alone.
            $drama->update(['free_count' => $data['free_count'], 'free_overridden' => true, 'version' => $drama->version + 1]);
            ContentGuard::changed('free.saved', 'content_drama', $id, $drama->version);

            return $drama;
        });
    }

    public function defaultPreview(array $input): array
    {
        app(IdentityContext::class)->requireStaff(Permission::FreeDefaultManage);
        $data = ContentGuard::validate($input, ['free_count' => 'required|integer|min:0|max:10000', 'scope' => 'required|in:new_only,sync']);

        return ContentGuard::mutate(fn ($settings) => $this->impact($settings, $data));
    }

    private function impact(object $settings, array $data): array
    {
        $rows = $data['scope'] === 'sync' ? DramaRecord::where('free_overridden', false)->orderBy('id')->get(['id', 'title_en', 'title_zh', 'free_count', 'version'])->toArray() : [];
        $result = ['version' => $settings->version, 'free_count' => (int) $data['free_count'], 'scope' => $data['scope'], 'dramas' => $rows, 'count' => count($rows)];
        $result['confirmation'] = hash_hmac('sha256', json_encode([$result, $settings->catalog_version], JSON_THROW_ON_ERROR), config('app.key'));

        return $result;
    }

    public function saveDefault(array $input): array
    {
        app(IdentityContext::class)->requireStaff(Permission::FreeDefaultManage);
        $data = ContentGuard::validate($input, ['free_count' => 'required|integer|min:0|max:10000', 'scope' => 'required|in:new_only,sync', 'version' => 'required|integer|min:1', 'confirmation' => 'required|string|size:64']);

        return ContentGuard::mutate(function ($settings) use ($data) {
            ContentGuard::version($settings, $data['version']);
            $impact = $this->impact($settings, $data);
            if (! hash_equals($impact['confirmation'], $data['confirmation'])) {
                throw new ApiProblem('STALE_VERSION', 409, '影响范围已变化，请重新预览并确认');
            }
            if ($data['scope'] === 'sync') {
                DramaRecord::where('free_overridden', false)->update(['free_count' => $data['free_count'], 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }
            DB::table('content_settings')->where('id', 1)->update(['default_free_count' => $data['free_count'], 'version' => $settings->version + 1]);
            ContentGuard::changed('free.default', 'content_settings', '1', $settings->version + 1);

            return ['version' => $settings->version + 1, 'affected_count' => $impact['count']];
        });
    }

    public function saveCategory(array $input, ?string $id = null): CategoryRecord
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => $id ? 'required|integer|min:1' : 'nullable|integer',
            'name_en' => 'present|nullable|string|max:160', 'name_zh' => 'present|nullable|string|max:160',
            'sort_order' => 'required|integer|min:0|max:1000000', 'active' => 'required|boolean']);
        if (! trim(($data['name_en'] ?? '').($data['name_zh'] ?? ''))) {
            throw ValidationException::withMessages(['name_en' => '分类至少填写一种语言的名称']);
        }

        return ContentGuard::mutate(function () use ($data, $id) {
            $row = $id ? CategoryRecord::findOrFail($id) : new CategoryRecord;
            if ($id) {
                ContentGuard::version($row, $data['version']);
            }
            unset($data['version']);
            $data['name_en'] = trim($data['name_en'] ?? '');
            $data['name_zh'] = trim($data['name_zh'] ?? '');
            $row->fill($data);
            $row->version = $id ? $row->version + 1 : 1;
            $row->save();
            ContentGuard::changed('category.saved', 'content_category', $row->id, $row->version);

            return $row;
        });
    }

    public function saveBanner(array $input, ?string $id = null): Banner
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => $id ? 'required|integer|min:1' : 'nullable|integer',
            'drama_id' => 'required|uuid', 'image_asset_id' => 'required|uuid',
            'title_en' => 'present|nullable|string|max:200', 'title_zh' => 'present|nullable|string|max:200',
            'sort_order' => 'required|integer|min:0|max:1000000', 'active' => 'required|boolean']);
        if (! trim(($data['title_en'] ?? '').($data['title_zh'] ?? ''))) {
            throw ValidationException::withMessages(['title_en' => 'Banner 至少填写一种语言的标题']);
        }

        return ContentGuard::mutate(function () use ($data, $id) {
            DramaRecord::findOrFail($data['drama_id']);
            $this->asset($data['image_asset_id'], ['banner']);
            $row = $id ? Banner::findOrFail($id) : new Banner;
            if ($id) {
                ContentGuard::version($row, $data['version']);
            }
            unset($data['version']);
            $data['title_en'] = trim($data['title_en'] ?? '');
            $data['title_zh'] = trim($data['title_zh'] ?? '');
            $row->fill($data);
            $row->version = $id ? $row->version + 1 : 1;
            $row->save();
            ContentGuard::changed('banner.saved', 'content_banner', $row->id, $row->version);

            return $row;
        });
    }

    private function asset(?string $id, array $kinds): ?Asset
    {
        if (! $id) {
            return null;
        }
        $asset = Asset::findOrFail($id);
        if (! in_array($asset->kind, $kinds, true)) {
            throw ValidationException::withMessages(['asset' => '素材类型不匹配']);
        }

        return $asset;
    }
}
