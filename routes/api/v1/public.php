<?php

use App\Domains\Shared\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
|
| Unauthenticated routes reachable by anyone. No tenant or auth context.
| Never gate anything here behind auth:sanctum.
|
*/

Route::get('/health', HealthController::class)->name('health');
