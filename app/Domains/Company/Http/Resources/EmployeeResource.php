<?php

namespace App\Domains\Company\Http\Resources;

use App\Domains\Company\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee, used by every employee endpoint: flat for show/store/
 * update, and inside { data, links, meta } for the paginated index.
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
            'last_name' => $this->last_name,
            'full_name' => $this->resource->fullName(),
            'email' => $this->email,
            'mobile' => $this->mobile,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'hired_at' => $this->hired_at?->toDateString(),
            'status' => $this->status->value,

            // Whether the employee has been invited into the employee
            // portal yet. Always false until the invite phase; surfaced
            // now, additively, so the frontend can branch on it early.
            'has_account' => $this->user_id !== null,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
