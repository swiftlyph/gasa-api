<?php

namespace App\Domains\Company\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown by DeleteDepartmentAction when employees are still assigned.
 * Refusing beats silently un-grouping them: allowance will be targeted
 * by department, so an accidental delete would quietly change who is
 * eligible. The caller moves the employees first, then deletes.
 */
class DepartmentInUse extends ApiException
{
    public function __construct(int $employeesCount)
    {
        $people = $employeesCount === 1 ? '1 employee is' : "{$employeesCount} employees are";

        parent::__construct(
            "This department can't be deleted while {$people} still assigned to it.",
            'department_in_use',
            422,
        );
    }
}
