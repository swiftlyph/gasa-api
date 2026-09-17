<?php

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Platform\Support\PortalRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /admin/users filters. Mirrors IndexAdminMerchantsRequest: per_page
 * capped at 100, recognized filters validated strictly. Authorization is
 * the admin.api middleware group, not here.
 */
class IndexAdminUsersRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::in(PortalRole::values())],
            'status' => ['sometimes', Rule::in(['active', 'deactivated'])],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
