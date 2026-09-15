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
 */
class EmployeeController extends Controller
{
    /**
     * Two queries regardless of roster size: one count for pagination,
     * one page. Ordered by surname, then given name, tiebroken by id so
     * two employees with the same name paginate stably.
     */
    public function index(IndexEmployeesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $query = Employee::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->validated('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('employee_no', 'ilike', "%{$search}%");
            });
        }

        $employees = $query->paginate($request->validated('per_page', 25))->withQueryString();

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

        return EmployeeResource::make($employee)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return EmployeeResource::make($employee);
    }

    public function update(
        UpdateEmployeeRequest $request,
        Employee $employee,
        UpdateEmployeeAction $action,
    ): EmployeeResource {
        $this->authorize('update', $employee);

        return EmployeeResource::make($action->execute($employee, $request->payload()));
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
