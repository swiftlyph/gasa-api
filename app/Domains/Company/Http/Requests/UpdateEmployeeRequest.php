<?php

namespace App\Domains\Company\Http\Requests;

use App\Domains\Company\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for PATCH /company/employees/{employee}. Every field
 * is `sometimes`: absent means "don't change it", matching
 * UpdateMerchantProfileRequest. The three identity fields are not
 * nullable (an employee always has a first name, last name and email);
 * the rest may be cleared with null.
 *
 * `status` lives here and not in StoreEmployeeRequest: a new employee is
 * always active, and PATCH is the only way to pause or reactivate one.
 * Roster uniqueness is, as on create, the Action's question.
 */
class UpdateEmployeeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'string', 'email', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:30'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'hired_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'string', Rule::in(EmployeeStatus::values())],
        ];
    }

    /**
     * @return array{
     *     employee_no?: string|null,
     *     first_name?: string,
     *     last_name?: string,
     *     email?: string,
     *     mobile?: string|null,
     *     department?: string|null,
     *     job_title?: string|null,
     *     hired_at?: string|null,
     *     status?: string,
     * }
     */
    public function payload(): array
    {
        /**
         * @var array{
         *     employee_no?: string|null,
         *     first_name?: string,
         *     last_name?: string,
         *     email?: string,
         *     mobile?: string|null,
         *     department?: string|null,
         *     job_title?: string|null,
         *     hired_at?: string|null,
         *     status?: string,
         * } $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
