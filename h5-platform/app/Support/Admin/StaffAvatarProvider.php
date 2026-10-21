<?php

namespace App\Support\Admin;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

final class StaffAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        // Staff names and initials must not be sent to an external avatar service.
        return asset('admin-ui/staff-avatar.svg');
    }
}
