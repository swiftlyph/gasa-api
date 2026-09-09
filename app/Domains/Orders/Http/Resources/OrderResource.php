<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Orders\Models\Order;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The order payload, identical for the list and the single endpoint (the
 * list wraps it in { data, links, meta }; see README § Response shapes).
 *
 * Every money field ships TWICE: `*_cents` for anything that computes,
 * `*_formatted` for anything that displays. Clients that format cents
 * themselves drift apart from each other and from printed receipts, so
 * the server renders the string once (App\Domains\Shared\Support\Money).
 *
 * `cash_cents` / `gcash_cents` stay null on non-split orders rather than
 * collapsing to 0 — "no cash component" and "zero pesos of cash" are
 * different facts.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currency = $this->currency;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,

            'currency' => $currency,
            'subtotal_cents' => $this->subtotal_cents,
            'subtotal_formatted' => Money::format($this->subtotal_cents, $currency),
            'discount_cents' => $this->discount_cents,
            'discount_formatted' => Money::format($this->discount_cents, $currency),
            'total_cents' => $this->total_cents,
            'total_formatted' => Money::format($this->total_cents, $currency),

            'payment_method' => $this->payment_method->value,
            'cash_cents' => $this->cash_cents,
            'cash_formatted' => Money::formatNullable($this->cash_cents, $currency),
            'gcash_cents' => $this->gcash_cents,
            'gcash_formatted' => Money::formatNullable($this->gcash_cents, $currency),

            // The cashier, and — if it was reversed — who reversed it.
            'created_by_user_id' => $this->created_by_user_id,
            'voided_by_user_id' => $this->voided_by_user_id,

            'completed_at' => $this->completed_at?->toISOString(),
            'voided_at' => $this->voided_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            'items' => $this->items->map(
                fn ($item) => new OrderItemResource($item, $currency),
            )->values(),
        ];
    }
}
