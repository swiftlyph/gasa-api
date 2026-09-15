<?php

namespace App\Domains\Company\Actions;

use App\Domains\Company\Exceptions\EmployeeEmailTaken;
use App\Domains\Company\Exceptions\EmployeeNumberTaken;
use App\Domains\Company\Models\Employee;

/**
 * Partial update of an employee via PATCH /company/employees/{employee}.
 * $payload is UpdateEmployeeRequest::payload(), so it only ever holds
 * the fields that request validates (never company_id or user_id) and
 * $employee->update($payload) is safe, the same reasoning
 * UpdateMerchantProfileAction gives.
 *
 * Uniqueness is re-checked only for the fields being changed, against
 * the rest of the same company's non-deleted roster, excluding the row
 * itself so re-sending an unchanged email is not a collision.
 */
class UpdateEmployeeAction
{
    /**
     * @param  array{
     *     employee_no?: string|null,
     *     first_name?: string,
     *     last_name?: string,
     *     email?: string,
     *     mobile?: string|null,
     *     department?: string|null,
     *     job_title?: string|null,
     *     hired_at?: string|null,
     *     status?: string,
     * }  $payload
     *
     * @throws EmployeeEmailTaken
     * @throws EmployeeNumberTaken
     */
    public function execute(Employee $employee, array $payload): Employee
    {
        $others = Employee::query()
            ->where('company_id', $employee->company_id)
            ->whereKeyNot($employee->getKey());

        if (isset($payload['email'])) {
            $payload['email'] = mb_strtolower($payload['email']);

            if ((clone $others)->where('email', $payload['email'])->exists()) {
                throw new EmployeeEmailTaken;
            }
        }

        if (isset($payload['employee_no'])
            && (clone $others)->where('employee_no', $payload['employee_no'])->exists()) {
            throw new EmployeeNumberTaken;
        }

        $employee->update($payload);

        // refresh(), not fresh(): see UpdateMerchantProfileAction.
        return $employee->refresh();
    }
}
