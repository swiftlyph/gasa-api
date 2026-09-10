<?php

namespace App\Domains\Merchant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for updating the caller's own merchant profile.
 *
 * Deliberately defines rules for none of status, owner_user_id, id, name
 * or timezone: those stay out of validated(), which is the only thing
 * UpdateMerchantProfileAction ever reads, so none of them can be changed
 * through this endpoint even though they're all $fillable on Merchant —
 * see that Action's docblock.
 */
class UpdateMerchantProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:60'],
            'receipt_header' => ['sometimes', 'nullable', 'string', 'max:255'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{
     *     legal_name?: string|null,
     *     address_line1?: string|null,
     *     address_line2?: string|null,
     *     city?: string|null,
     *     postal_code?: string|null,
     *     phone?: string|null,
     *     contact_email?: string|null,
     *     tax_identifier?: string|null,
     *     receipt_header?: string|null,
     *     receipt_footer?: string|null,
     * }
     */
    public function profilePayload(): array
    {
        /**
         * @var array{
         *     legal_name?: string|null,
         *     address_line1?: string|null,
         *     address_line2?: string|null,
         *     city?: string|null,
         *     postal_code?: string|null,
         *     phone?: string|null,
         *     contact_email?: string|null,
         *     tax_identifier?: string|null,
         *     receipt_header?: string|null,
         *     receipt_footer?: string|null,
         * } $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
