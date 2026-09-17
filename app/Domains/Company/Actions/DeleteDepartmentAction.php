<?php

namespace App\Domains\Company\Actions;

use App\Domains\Company\Exceptions\DepartmentInUse;
use App\Domains\Company\Models\Department;

/**
 * Deletes a department via DELETE /company/departments/{department}, but
 * only an EMPTY one. Allowance will be targeted by department, so quietly
 * un-grouping people would quietly change who is eligible; the caller
 * moves the employees first. Removed (soft-deleted) employees don't
 * count, and the foreign key nulls their department_id.
 */
class DeleteDepartmentAction
{
    /**
     * @throws DepartmentInUse
     */
    public function execute(Department $department): void
    {
        $employeesCount = $department->employees()->count();

        if ($employeesCount > 0) {
            throw new DepartmentInUse($employeesCount);
        }

        $department->delete();
    }
}
