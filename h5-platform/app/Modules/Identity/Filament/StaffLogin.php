<?php

namespace App\Modules\Identity\Filament;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;

class StaffLogin extends Login
{
    public function authenticate(): ?LoginResponse
    {
        $this->data['remember'] = false;
        $this->data['email'] = mb_strtolower(trim($this->data['email'] ?? ''));
        $response = parent::authenticate();
        // Only the successful native MFA challenge may establish this proof.
        $staff = Filament::auth()->user();
        if ($response && $staff && filled($staff->getAppAuthenticationSecret())) {
            session()->put('identity.mfa_staff', $staff->id);
        }

        return $response;
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->hidden()->dehydrated(false);
    }
}
