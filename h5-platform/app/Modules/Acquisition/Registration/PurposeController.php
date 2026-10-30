<?php

namespace App\Modules\Acquisition\Registration;

use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurposeController
{
    public function context(PurposeStore $store)
    {
        try {
            return response()->json(DB::transaction(fn () => $store->context()));
        } catch (\Throwable) {
            throw new ApiProblem('PURPOSE_UNAVAILABLE', 503, '暂时无法读取用途状态');
        }
    }

    public function preferences(Request $r, PurposeStore $store)
    {
        abort_if(strlen($r->getContent()) > 4096, 413);
        $p = json_decode($r->getContent(), true);
        abort_unless($r->isJson() && is_array($p) && ! array_is_list($p)
            && ! array_diff(array_keys($p), ['purpose', 'choice', 'notice_version', 'expected_revision', 'operation_id'])
            && count($p) === 5 && in_array($p['purpose'] ?? null, PurposePolicy::PURPOSES, true)
            && in_array($p['choice'] ?? null, ['allow', 'deny'], true) && is_string($p['notice_version'] ?? null)
            && is_int($p['expected_revision'] ?? null) && $p['expected_revision'] >= 0 && $p['expected_revision'] < 2147483647
            && is_string($p['operation_id'] ?? null) && Str::isUuid($p['operation_id']), 422);
        try {
            return response()->json($store->change($p['purpose'], $p['choice'], $p['notice_version'], $p['expected_revision'], $p['operation_id']));
        } catch (PurposeConflict $e) {
            return response()->json(['schema_version' => 3, 'purpose' => $p['purpose'], 'error' => ['code' => $e->problemCode], 'state' => $e->state], 409);
        } catch (ApiProblem $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ApiProblem('PURPOSE_UNAVAILABLE', 503, '用途选择未确认保存');
        }
    }
}
