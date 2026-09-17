<?php

namespace App\Domains\Company\Support;

use App\Domains\Company\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * The roster's filters and ordering, shared by the paginated list and the
 * CSV export so "export what I'm looking at" is true by construction.
 *
 * Ordered by surname, then given name, tiebroken by id so two employees
 * with the same name page (and export) stably.
 */
final class EmployeeFilters
{
    /**
     * @param  array<string, mixed>  $filters  validated IndexEmployeesRequest input
     * @return Builder<Employee>
     */
    public static function query(array $filters): Builder
    {
        $query = Employee::query()
            ->with('department')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($employmentType = $filters['employment_type'] ?? null) {
            $query->where('employment_type', $employmentType);
        }

        if ($departmentId = $filters['department_id'] ?? null) {
            $query->where('department_id', $departmentId);
        }

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($query) use ($search) {
                $query->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('employee_no', 'ilike', "%{$search}%");
            });
        }

        return $query;
    }
}
