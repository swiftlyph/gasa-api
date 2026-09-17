<?php

namespace App\Domains\Company\Actions;

use App\Domains\Company\Exceptions\DepartmentNameTaken;
use App\Domains\Company\Models\Department;

/**
 * Renames a department via PATCH /company/departments/{department}.
 * Employees follow automatically: they point at the row, not the name,
 * which is the point of departments no longer being free text. A change
 * of letter case only ("finance" -> "Finance") is not a collision with
 * itself.
 */
class UpdateDepartmentAction
{
    /**
     * @throws DepartmentNameTaken
     */
    public function execute(Department $department, string $name): Department
    {
        $taken = Department::query()
            ->where('company_id', $department->company_id)
            ->whereKeyNot($department->getKey())
            ->named($name)
            ->exists();

        if ($taken) {
            throw new DepartmentNameTaken;
        }

        $department->update(['name' => trim($name)]);

        return $department->refresh();
    }
}
