<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Employee API Routes
|--------------------------------------------------------------------------
|
| Routes for the employee audience. Middleware stack filled in a later
| phase.
|
*/

Route::get('/employee/_ping', fn () => response()->json(['pong' => 'employee']))
    ->name('employee.ping');
