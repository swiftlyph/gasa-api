<?php

use App\Domains\Company\Http\Controllers\CompanyProfileController;
use App\Domains\Company\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Company API Routes
|--------------------------------------------------------------------------
|
| Routes for the company_admin audience, behind auth:sanctum +
| role:company_admin + EnsureCompanyActive (see bootstrap/app.php). Every
| route here is automatically tenant-scoped: {employee} resolves through
| BelongsToCompany's global scope, so another company's id is a 404
| rather than a 403.
|
*/

// Proves the auth:sanctum + role:company_admin + EnsureCompanyActive
// stack works; kept alongside the real endpoints like the other portal
// files do.
Route::get('/whoami', fn () => response()->json(['portal' => 'company']))
    ->name('company.whoami');

// The company's own profile. No {company} parameter: "which company"
// always comes from the caller's own $user->activeCompany(), never from
// the URL. Read-only until the provisioning phase adds a PATCH.
Route::get('/profile', [CompanyProfileController::class, 'show'])->name('company.profile.show');

// The employee roster: HR records, not logins (see
// App\Domains\Company\Models\Employee). DELETE is a soft delete.
Route::prefix('employees')->name('company.employees.')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])->name('index');
    Route::post('/', [EmployeeController::class, 'store'])->name('store');
    Route::get('/{employee}', [EmployeeController::class, 'show'])->name('show');
    Route::patch('/{employee}', [EmployeeController::class, 'update'])->name('update');
    Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->name('destroy');
});
