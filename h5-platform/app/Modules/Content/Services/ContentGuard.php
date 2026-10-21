<?php

namespace App\Modules\Content\Services;

use App\Contracts\AuditRecorder;
use App\Contracts\IdentityContext;
use App\Data\ResourceScope;
use App\Enums\Permission;
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\DramaRecord;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ContentGuard
{
    public static function staff(?DramaRecord $drama = null)
    {
        return app(IdentityContext::class)->requireStaff(Permission::ContentManage,
            $drama ? new ResourceScope(ownerId: $drama->created_by) : null);
    }

    // A single, short database lock serializes Content mutations, always before object locks.
    // Media decoding and HTTP streaming happen outside it.
    public static function mutate(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            $settings = DB::table('content_settings')->where('id', 1)->lockForUpdate()->first();

            return $callback($settings);
        }, 3);
    }

    public static function version(object $record, mixed $version): void
    {
        if (! is_numeric($version) || (int) $version !== (int) $record->version) {
            throw new ApiProblem('STALE_VERSION', 409, '数据已更新，请刷新后重新确认');
        }
    }

    public static function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }

    public static function changed(string $action, string $type, string $id, int $version = 1): void
    {
        DB::table('content_settings')->where('id', 1)->increment('catalog_version');
        app(AuditRecorder::class)->record('content.'.$action, $type, $id, 'success', ['version' => $version]);
        // Module-local history projection; staff/security audit remains owned by Identity.
        $dramaId = match ($type) {
            'content_drama' => $id,
            'content_episode' => DB::table('content_episodes')->where('id', $id)->value('drama_id'),
            'content_plan' => DB::table('content_plans')->where('id', $id)->value('drama_id'),
            'content_asset' => Asset::find($id)?->metadata['context_drama_id'] ?? null,
            default => null,
        };
        if ($dramaId) {
            DB::table('content_operations')->insert(['drama_id' => $dramaId, 'action' => $action, 'resource_type' => $type,
                'resource_id' => $id, 'version' => $version, 'occurred_at' => now()]);
        }
    }
}
