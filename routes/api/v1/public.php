<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
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

Route::get('/health', function (Request $request) {
    $db = 'ok';

    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        $db = 'error';
    }

    $redis = 'ok';

    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $redis = 'error';
    }

    return response()->json([
        'app' => config('app.name'),
        'version' => config('app.version'),
        'db' => $db,
        'redis' => $redis,
    ]);
})->name('health');
