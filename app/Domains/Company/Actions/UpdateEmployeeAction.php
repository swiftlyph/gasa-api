<?php

namespace App\Domains\Company\Actions;

use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Exceptions\EmployeeEmailTaken;
use App\Domains\Company\Exceptions\EmployeeNumberTaken;
use App\Domains\Company\Exceptions\InvalidDepartment;
use App\Domains\Company\Models\Employee;
use App\Domains\Company\Support\DepartmentOwnership;

/**
 * Partial update of an employee via PATCH /company/employees/{employee}
 * (and row by row from the CSV importer). $payload only ever holds the
 * fields EmployeeFieldRules validates (never company_id or user_id), so
 * $employee->update($payload) is safe, the same reasoning
 * UpdateMerchantProfileAction gives.
 *
 * Uniqueness is re-checked only for the fields being changed, against
 * the rest of the same company's non-deleted roster, excluding the row
 * itself so re-sending an unchanged email is not a collision.
 *
 * STATUS AND separated_at MOVE TOGETHER, and this is the only place that
 * decides how: a `separated` employee always has a date (the one sent,
 * else the one already stored, else today in the company's timezone);
 * any other status never has one. When the allowance module lands, this
 * transition is where it stops grants and expires what is left.
 */
class UpdateEmployeeAction
{
    /**
     * @param  array{
     *     employee_no?: string|null,
     *     first_name?: string,
     *     middle_name?: string|null,
     *     last_name?: string,
     *     suffix?: string|null,
     *     email?: string,
     *     mobile?: string|null,
     *     department_id?: int|null,
     *     job_title?: string|null,
     *     employment_type?: string,
     *     hired_at?: string|null,
     *     birthdate?: string|null,
     *     status?: string,
     *     separated_at?: string|null,
     * }  $payload
     *
     * @throws EmployeeEmailTaken
     * @throws EmployeeNumberTaken
     * @throws InvalidDepartment
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

        if (isset($payload['department_id'])) {
            DepartmentOwnership::assert($employee->company_id, $payload['department_id']);
        }

        $status = isset($payload['status'])
            ? EmployeeStatus::from($payload['status'])
            : $employee->status;

        $payload['separated_at'] = $status === EmployeeStatus::Separated
            ? ($payload['separated_at']
                ?? $employee->separated_at?->toDateString()
                ?? now(config('company.day_timezone'))->toDateString())
            : null;

        $employee->update($payload);

        // refresh(), not fresh(): see UpdateMerchantProfileAction.
        return $employee->refresh();
    }
}
