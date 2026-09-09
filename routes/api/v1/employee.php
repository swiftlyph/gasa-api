<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Employee API Routes
|--------------------------------------------------------------------------
|
| Routes for the employee audience, behind auth:sanctum + role:employee
| (see bootstrap/app.php).
|
*/

// Temporary — proves the auth:sanctum + role:employee stack works.
// Replaced by real employee endpoints in a later phase.
Route::get('/whoami', fn () => response()->json(['portal' => 'employee']))
    ->name('employee.whoami');
