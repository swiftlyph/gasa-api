<?php

namespace App\Domains\Company\Http\Requests;

use App\Domains\Company\Support\EmployeeFieldRules;
use App\Domains\Company\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for PATCH /company/employees/{employee}. Every field
 * is `sometimes`: absent means "don't change it", matching
 * UpdateMerchantProfileRequest. The three identity fields are not
 * nullable (an employee always has a first name, last name and email);
 * the rest may be cleared with null.
 *
 * `status` and `separated_at` live here and not in StoreEmployeeRequest:
 * a new employee is always active, and PATCH is the only way to pause,
 * separate or reinstate one. How the two move together is
 * UpdateEmployeeAction's decision, not a validation rule. Roster
 * uniqueness and department ownership are, as on create, the Action's
 * questions.
 */
class UpdateEmployeeRequest extends FormRequest
{
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
        return EmployeeFieldRules::forUpdate();
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
     * }
     */
    public function payload(): array
    {
        /**
         * @var array{
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
         * } $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
