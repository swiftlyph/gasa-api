<?php

namespace App\Domains\Company\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Actions\CreateEmployeeAction;
use App\Domains\Company\Actions\DeleteEmployeeAction;
use App\Domains\Company\Actions\UpdateEmployeeAction;
use App\Domains\Company\Http\Requests\IndexEmployeesRequest;
use App\Domains\Company\Http\Requests\StoreEmployeeRequest;
use App\Domains\Company\Http\Requests\UpdateEmployeeRequest;
use App\Domains\Company\Http\Resources\EmployeeResource;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Employee;
use App\Domains\Company\Support\EmployeeFilters;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The company's employee roster. Every route here is tenant-scoped:
 * {employee} resolves through BelongsToCompany's global scope, so
 * another company's id is a 404 rather than a 403 (a 403 would confirm
 * the row exists). Soft-deleted employees are likewise 404 (SoftDeletes'
 * own scope). EmployeePolicy is auto-discovered for Employee::class, so
 * $this->authorize() works here without the TeamController workaround.
 *
 * Every response eager-loads `department`, so EmployeeResource never
 * issues a query of its own.
 */
class EmployeeController extends Controller
{
    /**
     * Three queries regardless of roster size: one count for pagination,
     * one page, one for that page's departments. Filters and ordering
     * live in EmployeeFilters, shared with the CSV export.
     */
    public function index(IndexEmployeesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $employees = EmployeeFilters::query($request->validated())
            ->paginate($request->validated('per_page', 25))
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): JsonResponse
    {
        $this->authorize('create', Employee::class);

        /** @var User $user */
        $user = $request->user();

        /** @var Company $company */
        $company = $user->activeCompany();

        $employee = $action->execute($company, $request->payload());

        return EmployeeResource::make($employee->load('department'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return EmployeeResource::make($employee->load('department'));
    }

    public function update(
        UpdateEmployeeRequest $request,
        Employee $employee,
        UpdateEmployeeAction $action,
    ): EmployeeResource {
        $this->authorize('update', $employee);

        $updated = $action->execute($employee, $request->payload());

        return EmployeeResource::make($updated->load('department'));
    }

    public function destroy(Employee $employee, DeleteEmployeeAction $action): JsonResponse
    {
        $this->authorize('delete', $employee);

        $action->execute($employee);

        // Mirrors TeamController::destroy()'s confirmation shape exactly:
        // a small 200 JSON body, never a bare 204.
        return response()->json(['message' => 'Employee removed.', 'code' => 'employee_removed']);
    }
}
