<?php

namespace App\Domains\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for POST /auth/accept-invite. Whether the token
 * actually matches a live, unused, unexpired invitation is NOT checked
 * here — that is AcceptInviteAction's job (see InvalidInvite), matching
 * LoginRequest's split between shape validation and credential checking.
 */
class AcceptInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    /**
     * @return array{token: string, password: string}
     */
    public function payload(): array
    {
        /**
         * @var array{token: string, password: string} $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
