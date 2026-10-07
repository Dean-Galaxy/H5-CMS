<?php

use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\Route;

// Owned by 05. Disabled attribution returns null without blocking billing.
Route::get('/r/{code}', fn () => throw new DependencyNotReady('Acquisition：D05 来源规则与落地插槽'));
