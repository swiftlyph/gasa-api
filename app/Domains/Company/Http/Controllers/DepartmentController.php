<?php

namespace App\Domains\Company\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Actions\CreateDepartmentAction;
use App\Domains\Company\Actions\DeleteDepartmentAction;
use App\Domains\Company\Actions\UpdateDepartmentAction;
use App\Domains\Company\Http\Requests\SaveDepartmentRequest;
use App\Domains\Company\Http\Resources\DepartmentResource;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Department;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * A company's departments. Tenant-scoped like everything else under
 * /company: {department} resolves through BelongsToCompany, so another
 * company's id is a 404, never a 403.
 */
class DepartmentController extends Controller
{
    /**
     * Unpaginated (a company has tens of departments, not thousands), in
     * the explicit { data: [...] } envelope every unpaginated list uses
     * (README § Response shapes). One query: the head counts are folded
     * in with withCount() rather than looped.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $departments = Department::query()
            ->withCount('employees')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => DepartmentResource::collection($departments)->resolve(),
        ]);
    }

    public function store(SaveDepartmentRequest $request, CreateDepartmentAction $action): JsonResponse
    {
        $this->authorize('create', Department::class);

        /** @var User $user */
        $user = $request->user();

        /** @var Company $company */
        $company = $user->activeCompany();

        $department = $action->execute($company, $request->name());

        return DepartmentResource::make($department)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(
        SaveDepartmentRequest $request,
        Department $department,
        UpdateDepartmentAction $action,
    ): DepartmentResource {
        $this->authorize('update', $department);

        return DepartmentResource::make($action->execute($department, $request->name()));
    }

    public function destroy(Department $department, DeleteDepartmentAction $action): JsonResponse
    {
        $this->authorize('delete', $department);

        $action->execute($department);

        // The API's confirmation shape: a small 200 body, never a bare 204.
        return response()->json(['message' => 'Department deleted.', 'code' => 'department_deleted']);
    }
}
