<?php

namespace App\Domains\CashSessions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for opening a cash session. Whether the register
 * already has an open session is NOT checked here — that depends on
 * current database state a validation rule shouldn't be answering, and
 * OpenCashSessionAction (backed by the partial unique index) is the real
 * authority; see SessionAlreadyOpen.
 */
class OpenCashSessionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional: omitted means "the merchant's default register" —
            // see App\Domains\CashSessions\Support\DefaultRegister. Not
            // Rule::exists() scoped to the merchant here, because tenancy
            // enforcement belongs to BelongsToMerchant's global scope, not
            // duplicated into a validation rule — OpenCashSessionAction
            // resolves it through the scoped Register query, so a foreign
            // register id fails there exactly like a foreign order id
            // fails in checkout.
            'register_id' => ['sometimes', 'integer', 'min:1'],

            // A float of 0 is a legitimate till with no starting cash, so
            // this is min:0, not min:1.
            'opening_float_cents' => ['required', 'integer', 'min:0'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{register_id?: int, opening_float_cents: int, notes?: string|null}
     */
    public function openPayload(): array
    {
        /**
         * @var array{register_id?: int, opening_float_cents: int, notes?: string|null} $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
