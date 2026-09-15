<?php

namespace App\Domains\Merchant\Http\Resources;

use App\Domains\Merchant\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full merchant profile payload — used by both the profile `show` and
 * `update` endpoints. Flat shape, no `data` wrapper (see README §
 * Response shapes for the single-resource convention).
 *
 * Deliberately omits owner_user_id: not asked for by this endpoint, and
 * keeping the payload to what's documented avoids leaking an internal
 * user id to every merchant-portal caller.
 *
 * @mixin Merchant
 */
class MerchantProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,

            'legal_name' => $this->legal_name,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'contact_email' => $this->contact_email,
            'tax_identifier' => $this->tax_identifier,
            'receipt_header' => $this->receipt_header,
            'receipt_footer' => $this->receipt_footer,
            'timezone' => $this->timezone,

            // P10: whether this shop is VAT-registered, which decides how
            // its sales are decomposed for tax. Additive; editable
            // through PATCH /merchant/profile.
            'vat_registered' => $this->vat_registered,

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
