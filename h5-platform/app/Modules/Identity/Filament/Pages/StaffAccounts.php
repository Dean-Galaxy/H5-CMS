<?php

namespace App\Modules\Identity\Filament\Pages;

use App\Contracts\IdentityContext;
use App\Enums\Permission;
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Services\StaffManager;
use App\Support\ApiProblem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class StaffAccounts extends Page
{
    protected static ?string $title = '员工与权限';

    protected string $view = 'identity.staff-accounts';

    // Keep the selected tab on reload; Back returns to the previous page.
    #[Url(as: 'tab', except: 'accounts')]
    public string $tab = 'accounts';

    public array $newStaff = ['name' => '', 'email' => '', 'password' => '', 'role' => 'content_operator'];

    public array $editStaff = [];

    #[Locked]
    public ?string $editingId = null;

    #[Locked]
    public string $editingName = '';

    #[Locked]
    public string $editingEmail = '';

    public static function canAccess(): bool
    {
        $user = auth('staff')->user();

        return $user?->active && $user->role === Role::Owner;
    }

    public function mount(): void
    {
        $this->authorizeStaff();
        if (! in_array($this->tab, ['accounts', 'audit'], true)) {
            $this->tab = 'accounts';
        }
    }

    public function selectTab(string $tab): void
    {
        $this->authorizeStaff();
        if (! in_array($tab, ['accounts', 'audit'], true)) {
            throw ValidationException::withMessages(['tab' => '请选择有效标签。']);
        }
        $this->tab = $tab;
    }

    public function openCreate(): void
    {
        $this->discardEditor();
    }

    public function openStaff(string $id): void
    {
        $this->authorizeStaff();
        $staff = Staff::findOrFail($id);
        $this->resetValidation();
        $this->reset('newStaff');
        $this->editingId = $staff->id;
        $this->editingName = $staff->name;
        $this->editingEmail = $staff->email;
        $this->editStaff = ['role' => $staff->role->value, 'active' => $staff->active, 'version' => $staff->version];
    }

    public function discardEditor(): bool
    {
        $this->authorizeStaff();
        $this->reset('newStaff', 'editStaff', 'editingId', 'editingName', 'editingEmail');
        $this->resetValidation();

        return true;
    }

    public function saveEditor(): bool
    {
        $this->authorizeStaff();
        $this->resetValidation();
        try {
            if ($this->editingId === null) {
                app(StaffManager::class)->create($this->newStaff);
            } else {
                app(StaffManager::class)->update($this->editingId, $this->editStaff);
            }
        } catch (ValidationException $exception) {
            $prefix = $this->editingId === null ? 'newStaff.' : 'editStaff.';
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($prefix.$field, $message);
                }
            }

            return false;
        } catch (ApiProblem $exception) {
            if ($exception->errorCode !== 'STALE_VERSION') {
                throw $exception;
            }
            $this->addError('editor', '该账号已被其他人修改。本次未保存，请取消后重新打开管理。');

            return false;
        }
        $message = $this->editingId === null
            ? '员工已创建；首次登录必须配置二次验证'
            : '权限已保存，旧会话已撤销';
        $this->discardEditor();
        Notification::make()->title($message)->success()->send();

        return true;
    }

    protected function authorizeStaff(): void
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);
    }

    protected function getViewData(): array
    {
        $this->authorizeStaff();

        return [
            'staffAccounts' => $this->tab === 'accounts' ? Staff::orderBy('created_at')->get() : collect(),
            'roles' => Role::cases(),
            'auditEvents' => $this->tab === 'audit'
                ? DB::table('identity_audit_events')->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get()
                : collect(),
        ];
    }
}
