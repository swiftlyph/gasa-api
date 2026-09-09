<?php

namespace App\Domains\Merchant\Http\Resources;

use App\Domains\Merchant\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact merchant shape embedded in /auth/me. Deliberately minimal —
 * enough for a frontend to render the merchant name and branch on status
 * (e.g. show the suspended screen), not a full merchant representation.
 *
 * @mixin Merchant
 */
class MerchantSummaryResource extends JsonResource
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
        ];
    }
}
