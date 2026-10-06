<?php

namespace App\Modules\Identity\Http;

use App\Contracts\IdentityContext;
use App\Enums\Permission;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Services\StaffManager;
use Illuminate\Http\Request;

final class StaffController
{
    public function index(): array
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);

        return ['items' => Staff::orderBy('created_at')->get()->map->toIdentityArray()->all()];
    }

    public function create(Request $request, StaffManager $manager): array
    {
        return $manager->create($request->all())->toIdentityArray();
    }

    public function update(Request $request, string $id, StaffManager $manager): array
    {
        return $manager->update($id, $request->all())->toIdentityArray();
    }
}
