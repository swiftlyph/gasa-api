<?php

namespace App\Domains\Company\Support;

use App\Domains\Company\Exceptions\InvalidDepartment;
use App\Domains\Company\Models\Department;

/**
 * The one check both employee write Actions make before accepting a
 * department_id: it must be a department of the SAME company as the
 * employee. Filters on company_id explicitly rather than relying on
 * BelongsToCompany's scope alone, so it means the same thing from a
 * seeder or the importer (scope lifted) as from a request.
 */
final class DepartmentOwnership
{
    /**
     * @throws InvalidDepartment
     */
    public static function assert(int $companyId, int $departmentId): void
    {
        $owned = Department::query()
            ->where('company_id', $companyId)
            ->whereKey($departmentId)
            ->exists();

        if (! $owned) {
            throw new InvalidDepartment;
        }
    }
}
