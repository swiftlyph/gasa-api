<?php

namespace App\Domains\CashSessions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for POST .../close. Whether the session is already
 * closed is CloseCashSessionAction's question (see SessionClosed), not
 * this request's — a validation rule has no view of session state.
 */
class CloseCashSessionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'counted_cash_cents' => ['required', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{counted_cash_cents: int, notes?: string|null}
     */
    public function closePayload(): array
    {
        /**
         * @var array{counted_cash_cents: int, notes?: string|null} $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
