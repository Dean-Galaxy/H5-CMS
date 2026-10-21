<?php

namespace App\Modules\Content\Http;

use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\Banner;
use App\Modules\Content\Models\CategoryRecord;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Models\Publication;
use App\Modules\Content\Services\ContentGuard;
use App\Modules\Content\Services\ContentManager;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Content\Services\PublicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminController
{
    public function index(): array
    {
        ContentGuard::staff();

        $dramas = DramaRecord::with(['plan', 'categories'])->withCount(['episodes as published_count' => fn ($q) => $q->whereNotNull('published_at')])->orderBy('sort_order')->orderBy('id')->get();
        $banners = Banner::orderBy('sort_order')->orderBy('id')->get();
        $page = $this->assetPage();
        $references = Asset::whereIn('id', $dramas->pluck('cover_asset_id')->merge($banners->pluck('image_asset_id'))->filter())->get()->map(fn ($a) => $this->asset($a));

        return ['dramas' => $dramas, 'assets' => collect($page['assets'])->merge($references)->unique('id')->values(),
            'asset_cursor' => $page['next_cursor'], 'categories' => CategoryRecord::orderBy('sort_order')->orderBy('id')->get(),
            'banners' => $banners, 'default_free_count' => DB::table('content_settings')->where('id', 1)->value('default_free_count')];
    }

    public function assets(Request $request): array
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($request->query(), ['before' => 'nullable|uuid']);

        return $this->assetPage($data['before'] ?? null);
    }

    private function assetPage(?string $before = null): array
    {
        $rows = Asset::when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(201)->get();
        $page = $rows->take(200);

        return ['assets' => $page->map(fn ($a) => $this->asset($a))->values(), 'next_cursor' => $rows->count() > 200 ? $page->last()->id : null];
    }

    public function drama(string $id): array
    {
        ContentGuard::staff();
        $drama = DramaRecord::with(['plan', 'categories', 'episodes'])->findOrFail($id);
        ContentGuard::staff($drama);
        // Always include this drama's bindings, even when they are older than the latest upload page.
        $ids = $drama->episodes->pluck('video_asset_id')->merge($drama->episodes->pluck('subtitle_asset_id'))->push($drama->cover_asset_id)->filter();
        $candidateIds = Asset::where('metadata->context_drama_id', $id)->whereIn('metadata->context_episode_id', $drama->episodes->pluck('id'))->pluck('id');

        return ['drama' => $drama, 'assets' => Asset::whereIn('id', $ids->merge($candidateIds))->get()->map(fn ($a) => $this->asset($a)),
            'operations' => DB::table('content_operations')->where('drama_id', $id)->orderByDesc('id')->limit(100)->get(['action', 'resource_type', 'resource_id', 'version', 'occurred_at']),
            'publications' => Publication::where('drama_id', $id)->orderByDesc('executed_at')->limit(50)->get()];
    }

    public function saveDrama(Request $r, ContentManager $m, ?string $id = null)
    {
        return response()->json($m->saveDrama($r->all(), $id));
    }

    public function saveEpisode(Request $r, ContentManager $m, string $drama, ?string $id = null)
    {
        return response()->json($m->saveEpisode($drama, $r->all(), $id));
    }

    public function replaceEpisodeMedia(Request $r, ContentManager $m, string $drama, string $id)
    {
        return response()->json($m->replaceEpisodeMedia($drama, $id, $r->all()));
    }

    public function freeCount(Request $r, ContentManager $m, string $id)
    {
        return $m->freeCount($id, $r->all());
    }

    public function defaultPreview(Request $r, ContentManager $m)
    {
        return $m->defaultPreview($r->all());
    }

    public function saveDefault(Request $r, ContentManager $m)
    {
        return $m->saveDefault($r->all());
    }

    public function category(Request $r, ContentManager $m, ?string $id = null)
    {
        return response()->json($m->saveCategory($r->all(), $id));
    }

    public function banner(Request $r, ContentManager $m, ?string $id = null)
    {
        return response()->json($m->saveBanner($r->all(), $id));
    }

    public function plan(Request $r, PublicationService $p, string $id)
    {
        return $p->savePlan($id, $r->all());
    }

    public function activate(Request $r, PublicationService $p, string $id)
    {
        return $p->activate($id, $r->all());
    }

    public function publish(Request $r, PublicationService $p, string $id)
    {
        return $p->manual($id, $r->all());
    }

    public function launchPreview(Request $r, PublicationService $p, string $id)
    {
        return $p->previewLaunchNow($id, $r->all());
    }

    public function launchNow(Request $r, PublicationService $p, string $id)
    {
        return $p->launchNow($id, $r->all());
    }

    public function nextBatchPreview(Request $r, PublicationService $p, string $id)
    {
        return $p->previewNextBatch($id);
    }

    public function publishNextBatch(Request $r, PublicationService $p, string $id)
    {
        return $p->publishNextBatch($id, $r->all());
    }

    public function resume(Request $r, PublicationService $p, string $id)
    {
        return $p->resume($id, $r->all());
    }

    public function relist(Request $r, PublicationService $p, string $id)
    {
        return $p->relist($id, $r->all());
    }

    public function preview(Request $r, PublicationService $p, string $id)
    {
        $d = ContentGuard::validate($r->all(), ['through' => 'nullable|integer|min:1|max:10000']);

        return $p->preview($id, $d['through'] ?? null);
    }

    public function pause(Request $r, PublicationService $p, string $id)
    {
        return $p->pause($id, $this->version($r));
    }

    public function withdraw(Request $r, PublicationService $p, string $id)
    {
        return $p->withdraw($id, $this->version($r));
    }

    public function removeEpisode(Request $r, ContentManager $m, string $drama, string $id)
    {
        $m->removeEpisode($drama, $id, $this->version($r));

        return response()->json(['deleted' => true]);
    }

    private function version(Request $r): int
    {
        return ContentGuard::validate($r->all(), ['version' => 'required|integer|min:1'])['version'];
    }

    private function asset(Asset $a): array
    {
        return $a->only(['id', 'kind', 'state', 'expected_bytes', 'received_bytes', 'metadata', 'error_code', 'created_at']);
    }

    public function upload(Request $r, MediaStore $m)
    {
        return $this->asset($m->start($r->all()));
    }

    public function chunk(Request $r, MediaStore $m, string $id)
    {
        $offset = ContentGuard::validate(['offset' => $r->header('Upload-Offset')], ['offset' => 'required|integer|min:0'])['offset'];

        return $m->append($id, $offset, $r->getContent());
    }

    public function finish(MediaStore $m, string $id)
    {
        return $this->asset($m->finish($id));
    }

    public function cancel(MediaStore $m, string $id)
    {
        return $this->asset($m->cancel($id));
    }

    public function retry(MediaStore $m, string $id)
    {
        return $this->asset($m->retry($id));
    }
}
