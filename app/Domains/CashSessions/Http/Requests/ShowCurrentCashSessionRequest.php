<?php

namespace App\Domains\CashSessions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /merchant/cash-sessions/current.
 */
class ShowCurrentCashSessionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional: omitted means "the merchant's default register" —
            // see App\Domains\CashSessions\Support\DefaultRegister.
            'register_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
