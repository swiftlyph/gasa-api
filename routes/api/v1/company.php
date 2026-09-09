<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Company API Routes
|--------------------------------------------------------------------------
|
| Routes for the company_admin audience, behind auth:sanctum +
| role:company_admin (see bootstrap/app.php).
|
*/

// Temporary — proves the auth:sanctum + role:company_admin stack works.
// Replaced by real company endpoints in a later phase.
Route::get('/whoami', fn () => response()->json(['portal' => 'company']))
    ->name('company.whoami');
