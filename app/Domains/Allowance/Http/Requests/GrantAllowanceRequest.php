<?php

namespace App\Domains\Allowance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /company/employees/{employee}/allowance/grants payload. */
class GrantAllowanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array{amount_cents: int, reason: string, idempotency_key: string}
     */
    public function payload(): array
    {
        /** @var array{amount_cents: int, reason: string, idempotency_key: string} $payload */
        $payload = $this->validated();

        return $payload;
    }
}
