<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Company API Routes
|--------------------------------------------------------------------------
|
| Routes for the company_admin audience. Middleware stack filled in a
| later phase.
|
*/

Route::get('/company/_ping', fn () => response()->json(['pong' => 'company']))
    ->name('company.ping');
