<?php

namespace App\Modules\Identity\Services;

use App\Contracts\AuditRecorder;
use App\Contracts\IdentityContext;
use App\Enums\Permission;
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StaffManager
{
    public function create(array $input): Staff
    {
        $actor = app(IdentityContext::class)->requireStaff(Permission::StaffManage);
        $input['email'] = mb_strtolower(trim($input['email'] ?? ''));
        $values = Validator::make($input, ['name' => ['required', 'string', 'max:80'], 'email' => ['required', 'email', 'max:254', 'unique:identity_staff,email'],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'max:128'], 'role' => ['required', Rule::enum(Role::class)]])->validate();

        return DB::transaction(function () use ($values, $actor) {
            $currentActor = Staff::query()->lockForUpdate()->findOrFail($actor->id);
            if (! $currentActor->active || $currentActor->role !== Role::Owner) {
                throw new ApiProblem('NOT_AUTHORIZED', 403, '负责人身份已变更');
            }
            $staff = Staff::create($values);
            app(AuditRecorder::class)->record('staff.created', 'staff', $staff->id, 'success', ['role' => $staff->role->value]);

            return $staff;
        });
    }

    public function update(string $id, array $input): Staff
    {
        $actor = app(IdentityContext::class)->requireStaff(Permission::StaffManage);
        $values = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean'], 'role' => ['required', Rule::enum(Role::class)]])->validate();

        return DB::transaction(function () use ($id, $values, $actor) {
            Staff::where('role', Role::Owner->value)->orderBy('id')->lockForUpdate()->get();
            $currentActor = Staff::findOrFail($actor->id);
            if (! $currentActor->active || $currentActor->role !== Role::Owner) {
                throw new ApiProblem('NOT_AUTHORIZED', 403, '负责人身份已变更');
            }
            $staff = Staff::query()->lockForUpdate()->findOrFail($id);
            if ($staff->version !== (int) $values['version']) {
                throw new ApiProblem('STALE_VERSION', 409, '账号已被其他人修改，请刷新后重试');
            }
            if ($id === $actor->id && (! $values['active'] || $values['role'] !== Role::Owner->value)) {
                throw new ApiProblem('NOT_AUTHORIZED', 403, '不能停用或降级当前负责人的账号');
            }
            $previous = $staff->role->value;
            $staff->forceFill(['role' => $values['role'], 'active' => (bool) $values['active'], 'version' => $staff->version + 1, 'session_version' => $staff->session_version + 1, 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $staff->id)->delete();
            app(AuditRecorder::class)->record('staff.updated', 'staff', $staff->id, 'success', ['role' => $staff->role->value, 'previous_role' => $previous, 'active' => $staff->active, 'version' => $staff->version]);

            return $staff;
        }, 3);
    }
}
