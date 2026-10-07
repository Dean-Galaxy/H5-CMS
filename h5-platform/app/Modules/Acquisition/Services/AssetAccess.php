<?php

namespace App\Modules\Acquisition\Services;

use App\Contracts\IdentityContext;
use App\Data\ResourceScope;
use App\Data\StaffIdentity;
use App\Enums\Permission;
use App\Enums\Role;
use App\Support\ApiProblem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetAccess
{
    public function actor(): StaffIdentity
    {
        // Creation/listing use the current server identity, never a submitted owner id.
        $identity = app(IdentityContext::class);
        $staff = $identity->staff() ?? throw new ApiProblem('AUTHENTICATION_REQUIRED', 401, '请先登录后台');

        return $identity->requireStaff(Permission::AcquisitionManage, new ResourceScope($staff->id));
    }

    public function query(string $type, ?StaffIdentity $staff = null): Builder
    {
        $staff ??= $this->actor();
        $table = $this->table($type);
        $query = DB::table($table);
        if ($staff->role !== Role::Owner) {
            $query->where(function ($q) use ($type, $staff, $table) {
                $q->where('owner_id', $staff->id)->orWhereIn($table.'.id', DB::table('acquisition_assignments')
                    ->select('resource_id')->where('resource_type', $type)->where('staff_id', $staff->id));
            });
        }

        return $query;
    }

    public function get(string $type, string $id, bool $lock = false, Permission $permission = Permission::AcquisitionManage): object
    {
        $staff = $this->actor();
        if (! Str::isUuid($id)) {
            throw new ApiProblem('NOT_FOUND', 404, '资产不存在或无权查看');
        }
        $q = $this->query($type, $staff)->where('id', $id);
        $row = ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new ApiProblem('NOT_FOUND', 404, '资产不存在或无权查看');
        $assigned = DB::table('acquisition_assignments')->where('resource_type', $type)->where('resource_id', $id)->pluck('staff_id')->all();
        app(IdentityContext::class)->requireStaff($permission, new ResourceScope($row->owner_id, $assigned));

        return $row;
    }

    public function table(string $type): string
    {
        return match ($type) {
            'pixel' => 'acquisition_pixels',
            'link' => 'acquisition_links',
            default => throw new ApiProblem('VALIDATION_FAILED', 422, '资产类型无效'),
        };
    }
}
