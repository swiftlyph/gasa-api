<?php

use App\Domains\Orders\Http\Controllers\CheckoutController;
use App\Domains\Orders\Http\Controllers\KitchenQueueController;
use App\Domains\Orders\Http\Controllers\MenuController;
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

// The POS product list. A read-only projection of the shared `products`
// table for the till — product management belongs to the catalog module,
// in its own namespace, and must not be added here.
Route::get('/menu', MenuController::class)->name('merchant.menu');

// The kitchen screen. A read-only VIEW over pending orders — there is no
// kitchen queue table and no kitchen-specific status. Completing a ticket
// goes through the ordinary transition endpoint below, so the transition
// map stays the only thing that decides what a legal status change is.
//
// Polled on a timer (no websockets yet), so both routes are pure reads.
Route::prefix('kitchen-queue')->name('merchant.kitchen-queue.')->group(function (): void {
    // Declared before the bare route purely for reading order; neither is
    // parameterised, so they cannot collide.
    Route::get('/summary', [KitchenQueueController::class, 'summary'])->name('summary');
    Route::get('/', [KitchenQueueController::class, 'index'])->name('index');
});

Route::prefix('orders')->name('merchant.orders.')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->name('index');

    // Checkout. Declared before the /{order} routes purely for reading
    // order; it can't collide with them, since those are GET/POST on a
    // parameter segment.
    Route::post('/', CheckoutController::class)->name('store');

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
