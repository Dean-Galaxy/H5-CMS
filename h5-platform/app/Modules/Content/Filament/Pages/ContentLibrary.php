<?php

namespace App\Modules\Content\Filament\Pages;

use App\Contracts\IdentityContext;
use App\Enums\Permission;
use App\Enums\Role;
use Filament\Pages\Page;

final class ContentLibrary extends Page
{
    protected static ?string $title = '内容管理';

    protected static ?string $slug = 'content-library';

    protected string $view = 'content.library';

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        $user = auth('staff')->user();

        return $user?->active && in_array($user->role, [Role::Owner, Role::ContentOperator], true);
    }

    public function mount(): void
    {
        app(IdentityContext::class)->requireStaff(Permission::ContentManage);
    }

    protected function getViewData(): array
    {
        $staff = app(IdentityContext::class)->requireStaff(Permission::ContentManage);

        return ['isOwner' => $staff->role === Role::Owner];
    }
}
