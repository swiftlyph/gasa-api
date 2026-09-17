<?php

namespace App\Domains\Company\Http\Resources;

use App\Domains\Company\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee, used by every employee endpoint: flat for show/store/
 * update, and inside { data, links, meta } for the paginated index.
 *
 * `department` is the compact { id, name } block, or null; every
 * controller path eager-loads it, so it is always present and never an
 * N+1. `department_id` sits beside it so a form can bind to the id
 * without unpacking the object.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->resource->fullName(),
            'email' => $this->email,
            'mobile' => $this->mobile,
            'birthdate' => $this->birthdate?->toDateString(),

            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'job_title' => $this->job_title,
            'employment_type' => $this->employment_type->value,
            'hired_at' => $this->hired_at?->toDateString(),

            'status' => $this->status->value,
            // Set if, and only if, status is `separated`.
            'separated_at' => $this->separated_at?->toDateString(),

            // Whether the employee has been invited into the employee
            // portal yet. Always false until the invite phase; surfaced
            // now, additively, so the frontend can branch on it early.
            'has_account' => $this->user_id !== null,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
