<?php

use App\Domains\Orders\Http\Controllers\OrderController;
use App\Domains\Orders\Http\Controllers\OrderTransitionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant API Routes
|--------------------------------------------------------------------------
|
| Routes for the merchant audience, behind auth:sanctum + role:merchant +
| EnsureMerchantActive (see bootstrap/app.php). Every route here is
| automatically tenant-scoped: {order} resolves through
| BelongsToMerchant's global scope, so another merchant's id is a 404
| rather than a 403 — a 403 would confirm the order exists.
|
*/

// Temporary — proves the auth:sanctum + role:merchant stack works.
// Replaced by real merchant endpoints in a later phase.
Route::get('/whoami', fn () => response()->json(['portal' => 'merchant']))
    ->name('merchant.whoami');

Route::prefix('orders')->name('merchant.orders.')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::get('/{order}', [OrderController::class, 'show'])->name('show');

    // POST, not PATCH: these are named operations on an order, not
    // arbitrary edits to its status field. There is deliberately no route
    // that sets `status` directly — every status change goes through the
    // transition map (see App\Domains\Orders\Enums\OrderStatus).
    Route::post('/{order}/complete', [OrderTransitionController::class, 'complete'])->name('complete');
    Route::post('/{order}/void', [OrderTransitionController::class, 'void'])->name('void');
});

// Note for later phases: there is NO DELETE route for an order, and there
// must never be one. Orders are financial records — voiding is the
// reversal, and it is terminal.
