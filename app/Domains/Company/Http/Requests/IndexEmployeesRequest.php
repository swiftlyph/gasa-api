<?php

namespace App\Domains\Company\Http\Requests;

use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /company/employees filters, also used by the CSV export so both
 * accept exactly the same query string. Mirrors
 * IndexAdminMerchantsRequest's shape: per_page capped at 100, recognized
 * filters validated strictly. Authorization is not here; that is the
 * company.api middleware group plus EmployeePolicy::viewAny().
 *
 * department_id is only checked for shape. A department of another
 * company simply matches no employees (the roster query is tenant-scoped),
 * which leaks nothing and needs no special case.
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
            'employment_type' => ['sometimes', Rule::in(EmploymentType::values())],
            'department_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
