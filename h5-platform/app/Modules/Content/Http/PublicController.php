<?php

namespace App\Modules\Content\Http;

use App\Contracts\CatalogReader;
use App\Contracts\IdentityContext;
use App\Contracts\PlaybackAuthorizer;
use App\Contracts\RegionResolver;
use App\Modules\Content\Services\ContentGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PublicController
{
    private function locale(Request $r): string
    {
        return ContentGuard::validate(['locale' => $r->query('locale', 'en')], ['locale' => 'required|in:en,zh_CN'])['locale'];
    }

    public function homepage(Request $r, CatalogReader $catalog)
    {
        return $catalog->homepage($this->locale($r));
    }

    public function categories(Request $r, CatalogReader $catalog)
    {
        return $catalog->categories($this->locale($r));
    }

    public function catalog(Request $r, CatalogReader $catalog)
    {
        $data = ContentGuard::validate($r->query(), ['cursor' => 'nullable|string|max:2048', 'limit' => 'nullable|integer|min:1|max:50', 'category_id' => 'nullable|uuid']);

        return $catalog->catalog($data['cursor'] ?? null, (int) ($data['limit'] ?? 20), $this->locale($r), $data['category_id'] ?? null);
    }

    public function drama(Request $r, CatalogReader $catalog, string $id)
    {
        ContentGuard::validate(['id' => $id], ['id' => 'required|uuid']);

        return $catalog->drama($id, $this->locale($r));
    }

    public function grant(Request $r, PlaybackAuthorizer $playback, string $id)
    {
        ContentGuard::validate(['id' => $id], ['id' => 'required|uuid']);
        if (array_diff(array_keys($r->all()), ['play_session_id', '_token'])) {
            throw ValidationException::withMessages(['input' => '请求只能包含 play_session_id']);
        }
        $data = ContentGuard::validate($r->all(), ['play_session_id' => 'present|nullable|uuid']);

        return $playback->authorizePlayback(app(IdentityContext::class)->viewer(), $id, app(RegionResolver::class)->resolve()->country_code, $data['play_session_id']);
    }
}
