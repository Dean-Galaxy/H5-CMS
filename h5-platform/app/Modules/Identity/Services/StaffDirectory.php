<?php

namespace App\Modules\Identity\Services;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\IdentityContext;
use App\Data\AssignableStaff;
use App\Data\AssignableStaffPage;
use App\Enums\Permission;
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Support\ApiProblem;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class StaffDirectory implements AcquisitionStaffDirectory
{
    private const CURSOR_PURPOSE = 'identity.acquisition-staff.candidates.v1';

    private const CURSOR_ORDER = 'id:asc';

    public function candidates(?string $cursor = null, int $limit = 50): AssignableStaffPage
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);
        Validator::make(['limit' => $limit], ['limit' => ['integer', 'min:1', 'max:50']])->validate();

        // The immutable, unique ID keeps pagination stable when names change or repeat.
        $query = $this->eligible()->orderBy('id');
        if ($cursor !== null) {
            $query->where('id', '>', $this->decodeCursor($cursor));
        }
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $next = $more ? $this->encodeCursor($rows->last()->id) : null;

        return new AssignableStaffPage($rows->map(fn (Staff $staff) => $this->toData($staff))->values()->all(), $next);
    }

    public function candidate(string $staffId): AssignableStaff
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);
        Validator::make(['staff_id' => $staffId], ['staff_id' => ['required', 'uuid']])->validate();
        $staff = $this->eligible()->whereKey($staffId)->first()
            ?? throw new ApiProblem('NOT_FOUND', 404, '员工不存在或不可分配');

        return $this->toData($staff);
    }

    /** @return Builder<Staff> */
    private function eligible(): Builder
    {
        return Staff::query()->select(['id', 'name', 'role', 'active'])
            ->where('active', true)->where('role', Role::Acquisition->value);
    }

    private function toData(Staff $staff): AssignableStaff
    {
        return new AssignableStaff($staff->id, $staff->name, $staff->role, $staff->active);
    }

    private function encodeCursor(string $id): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => self::CURSOR_PURPOSE, 'order' => self::CURSOR_ORDER, 'id' => $id,
        ], JSON_THROW_ON_ERROR));
    }

    private function decodeCursor(string $cursor): string
    {
        if ($cursor === '' || strlen($cursor) > 2048) {
            $this->badCursor();
        }
        try {
            $point = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $this->badCursor();
        }
        if (! is_array($point)
            || ($point['purpose'] ?? null) !== self::CURSOR_PURPOSE
            || ($point['order'] ?? null) !== self::CURSOR_ORDER
            || ! is_string($point['id'] ?? null)
            || ! Str::isUuid($point['id'])) {
            $this->badCursor();
        }

        return $point['id'];
    }

    private function badCursor(): never
    {
        throw ValidationException::withMessages(['cursor' => '游标无效，请从第一页重新读取']);
    }
}
