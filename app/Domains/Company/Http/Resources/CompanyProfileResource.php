<?php

namespace App\Domains\Company\Http\Resources;

use App\Domains\Company\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full company profile payload for GET /company/profile. Flat shape,
 * no `data` wrapper (README § Response shapes).
 *
 * Deliberately omits owner_user_id, for the same reason
 * MerchantProfileResource does: not asked for, and an internal user id
 * has no business in every company-portal response.
 *
 * @mixin Company
 */
class CompanyProfileResource extends JsonResource
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

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
