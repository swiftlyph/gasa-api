<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant API Routes
|--------------------------------------------------------------------------
|
| Routes for the merchant audience. Middleware stack filled in a later
| phase.
|
*/

Route::get('/merchant/_ping', fn () => response()->json(['pong' => 'merchant']))
    ->name('merchant.ping');
