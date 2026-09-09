<?php

namespace App\Domains\CashSessions\Http\Resources;

use App\Domains\CashSessions\Models\CashMovement;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashMovement
 */
class CashMovementResource extends JsonResource
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
            'amount_formatted' => Money::format($this->amount_cents, 'PHP'),
            'reason' => $this->reason,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
