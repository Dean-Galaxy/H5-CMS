<?php

use App\Modules\Content\Http\AdminController;
use App\Modules\Content\Http\ContentEnabled;
use App\Modules\Content\Http\MediaController;
use App\Modules\Content\Http\PublicController;
use Illuminate\Support\Facades\Route;

Route::middleware(ContentEnabled::class)->group(function () {
    Route::get('/api/v1/home', [PublicController::class, 'homepage']);
    Route::get('/api/v1/categories', [PublicController::class, 'categories']);
    Route::get('/api/v1/catalog', [PublicController::class, 'catalog']);
    Route::get('/api/v1/dramas/{id}', [PublicController::class, 'drama']);
    Route::post('/api/v1/episodes/{id}/playback-grants', [PublicController::class, 'grant'])->middleware('throttle:120,1');
    Route::get('/api/v1/content/images/{id}', [MediaController::class, 'image'])->whereUuid('id');
    Route::get('/api/v1/content/playback/{id}/{file}', [MediaController::class, 'playback'])->whereUuid('id')->where('file', 'media\.mp4|master\.m3u8|captions\.m3u8|index\.m3u8|segment-[0-9]{5}\.ts|subtitles\.vtt')->name('content.media');
    Route::get('/admin/content/episodes/{id}/preview', [MediaController::class, 'preview'])->whereUuid('id');
    Route::get('/api/v1/admin/content/preview/{id}/{file}', [MediaController::class, 'previewMedia'])->whereUuid('id')->where('file', 'media\.mp4|master\.m3u8|captions\.m3u8|index\.m3u8|segment-[0-9]{5}\.ts|subtitles\.vtt')->name('content.preview-media');
    Route::prefix('/api/v1/admin/content')->group(function () {
        Route::get('/', [AdminController::class, 'index']);
        Route::get('/assets', [AdminController::class, 'assets']);
        Route::get('/dramas/{id}', [AdminController::class, 'drama'])->whereUuid('id');
        Route::post('/dramas', [AdminController::class, 'saveDrama']);
        Route::patch('/dramas/{id}', [AdminController::class, 'saveDrama'])->whereUuid('id');
        Route::post('/dramas/{drama}/episodes', [AdminController::class, 'saveEpisode'])->whereUuid('drama');
        Route::patch('/dramas/{drama}/episodes/{id}', [AdminController::class, 'saveEpisode'])->whereUuid(['drama', 'id']);
        Route::delete('/dramas/{drama}/episodes/{id}', [AdminController::class, 'removeEpisode'])->whereUuid(['drama', 'id']);
        Route::patch('/dramas/{id}/free', [AdminController::class, 'freeCount'])->whereUuid('id');
        Route::put('/dramas/{id}/plan', [AdminController::class, 'plan'])->whereUuid('id');
        foreach (['publication-preview' => 'preview', 'activate' => 'activate', 'pause' => 'pause', 'resume' => 'resume', 'publish' => 'publish', 'withdraw' => 'withdraw', 'relist' => 'relist'] as $path => $method) {
            Route::post('/dramas/{id}/'.$path, [AdminController::class, $method])->whereUuid('id');
        }
        Route::post('/categories', [AdminController::class, 'category']);
        Route::patch('/categories/{id}', [AdminController::class, 'category'])->whereUuid('id');
        Route::post('/banners', [AdminController::class, 'banner']);
        Route::patch('/banners/{id}', [AdminController::class, 'banner'])->whereUuid('id');
        Route::post('/default-preview', [AdminController::class, 'defaultPreview']);
        Route::patch('/default', [AdminController::class, 'saveDefault']);
        Route::post('/uploads', [AdminController::class, 'upload'])->middleware('throttle:120,1');
        Route::post('/uploads/{id}/chunks', [AdminController::class, 'chunk'])->whereUuid('id');
        Route::post('/uploads/{id}/finish', [AdminController::class, 'finish'])->whereUuid('id');
        Route::post('/uploads/{id}/cancel', [AdminController::class, 'cancel'])->whereUuid('id');
        Route::post('/uploads/{id}/retry', [AdminController::class, 'retry'])->whereUuid('id');
    });
});
