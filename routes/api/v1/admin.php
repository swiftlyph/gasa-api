<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Admin API Routes
|--------------------------------------------------------------------------
|
| Routes for the platform_admin audience, behind auth:sanctum +
| role:platform_admin (see bootstrap/app.php).
|
*/

// Temporary — proves the auth:sanctum + role:platform_admin stack works.
// Replaced by real admin endpoints in a later phase.
Route::get('/whoami', fn () => response()->json(['portal' => 'admin']))
    ->name('admin.whoami');
