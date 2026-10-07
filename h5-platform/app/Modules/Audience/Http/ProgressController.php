<?php

namespace App\Modules\Audience\Http;

use App\Contracts\IdentityContext;
use App\Contracts\ProgressService;
use App\Contracts\RegionResolver;
use App\Data\ProgressInput;
use App\Modules\Audience\Progress\ProgressRecorder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProgressController
{
    public function show(string $drama_id, IdentityContext $identity, ProgressService $progress)
    {
        // JsonResponse's constructor turns null into {}; setData preserves the contract's JSON null.
        return response()->json()->setData($progress->progress($identity->requireViewer(), $drama_id));
    }

    public function save(Request $request, string $drama_id, IdentityContext $identity, ProgressService $progress)
    {
        $this->only($request, ['episode_id', 'position_seconds', 'play_session_id', 'sequence']);
        $data = $request->validate(['episode_id' => 'required|uuid', 'position_seconds' => 'required|integer|min:0',
            'play_session_id' => 'required|uuid', 'sequence' => 'required|integer|min:1|max:2147483647']);
        $data['position_seconds'] = (int) $data['position_seconds'];
        $data['sequence'] = (int) $data['sequence'];

        return $progress->saveProgress($identity->requireViewer(), $drama_id, ProgressInput::fromArray($data));
    }

    public function playback(Request $request, string $drama, string $episode, IdentityContext $identity, ProgressRecorder $progress, RegionResolver $regions)
    {
        $this->only($request, ['play_session_id']);
        $data = $request->validate(['play_session_id' => 'present|nullable|uuid']);

        return $progress->authorizePlayback($identity->viewer(), $drama, $episode, $regions->resolve()->country_code, $data['play_session_id']);
    }

    private function only(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), [...$keys, '_token'])) {
            throw ValidationException::withMessages(['input' => 'Unsupported input fields.']);
        }
    }
}
