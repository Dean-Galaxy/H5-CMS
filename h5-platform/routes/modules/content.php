<?php

use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\Route;

// Owned by 02. Replace only after implementing the common contracts.
Route::get('/api/v1/home', fn () => throw new DependencyNotReady('Content'));
Route::get('/api/v1/categories', fn () => throw new DependencyNotReady('Content'));
Route::get('/api/v1/catalog', fn () => throw new DependencyNotReady('Content'));
Route::get('/api/v1/dramas/{id}', fn () => throw new DependencyNotReady('Content'));
Route::post('/api/v1/episodes/{id}/playback-grants', fn () => throw new DependencyNotReady('Content'));
