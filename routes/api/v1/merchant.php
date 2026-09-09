<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant API Routes
|--------------------------------------------------------------------------
|
| Routes for the merchant audience, behind auth:sanctum + role:merchant
| (see bootstrap/app.php).
|
*/

// Temporary — proves the auth:sanctum + role:merchant stack works.
// Replaced by real merchant endpoints in a later phase.
Route::get('/whoami', fn () => response()->json(['portal' => 'merchant']))
    ->name('merchant.whoami');
