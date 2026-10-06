<?php

namespace App\Modules\Identity\Services;

use App\Contracts\AuditRecorder;
use App\Contracts\IdentityContext;
use App\Data\ResourceScope;
use App\Data\StaffIdentity;
use App\Data\ViewerIdentity;
use App\Enums\Permission;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\Auth;

final class CurrentIdentity implements IdentityContext
{
    public function viewer(): ?ViewerIdentity
    {
        $user = Auth::guard('web')->user();

        return $user && $user->active ? new ViewerIdentity($user->id, $user->email, $user->hasVerifiedEmail()) : null;
    }

    public function staff(): ?StaffIdentity
    {
        $user = Auth::guard('staff')->user();

        return $user && $user->active ? new StaffIdentity($user->id, $user->name, $user->role) : null;
    }

    public function requireViewer(bool $verified = false): ViewerIdentity
    {
        $viewer = $this->viewer() ?? throw new ApiProblem('AUTHENTICATION_REQUIRED', 401, '请先登录');
        if ($verified && ! $viewer->emailVerified) {
            throw new ApiProblem('EMAIL_UNVERIFIED', 403, '首次付款前请先验证邮箱');
        }

        return $viewer;
    }

    public function requireStaff(Permission $permission, ?ResourceScope $scope = null): StaffIdentity
    {
        $staff = $this->staff() ?? throw new ApiProblem('AUTHENTICATION_REQUIRED', 401, '请先登录后台');
        if (! filled(Auth::guard('staff')->user()->getAppAuthenticationSecret()) || request()->session()->get('identity.mfa_staff') !== $staff->id) {
            throw new ApiProblem('MFA_REQUIRED', 403, '请完成后台二次验证；初次配置后请重新登录');
        }
        if (! app(Permissions::class)->allows($staff, $permission, $scope)) {
            app(AuditRecorder::class)->record('permission.denied', 'permission', $permission->value, 'denied');
            throw new ApiProblem('NOT_AUTHORIZED', 403, '无权执行此操作');
        }

        return $staff;
    }
}
