<?php

namespace App\Modules\Identity\Filament;

use App\Modules\Identity\Services\StaffPassword;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

class StaffProfile extends EditProfile
{
    protected function getPasswordFormComponent(): Component
    {
        // Keep Filament's current-password, confirmation, MFA and save throttling.
        // The viewer/global default intentionally remains at twelve characters.
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/edit-profile.form.password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.password.validation_attribute'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->rule(StaffPassword::rule())
            ->minLength(StaffPassword::MIN_LENGTH)
            ->maxLength(StaffPassword::MAX_LENGTH)
            ->helperText(StaffPassword::HELP.'；留空则不修改密码。')
            ->showAllValidationMessages()
            ->autocomplete('new-password')
            ->dehydrated(fn (#[SensitiveParameter] $state): bool => filled($state))
            ->dehydrateStateUsing(fn (#[SensitiveParameter] $state): string => Hash::make($state))
            ->live(debounce: 500)
            ->same('passwordConfirmation');
    }
}
