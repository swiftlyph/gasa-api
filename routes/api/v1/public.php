<?php

use App\Domains\Auth\Http\Controllers\AcceptInviteController;
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

// P7: redeem a team invitation — sets the invited user's password and
// signs them in exactly like login does. Throttled the same as login:
// AppServiceProvider's 'login' limiter keys on email+IP, and this request
// has no `email` field, so it degrades to keying on IP alone here — still
// an effective per-IP rate limit, just not email-scoped the way login's is.
Route::post('/auth/accept-invite', AcceptInviteController::class)
    ->middleware('throttle:login')
    ->name('auth.accept-invite');
