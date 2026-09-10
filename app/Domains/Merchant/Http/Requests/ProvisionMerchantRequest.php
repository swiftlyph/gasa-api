<?php

namespace App\Domains\Merchant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for POST /admin/merchants. `profile.*` mirrors
 * UpdateMerchantProfileRequest's field list exactly — the same nine
 * optional profile columns, just nested under `profile` here since this
 * endpoint also creates the merchant's name and owner in the same
 * request. Authorization is not here: it's the admin.api middleware
 * group (auth:sanctum + role:platform_admin + AllowsAdminContext).
 */
class ProvisionMerchantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'owner' => ['required', 'array'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'string', 'email', 'max:255'],

            'profile' => ['sometimes', 'array'],
            'profile.legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'profile.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'profile.phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'profile.contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'profile.tax_identifier' => ['sometimes', 'nullable', 'string', 'max:60'],
            'profile.receipt_header' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.receipt_footer' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{name: string, owner: array{name: string, email: string}, profile?: array<string, mixed>}
     */
    public function payload(): array
    {
        /**
         * @var array{name: string, owner: array{name: string, email: string}, profile?: array<string, mixed>} $payload
         */
        $payload = $this->validated();

        return $payload;
    }
}
