<?php

namespace App\Domains\Company\Http\Resources;

use App\Domains\Company\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One department. `employees_count` is present only when the controller
 * asked the database for it (withCount), so this resource never triggers
 * a query of its own: the list counts, a rename response does not.
 *
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'employees_count' => $this->whenCounted('employees'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
