<?php

namespace App\Domains\Company\Http\Requests;

use App\Domains\Company\Support\EmployeeFieldRules;
use App\Domains\Company\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for adding an employee via POST /company/employees.
 * The rules themselves live in EmployeeFieldRules, shared with the update
 * request and the CSV importer.
 *
 * Whether the email or employee_no is already on this company's roster,
 * and whether department_id is one of this company's departments, is NOT
 * checked here: that depends on current database state a validation rule
 * shouldn't be answering (the same split AddTeamMemberRequest makes), and
 * CreateEmployeeAction is the real authority.
 *
 * `status` is deliberately absent: a new employee is always active.
 */
class StoreEmployeeRequest extends FormRequest
{
    /**
     * The mobile number is normalized to E.164 BEFORE validation, so the
     * rule only has to check one shape and what gets stored is what an
     * SMS gateway can use.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('mobile')) {
            $mobile = $this->input('mobile');

            $this->merge(['mobile' => is_string($mobile) ? PhoneNumber::normalize($mobile) : $mobile]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return EmployeeFieldRules::forCreate();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return EmployeeFieldRules::messages();
    }

    /**
     * @return array{
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
     * }
     */
    public function payload(): array
    {
        /**
         * @var array{
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
         * } $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
