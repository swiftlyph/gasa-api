<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderBeneficiary;
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

            // P10. `discount_cents` above KEEPS meaning "every peso off
            // this order" (statutory + promo); this block says how that
            // total was reached and how the sale decomposes for tax. Every
            // figure is the order's OWN SNAPSHOT — `vat_registered` here
            // is what the merchant was at SALE TIME, not what it is now,
            // so toggling registration never rewrites a past order.
            'tax' => [
                'vat_registered' => $this->vat_registered_snapshot,
                'vat_rate_bps' => $this->vat_rate_bps_snapshot,

                'vatable_sales_cents' => $this->vatable_sales_cents,
                'vatable_sales_formatted' => Money::format($this->vatable_sales_cents, $currency),
                'vat_cents' => $this->vat_cents,
                'vat_formatted' => Money::format($this->vat_cents, $currency),
                'vat_exempt_sales_cents' => $this->vat_exempt_sales_cents,
                'vat_exempt_sales_formatted' => Money::format($this->vat_exempt_sales_cents, $currency),
                'nonvat_sales_cents' => $this->nonvat_sales_cents,
                'nonvat_sales_formatted' => Money::format($this->nonvat_sales_cents, $currency),

                'statutory_discount_cents' => $this->statutory_discount_cents,
                'statutory_discount_formatted' => Money::format($this->statutory_discount_cents, $currency),
                'promo_discount_cents' => $this->promo_discount_cents,
                'promo_discount_formatted' => Money::format($this->promo_discount_cents, $currency),
            ],

            // Usually empty. One entry per person who claimed a statutory
            // discount, never per line — the lines point back via
            // `beneficiary_id`.
            'beneficiaries' => $this->beneficiaries->map(
                fn (OrderBeneficiary $beneficiary) => [
                    'id' => $beneficiary->id,
                    'type' => $beneficiary->type->value,
                    'type_label' => $beneficiary->type->label(),
                    'name' => $beneficiary->name,
                    'id_number' => $beneficiary->id_number,
                    'discount_cents' => $beneficiary->discount_cents,
                    'discount_formatted' => Money::format($beneficiary->discount_cents, $currency),
                    'vat_exempt_sales_cents' => $beneficiary->vat_exempt_sales_cents,
                    'vat_exempt_sales_formatted' => Money::format($beneficiary->vat_exempt_sales_cents, $currency),
                ],
            )->values(),

            'items' => $this->items->map(
                fn ($item) => new OrderItemResource($item, $currency),
            )->values(),
        ];
    }
}
