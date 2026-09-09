<?php

use App\Domains\Auth\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared Authenticated Routes
|--------------------------------------------------------------------------
|
| Routes reachable by any authenticated user regardless of role/portal.
| Registered once here rather than duplicated per audience file.
|
*/

Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
