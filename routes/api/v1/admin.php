<?php

use App\Domains\Merchant\Http\Controllers\AdminMerchantController;
use App\Domains\Platform\Http\Controllers\AdminAuditLogController;
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
