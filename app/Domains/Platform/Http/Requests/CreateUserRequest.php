<?php

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Platform\Support\PortalRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for POST /admin/users.
 *
 * No `password` field exists by design — the invite flow is the only way
 * a password is set, so an admin never chooses or learns another user's
 * credentials.
 *
 * Email availability is NOT checked here: that depends on current
 * database state a validation rule shouldn't answer, and
 * CreateUserAction (via EmailUnavailable) is the real authority —
 * matching AddTeamMemberRequest's documented reasoning.
 */
class CreateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(PortalRole::values())],
        ];
    }

    /**
     * @return array{name: string, email: string, role: string}
     */
    public function payload(): array
    {
        /** @var array{name: string, email: string, role: string} $payload */
        $payload = $this->validated();

        return $payload;
    }
}
