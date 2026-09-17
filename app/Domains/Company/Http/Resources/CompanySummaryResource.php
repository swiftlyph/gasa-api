<?php

namespace App\Domains\Company\Http\Resources;

use App\Domains\Company\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact company shape embedded in /auth/me, the
 * MerchantSummaryResource twin. Enough for a frontend to render the
 * company name and branch on status (e.g. show a suspended screen), not
 * a full company representation.
 *
 * @mixin Company
 */
class CompanySummaryResource extends JsonResource
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
