<?php

use App\Modules\Audience\Billing\BillingController;
use App\Modules\Audience\Http\AudienceEnabled;
use App\Modules\Audience\Http\CatalogController;
use App\Modules\Audience\Http\ProgressController;
use Illuminate\Support\Facades\Route;

// Only an explicitly loaded Audience replaces the foundation landing page.
if (config('h5.modules.audience.ready')) {
    Route::middleware(AudienceEnabled::class)->group(function () {
        Route::get('/', [CatalogController::class, 'home'])->name('audience.home');
        Route::get('/catalog', [CatalogController::class, 'catalog'])->name('audience.catalog');
        Route::get('/dramas/{id}', [CatalogController::class, 'drama'])->whereUuid('id')->name('audience.drama');
        Route::get('/dramas/{id}/episodes/{episode}', [CatalogController::class, 'watch'])->whereUuid(['id', 'episode'])->name('audience.watch');
        Route::post('/audience/dramas/{drama}/episodes/{episode}/playback', [ProgressController::class, 'playback'])->whereUuid(['drama', 'episode'])->middleware('throttle:120,1');
        Route::get('/membership', [BillingController::class, 'index']);
        Route::get('/billing/return', [BillingController::class, 'paymentReturn']);
        Route::middleware('auth:web')->group(function () {
            Route::post('/membership/checkout', [BillingController::class, 'checkout'])->middleware('throttle:30,1')->block(10, 10);
            Route::post('/billing/return/recheck', [BillingController::class, 'recheckPaymentReturn'])->middleware('throttle:30,1')->block(10, 10);
            Route::post('/membership/cancel', [BillingController::class, 'cancel'])->middleware('throttle:30,1')->block(10, 10);
            Route::post('/membership/resume', [BillingController::class, 'resume'])->middleware('throttle:30,1')->block(10, 10);
        });
    });
}

Route::middleware(['auth:web', AudienceEnabled::class])->group(function () {
    Route::get('/api/v1/me/progress/{drama_id}', [ProgressController::class, 'show'])->whereUuid('drama_id');
    Route::put('/api/v1/me/progress/{drama_id}', [ProgressController::class, 'save'])->whereUuid('drama_id')->middleware('throttle:120,1');
});
