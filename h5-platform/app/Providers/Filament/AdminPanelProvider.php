<?php

namespace App\Providers\Filament;

use App\Modules\Identity\Filament\StaffLogin;
use App\Modules\Identity\Filament\StaffProfile;
use App\Modules\Identity\Http\SessionProtection;
use App\Modules\Identity\Http\StaffPanelMfa;
use App\Support\Admin\StaffAvatarProvider;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel->default()->id('admin')->path('admin')->brandName('H5 员工后台')->login(StaffLogin::class)->authGuard('staff')->profile(StaffProfile::class)
            ->defaultAvatarProvider(StaffAvatarProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('components.admin.assets'))
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->pages([Dashboard::class])
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                AuthenticateSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class, SubstituteBindings::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class, SessionProtection::class])
            ->authMiddleware([Authenticate::class, StaffPanelMfa::class], isPersistent: true);
        foreach (['Identity', 'Content', 'Billing', 'Audience', 'Acquisition'] as $module) {
            $panel->discoverResources(in: app_path("Modules/$module/Filament/Resources"), for: "App\\Modules\\$module\\Filament\\Resources")
                ->discoverPages(in: app_path("Modules/$module/Filament/Pages"), for: "App\\Modules\\$module\\Filament\\Pages")
                ->discoverWidgets(in: app_path("Modules/$module/Filament/Widgets"), for: "App\\Modules\\$module\\Filament\\Widgets");
        }

        return $panel;
    }
}
