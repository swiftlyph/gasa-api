<?php

namespace App\Domains\CashSessions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for POST .../remittances. Whether the amount exceeds
 * expected cash, or the session is closed, are CreateRemittanceAction's
 * questions (see RemittanceExceedsCash, SessionClosed) — both depend on
 * server-side state a validation rule cannot see.
 */
class CreateRemittanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{amount_cents: int, note?: string|null}
     */
    public function remittancePayload(): array
    {
        /**
         * @var array{amount_cents: int, note?: string|null} $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
