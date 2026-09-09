<?php

use App\Domains\Auth\Http\Controllers\AuthController;
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

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');
