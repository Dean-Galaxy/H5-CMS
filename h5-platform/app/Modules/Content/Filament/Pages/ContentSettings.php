<?php

namespace App\Modules\Content\Filament\Pages;

use App\Contracts\IdentityContext;
use App\Enums\Permission;
use App\Enums\Role;
use Filament\Pages\Page;

final class ContentSettings extends Page
{
    protected static ?string $title = '全局设置';

    protected static ?string $slug = 'content-settings';

    protected string $view = 'content.settings';

    public static function canAccess(): bool
    {
        $staff = auth('staff')->user();

        return $staff?->active && $staff->role === Role::Owner;
    }

    public function mount(): void
    {
        app(IdentityContext::class)->requireStaff(Permission::FreeDefaultManage);
    }
}
