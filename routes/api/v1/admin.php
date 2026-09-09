<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Admin API Routes
|--------------------------------------------------------------------------
|
| Routes for the platform_admin audience. Middleware stack filled in a
| later phase.
|
*/

Route::get('/admin/_ping', fn () => response()->json(['pong' => 'admin']))
    ->name('admin.ping');
