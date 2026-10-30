<?php

use App\Modules\Identity\Http\IdentityController as Identity;
use App\Modules\Identity\Http\RememberReturn;
use App\Modules\Identity\Http\StaffController;
use App\Modules\Identity\Http\StaffMfa;
use App\Modules\Identity\Http\TrackViewerAuthentication;
use App\Modules\Identity\Http\VerificationLogin;
use App\Modules\Identity\Http\ViewerRegistrationController;
use App\Modules\Identity\Services\RegistrationTerms;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::prefix('api/v1/auth')->group(function () {
    Route::get('csrf', [Identity::class, 'csrf']);
    Route::get('me', [Identity::class, 'me']);
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware(['guest:web', TrackViewerAuthentication::class.':login', RememberReturn::class])->name('login.store');
    Route::post('register', [ViewerRegistrationController::class, 'store'])->middleware(['guest:web', TrackViewerAuthentication::class.':registration', RememberReturn::class])->name('register.store');
    Route::post('forgot-password', [Identity::class, 'forgot'])->middleware('throttle:identity-mail')->name('password.email');
    Route::post('reset-password', [Identity::class, 'reset'])->middleware('throttle:identity-reset')->name('password.update');
    Route::get('email/verify/{id}/{hash}', [Identity::class, 'verify'])->middleware([VerificationLogin::class, 'throttle:identity-reset'])->name('verification.verify');
    Route::middleware('auth:web')->group(function () {
        Route::post('logout', [Identity::class, 'logout'])->name('logout');
        Route::post('email/verification-notification', [Identity::class, 'resend'])->middleware('throttle:identity-mail')->name('verification.send');
        Route::patch('email', [Identity::class, 'correctEmail'])->middleware('throttle:identity-mail');
    });
});
Route::prefix('identity')->group(function () {
    foreach (['test-terms' => 'terms', 'test-privacy' => 'privacy'] as $path => $document) {
        Route::get($path, function () use ($document) {
            abort_unless(RegistrationTerms::enabled(), 404);

            return view('identity.test-document', ['document' => $document]);
        });
    }
    Route::get('login', fn () => view('identity.login'))->middleware(RememberReturn::class)->name('login');
    Route::get('register', fn () => view('identity.register'))->middleware(RememberReturn::class)->name('register');
    Route::get('forgot-password', fn () => view('identity.forgot'))->name('password.request');
    Route::get('reset-password', fn () => view('identity.reset'))->name('password.reset');
    Route::get('account', fn () => view('identity.account'))->middleware('auth:web')->name('verification.notice');
});
Route::prefix('api/v1/admin')->middleware(StaffMfa::class)->group(function () {
    Route::get('csrf', [Identity::class, 'csrf']);
    Route::get('staff', [StaffController::class, 'index']);
    Route::post('staff', [StaffController::class, 'create']);
    Route::patch('staff/{id}', [StaffController::class, 'update'])->whereUuid('id');
});
