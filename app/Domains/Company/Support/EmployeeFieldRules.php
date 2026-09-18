<?php

namespace App\Domains\Company\Support;

use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Enums\EmploymentType;
use Illuminate\Validation\Rule;

/**
 * The one definition of what an employee's fields may look like, shared
 * by StoreEmployeeRequest, UpdateEmployeeRequest and the CSV importer,
 * so a row that the form would reject can never get in through a file
 * (or the other way round).
 *
 * Shape only. Anything that depends on current database state (is this
 * email taken, does this department belong to the company) stays in the
 * Actions, per the API's convention.
 */
final class EmployeeFieldRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function forCreate(): array
    {
        return [
            'employee_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'regex:'.PhoneNumber::E164_PATTERN],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employment_type' => ['sometimes', 'string', Rule::in(EmploymentType::values())],
            'hired_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'birthdate' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
        ];
    }

    /**
     * Every field optional (absent means "don't change it"), the three
     * identity fields never nullable, plus the two fields only an update
     * may touch: a new employee is always active.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function forUpdate(): array
    {
        return [
            ...self::forCreate(),
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'string', 'email', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(EmployeeStatus::values())],
            'separated_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * One CSV row. Like create, except a file names its department (the
     * importer finds or creates it) instead of carrying an id.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function forImportRow(): array
    {
        $rules = self::forCreate();
        unset($rules['department_id']);

        return [
            ...$rules,
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'mobile.regex' => 'The mobile number must be a valid phone number, for example 0917 123 4567 or +639171234567.',
            'birthdate.before' => 'The birthdate must be a date in the past.',
        ];
    }
}
