<?php

namespace App\Domains\Merchant\Http\Requests;

use App\Domains\Merchant\Enums\RoleInMerchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for changing a team member's role via PATCH
 * /merchant/team/{user}. Whether {user} actually belongs to the acting
 * merchant is NOT checked here — that is a tenancy/authorization concern,
 * not a validation rule, and is checked explicitly in TeamController.
 */
class UpdateTeamMemberRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role_in_merchant' => ['required', 'string', Rule::in(RoleInMerchant::values())],
        ];
    }

    /**
     * @return array{role_in_merchant: string}
     */
    public function payload(): array
    {
        /**
         * @var array{role_in_merchant: string} $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
