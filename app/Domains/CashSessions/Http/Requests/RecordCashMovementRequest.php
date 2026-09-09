<?php

namespace App\Domains\CashSessions\Http\Requests;

use App\Domains\CashSessions\Enums\CashMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for POST .../movements. Whether the session is open is
 * NOT checked here — RecordCashMovementAction is the authority (see
 * SessionClosed) — this only validates the shape a movement must have
 * regardless of session state.
 */
class RecordCashMovementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CashMovementType::class)],

            // Always positive — the type carries direction (see
            // CashMovementType::sign() and the cash_movements migration's
            // amount_cents > 0 CHECK constraint, which this mirrors).
            'amount_cents' => ['required', 'integer', 'min:1'],

            'reason' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    /**
     * @return array{type: string, amount_cents: int, reason: string}
     */
    public function movementPayload(): array
    {
        /**
         * @var array{type: string, amount_cents: int, reason: string} $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
