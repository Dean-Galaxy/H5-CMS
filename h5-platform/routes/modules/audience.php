<?php

use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\Route;

// Owned by 04. No duplicate authentication or membership facts.
Route::middleware('auth:web')->group(function () {
    Route::get('/api/v1/me/progress/{drama_id}', fn () => throw new DependencyNotReady('Audience'));
    Route::put('/api/v1/me/progress/{drama_id}', fn () => throw new DependencyNotReady('Audience'));
});
