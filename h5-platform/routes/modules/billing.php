<?php

use App\Contracts\IdentityContext;
use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\Route;

// Owned by 03. Cashier installed, automatic routes/migrations deliberately not discovered.
Route::get('/api/v1/billing/offer', fn () => throw new DependencyNotReady('Billing'));
Route::post('/api/v1/billing/checkout', function (IdentityContext $identity) {
    $identity->requireViewer(verified: true);
    throw new DependencyNotReady('Billing');
});
Route::middleware('auth:web')->group(function () {
    Route::get('/api/v1/me/membership', fn () => throw new DependencyNotReady('Billing'));
    Route::get('/api/v1/billing/orders/{id}', fn () => throw new DependencyNotReady('Billing'));
    Route::post('/api/v1/billing/subscription/cancel', fn () => throw new DependencyNotReady('Billing'));
    Route::post('/api/v1/billing/subscription/resume', fn () => throw new DependencyNotReady('Billing'));
});
Route::post('/webhooks/stripe', fn () => throw new DependencyNotReady('Billing'));
