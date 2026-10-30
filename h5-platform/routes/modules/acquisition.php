<?php

use App\Modules\Acquisition\Http\TrackingController;
use App\Modules\Acquisition\Http\TrackingRequest;
use App\Modules\Acquisition\Http\TrackingThrottle;
use Illuminate\Support\Facades\Route;

Route::get('/r/{code}', [TrackingController::class, 'landing'])->middleware('throttle:60,1');
Route::prefix('/api/v1/tracking')->middleware(TrackingRequest::class)->group(function () {
    Route::get('context', [TrackingController::class, 'context'])->middleware(TrackingThrottle::class.':120,1');
    Route::post('landing', [TrackingController::class, 'confirmLanding'])->middleware(TrackingThrottle::class.':60,1')->block(10, 10);
    Route::post('consent', [TrackingController::class, 'consent'])->middleware(TrackingThrottle::class.':30,1')->block(10, 10);
    Route::post('events', [TrackingController::class, 'events'])->middleware(TrackingThrottle::class.':120,1')->block(10, 10);
});

// C-only API. No webpage consumer or identity/payment port is connected in c-core-1.
Route::prefix('/api/v2/measurement')->middleware(\App\Modules\Acquisition\Measurement\CoreRequest::class)->group(function () {
    $controller = \App\Modules\Acquisition\Measurement\CoreController::class;
    Route::get('context', [$controller, 'context'])->middleware('throttle:120,1');
    Route::post('start', [$controller, 'start'])->middleware('throttle:60,1')->block(10, 10);
    Route::patch('preferences', [$controller, 'preferences'])->middleware('throttle:30,1')->block(10, 10);
    Route::post('events', [$controller, 'events'])->middleware('throttle:120,1')->block(10, 10);
});
