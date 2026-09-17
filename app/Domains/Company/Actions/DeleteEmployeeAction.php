<?php

namespace App\Domains\Company\Actions;

use App\Domains\Company\Models\Employee;

/**
 * Removes an employee from the roster via DELETE
 * /company/employees/{employee}. A SOFT delete: the row keeps its
 * history for whatever later references it (a wallet ledger), simply
 * stops appearing (SoftDeletes' global scope) and frees its email and
 * employee_no for reuse (the partial unique indexes ignore deleted
 * rows). There is deliberately no hard-delete path.
 */
class DeleteEmployeeAction
{
    public function execute(Employee $employee): void
    {
        $employee->delete();
    }
}
