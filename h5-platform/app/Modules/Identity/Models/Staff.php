<?php

namespace App\Modules\Identity\Models;

use App\Enums\Role;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Staff extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasUuids, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    protected $table = 'identity_staff';

    protected $attributes = ['active' => true, 'version' => 1, 'session_version' => 1];

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'role' => Role::class, 'active' => 'boolean', 'version' => 'integer', 'session_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (Staff $staff): void {
            if ($staff->isDirty('password')) {
                $staff->session_version++;
                $staff->remember_token = null;
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->active;
    }

    public function toIdentityArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'role' => $this->role->value,
            'active' => $this->active, 'version' => $this->version,
            'mfa_configured' => filled($this->getAppAuthenticationSecret())];
    }
}
