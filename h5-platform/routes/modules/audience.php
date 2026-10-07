<?php

use App\Modules\Audience\Http\AudienceEnabled;
use App\Modules\Audience\Http\CatalogController;
use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\Route;

// Only an explicitly loaded Audience replaces the foundation landing page.
if (config('h5.modules.audience.ready')) {
    Route::middleware(AudienceEnabled::class)->group(function () {
        Route::get('/', [CatalogController::class, 'home'])->name('audience.home');
        Route::get('/catalog', [CatalogController::class, 'catalog'])->name('audience.catalog');
        Route::get('/dramas/{id}', [CatalogController::class, 'drama'])->whereUuid('id')->name('audience.drama');
        Route::get('/dramas/{id}/episodes/{episode}', [CatalogController::class, 'watch'])->whereUuid(['id', 'episode'])->name('audience.watch');
    });
}

Route::middleware('auth:web')->group(function () {
    Route::get('/api/v1/me/progress/{drama_id}', fn () => throw new DependencyNotReady('Audience'));
    Route::put('/api/v1/me/progress/{drama_id}', fn () => throw new DependencyNotReady('Audience'));
});
