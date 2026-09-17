<?php

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Platform\Support\PortalRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /admin/users/{user}/role. `role` is validated only for SHAPE
 * here (one of the four portal roles) — whether THIS change is allowed
 * (self, last admin) is ChangeUserRoleAction's question, matching how
 * UpdateMerchantStatusRequest leaves transition legality to the enum.
 */
class ChangeUserRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(PortalRole::values())],
        ];
    }

    /**
     * @return array{role: string}
     */
    public function payload(): array
    {
        /** @var array{role: string} $payload */
        $payload = $this->validated();

        return $payload;
    }
}
