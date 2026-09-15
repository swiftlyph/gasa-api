<?php

namespace App\Domains\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for adding an employee via POST /company/employees.
 * Whether the email or employee_no is already on this company's roster
 * is NOT checked here: that depends on current database state a
 * validation rule shouldn't be answering (the same split
 * AddTeamMemberRequest makes), and CreateEmployeeAction (via
 * EmployeeEmailTaken / EmployeeNumberTaken) is the real authority.
 *
 * `status` is deliberately absent: a new employee is always active.
 * PATCH is where status changes.
 */
class StoreEmployeeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:30'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'hired_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array{
     *     employee_no?: string|null,
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     mobile?: string|null,
     *     department?: string|null,
     *     job_title?: string|null,
     *     hired_at?: string|null,
     * }
     */
    public function payload(): array
    {
        /**
         * @var array{
         *     employee_no?: string|null,
         *     first_name: string,
         *     last_name: string,
         *     email: string,
         *     mobile?: string|null,
         *     department?: string|null,
         *     job_title?: string|null,
         *     hired_at?: string|null,
         * } $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
