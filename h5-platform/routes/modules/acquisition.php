<?php

use App\Modules\Acquisition\Http\TrackingController;
use App\Modules\Acquisition\Http\TrackingRequest;
use App\Modules\Acquisition\Http\TrackingThrottle;
use App\Modules\Acquisition\Measurement\CoreController;
use App\Modules\Acquisition\Measurement\CoreRequest;
use App\Modules\Acquisition\Measurement\CoreThrottle;
use App\Modules\Acquisition\Registration\PurposeController;
use App\Modules\Acquisition\Registration\PurposeThrottle;
use Illuminate\Support\Facades\Route;

Route::get('/r/{code}', [TrackingController::class, 'landing'])->middleware('throttle:60,1');
Route::prefix('/api/v1/tracking')->middleware(TrackingRequest::class)->group(function () {
    Route::get('context', [TrackingController::class, 'context'])->middleware(TrackingThrottle::class.':120,1');
    Route::post('landing', [TrackingController::class, 'confirmLanding'])->middleware(TrackingThrottle::class.':60,1')->block(10, 10);
    Route::post('consent', [TrackingController::class, 'consent'])->middleware(TrackingThrottle::class.':30,1')->block(10, 10);
    Route::post('events', [TrackingController::class, 'events'])->middleware(TrackingThrottle::class.':120,1')->block(10, 10);
});

// C API: route-specific budgets keep optional traffic out of necessary preference writes.
Route::prefix('/api/v2/measurement')->middleware(CoreRequest::class)->group(function () {
    $controller = CoreController::class;
    Route::get('context', [$controller, 'context'])->middleware(CoreThrottle::class.':context');
    Route::post('start', [$controller, 'start'])->middleware(CoreThrottle::class.':start')->block(10, 10);
    Route::patch('preferences', [$controller, 'preferences'])->middleware(CoreThrottle::class.':preferences')->block(10, 10);
    Route::post('events', [$controller, 'events'])->middleware(CoreThrottle::class.':events')->block(10, 10);
});

Route::prefix('/api/v3/measurement/purposes')->middleware(CoreRequest::class)->group(function () {
    $controller = PurposeController::class;
    $throttle = PurposeThrottle::class;
    Route::get('context', [$controller, 'context'])->middleware($throttle.':context');
    Route::patch('preferences', [$controller, 'preferences'])->middleware($throttle.':preferences')->block(10, 10);
});
