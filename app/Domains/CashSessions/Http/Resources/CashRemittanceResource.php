<?php

namespace App\Domains\CashSessions\Http\Resources;

use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRemittance
 */
class CashRemittanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'amount_cents' => $this->amount_cents,
            'amount_formatted' => Money::format($this->amount_cents, 'PHP'),
            'note' => $this->note,

            // Null on every row this phase — no upload endpoint writes it
            // (see the cash_remittances migration). Included anyway so a
            // frontend doesn't need a schema change once uploads land.
            'attachment_path' => $this->attachment_path,

            'created_by_user_id' => $this->created_by_user_id,
            'confirmed_by_user_id' => $this->confirmed_by_user_id,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
