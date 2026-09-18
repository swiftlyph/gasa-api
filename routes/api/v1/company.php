<?php

use App\Domains\Allowance\Http\Controllers\EmployeeAllowanceController;
use App\Domains\Company\Http\Controllers\CompanyProfileController;
use App\Domains\Company\Http\Controllers\DepartmentController;
use App\Domains\Company\Http\Controllers\EmployeeController;
use App\Domains\Company\Http\Controllers\EmployeeExportController;
use App\Domains\Company\Http\Controllers\EmployeeImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Company API Routes
|--------------------------------------------------------------------------
|
| Routes for the company_admin audience, behind auth:sanctum +
| role:company_admin + EnsureCompanyActive (see bootstrap/app.php). Every
| route here is automatically tenant-scoped: {employee} and {department}
| resolve through BelongsToCompany's global scope, so another company's id
| is a 404 rather than a 403.
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

// Departments: what employees are grouped under, and what allowance will
// be targeted by. DELETE only ever removes an EMPTY department.
Route::prefix('departments')->name('company.departments.')->group(function (): void {
    Route::get('/', [DepartmentController::class, 'index'])->name('index');
    Route::post('/', [DepartmentController::class, 'store'])->name('store');
    Route::patch('/{department}', [DepartmentController::class, 'update'])->name('update');
    Route::delete('/{department}', [DepartmentController::class, 'destroy'])->name('destroy');
});

// The employee roster: HR records, not logins (see
// App\Domains\Company\Models\Employee). DELETE is a soft delete.
Route::prefix('employees')->name('company.employees.')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])->name('index');
    Route::post('/', [EmployeeController::class, 'store'])->name('store');

    // MUST be declared before /{employee}: Laravel matches in declaration
    // order, so GET /export would otherwise be swallowed by {employee}
    // and looked up as if "export" were an id.
    Route::get('/export', EmployeeExportController::class)->name('export');
    Route::post('/import', EmployeeImportController::class)->name('import');

    Route::get('/{employee}/allowance', [EmployeeAllowanceController::class, 'show'])->name('allowance.show');
    Route::post('/{employee}/allowance/grants', [EmployeeAllowanceController::class, 'grant'])->name('allowance.grant');

    Route::get('/{employee}', [EmployeeController::class, 'show'])->name('show');
    Route::patch('/{employee}', [EmployeeController::class, 'update'])->name('update');
    Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->name('destroy');
});
