<?php

namespace App\Modules\Identity;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\AuditRecorder;
use App\Contracts\IdentityContext;
use App\Modules\Identity\Actions\CreateViewer;
use App\Modules\Identity\Actions\ResetViewerPassword;
use App\Modules\Identity\Console\CreateOwner;
use App\Modules\Identity\Http\AuthResponse;
use App\Modules\Identity\Mail\PreviewTransport;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\CurrentIdentity;
use App\Modules\Identity\Services\DatabaseAuditRecorder;
use App\Modules\Identity\Services\StaffDirectory;
use App\Modules\Identity\Services\Verification;
use App\Modules\Identity\Services\ViewerBehavior;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
        $this->app->bind(IdentityContext::class, CurrentIdentity::class);
        $this->app->bind(AcquisitionStaffDirectory::class, StaffDirectory::class);
        $this->app->bind(AuditRecorder::class, DatabaseAuditRecorder::class);
        $this->app->bind(LoginResponse::class, AuthResponse::class);
        $this->app->bind(RegisterResponse::class, AuthResponse::class);
    }

    public function boot(): void
    {
        // Never construct emailed credentials from an untrusted request Host header.
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));
        Password::defaults(fn () => Password::min(12)->letters()->numbers());
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/admin/livewire/update', $handle)->middleware('web'));
        Fortify::createUsersUsing(CreateViewer::class);
        Fortify::resetUserPasswordsUsing(ResetViewerPassword::class);
        Fortify::authenticateUsing(function (Request $request) {
            $user = Viewer::where('email', mb_strtolower(trim((string) $request->input('email'))))->first();
            $valid = Hash::check((string) $request->input('password'), $user?->password ?? '$2y$12$7Rk19nOXwtPE2dQLGTilvOGQDrm84Uy9NchgfW8rrMgNK3JD9nS2e');

            return $valid && $user?->active ? $user : null;
        });
        VerifyEmail::createUrlUsing(fn ($user) => app(Verification::class)->url($user));
        ResetPassword::createUrlUsing(fn ($user, $token) => url('/identity/reset-password').'?'.http_build_query(['token' => $token, 'email' => $user->email]));
        Mail::extend('preview', fn () => new PreviewTransport);
        RateLimiter::for('identity-login', fn (Request $r) => [Limit::perMinute(5)->by(hash('sha256', mb_strtolower((string) $r->input('email')).'|'.$r->ip())), Limit::perMinute(30)->by($r->ip())]);
        RateLimiter::for('identity-register', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('identity-mail', fn (Request $r) => [Limit::perMinute(1)->by('mail:'.($r->user('web')?->id ?? hash('sha256', mb_strtolower((string) $r->input('email'))))), Limit::perMinute(10)->by($r->ip())]);
        RateLimiter::for('identity-reset', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        Event::listen(Login::class, function (Login $event) {
            if (! in_array($event->guard, ['web', 'staff'], true)) {
                return;
            }
            session()->put('identity.version.'.$event->guard, $event->user->session_version);
            if ($event->guard === 'staff') {
                session()->forget('identity.mfa_staff');
            }
            app(AuditRecorder::class)->record('login', $event->guard, $event->user->id, 'success', ['guard' => $event->guard]);
            if ($event->guard === 'web' && $event->user instanceof Viewer) {
                app(ViewerBehavior::class)->preparingLogin($event->user);
                request()->attributes->set('identity.viewer_login', true);
            }
        });
        Event::listen(Failed::class, function (Failed $event): void {
            app(AuditRecorder::class)->record('login.failed', 'authentication', null, 'denied', ['guard' => $event->guard]);
            if ($event->guard === 'web') {
                request()->attributes->set('identity.invalid_credentials', true);
            }
        });
        Event::listen(Verified::class, function (Verified $event): void {
            if ($event->user instanceof Viewer && request()->attributes->get('identity_guard') === 'web') {
                // Do not collect an uncommitted verification if its surrounding audit/transaction fails.
                DB::afterCommit(fn () => app(ViewerBehavior::class)->verified($event->user));
            }
        });
        if ($this->app->runningInConsole()) {
            $this->commands([CreateOwner::class]);
        }
    }
}
