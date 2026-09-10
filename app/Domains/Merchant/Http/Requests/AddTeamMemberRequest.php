<?php

namespace App\Domains\Merchant\Http\Requests;

use App\Domains\Merchant\Enums\RoleInMerchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for adding a team member via POST /merchant/team.
 * Whether the email is already in use by this or another merchant is NOT
 * checked here — that depends on current database state a validation
 * rule shouldn't be answering, and AddTeamMemberAction (via
 * MemberAlreadyExists / EmailUnavailable) is the real authority.
 */
class AddTeamMemberRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_in_merchant' => ['required', 'string', Rule::in(RoleInMerchant::values())],
        ];
    }

    /**
     * @return array{name: string, email: string, role_in_merchant: string}
     */
    public function payload(): array
    {
        /**
         * @var array{name: string, email: string, role_in_merchant: string} $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
