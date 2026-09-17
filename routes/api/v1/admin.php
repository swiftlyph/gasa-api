<?php

use App\Domains\Merchant\Http\Controllers\AdminMerchantController;
use App\Domains\Platform\Http\Controllers\AdminAuditLogController;
use App\Domains\Platform\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Admin API Routes
|--------------------------------------------------------------------------
|
| Routes for the platform_admin audience, behind auth:sanctum +
| role:platform_admin + AllowsAdminContext (see bootstrap/app.php).
| AllowsAdminContext is what lifts BelongsToMerchant's tenant scope for
| every query below — never a manual TenantContext::runInAdminContext()
| call in any controller or Action reachable from here.
|
*/

Route::get('/whoami', fn () => response()->json(['portal' => 'admin']))
    ->name('admin.whoami');

// P5: merchant provisioning and lifecycle management. No {merchant}
// resolves outside this file's tenant-bypassing context — see
// AdminMerchantController's docblock.
Route::prefix('merchants')->name('admin.merchants.')->group(function (): void {
    Route::get('/', [AdminMerchantController::class, 'index'])->name('index');
    Route::post('/', [AdminMerchantController::class, 'store'])->name('store');
    Route::get('/{merchant}', [AdminMerchantController::class, 'show'])->name('show');
    Route::patch('/{merchant}/status', [AdminMerchantController::class, 'updateStatus'])->name('update-status');
    Route::post('/{merchant}/resend-invite', [AdminMerchantController::class, 'resendInvite'])->name('resend-invite');
});

// P5: the audit trail. Deliberately reachable ONLY here — audit_logs
// carries no tenant scope of its own, so this route group is the entire
// access control for it (see App\Domains\Platform\Models\AuditLog's
// docblock).
Route::prefix('audit-logs')->name('admin.audit-logs.')->group(function (): void {
    Route::get('/', [AdminAuditLogController::class, 'index'])->name('index');
});

// P11: user management across every audience. `{user}` binds withTrashed()
// so a deactivated account stays viewable and restorable — the default
// binding would 404 the moment a user was deactivated, stranding them.
//
// No policy layer, matching the merchant routes above: every
// platform_admin may act on every user, so this route group is the
// authorization boundary. The lockout guards (self, last admin, merchant
// owner) live in the Actions, not here.
Route::prefix('users')->name('admin.users.')->group(function (): void {
    Route::get('/', [AdminUserController::class, 'index'])->name('index');
    Route::post('/', [AdminUserController::class, 'store'])->name('store');

    Route::get('/{user}', [AdminUserController::class, 'show'])
        ->withTrashed()->name('show');
    Route::patch('/{user}/role', [AdminUserController::class, 'updateRole'])
        ->withTrashed()->name('update-role');
    Route::delete('/{user}', [AdminUserController::class, 'destroy'])
        ->name('destroy');
    Route::post('/{user}/restore', [AdminUserController::class, 'restore'])
        ->withTrashed()->name('restore');
    Route::post('/{user}/resend-invite', [AdminUserController::class, 'resendInvite'])
        ->withTrashed()->name('resend-invite');
});
