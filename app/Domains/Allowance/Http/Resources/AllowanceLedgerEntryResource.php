<?php

namespace App\Domains\Allowance\Http\Resources;

use App\Domains\Allowance\Models\AllowanceLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AllowanceLedgerEntry */
class AllowanceLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount_cents' => $this->amount_cents,
            'balance_after_cents' => $this->balance_after_cents,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
