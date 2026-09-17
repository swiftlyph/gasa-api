<?php

namespace App\Domains\Company\Actions;

use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Enums\EmploymentType;
use App\Domains\Company\Exceptions\EmployeeEmailTaken;
use App\Domains\Company\Exceptions\EmployeeNumberTaken;
use App\Domains\Company\Exceptions\InvalidDepartment;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Employee;
use App\Domains\Company\Support\DepartmentOwnership;

/**
 * Adds an employee to a company's roster via POST /company/employees (and
 * row by row from the CSV importer). An HR record only: no User is
 * created and no invite is issued this phase. That arrives with the
 * employee portal, the way a merchant's team arrived after its profile.
 *
 * The uniqueness checks ask the same question the partial unique
 * indexes enforce (this company, non-deleted rows only), so a race
 * between check and insert surfaces as a database exception rather than
 * a duplicate. They filter on company_id explicitly rather than relying
 * on BelongsToCompany's scope alone, so the Action means the same thing
 * from a seeder (admin context, scope lifted) as from a request.
 *
 * Email is lowercased on the way in, matching how users.email is
 * handled, so the lower(email) unique index and application lookups
 * agree. The payload comes from a FormRequest's payload() (or the
 * importer's per-row validator), never raw request input, so spreading
 * it into create() is safe: nothing in it is outside $fillable, and
 * company_id/status are set here, not by the caller.
 */
class CreateEmployeeAction
{
    /**
     * @param  array{
     *     employee_no?: string|null,
     *     first_name: string,
     *     middle_name?: string|null,
     *     last_name: string,
     *     suffix?: string|null,
     *     email: string,
     *     mobile?: string|null,
     *     department_id?: int|null,
     *     job_title?: string|null,
     *     employment_type?: string,
     *     hired_at?: string|null,
     *     birthdate?: string|null,
     * }  $payload
     *
     * @throws EmployeeEmailTaken
     * @throws EmployeeNumberTaken
     * @throws InvalidDepartment
     */
    public function execute(Company $company, array $payload): Employee
    {
        $email = mb_strtolower($payload['email']);
        $employeeNo = $payload['employee_no'] ?? null;
        $departmentId = $payload['department_id'] ?? null;

        $roster = Employee::query()->where('company_id', $company->getKey());

        if ((clone $roster)->where('email', $email)->exists()) {
            throw new EmployeeEmailTaken;
        }

        if ($employeeNo !== null && (clone $roster)->where('employee_no', $employeeNo)->exists()) {
            throw new EmployeeNumberTaken;
        }

        if ($departmentId !== null) {
            DepartmentOwnership::assert($company->getKey(), $departmentId);
        }

        return Employee::create([
            // Set explicitly rather than left to the column default, so
            // the model handed back already carries it.
            'employment_type' => EmploymentType::Regular->value,
            ...$payload,
            'email' => $email,
            'company_id' => $company->getKey(),
            'status' => EmployeeStatus::Active,
        ]);
    }
}
