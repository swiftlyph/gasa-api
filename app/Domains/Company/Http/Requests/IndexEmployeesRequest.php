<?php

namespace App\Domains\Company\Http\Requests;

use App\Domains\Company\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /company/employees filters. Mirrors IndexAdminMerchantsRequest's
 * shape: per_page capped at 100, recognized filters validated strictly.
 * Authorization is not here; that is the company.api middleware group
 * plus EmployeePolicy::viewAny().
 */
class IndexEmployeesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(EmployeeStatus::values())],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
