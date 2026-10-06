<?php

namespace App\Modules\Identity\Filament\Pages;

use App\Contracts\IdentityContext;
use App\Enums\Permission;
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Services\StaffManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class StaffAccounts extends Page
{
    protected static ?string $title = '员工账号与角色';

    protected string $view = 'identity.staff-accounts';

    public array $newStaff = ['name' => '', 'email' => '', 'password' => '', 'role' => 'content_operator'];

    public array $edits = [];

    public static function canAccess(): bool
    {
        $user = auth('staff')->user();

        return $user?->active && $user->role === Role::Owner;
    }

    public function mount(): void
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);
        $this->reloadRows();
    }

    protected function reloadRows(): void
    {
        $this->edits = Staff::all()->mapWithKeys(fn ($s) => [$s->id => ['role' => $s->role->value, 'active' => $s->active, 'version' => $s->version]])->all();
    }

    public function createStaff(): void
    {
        app(StaffManager::class)->create($this->newStaff);
        $this->newStaff = ['name' => '', 'email' => '', 'password' => '', 'role' => 'content_operator'];
        $this->reloadRows();
        Notification::make()->title('员工已创建；首次登录必须配置二次验证')->success()->send();
    }

    public function saveStaff(string $id): void
    {
        app(StaffManager::class)->update($id, $this->edits[$id] ?? []);
        $this->reloadRows();
        Notification::make()->title('权限已保存，旧会话已撤销')->success()->send();
    }

    protected function getViewData(): array
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);

        return ['staffAccounts' => Staff::orderBy('created_at')->get(), 'roles' => Role::cases(),
            'auditEvents' => DB::table('identity_audit_events')->orderByDesc('occurred_at')->limit(30)->get()];
    }
}
