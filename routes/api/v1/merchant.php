<?php

use App\Domains\CashSessions\Http\Controllers\CashMovementController;
use App\Domains\CashSessions\Http\Controllers\CashRemittanceController;
use App\Domains\CashSessions\Http\Controllers\CashSessionController;
use App\Domains\CashSessions\Http\Controllers\RegisterController;
use App\Domains\Catalog\Http\Controllers\CategoryController;
use App\Domains\Catalog\Http\Controllers\IngredientController;
use App\Domains\Catalog\Http\Controllers\ProductController;
use App\Domains\Catalog\Http\Controllers\RecipeController;
use App\Domains\Merchant\Http\Controllers\MerchantProfileController;
use App\Domains\Merchant\Http\Controllers\TeamController;
use App\Domains\Orders\Http\Controllers\CheckoutController;
use App\Domains\Orders\Http\Controllers\KitchenQueueController;
use App\Domains\Orders\Http\Controllers\MenuController;
use App\Domains\Orders\Http\Controllers\OrderController;
use App\Domains\Orders\Http\Controllers\OrderTransitionController;
use App\Domains\Orders\Http\Controllers\ReportController;
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

// P7: the merchant's own profile — name, status, and every profile field
// (address, contact, tax id, receipt copy). No {merchant} parameter:
// "which merchant" always comes from the caller's own $user->merchant(),
// never from the URL.
Route::prefix('profile')->name('merchant.profile.')->group(function (): void {
    Route::get('/', [MerchantProfileController::class, 'show'])->name('show');
    Route::patch('/', [MerchantProfileController::class, 'update'])->name('update');
});

// P7: team members. {user} binds directly to the Auth\Models\User model
// (not tenant-scoped via BelongsToMerchant — User has no merchant_id
// column of its own), so TeamController verifies membership in the
// caller's own merchant explicitly and 404s a foreign id, matching every
// other tenant-owned resource in this file.
Route::prefix('team')->name('merchant.team.')->group(function (): void {
    Route::get('/', [TeamController::class, 'index'])->name('index');
    Route::post('/', [TeamController::class, 'store'])->name('store');
    Route::patch('/{user}', [TeamController::class, 'update'])->name('update');
    Route::delete('/{user}', [TeamController::class, 'destroy'])->name('destroy');
});

// The catalog module: full product management (create, edit, delete,
// browse) over the shared `products` contract table (see that
// migration's docblock), each product's recipe, and the ingredients that
// recipe draws from — the ingredients ARE this merchant's inventory now
// (see README § Catalog). {product}/{ingredient} resolve through their
// merchant-scoped models, so a foreign id is a 404 on every verb, never
// a 403.
Route::get('/catalog/categories', [CategoryController::class, 'index'])->name('merchant.catalog.categories');

Route::prefix('products')->name('merchant.products.')->group(function (): void {
    Route::get('/', [ProductController::class, 'index'])->name('index');
    Route::post('/', [ProductController::class, 'store'])->name('store');
    Route::get('/{product}', [ProductController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '/{product}', [ProductController::class, 'update'])->name('update');
    Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');

    // Replaces the whole recipe in one call — see UpdateRecipeRequest's
    // docblock for why that's the shape rather than add/remove-one-line.
    Route::put('/{product}/recipe', [RecipeController::class, 'update'])->name('recipe.update');
});

// Ingredients: the merchant's actual inventory (quantity on hand, in the
// ingredient's own base unit, plus a low-stock threshold). Selling a
// product that recipes one deducts it automatically at checkout — see
// App\Domains\Catalog\Actions\DeductIngredientsForOrderAction.
Route::prefix('ingredients')->name('merchant.ingredients.')->group(function (): void {
    Route::get('/', [IngredientController::class, 'index'])->name('index');
    Route::post('/', [IngredientController::class, 'store'])->name('store');
    Route::get('/{ingredient}', [IngredientController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '/{ingredient}', [IngredientController::class, 'update'])->name('update');
    Route::delete('/{ingredient}', [IngredientController::class, 'destroy'])->name('destroy');
});

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

    // P9: printable receipt data — no PDF, no printer driver. Always 200,
    // even for a voided order (see OrderController::receipt's docblock).
    Route::get('/{order}/receipt', [OrderController::class, 'receipt'])->name('receipt');

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

// Registers. Listing only this phase — see App\Domains\CashSessions\
// Models\Register's docblock for why there is no register CRUD here.
Route::get('/registers', [RegisterController::class, 'index'])->name('merchant.registers.index');

// Cash sessions, movements and remittances (P4). A cash session is a
// till-shift: opened with a float, closed with a count. Movements and
// remittances are always created against an explicit session, never
// addressed on their own, which is why their routes are nested under
// /cash-sessions/{cashSession}/... rather than living at their own
// top-level prefix.
Route::prefix('cash-sessions')->name('merchant.cash-sessions.')->group(function (): void {
    Route::post('/', [CashSessionController::class, 'open'])->name('open');
    Route::get('/', [CashSessionController::class, 'index'])->name('index');

    // MUST be declared before /{cashSession}: unlike the static routes
    // elsewhere in this file, this one really would collide the other way
    // round — Laravel matches routes in declaration order, so "current"
    // would otherwise be swallowed by {cashSession} and looked up as if
    // it were an id.
    Route::get('/current', [CashSessionController::class, 'current'])->name('current');

    Route::get('/{cashSession}', [CashSessionController::class, 'show'])->name('show');
    Route::post('/{cashSession}/close', [CashSessionController::class, 'close'])->name('close');

    // P9: the printable shift summary — sales attributed to THIS session
    // (cash_session_id, never a date range) plus the same reconciliation
    // block GET /{cashSession} already reports, reused rather than
    // re-derived. Works on an open session (live figures) exactly as it
    // does on a closed one (final figures).
    Route::get('/{cashSession}/z-report', [CashSessionController::class, 'zReport'])->name('z-report');

    Route::post('/{cashSession}/movements', [CashMovementController::class, 'store'])
        ->name('movements.store');

    Route::post('/{cashSession}/remittances', [CashRemittanceController::class, 'store'])
        ->name('remittances.store');
});

// Remittance confirmation lives at its own top-level route rather than
// nested under a session, because confirming addresses the remittance
// itself, not the session it belongs to — mirroring how order transitions
// are addressed by {order}, not by some parent resource.
Route::post('/remittances/{remittance}/confirm', [CashRemittanceController::class, 'confirm'])
    ->name('merchant.remittances.confirm');

// Note for later phases: there is no DELETE anywhere in this group either.
// A cash session, a movement, and a remittance are all financial records —
// the same "never deleted" rule that governs orders.

// Date-range reporting over orders (P6). READ-ONLY — see README §
// Reporting for the accounting rules and ReportController's docblock for
// why session-scoped (Z-report) reporting is deliberately not here yet.
Route::prefix('reports')->name('merchant.reports.')->group(function (): void {
    Route::get('/sales-summary', [ReportController::class, 'salesSummary'])->name('sales-summary');
    Route::get('/sales-by-day', [ReportController::class, 'salesByDay'])->name('sales-by-day');
    Route::get('/top-items', [ReportController::class, 'topItems'])->name('top-items');
});
